import { cookingContextRecord, componentStateKey, restoreCookingState, remainingSeconds as timerRemaining, formatClock, cookingClock, cookingGroups, cookingStepMap, cookingTimerRemaining, orderedCookingSteps, initialCookingProgress, restoreCookingProgress, applyCookingEvent } from './cooking-state.js';
import { cookingSessionClient } from './cooking-session-client.js';

const memoryStorage = new Map();
const storage = {
    read(key, fallback) {
        try {
            const raw = window.sessionStorage.getItem(key);

            return raw === null ? (memoryStorage.get(key) ?? fallback) : JSON.parse(raw);
        } catch {
            return memoryStorage.get(key) ?? fallback;
        }
    },
    write(key, value) {
        memoryStorage.set(key, value);
        try {
            window.sessionStorage.setItem(key, JSON.stringify(value));
        } catch {
            // Storage can be unavailable (private mode); state then lives only on the page.
        }
    },
};

const template = (text, values) => Object.entries(values)
    .reduce((result, [name, value]) => result.replaceAll(`{${name}}`, String(value)), text ?? '');

/* Ingredient checkboxes: temporary, per-browser-session state that never touches the recipe. */
const ingredientState = (recipeId) => ({
    key: `ingredients:${recipeId}`,
    get checked() {
        const values = storage.read(this.key, []);
        return new Set(Array.isArray(values) ? values.filter((value) => typeof value === 'string') : []);
    },
    save(set) {
        storage.write(this.key, [...set]);
    },
});

const syncIngredients = (root, recipeId) => {
    const version = root.querySelector('[data-ingredients-panel]')?.dataset.stateVersion
        ?? document.querySelector('[data-ingredients-panel]')?.dataset.stateVersion ?? '';
    const checked = ingredientState(`${recipeId}:${version}`).checked;

    root.querySelectorAll('input[data-ingredient-key]').forEach((input) => {
        input.checked = checked.has(input.dataset.ingredientKey);
    });
    root.querySelectorAll('[data-ingredient-reset]').forEach((button) => {
        button.hidden = checked.size === 0;
    });
};

const initIngredients = () => {
    const panel = () => document.querySelector('[data-ingredients-panel]');
    const recipeId = () => panel()?.dataset.recipeId ?? document.querySelector('[data-cooking-overlay]')?.dataset.recipeId;

    document.addEventListener('change', (event) => {
        const input = event.target;

        if (!(input instanceof HTMLInputElement) || !input.dataset.ingredientKey || !recipeId()) {
            return;
        }

        const state = ingredientState(`${recipeId()}:${panel()?.dataset.stateVersion ?? ''}`);
        const checked = state.checked;

        if (input.checked) {
            checked.add(input.dataset.ingredientKey);
        } else {
            checked.delete(input.dataset.ingredientKey);
        }

        state.save(checked);
        syncIngredients(document, recipeId());
    });

    document.addEventListener('click', (event) => {
        if (!event.target.closest?.('[data-ingredient-reset]') || !recipeId()) {
            return;
        }

        ingredientState(`${recipeId()}:${panel()?.dataset.stateVersion ?? ''}`).save(new Set());
        syncIngredients(document, recipeId());
    });

    if (!panel()) {
        return;
    }

    syncIngredients(document, recipeId());

    // Livewire re-renders the list when servings change; restore ticks afterwards.
    new MutationObserver(() => syncIngredients(document, recipeId())).observe(panel(), { childList: true, subtree: true });
};

/* Jump to recipe: move focus to the target so keyboard and screen-reader users land on the content. */
const initJump = () => {
    document.querySelectorAll('[data-jump-to-recipe]').forEach((link) => {
        link.addEventListener('click', (event) => {
            const target = document.querySelector(link.getAttribute('href'));

            if (!target) {
                return;
            }

            event.preventDefault();
            const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

            target.scrollIntoView({ behavior: reduceMotion ? 'auto' : 'smooth', block: 'start' });
            target.focus({ preventScroll: true });
            history.replaceState(null, '', link.getAttribute('href'));
        });
    });
};

/* Print: carry the currently selected servings (kept in the URL by the servings component). */
const initPrint = () => {
    document.querySelectorAll('[data-print-link]').forEach((link) => {
        link.addEventListener('click', () => {
            const servings = new URL(window.location.href).searchParams.get('servings');
            const url = new URL(link.href, window.location.origin);

            if (servings) {
                url.searchParams.set('servings', servings);
            } else {
                url.searchParams.delete('servings');
            }

            link.href = url.pathname + url.search;
        });
    });

    document.querySelectorAll('[data-print-now]').forEach((button) => {
        button.addEventListener('click', () => window.print());
    });

    if (document.body.hasAttribute('data-auto-print')) {
        window.addEventListener('load', () => window.setTimeout(() => window.print(), 300));
    }
};

