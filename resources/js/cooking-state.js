export const restoreCookingState = (saved, stepCount) => {
    const data = saved && typeof saved === 'object' ? saved : {};
    const validIndex = (index) => Number.isInteger(index) && index >= 0 && index < stepCount;
    return {
        step: Number.isInteger(data.step) ? Math.max(0, Math.min(data.step, stepCount - 1)) : 0,
        done: new Set(Array.isArray(data.done) ? data.done.filter(validIndex) : []),
        size: Number.isInteger(data.size) ? Math.max(0, Math.min(data.size, 4)) : 1,
        timers: Object.fromEntries(Object.entries(data.timers && typeof data.timers === 'object' ? data.timers : {})
            .filter(([index, end]) => String(Number(index)) === index && validIndex(Number(index)) && Number.isFinite(end) && end > 0)),
    };
};

export const remainingSeconds = (end, now = Date.now()) => Number.isFinite(end) && end > 0
    ? Math.max(0, Math.ceil((end - now) / 1000)) : null;

export const formatClock = (seconds) => {
    const hours = Math.floor(seconds / 3600);
    const minutes = Math.floor((seconds % 3600) / 60);
    const rest = seconds % 60;
    const pad = (value) => String(value).padStart(2, '0');
    return hours > 0 ? `${hours}:${pad(minutes)}:${pad(rest)}` : `${pad(minutes)}:${pad(rest)}`;
};

// Each occurrence and revision owns its progress; repeated components never share timers.
export const componentStateKey = (parentId, parentVersion, path, revision) =>
    `cooking-component:${parentId}:${parentVersion}:${path}:${revision}`;

export const cookingStepMap = (content) => Object.fromEntries((content.steps ?? []).map((step, index) => [`s${index}`, {
    index, group: Number.isInteger(step.step_group_index) ? `g${step.step_group_index}` : 'ungrouped',
    group_index: Number.isInteger(step.step_group_index) ? step.step_group_index : -1,
    minutes: step.duration_minutes ?? null,
}]));

export const orderedCookingSteps = (content) => Object.entries(cookingStepMap(content))
    .sort(([, a], [, b]) => a.group_index - b.group_index || a.index - b.index).map(([key]) => key);

export const initialCookingProgress = (content) => {
    const map = cookingStepMap(content);
    const first = orderedCookingSteps(content)[0] ?? null;
    return {
        schema_version: 1, active_step: first,
        last_steps: first ? { [map[first].group]: first } : {},
        steps: Object.fromEntries(Object.keys(map).map((key) => [key, { status: 'not_started', completed_at: null }])),
        timers: {}, checked_ingredients: {}, size: 1,
    };
};

export const cookingTimerRemaining = (timer, now = Date.now()) => {
    if (!timer) return null;
    if (timer.status === 'finished') return 0;
    const milliseconds = timer.status === 'paused' ? timer.remaining_ms : timer.deadline_ms - now;
    return Number.isFinite(milliseconds) ? Math.max(0, Math.ceil(milliseconds / 1000)) : null;
};

export const restoreCookingProgress = (saved, content, contexts = null) => {
    const initial = initialCookingProgress(content);
    const data = saved && typeof saved === 'object' ? saved : {};
    const map = cookingStepMap(content);
    if (Object.hasOwn(map, data.active_step)) initial.active_step = data.active_step;
    for (const key of Object.keys(map)) {
        const step = data.steps?.[key];
        if (['not_started', 'in_progress', 'waiting', 'completed'].includes(step?.status)) {
            initial.steps[key] = { status: step.status, completed_at: step.status === 'completed' && typeof step.completed_at === 'string' ? step.completed_at.slice(0, 64) : null };
        }
        if (data.last_steps?.[map[key].group] === key) initial.last_steps[map[key].group] = key;
        const timer = data.timers?.[key];
        if (!timer || !Number.isInteger(timer.duration_seconds) || timer.duration_seconds < 1 || timer.duration_seconds > 2592000) continue;
        if ((timer.status === 'running' && Number.isFinite(timer.deadline_ms) && timer.deadline_ms > 0)
            || (timer.status === 'paused' && Number.isFinite(timer.remaining_ms) && timer.remaining_ms >= 0 && timer.remaining_ms <= timer.duration_seconds * 1000)
            || (timer.status === 'finished' && timer.remaining_ms === 0)) {
            initial.timers[key] = { ...timer };
        }
    }
    (content.ingredients ?? []).forEach((_, index) => {
        if (data.checked_ingredients?.[`i${index}`] === true) initial.checked_ingredients[`i${index}`] = true;
    });
    if (Number.isInteger(data.size)) initial.size = Math.min(4, Math.max(0, data.size));
    if (contexts) {
        initial.contexts = {};
        for (const [path, context] of Object.entries(contexts)) {
            if (data.contexts?.[path]) initial.contexts[path] = restoreCookingProgress(data.contexts[path], context.content);
        }
        initial.active_context = Object.hasOwn(contexts, data.active_context) && initial.contexts[data.active_context] ? data.active_context : null;
    }
    return initial;
};

export const cookingProgressCompleted = (state) => Object.keys(state.steps).length > 0
    && Object.values(state.steps).every(step => step.status === 'completed')
    && Object.values(state.contexts ?? {}).every(cookingProgressCompleted);

/** Project a child onto the same overlay without changing the parent instruction/progress state. */
export const cookingContextRecord = (root) => {
    const path = root?.state.active_context;
    if (!path || !root.contexts?.[path]) return root;
    const state = root.state.contexts?.[path] ?? initialCookingProgress(root.contexts[path].content);
    return { ...root.contexts[path], id: root.id, version: root.version, context_path: path, state,
        status: cookingProgressCompleted(state) ? 'completed' : 'active' };
};

