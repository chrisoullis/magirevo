import test from 'node:test';
import assert from 'node:assert/strict';
import { applyCookingEvent, cookingClock, cookingGroups, cookingTimerRemaining, initialCookingProgress, orderedCookingSteps, restoreCookingProgress } from '../../resources/js/cooking-state.js';
import { cookingSessionClient } from '../../resources/js/cooking-session-client.js';

const content = { recipe: { base_servings: 2 }, ingredients: [{ id: 4 }], step_groups: [
    { translations: { en: { title: 'Dough' }, el: { title: 'Ζύμη' } } }, { translations: { en: { title: 'Filling' } } },
], steps: [{ step_number: 1, step_group_index: 0, duration_minutes: 1 }, { step_number: 2, step_group_index: 1, duration_minutes: 2 }, { step_number: 3, step_group_index: 0 }] };
const record = () => ({ id: 'opaque-session-id', revision_id: 7, content_hash: 'hash', version: 1, servings: '2', content, state: initialCookingProgress(content) });
const memory = () => { const data = new Map(); return { data, read: (key, fallback) => data.get(key) ?? fallback, write: (key, value) => data.set(key, structuredClone(value)) }; };
const urls = { content: '/content', latest: '/latest', start: '/start', session: '/session/__SESSION__' };
const response = (data, status = 200) => ({ ok: status < 400, status, json: async () => ({ data }) });

test('groups switch freely and remember separate last steps, waiting and completion', () => {
    let current = record();
    const send = (event) => current = applyCookingEvent(current, event, 1000);
    assert.deepEqual(orderedCookingSteps(content), ['s0', 's2', 's1']);
    send({ action: 'visit', step: 's2' }); send({ action: 'visit', step: 's1' });
    assert.deepEqual(current.state.last_steps, { g0: 's2', g1: 's1' });
    send({ action: 'step_status', step: 's0', status: 'waiting' });
    send({ action: 'step_status', step: 's1', status: 'completed' });
    const groups = cookingGroups(content, current.state, 'el', 1000);
    assert.equal(groups[0].title, 'Ζύμη'); assert.equal(groups[0].waiting, 1);
    assert.equal(groups[0].completed, 0); assert.equal(groups[1].completed, 1);
    send({ action: 'step_status', step: 's1', status: 'in_progress' });
    assert.equal(current.state.steps.s1.completed_at, null); assert.equal(current.status, 'active');
});

test('simultaneous timers pause, resume, restart, recover and expire without completing steps', () => {
    let current = record();
    const send = (event, now) => current = applyCookingEvent(current, event, now);
    send({ action: 'timer_start', step: 's0' }, 1000); send({ action: 'timer_start', step: 's1' }, 2000);
    send({ action: 'timer_start', step: 's0' }, 3000); assert.equal(current.state.timers.s0.deadline_ms, 61000);
    send({ action: 'timer_pause', step: 's0' }, 11000);
    assert.equal(cookingTimerRemaining(current.state.timers.s0, 999999), 50);
    send({ action: 'timer_resume', step: 's0' }, 21000);
    const restored = restoreCookingProgress(JSON.parse(JSON.stringify(current.state)), content);
    assert.equal(cookingTimerRemaining(restored.timers.s0, 71000), 0);
    assert.equal(cookingTimerRemaining(restored.timers.s1, 71000), 51);
    assert.equal(restored.steps.s0.status, 'waiting');
    send({ action: 'timer_restart', step: 's0' }, 100000); assert.equal(current.state.timers.s0.deadline_ms, 160000);
    send({ action: 'timer_cancel', step: 's0' }, 100001); assert.equal(current.state.steps.s0.status, 'in_progress');
    assert.equal(current.state.timers.s0, undefined); assert.ok(current.state.timers.s1);
});

test('restoration rejects unknown snapshot references and malformed timers', () => {
    const saved = { active_step: 's99', steps: { s0: { status: 'invalid' } }, last_steps: { g0: 's1' }, timers: { s0: { duration_seconds: -1 }, s99: { duration_seconds: 20, status: 'running', deadline_ms: 1000 } }, checked_ingredients: { i0: true, i9: true } };
    const state = restoreCookingProgress(saved, content);
    assert.equal(state.active_step, 's0'); assert.deepEqual(state.timers, {}); assert.deepEqual(state.checked_ingredients, { i0: true });
    assert.equal(state.last_steps.g0, 's0');
    assert.throws(() => applyCookingEvent(record(), { action: 'size', size: NaN }));
});

test('clock uses server time and monotonic elapsed time after device clock changes', () => {
    let mono = 10; let wall = 1000;
    const clock = cookingClock(() => mono, () => wall);
    mono = 30; clock.sync(5000, 10); assert.equal(clock.now(), 5010);
    wall += 9999999; mono += 1000; assert.equal(clock.now(), 6010);
});

test('authenticated events serialize versions and persist only the opaque session ID', async () => {
    const storage = memory(); const requests = []; let authoritative = record();
    const client = cookingSessionClient({ urls, user: '8', recipeId: 3, storage, csrf: 'token', fetcher: async (url, options) => {
        requests.push({ url, options });
        if (options.method === 'PATCH') { const body = JSON.parse(options.body); assert.equal(body.version, authoritative.version); authoritative = { ...applyCookingEvent(authoritative, body.event, 1000), version: authoritative.version + 1 }; }
        return response(authoritative);
    } });
    await client.load();
    await Promise.all([client.send({ action: 'visit', step: 's1' }), client.send({ action: 'ingredient', ingredient: 'i0', checked: true })]);
    assert.equal(client.record.version, 3); assert.deepEqual(storage.read('cooking-session:8:3'), { id: 'opaque-session-id' });
    assert.equal(requests[1].options.headers['X-CSRF-TOKEN'], 'token');
    assert.equal(requests.length, 3);
    for (let time = 1000; time < 100000; time += 1000) cookingTimerRemaining(client.record.state.timers.s0, time);
    assert.equal(requests.length, 3);
});