/* Cooking mode: one overlay and state contract, with local or authenticated persistence. */
const initCookingMode = () => {
    const overlay = document.querySelector('[data-cooking-overlay]');
    const start = document.querySelector('[data-cooking-start]');
    if (!overlay || !start || overlay.dataset.cookingInitialized) return;
    overlay.dataset.cookingInitialized = 'true';
    const labels = { ...overlay.dataset, ...JSON.parse(overlay.querySelector('[data-cooking-labels]')?.textContent ?? '{}') };
    const locale = document.documentElement.lang;
    const pageVersion = overlay.dataset.stateVersion;
    const enhanced = overlay.dataset.sessionEnabled === 'true';
    const legacyKey = `cooking:${labels.recipeId}:${pageVersion}`;
    const stepsRoot = overlay.querySelector('.cooking-steps');
    const legacyComponents = [...stepsRoot.querySelectorAll(':scope > .nested-component')];
    const els = Object.fromEntries(['previous', 'next', 'close', 'progress', 'done-count', 'bar', 'timers', 'alert', 'ingredients', 'heading', 'groups', 'group-list', 'group-current', 'status', 'servings', 'again', 'sound', 'context-back']
        .map((key) => [key, overlay.querySelector(`[data-cooking-${key}]`)]));
    els.smaller = overlay.querySelector('[data-text-smaller]');
    els.larger = overlay.querySelector('[data-text-larger]');
    els.wake = overlay.querySelector('[data-wake-toggle]');
    const modeHeading = els.heading.textContent;
    let open = false;
    let ticker = null;
    let wakeLock = null;
    let wantsWake = false;
    let sound = false;
    let busy = 0;
    let blocked = false;
    let record = null;
    let renderedStep = '';
    let renderedContent = '';
    let renderedIngredients = '';
    const finished = new Set();
    const clock = cookingClock();
    const text = (node, value) => { if (node && node.textContent !== String(value ?? '')) node.textContent = value ?? ''; };
    const element = (tag, className, value) => { const node = document.createElement(tag); if (className) node.className = className; if (value !== undefined) node.textContent = value; return node; };
    const button = (value, event, attributes = {}) => {
        const node = element('button', 'button', value); node.type = 'button';
        Object.entries(attributes).forEach(([name, val]) => node.setAttribute(name, val));
        node.addEventListener('click', async () => {
            const action = typeof event === 'function' ? event() : event;
            await commit(action);
            if (action.action === 'context_open') els.heading.focus();
        });
        return node;
    };
    const safeUrl = (value, video = false) => {
        if (typeof value !== 'string' || value.trim() === '') return null;
        try {
            const url = new URL(value, location.origin);
            if (url.protocol !== 'https:' && !(url.origin === location.origin && url.protocol === 'http:')) return null;
            if (video && !(['www.youtube-nocookie.com', 'player.vimeo.com'].includes(url.hostname))) return null;
            return url.href;
        } catch { return null; }
    };
    const localStore = {
        read(key, fallback) { try { return JSON.parse(localStorage.getItem(key)) ?? fallback; } catch { return storage.read(key, fallback); } },
        write(key, value) { try { localStorage.setItem(key, JSON.stringify(value)); } catch { storage.write(key, value); } },
    };
    const currentCompatible = () => record && (String(record.revision_id) === pageVersion || record.content_hash === overlay.dataset.pageHash);
    const syncOuterIngredients = () => {
        if (!enhanced || !currentCompatible()) return;
        const checked = new Set();
        document.querySelectorAll('[data-ingredients-panel] [data-recipe-ingredient-id]').forEach((input) => {
            const index = record.content.ingredients.findIndex((row) => String(row.id) === input.dataset.recipeIngredientId);
            if (index >= 0) input.checked = record.state.checked_ingredients[`i${index}`] === true;
            if (input.checked) checked.add(input.dataset.ingredientKey);
        });
        const panel = document.querySelector('[data-ingredients-panel]');
        if (panel) ingredientState(`${labels.recipeId}:${panel.dataset.stateVersion}`).save(checked);
    };
    const rememberLegacy = () => {
        storage.write(`${legacyKey}:v2`, record.state);
        storage.write(legacyKey, { step: Number(record.state.active_step.slice(1)), size: record.state.size,
            done: Object.entries(record.state.steps).filter(([, row]) => row.status === 'completed').map(([key]) => Number(key.slice(1))),
            timers: Object.fromEntries(Object.entries(record.state.timers).filter(([, timer]) => timer.status === 'running').map(([key, timer]) => [key.slice(1), timer.deadline_ms])) });
    };
    const legacyContent = () => {
        const media = [];
        const steps = [...stepsRoot.querySelectorAll('[data-cooking-step]')].map((node, index) => {
            const references = [];
            node.querySelectorAll('.step-media-gallery figure').forEach((figure, photo) => {
                const image = figure.querySelector('img'); const id = `legacy-${index}-${photo}`;
                media.push({ id, type: 'image', url: image.src, translations: { [locale]: { alt_text: image.alt, caption: figure.querySelector('figcaption')?.textContent } } });
                references.push({ media_id: id, kind: 'image' });
            });
            const video = node.querySelector('iframe');
            if (video) { const id = `legacy-video-${index}`; media.push({ id, type: 'video', url: video.src, translations: { [locale]: { alt_text: video.title } } }); references.push({ media_id: id, kind: 'video' }); }
            return { step_number: Number(node.dataset.stepNumber), duration_minutes: Number(node.dataset.minutes) || null,
                translations: { [locale]: { instruction: node.querySelector('.cooking-step-text').textContent } }, media: references };
        });
        return { content: { steps, ingredients: [], step_groups: [], translations: { [locale]: { title: overlay.querySelector('.cooking-recipe').textContent } } }, media,
            revision_id: pageVersion, servings: document.querySelector('[data-ingredients-panel]')?.dataset.servings ?? '1' };
    };
    const legacyRecord = legacyContent();
    const client = enhanced ? cookingSessionClient({
        urls: JSON.parse(overlay.querySelector('[data-cooking-urls]').textContent), user: labels.sessionUser ?? '', recipeId: labels.recipeId,
        csrf: document.querySelector('meta[name="csrf-token"]')?.content ?? '', storage: labels.sessionUser ? storage : localStore, clock,
        notify: (next) => { record = cookingContextRecord(next); blocked = false; if (open) render(); },
    }) : null;
    const now = () => client ? client.now() : clock.now();
    const status = (message) => text(els.status, message);
    const lockControls = () => {
        overlay.querySelectorAll('[data-cooking-interactive] button, [data-cooking-interactive] input, [data-cooking-previous], [data-cooking-next], [data-text-smaller], [data-text-larger], [data-cooking-again], [data-cooking-context-back]')
            .forEach((node) => { node.disabled = !record || blocked || busy > 0 || node.dataset.unavailable === 'true'; });
    };
    const fail = (error) => {
        if ([401, 403, 404, 419].includes(error.status)) {
            blocked = true; stepsRoot.replaceChildren(); els.ingredients.replaceChildren(); els.timers.replaceChildren(); els['group-list'].replaceChildren();
            renderedStep = ''; renderedContent = ''; renderedIngredients = '';
            status(labels.unavailable);
        } else status(error.status === 409 ? labels.conflict : labels.saveError);
        lockControls();
    };
    async function commit(event) {
        if (!record || blocked || busy) return;
        busy++; lockControls(); status(enhanced && labels.sessionUser ? labels.saving : '');
        try {
            if (client) {
                const scoped = record.context_path && !Object.hasOwn(event, 'context') && !['context_open', 'context_close'].includes(event.action)
                    ? { ...event, context: record.context_path } : event;
                record = cookingContextRecord(await client.send(scoped));
            }
            else { record = applyCookingEvent(record, event, now()); rememberLegacy(); }
            status(enhanced && labels.sessionUser ? labels.saved : '');
            if (event.action.startsWith('timer_')) finished.delete(event.step);
        } catch (error) { fail(error); }
        finally { busy--; if (open && !blocked) render(); lockControls(); }
    }
    const renderMedia = (section, step) => {
        for (const reference of step.media ?? []) {
            const asset = record.media?.find((item) => item.id === reference.media_id);
            if (!asset) continue;
            const url = safeUrl(asset.url, reference.kind === 'video');
            if (!url) continue;
            const translated = asset.translations?.[locale] ?? asset.translations?.en ?? {};
            const figure = element('figure', reference.kind === 'video' ? 'step-video' : 'step-media-gallery');
            if (reference.kind === 'video') {
                const frame = element('iframe'); frame.src = url; frame.title = translated.alt_text || labels.videoTitle;
                frame.loading = 'lazy'; frame.referrerPolicy = 'strict-origin-when-cross-origin'; frame.allow = 'encrypted-media; picture-in-picture'; frame.allowFullscreen = true;
                const wrap = element('div', 'step-video-frame'); wrap.append(frame); figure.append(wrap);
            } else { const image = element('img'); image.src = url; image.alt = translated.alt_text ?? ''; image.loading = 'lazy'; figure.append(image); }
            if (translated.caption) figure.append(element('figcaption', '', translated.caption));
            section.append(figure);
        }
    };
    const renderComponents = () => {
        if (!enhanced) { legacyComponents.forEach((node) => stepsRoot.append(node)); return; }
        for (const component of record.components ?? []) {
            const section = element('section', 'nested-component'); section.dataset.cookingInteractive = ''; section.dataset.componentPath = component.path; section.dataset.componentRevision = component.revision_id;
            section.append(element('h3', '', component.title), element('p', '', component.amount));
            if (component.quantity === null) section.append(element('p', 'cooking-note', labels.manualComponent));
            const ingredients = element('details'); ingredients.append(element('summary', '', labels.ingredientsTitle));
            const list = element('ul'); (component.ingredients ?? []).forEach((item) => list.append(element('li', '', `${item.amount} ${item.name}`))); ingredients.append(list);
            const scratch = element('details'); scratch.dataset.componentScratch = ''; scratch.append(element('summary', '', labels.componentSteps));
            const path = component.path;
            if (client?.record?.contexts?.[path]?.content.steps?.length) {
                const openContext = button(labels.viewComponent, { action: 'context_open', context: path });
                openContext.dataset.openContext = path;
                scratch.append(openContext);
            }
            section.append(ingredients, scratch);
            if (component.can_use_prepared && currentCompatible()) {
                const label = element('label', '', labels.prepared); const input = element('input'); input.type = 'checkbox'; input.dataset.componentPrepared = ''; label.prepend(input); section.append(label);
            }
            if (!client?.record?.contexts?.[path]?.content.steps?.length && currentCompatible() && safeUrl(component.preview_url)) { const link = element('a', '', labels.viewComponent); link.href = safeUrl(component.preview_url); section.append(link); }
            stepsRoot.append(section);
        }
        initNestedCooking();
    };
    const renderIngredients = () => {
        if (!enhanced) {
            if (renderedIngredients === 'legacy') return;
            els.ingredients.replaceChildren();
            document.querySelectorAll('[data-ingredients-panel] > h3, [data-ingredients-panel] > [data-ingredient-list]').forEach((node) => {
                const clone = node.cloneNode(true);
                clone.querySelectorAll('.personal-ingredient-change, [data-component-row]').forEach((item) => item.remove());
                [clone, ...clone.querySelectorAll('*')].forEach((item) => {
                    if (item.id) item.id = `cook-${item.id}`;
                    if (item.hasAttribute('for')) item.setAttribute('for', `cook-${item.getAttribute('for')}`);
                    [...item.attributes].filter((attr) => attr.name.startsWith('wire:')).forEach((attr) => item.removeAttribute(attr.name));
                });
                els.ingredients.append(clone);
            });
            syncIngredients(overlay, labels.recipeId); renderedIngredients = 'legacy'; return;
        }
        const signature = JSON.stringify([record.revision_id, record.servings, record.ingredients]);
        if (renderedIngredients !== signature) {
            els.ingredients.replaceChildren();
            if (record.manual_quantity) els.ingredients.append(element('p', 'cooking-note', labels.manualComponent));
            let lastGroup = null;
            const list = element('ul', 'ingredient-list');
            for (const item of record.ingredients ?? []) {
                if (item.group && item.group !== lastGroup) { const heading = element('li', 'cooking-ingredient-group', item.group); list.append(heading); lastGroup = item.group; }
                const row = element('li'); const input = element('input'); input.type = 'checkbox'; input.dataset.cookingIngredient = item.key; input.id = `cook-${labels.recipeId}-${item.key}`;
                input.addEventListener('change', () => commit({ action: 'ingredient', ingredient: item.key, checked: input.checked }));
                const label = element('label', '', `${(item.quantity ?? item.amount) ? (item.quantity ?? item.amount) + ' ' : ''}${item.name}${item.note ? ', ' + item.note : ''}${item.optional ? ` (${labels.optional})` : ''}`); label.htmlFor = input.id; row.append(input, label); list.append(row);
            }
            els.ingredients.append(list);
            const reset = button(labels.resetIngredients, { action: 'ingredients_reset' }); els.ingredients.append(reset);
            renderedIngredients = signature;
        }
        els.ingredients.querySelectorAll('[data-cooking-ingredient]').forEach((input) => { input.checked = record.state.checked_ingredients[input.dataset.cookingIngredient] === true; });
        if (document.activeElement !== els.servings) els.servings.value = record.servings;
        syncOuterIngredients();
    };
    const renderGroups = () => {
        const groups = cookingGroups(record.content, record.state, locale, now());
        const grouped = (record.content.step_groups?.length ?? 0) > 0;
        els.groups.hidden = !grouped; overlay.dataset.hasGroups = String(grouped);
        const map = cookingStepMap(record.content);
        const active = groups.find((row) => row.key === map[record.state.active_step]?.group);
        text(els['group-current'], active?.title || labels.ungrouped);
        for (const group of groups) {
            let node = [...els['group-list'].children].find((item) => item.dataset.group === group.key);
            if (!node) {
                node = button('', () => ({ action: 'visit', step: record.state.last_steps[group.key] ?? group.steps[0] }));
                node.dataset.group = group.key; els['group-list'].append(node);
            }
            text(node, `${group.title || labels.ungrouped} · ${group.completed}/${group.steps.length} · ${template(labels.groupWaiting, { count: group.waiting })} · ${template(labels.groupTimers, { count: group.timers })}`);
            node.setAttribute('aria-current', String(active?.key === group.key));
        }
        return active;
    };
    const renderStep = (activeGroup) => {
        const key = record.state.active_step;
        const index = Number(key.slice(1));
        const step = record.content.steps[index];
        const signature = `${record.revision_id}:${record.content_hash}:${key}`;
        if (renderedStep !== signature) {
            stepsRoot.querySelector('[data-cooking-current-step]')?.remove();
            const section = element('section', 'cooking-step'); section.dataset.cookingCurrentStep = ''; section.dataset.cookingStep = ''; section.dataset.stepKey = key;
            if (activeGroup?.title) section.append(element('p', 'cooking-group-title', activeGroup.title));
            if (activeGroup?.description) section.append(element('p', '', activeGroup.description));
            const heading = element('h3', '', template(labels.stepLabel, { number: step.step_number ?? index + 1 })); heading.id = 'cooking-current-step'; section.setAttribute('aria-labelledby', heading.id);
            section.append(heading, element('p', 'cooking-step-text', step.translations?.[locale]?.instruction || step.translations?.en?.instruction || labels.missingTranslation));
            const state = element('p', 'cooking-step-status'); state.dataset.currentStatus = ''; section.append(state);
            const actions = element('div', 'cooking-step-actions'); actions.dataset.cookingInteractive = '';
            if (step.duration_minutes) { const timer = button(template(labels.labelTimerStart, { minutes: step.duration_minutes }), { action: 'timer_start', step: key }); timer.dataset.startTimer = ''; actions.append(timer); }
            const done = button('', () => ({ action: 'step_status', step: key, status: record.state.steps[key].status === 'completed' ? 'in_progress' : 'completed' })); done.dataset.stepDone = '';
            const wait = button('', () => ({ action: 'step_status', step: key, status: record.state.steps[key].status === 'waiting' ? 'in_progress' : 'waiting' })); wait.dataset.stepWaiting = '';
            actions.append(done, wait); section.append(actions); renderMedia(section, step); stepsRoot.prepend(section); renderedStep = signature;
        }
        const section = stepsRoot.querySelector('[data-cooking-current-step]');
        const stepState = record.state.steps[key].status;
        text(section.querySelector('[data-current-status]'), labels[`state_${stepState}`]);
        const done = section.querySelector('[data-step-done]'); text(done, stepState === 'completed' ? labels.reopen : labels.labelMarkDone); done.setAttribute('aria-pressed', String(stepState === 'completed'));
        text(section.querySelector('[data-step-waiting]'), stepState === 'waiting' ? labels.continueStep : labels.wait);
        const startTimer = section.querySelector('[data-start-timer]'); if (startTimer) startTimer.hidden = !!record.state.timers[key];
        const order = orderedCookingSteps(record.content); const position = order.indexOf(key);
        els.previous.dataset.unavailable = String(position <= 0); els.next.dataset.unavailable = String(position >= order.length - 1);
        const complete = Object.values(record.state.steps).filter((item) => item.status === 'completed').length;
        text(els.progress, overlay.dataset.hasGroups === 'true'
            ? template(labels.groupProgress, { current: activeGroup.steps.indexOf(key) + 1, total: activeGroup.steps.length })
            : template(labels.labelProgress, { current: position + 1, total: order.length }));
        text(els['done-count'], template(labels.labelDoneCount, { count: complete }));
        els.bar.setAttribute('aria-valuemin', '0'); els.bar.setAttribute('aria-valuemax', order.length); els.bar.setAttribute('aria-valuenow', complete); els.bar.firstElementChild.style.width = `${complete / order.length * 100}%`;
        overlay.dataset.textSize = record.state.size; els.smaller.dataset.unavailable = String(record.state.size === 0); els.larger.dataset.unavailable = String(record.state.size === 4);
        els.again.hidden = !enhanced || !!record.context_path || record.status !== 'completed';
    };
    const renderTimers = () => {
        const root = client?.record ?? record;
        const sources = [{ path: '', data: root, state: root.state }, ...Object.entries(root.state.contexts ?? {}).map(([path, state]) => ({ path, data: root.contexts?.[path], state })).filter(item => item.data)];
        const timers = sources.flatMap(source => Object.entries(source.state.timers ?? {}).map(([key, timer]) => ({ ...source, key, timer, tag: `${source.path}:${key}` })));
        els.timers.hidden = timers.length === 0;
        [...els.timers.children].forEach(node => { if (!timers.some(timer => timer.tag === node.dataset.timerKey)) node.remove(); });
        for (const { path, data, key, timer, tag } of timers) {
            let row = [...els.timers.children].find(node => node.dataset.timerKey === tag);
            if (!row) {
                row = element('div', 'cooking-timer'); row.dataset.timerKey = tag; row.dataset.cookingInteractive = '';
                const title = element('span'); title.dataset.timerTitle = ''; const timerClock = element('strong'); timerClock.dataset.timerClock = ''; const actions = element('div', 'cooking-timer-actions');
                const go = element('button', 'button', labels.goToStep); go.type = 'button';
                go.addEventListener('click', async () => {
                    if (client) await commit({ action: 'context_open', context: path });
                    await commit({ action: 'visit', step: key, context: path });
                    els.heading.focus();
                });
                const pause = button('', () => ({ action: (path ? client.record.state.contexts[path] : (client?.record ?? record).state).timers[key].status === 'paused' ? 'timer_resume' : 'timer_pause', step: key, context: path })); pause.dataset.pauseTimer = '';
                actions.append(go, pause, button(labels.restart, { action: 'timer_restart', step: key, context: path }), button(labels.labelTimerStop, { action: 'timer_cancel', step: key, context: path })); row.append(title, timerClock, actions); els.timers.append(row);
            }
            const left = cookingTimerRemaining(timer, now()); const number = data.content.steps[Number(key.slice(1))]?.step_number ?? Number(key.slice(1)) + 1;
            const title = path ? `${data.content.translations?.[locale]?.title || data.content.translations?.en?.title} · ` : '';
            text(row.querySelector('[data-timer-title]'), title + template(labels.labelTimerRunning, { step: number }));
            text(row.querySelector('[data-timer-clock]'), `${formatClock(left ?? 0)}${left === 0 ? ` · ${labels.finished}` : timer.status === 'paused' ? ` · ${labels.paused}` : ''}`);
            row.classList.toggle('is-finished', left === 0);
            const pause = row.querySelector('[data-pause-timer]'); text(pause, timer.status === 'paused' ? labels.resume : labels.pause); pause.dataset.unavailable = String(left === 0);
            const token = `${tag}:${timer.started_at_ms}`;
            if (left === 0 && !finished.has(token)) {
                finished.add(token); text(els.alert, title + template(labels.labelTimerFinished, { step: number }));
                if (sound) {
                    try { const context = new (window.AudioContext || window.webkitAudioContext)(); const oscillator = context.createOscillator(); oscillator.connect(context.destination); oscillator.frequency.value = 880; oscillator.start(); oscillator.stop(context.currentTime + 0.4); oscillator.onended = () => context.close(); } catch { /* Visible alert remains available. */ }
                }
            }
        }
    };
    function render() {
        if (!record || blocked) return;
        const contentSignature = `${record.context_path ?? ""}:${record.revision_id}:${record.content_hash}`;
        if (renderedContent !== contentSignature) {
            stepsRoot.replaceChildren(); els['group-list'].replaceChildren(); renderedStep = ''; renderedIngredients = '';
            overlay.dataset.stateVersion = record.revision_id; overlay.dataset.cookingHistorical = String(!currentCompatible());
            text(overlay.querySelector('.cooking-recipe'), record.content.translations?.[locale]?.title || record.content.translations?.en?.title);
            renderComponents(); renderedContent = contentSignature;
        }
        text(els.heading, record.context_path ? `${modeHeading} · ${record.content.translations?.[locale]?.title || record.content.translations?.en?.title || ''}` : modeHeading);
        els['context-back'].hidden = !record.context_path;
        els.servings.closest('[data-cooking-servings-control]').hidden = !enhanced || !!record.context_path;
        const group = renderGroups(); renderStep(group); renderIngredients(); renderTimers(); lockControls();
        if (ticker === null) ticker = window.setInterval(() => { if (open && record && !blocked) { renderGroups(); renderTimers(); lockControls(); } }, 1000);
    }
    const releaseWake = async () => { try { await wakeLock?.release(); } catch { /* Browser may already have released it. */ } wakeLock = null; };
    const takeWake = async () => {
        try { const requested = await navigator.wakeLock.request('screen'); if (!open || !wantsWake) return requested.release(); wakeLock = requested; }
        catch { wantsWake = false; els.wake.setAttribute('aria-pressed', 'false'); text(overlay.querySelector('[data-wake-status]'), labels.wakeFailed); }
    };
    const background = (value) => [...(overlay.parentElement?.children ?? []), document.querySelector('.site-header'), document.querySelector('.site-footer'), document.querySelector('.kitchen-bottom-nav')]
        .filter((node) => node && node !== overlay).forEach((node) => node.toggleAttribute('inert', value));
    const close = () => {
        open = false; overlay.hidden = true; background(false); document.documentElement.classList.remove('cooking-open');
        if (ticker !== null) clearInterval(ticker); ticker = null; wantsWake = false; els.wake.setAttribute('aria-pressed', 'false'); text(els.wake, labels.labelWakeOff); releaseWake(); start.focus();
    };
    const refresh = async () => {
        if (!open || !client || busy) return;
        busy++; lockControls();
        try { record = cookingContextRecord(await client.refresh()); blocked = false; status(''); render(); } catch (error) { fail(error); }
        finally { busy--; lockControls(); }
    };
    start.hidden = false;
    start.addEventListener('click', async () => {
        if (open) return;
        open = true; blocked = false; overlay.hidden = false; background(true); document.documentElement.classList.add('cooking-open');
        els.heading.focus(); busy++; lockControls(); status(labels.loading);
        try {
            if (client) {
                stepsRoot.replaceChildren(); renderedContent = ''; renderedStep = '';
                record = cookingContextRecord(await client.load({ servings: document.querySelector('[data-ingredients-panel]')?.dataset.servings ?? undefined, contentHash: overlay.dataset.pageHash }));
            } else {
                const old = restoreCookingState(storage.read(legacyKey, {}), legacyRecord.content.steps.length);
                const initial = initialCookingProgress(legacyRecord.content); initial.active_step = `s${old.step}`; initial.size = old.size;
                old.done.forEach((index) => { initial.steps[`s${index}`] = { status: 'completed', completed_at: null }; });
                Object.entries(old.timers).forEach(([index, deadline]) => { initial.timers[`s${index}`] = { duration_seconds: Math.max(1, Math.ceil((deadline - now()) / 1000)), started_at_ms: deadline - 60000, deadline_ms: deadline, remaining_ms: null, status: 'running' }; });
                record = { ...legacyRecord, state: restoreCookingProgress(storage.read(`${legacyKey}:v2`, initial), legacyRecord.content) };
                renderedIngredients = ''; record = applyCookingEvent(record, { action: 'visit', step: record.state.active_step }, now());
            }
            status(client && labels.sessionUser ? labels.saved : ''); if (open) render();
        } catch (error) { fail(error); }
        finally { busy--; lockControls(); }
    });
    els.close.addEventListener('click', close);
    els['context-back'].addEventListener('click', () => commit({ action: 'context_close' }).then(() => els.heading.focus()));
    const move = (delta) => { if (!record) return; const order = orderedCookingSteps(record.content); const next = order[order.indexOf(record.state.active_step) + delta]; if (next) commit({ action: 'visit', step: next }).then(() => els.heading.focus()); };
    els.previous.addEventListener('click', () => move(-1)); els.next.addEventListener('click', () => move(1));
    els.smaller.addEventListener('click', () => commit({ action: 'size', size: record.state.size - 1 })); els.larger.addEventListener('click', () => commit({ action: 'size', size: record.state.size + 1 }));
    els.servings.closest('[data-cooking-servings-control]').hidden = !enhanced;
    els.servings.addEventListener('change', () => commit({ action: 'servings', servings: els.servings.value }));
    els.again.addEventListener('click', async () => { if (!client || busy) return; busy++; lockControls(); try { record = cookingContextRecord(await client.again()); finished.clear(); render(); } catch (error) { fail(error); } finally { busy--; lockControls(); } });
    els.sound.addEventListener('click', () => { sound = !sound; els.sound.setAttribute('aria-pressed', String(sound)); text(els.sound, sound ? labels.soundOn : labels.soundOff); });
    if ('wakeLock' in navigator) {
        els.wake.hidden = false;
        els.wake.addEventListener('click', () => { wantsWake = !wantsWake; els.wake.setAttribute('aria-pressed', String(wantsWake)); text(els.wake, wantsWake ? labels.labelWakeOn : labels.labelWakeOff); if (wantsWake) takeWake(); else releaseWake(); });
    }
    document.addEventListener('keydown', (event) => {
        if (!open) return;
        if (event.key === 'Escape') { event.preventDefault(); close(); }
        else if ((event.key === 'ArrowLeft' || event.key === 'ArrowRight') && !event.target.closest('input, textarea, select') && !busy && !blocked) { event.preventDefault(); move(event.key === 'ArrowRight' ? 1 : -1); }
        else if (event.key === 'Tab') {
            const nodes = [...overlay.querySelectorAll('button:not([disabled]), input:not([disabled]), select, textarea, a[href], summary')].filter((node) => node.offsetParent !== null);
            if (event.shiftKey && document.activeElement === nodes[0]) { event.preventDefault(); nodes.at(-1)?.focus(); }
            else if (!event.shiftKey && document.activeElement === nodes.at(-1)) { event.preventDefault(); nodes[0]?.focus(); }
        }
    });
    document.addEventListener('visibilitychange', () => { if (document.visibilityState === 'visible' && open) { refresh(); if (wantsWake) takeWake(); } });
    window.addEventListener('pageshow', () => { if (open) refresh(); });
    window.addEventListener('pagehide', () => { if (ticker !== null) clearInterval(ticker); ticker = null; releaseWake(); });
    document.addEventListener('livewire:navigating', () => { if (open) close(); if (ticker !== null) clearInterval(ticker); ticker = null; });
    document.addEventListener('click', (event) => {
        if (overlay.isConnected && enhanced && record && currentCompatible() && event.target.closest?.('[data-ingredients-panel] [data-ingredient-reset]')) commit({ action: 'ingredients_reset' });
    });
    document.addEventListener('change', (event) => {
        if (!overlay.isConnected || !enhanced || !record || !currentCompatible() || !event.target.matches?.('[data-ingredients-panel] [data-recipe-ingredient-id]')) return;
        const index = record.content.ingredients.findIndex((row) => String(row.id) === event.target.dataset.recipeIngredientId);
        if (index >= 0) commit({ action: 'ingredient', ingredient: `i${index}`, checked: event.target.checked });
    });
};
document.addEventListener('livewire:navigated', initCookingMode);