export const applyCookingEvent = (record, event, now = Date.now()) => {
    if (event.action === 'context_close') {
        const parts = (record.state.active_context ?? '').split('/'); parts.pop();
        const path = parts.length ? parts.join('/') : null;
        const contexts = { ...record.state.contexts };
        if (path && record.contexts?.[path]) contexts[path] ??= initialCookingProgress(record.contexts[path].content);
        return { ...record, state: { ...record.state, active_context: path, contexts } };
    }
    if (event.action === 'context_open' || event.context) {
        const path = event.context;
        if (!path && event.action === 'context_open') return { ...record, state: { ...record.state, active_context: null } };
        const context = record.contexts?.[path];
        if (!context) throw new Error('Unknown cooking context');
        const state = structuredClone(record.state);
        state.contexts ??= {};
        const child = { ...context, state: state.contexts[path] ?? initialCookingProgress(context.content) };
        if (event.action === 'context_open') {
            state.contexts[path] = child.state; state.active_context = path;
        } else {
            if (event.action === 'servings') throw new Error('Component quantities follow the parent servings');
            const { context: _, ...childEvent } = event;
            state.contexts[path] = applyCookingEvent(child, childEvent, now).state;
        }
        return { ...record, state };
    }
    const map = cookingStepMap(record.content);
    const state = structuredClone(record.state);
    const key = event.step;
    const timer = state.timers[key];
    if (['visit', 'step_status', 'timer_start', 'timer_restart', 'timer_pause', 'timer_resume', 'timer_cancel'].includes(event.action) && !Object.hasOwn(map, key)) throw new Error('Unknown cooking step');
    switch (event.action) {
        case 'visit':
            state.active_step = key;
            state.last_steps[map[key].group] = key;
            if (state.steps[key].status === 'not_started') state.steps[key].status = 'in_progress';
            break;
        case 'step_status':
            if (!['not_started', 'in_progress', 'waiting', 'completed'].includes(event.status)) throw new Error('Unknown step status');
            state.steps[key] = { status: event.status, completed_at: event.status === 'completed' ? state.steps[key].completed_at ?? new Date(now).toISOString() : null };
            break;
        case 'timer_start':
        case 'timer_restart': {
            if (timer && event.action === 'timer_start') break;
            const seconds = event.duration_seconds ?? timer?.duration_seconds ?? Number(map[key].minutes) * 60;
            if (!Number.isInteger(seconds) || seconds < 1 || seconds > 2592000) throw new Error('Invalid timer duration');
            state.timers[key] = { duration_seconds: seconds, started_at_ms: now, deadline_ms: now + seconds * 1000, remaining_ms: null, status: 'running' };
            state.steps[key] = { status: 'waiting', completed_at: null };
            break;
        }
        case 'timer_pause':
            if (timer?.status === 'running') state.timers[key] = { ...timer, remaining_ms: Math.max(0, timer.deadline_ms - now), deadline_ms: null, status: 'paused' };
            break;
        case 'timer_resume':
            if (timer?.status === 'paused' && timer.remaining_ms > 0) state.timers[key] = { ...timer, deadline_ms: now + timer.remaining_ms, remaining_ms: null, status: 'running' };
            break;
        case 'timer_cancel':
            delete state.timers[key];
            if (state.steps[key].status === 'waiting') state.steps[key] = { status: 'in_progress', completed_at: null };
            break;
        case 'ingredient':
            if (!/^i\d+$/.test(event.ingredient) || !record.content.ingredients?.[Number(event.ingredient.slice(1))]) throw new Error('Unknown ingredient');
            if (event.checked) state.checked_ingredients[event.ingredient] = true;
            else delete state.checked_ingredients[event.ingredient];
            break;
        case 'ingredients_reset': state.checked_ingredients = {}; break;
        case 'size':
            if (!Number.isInteger(event.size) || event.size < 0 || event.size > 4) throw new Error('Invalid text size');
            state.size = event.size; break;
        case 'servings':
            if (!Number.isFinite(Number(event.servings)) || Number(event.servings) < 0.01 || Number(event.servings) > 1000 || !/^\d+(\.\d{1,2})?$/.test(String(event.servings))) throw new Error('Invalid servings');
            break;
        default: throw new Error('Unknown cooking event');
    }
    return {
        ...record, state, servings: event.action === 'servings' ? String(event.servings) : record.servings,
        status: cookingProgressCompleted(state) ? 'completed' : 'active',
    };
};

export const cookingGroups = (content, state, locale, now = Date.now()) => {
    const map = cookingStepMap(content);
    const groups = new Map();
    orderedCookingSteps(content).forEach((key) => {
        const { group, group_index: index } = map[key];
        if (!groups.has(group)) {
            const texts = content.step_groups?.[index]?.translations;
            groups.set(group, { key: group, title: texts?.[locale]?.title ?? texts?.en?.title ?? '', description: texts?.[locale]?.description ?? '', steps: [], completed: 0, waiting: 0, timers: 0 });
        }
        const row = groups.get(group);
        row.steps.push(key);
        if (state.steps[key]?.status === 'completed') row.completed++;
        if (state.steps[key]?.status === 'waiting') row.waiting++;
        if (cookingTimerRemaining(state.timers[key], now) > 0) row.timers++;
    });
    return [...groups.values()];
};

/** Server time anchored to a monotonic clock avoids countdown jumps when the device clock changes. */
export const cookingClock = (monotonic = () => performance.now(), wall = () => Date.now()) => {
    let anchor = wall();
    let origin = monotonic();
    return {
        sync(serverTime, requestStarted = monotonic()) {
            const received = monotonic();
            anchor = serverTime + Math.max(0, received - requestStarted) / 2;
            origin = received;
        },
        now: () => anchor + Math.max(0, monotonic() - origin),
    };
};