test('conflicts refresh authoritative progress and discard queued stale events without replay', async () => {
    const storage = memory(); let patches = 0; let gets = 0;
    const client = cookingSessionClient({ urls, user: '8', recipeId: 3, storage, fetcher: async (url, options) => {
        if (options.method === 'PATCH') { patches++; return response(null, 409); }
        gets++; return response({ ...record(), version: gets === 1 ? 1 : 9 });
    } });
    await client.load();
    const results = await Promise.allSettled([client.send({ action: 'visit', step: 's1' }), client.send({ action: 'visit', step: 's2' })]);
    assert.deepEqual(results.map((row) => row.status), ['rejected', 'rejected']); assert.equal(patches, 1); assert.equal(gets, 2); assert.equal(client.record.version, 9);
});

test('guests store progress without content, revalidate pinned access and never POST', async () => {
    const storage = memory(); const requests = []; let revoked = false;
    const options = { urls, recipeId: 3, storage, fetcher: async (url, options) => { requests.push({ url, options }); return response(record(), revoked ? 404 : 200); } };
    const first = cookingSessionClient(options); await first.load(); await first.send({ action: 'timer_start', step: 's0' });
    const saved = storage.read('cooking-guest:3'); assert.equal(saved.content, undefined); assert.equal(saved.media, undefined);
    const second = cookingSessionClient(options); await second.load(); assert.ok(second.record.state.timers.s0); assert.match(requests[1].url, /revision=7/);
    revoked = true; await assert.rejects(second.refresh(), { status: 404 });
    assert.ok(requests.every((row) => !row.options.method));
});

test('new authenticated starts reuse a request key after a lost response', async () => {
    const storage = memory(); const starts = []; let lost = true;
    const client = cookingSessionClient({ urls, user: '8', recipeId: 3, storage, fetcher: async (url, options) => {
        if (url === '/latest') return response(null);
        starts.push(JSON.parse(options.body));
        if (lost) { lost = false; throw new Error('Lost response'); }
        return response(record());
    } });
    await assert.rejects(client.load({ servings: '4', contentHash: 'hash' }));
    await client.load({ servings: '4', contentHash: 'hash' });
    assert.equal(starts.length, 2); assert.equal(starts[0].request_key, starts[1].request_key);
    assert.equal(starts[1].content_hash, 'hash'); assert.equal(starts[1].servings, '4');
});

test('guest servings use pinned server scaling and checklist reset preserves timers', async () => {
    const storage = memory(); let path;
    const client = cookingSessionClient({ urls, recipeId: 3, storage, fetcher: async (url) => { path = url; return response({ ...record(), servings: new URL(url, 'https://test').searchParams.get('servings') ?? '2', ingredients: [{ key: 'i0', quantity: 'scaled' }] }); } });
    await client.load(); await client.send({ action: 'timer_start', step: 's0' });
    await client.send({ action: 'ingredient', ingredient: 'i0', checked: true });
    await client.send({ action: 'servings', servings: '4.5' });
    assert.match(path, /revision=7/); assert.match(path, /servings=4.5/); assert.equal(client.record.ingredients[0].quantity, 'scaled');
    await client.send({ action: 'ingredients_reset' }); assert.deepEqual(client.record.state.checked_ingredients, {}); assert.ok(client.record.state.timers.s0);
});

test('nested contexts use the shared progress engine without changing parent timers or completion', () => {
    let root = { ...record(), contexts: { sauce: { ...record(), revision_id: 9 } } };
    root = applyCookingEvent(root, { action: 'timer_start', step: 's0' }, 1000);
    const parent = structuredClone(root.state);
    root = applyCookingEvent(root, { action: 'context_open', context: 'sauce' }, 2000);
    root = applyCookingEvent(root, { action: 'visit', context: 'sauce', step: 's1' }, 3000);
    root = applyCookingEvent(root, { action: 'timer_start', context: 'sauce', step: 's1' }, 4000);
    root = applyCookingEvent(root, { action: 'ingredient', context: 'sauce', ingredient: 'i0', checked: true }, 5000);
    assert.equal(root.state.active_step, parent.active_step);
    assert.deepEqual(root.state.timers, parent.timers);
    assert.deepEqual(root.state.steps, parent.steps);
    assert.equal(root.state.contexts.sauce.timers.s1.deadline_ms, 124000);
    const restored = restoreCookingProgress(JSON.parse(JSON.stringify(root.state)), root.content, root.contexts);
    assert.equal(restored.active_context, 'sauce');
    assert.equal(restored.contexts.sauce.checked_ingredients.i0, true);
    root = applyCookingEvent({ ...root, state: restored }, { action: 'context_close' }, 6000);
    assert.equal(root.state.active_context, null);
    assert.equal(root.state.contexts.sauce.active_step, 's1');
    assert.throws(() => applyCookingEvent(root, { action: 'context_open', context: 'foreign' }));
});