document.addEventListener('DOMContentLoaded', () => {
    initIngredients();
    initJump();
    initPrint();
    initCookingMode();
});

/* Native disclosures retain keyboard and no-JavaScript operation. */
const navigationDisclosures = [...document.querySelectorAll('[data-navigation-disclosure]')];
const closeNavigation = (except = null) => {
    navigationDisclosures.forEach((disclosure) => {
        if (disclosure !== except) disclosure.open = false;
    });
};
navigationDisclosures.forEach((disclosure) => {
    disclosure.addEventListener('toggle', () => {
        if (disclosure.open) closeNavigation(disclosure);
    });
    disclosure.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && disclosure.open) {
            event.preventDefault();
            disclosure.open = false;
            disclosure.querySelector('summary').focus();
        }
    });
    disclosure.addEventListener('focusout', () => {
        window.requestAnimationFrame(() => {
            if (!disclosure.contains(document.activeElement)) disclosure.open = false;
        });
    });
});
document.addEventListener('click', (event) => {
    navigationDisclosures.forEach((disclosure) => {
        if (!disclosure.contains(event.target) || event.target.closest('a')) disclosure.open = false;
    });
});
window.matchMedia('(min-width: 1280px)').addEventListener('change', () => closeNavigation());
window.addEventListener('pagehide', () => closeNavigation());
document.querySelectorAll('[data-language-link]').forEach((link) => {
    link.addEventListener('click', () => {
        if (window.location.hash) {
            const target = new URL(link.href, window.location.origin);
            target.hash = window.location.hash;
            link.href = target.href;
        }
    });
});


