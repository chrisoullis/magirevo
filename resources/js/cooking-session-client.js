import { cookingProgressCompleted, applyCookingEvent, cookingClock, initialCookingProgress, restoreCookingProgress } from './cooking-state.js';

export class CookingRequestError extends Error {
    constructor(status, message) { super(message); this.status = status; }
}

/** One transport adapter for the existing overlay; private session content is never stored in the browser. */
export const cookingSessionClient = ({ urls, user = '', recipeId, csrf = '', storage, clock = cookingClock(), fetcher = (...args) => fetch(...args), notify = () => {} }) => {
    let record = null;
    let queue = Promise.resolve();
    let generation = 0;
    const key = user ? `cooking-session:${user}:${recipeId}` : `cooking-guest:${recipeId}`;
    const request = async (url, options = {}) => {
        const started = performance.now();
        const response = await fetcher(url, { credentials: 'same-origin', cache: 'no-store', ...options,
            headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, ...options.headers } });
        const body = await response.json().catch(() => ({}));
        if (!response.ok) throw new CookingRequestError(response.status, body.message ?? '');
        if (body.data?.server_time_ms) clock.sync(body.data.server_time_ms, started);
        return body.data;
    };
    const guestContent = (revision, servings) => {
        const url = new URL(urls.content, globalThis.location?.origin ?? 'http://localhost');
        if (revision) url.searchParams.set('revision', revision);
        if (servings) url.searchParams.set('servings', servings);
        return request(url.pathname + url.search);
    };
    const persist = () => storage.write(key, user ? { id: record.id } : {
        revision_id: record.revision_id, content_hash: record.content_hash, state: record.state, servings: record.servings,
    });
    const take = (data) => {
        record = { ...data, state: restoreCookingProgress(data.state, data.content, data.contexts ?? {}) };
        record.status = cookingProgressCompleted(record.state) ? 'completed' : 'active';
        persist(); notify(record); return record;
    };
    const sessionUrl = (id) => urls.session.replace('__SESSION__', encodeURIComponent(id));
    const load = async ({ servings, contentHash } = {}) => {
        const held = storage.read(key, {});
        if (user) {
            if (held.id) return take(await request(sessionUrl(held.id)));
            const latest = await request(urls.latest);
            if (latest) return take(latest);
            const nonceKey = `${key}:nonce`;
            let nonce = storage.read(nonceKey, null);
            if (!nonce) { nonce = crypto.randomUUID(); storage.write(nonceKey, nonce); }
            return take(await request(urls.start, { method: 'POST', body: JSON.stringify({ request_key: nonce, servings, content_hash: contentHash || undefined }) }));
        }
        const data = await guestContent(held.revision_id, held.servings ?? servings);
        if (held.content_hash && held.content_hash !== data.content_hash) throw new CookingRequestError(409, '');
        return take({ ...data, state: held.state ?? data.state });
    };
    const refresh = async () => {
        await queue.catch(() => {});
        if (!record) return load();
        if (user) return take(await request(sessionUrl(record.id)));
        const data = await guestContent(record.revision_id, record.servings);
        if (data.content_hash !== record.content_hash) throw new CookingRequestError(409, '');
        return take({ ...data, state: record.state });
    };
    const send = (event) => {
        const expectedGeneration = generation;
        const pending = queue.then(async () => {
            if (generation !== expectedGeneration || !record) throw new CookingRequestError(409, '');
            if (user) {
                try {
                    return take(await request(sessionUrl(record.id), { method: 'PATCH', body: JSON.stringify({ version: record.version, event }) }));
                } catch (error) {
                    generation++;
                    if (error.status === 409) take(await request(sessionUrl(record.id)));
                    throw error;
                }
            }
            let next = applyCookingEvent(record, event, clock.now());
            if (event.action === 'servings') next = { ...await guestContent(record.revision_id, event.servings), state: next.state };
            return take(next);
        });
        queue = pending.catch(() => {});
        return pending;
    };
    const again = async () => {
        generation++; await queue.catch(() => {});
        if (user) {
            const data = await request(urls.start, { method: 'POST', body: JSON.stringify({ request_key: crypto.randomUUID(), servings: record.servings }) });
            return take(data);
        }
        const data = await guestContent(null, record.servings);
        return take({ ...data, state: initialCookingProgress(data.content) });
    };
    return { load, refresh, send, again, now: clock.now, get record() { return record; } };
};