const initializedNestedComponents = new WeakSet();
const pendingPlannerModes = new Map();
const sentPlannerModes = new Map();
const nestedComponentKey = (component) => {
    const parent = component.closest('[data-cooking-overlay], [data-ingredients-panel]');
    return componentStateKey(parent?.dataset.recipeId ?? '', parent?.dataset.stateVersion ?? '', component.dataset.componentPath, component.dataset.componentRevision);
};
const flushPlannerModes = () => {
    const planning = document.querySelector('#recipe-planning');
    const id = planning?.getAttribute('wire:id');
    if (!id || !window.Livewire?.find(id)) return;
    for (const [key, choice] of pendingPlannerModes) {
        const signature = `${id}:${key}`;
        if (sentPlannerModes.get(signature) !== choice.mode) {
            window.Livewire.dispatchTo('recipe-planning', 'recipe-component-mode-changed', choice);
            sentPlannerModes.set(signature, choice.mode);
        }
        pendingPlannerModes.delete(key);
    }
};
const notifyPlannerMode = (component, mode) => {
    const parent = component.closest('[data-cooking-overlay], [data-ingredients-panel]');
    if (!parent) return;
    pendingPlannerModes.set(nestedComponentKey(component), {
        recipeId: Number(parent.dataset.recipeId), path: component.dataset.componentPath, mode,
    });
    flushPlannerModes();
};
const applyComponentMode = (key, prepared) => {
    document.querySelectorAll('[data-component-path]').forEach((component) => {
        if (nestedComponentKey(component) !== key) return;
        const checkbox = component.querySelector('[data-component-prepared]');
        if (checkbox) checkbox.checked = prepared;
        component.querySelector('[data-component-scratch]').hidden = prepared;
    });
};
const initNestedCooking = () => {
    document.querySelectorAll('[data-component-path]').forEach((component) => {
        if (initializedNestedComponents.has(component)) return;
        initializedNestedComponents.add(component);
        const key = nestedComponentKey(component);
        const rows = [...component.querySelectorAll('[data-nested-step]')];
        const state = restoreCookingState(storage.read(key, {}), rows.length);
        const save = () => storage.write(key, { done: [...state.done], timers: state.timers });
        rows.forEach((row, index) => {
            const done = row.querySelector('[data-nested-done]');
            if (done) {
                done.checked = state.done.has(index);
                done.addEventListener('change', () => {
                    if (done.checked) state.done.add(index); else state.done.delete(index);
                    save();
                });
            }
            const timer = row.querySelector('[data-nested-timer]');
            const clock = row.querySelector('[data-nested-clock]');
            const tick = () => {
                if (clock) clock.textContent = state.timers[index] ? formatClock(timerRemaining(state.timers[index])) : '';
            };
            timer?.addEventListener('click', () => {
                if (state.timers[index]) delete state.timers[index];
                else state.timers[index] = Date.now() + Number(row.dataset.minutes) * 60000;
                save(); tick();
            });
            if (timer) {
                tick();
                const interval = window.setInterval(() => {
                    if (!component.isConnected) window.clearInterval(interval); else tick();
                }, 1000);
            }
        });
        const prepared = component.querySelector('[data-component-prepared]');
        if (prepared) {
            const savedChoice = storage.read(`${key}:prepared`, null);
            prepared.checked = savedChoice === true;
            component.querySelector('[data-component-scratch]').hidden = prepared.checked;
            if (typeof savedChoice === 'boolean') notifyPlannerMode(component, savedChoice ? 'prepared' : 'scratch');
            prepared.addEventListener('change', () => {
                storage.write(`${key}:prepared`, prepared.checked);
                applyComponentMode(key, prepared.checked);
                notifyPlannerMode(component, prepared.checked ? 'prepared' : 'scratch');
            });
        }
    });
};
initNestedCooking();
document.addEventListener('livewire:navigated', () => { initNestedCooking(); flushPlannerModes(); });
document.addEventListener('livewire:updated', initNestedCooking);

let nestedLivewireHooksInstalled = false;
const installNestedLivewireHooks = () => {
    if (!window.Livewire || nestedLivewireHooksInstalled) return;
    nestedLivewireHooksInstalled = true;
    window.Livewire.hook('morph.updated', () => queueMicrotask(() => { initNestedCooking(); flushPlannerModes(); }));
};
document.addEventListener('livewire:init', installNestedLivewireHooks);
document.addEventListener('livewire:initialized', () => { installNestedLivewireHooks(); flushPlannerModes(); });
installNestedLivewireHooks();
