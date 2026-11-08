import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';
import { componentStateKey, restoreCookingState } from '../../resources/js/cooking-state.js';

// Exercise the actual public-page initializer without a browser or Livewire server.
const appSource = readFileSync(new URL('../../resources/js/app.js', import.meta.url), 'utf8');
const initializer = appSource.slice(appSource.indexOf('const initializedNestedComponents ='));
const harness = ({ saved = null, planningReady = true } = {}) => {
    const values = new Map();
    const key = componentStateKey('7', '8', 'z-root/child', '9');
    if (saved !== null) values.set(`${key}:prepared`, saved);
    const events = new Map();
    const dispatched = [];
    const makeComponent = () => {
        const handlers = new Map();
        const checkbox = { checked: false, addEventListener: (event, fn) => handlers.set(event, fn) };
        const scratch = { hidden: false };
        return {
            dataset: { componentPath: 'z-root/child', componentRevision: '9' },
            closest: () => ({ dataset: { recipeId: '7', stateVersion: '8' } }),
            querySelectorAll: () => [],
            querySelector: (selector) => selector === '[data-component-prepared]' ? checkbox : scratch,
            checkbox, scratch, change: () => handlers.get('change')(),
        };
    };
    const components = [makeComponent(), makeComponent()];
    const planner = { getAttribute: () => 'planner-1' };
    const document = {
        querySelectorAll: () => components,
        querySelector: () => planningReady ? planner : null,
        addEventListener: (event, fn) => events.set(event, fn),
    };
    runInNewContext(initializer, {
        componentStateKey, restoreCookingState, document, queueMicrotask: (fn) => fn(),
        storage: { read: (key, fallback) => values.has(key) ? values.get(key) : fallback, write: (key, value) => values.set(key, value) },
        window: { Livewire: { find: () => ({}), hook: () => {}, dispatchTo: (...args) => dispatched.push(args) } },
    });
    return { components, values, key, dispatched, ready: () => { planningReady = true; events.get('livewire:initialized')(); } };
};

test('Ready-Made choice reaches RecipePlanning and stays synchronized with Cooking Mode', () => {
    const { components, values, key, dispatched } = harness();
    assert.equal(dispatched.length, 0); // No explicit choice: preserve server defaults.
    components[0].checkbox.checked = true;
    components[0].change();
    assert.equal(values.get(`${key}:prepared`), true);
    assert.equal(components[1].checkbox.checked, true);
    assert.equal(components[1].scratch.hidden, true);
    assert.deepEqual(JSON.parse(JSON.stringify(dispatched[0])), ['recipe-planning', 'recipe-component-mode-changed', {
        recipeId: 7, path: 'z-root/child', mode: 'prepared',
    }]);
    components[1].checkbox.checked = false;
    components[1].change();
    assert.equal(components[0].checkbox.checked, false);
    assert.equal(components[0].scratch.hidden, false);
    assert.equal(dispatched[1][2].mode, 'scratch');
});

test('restored choices are sent once after the planner is initialized', () => {
    const state = harness({ saved: true, planningReady: false });
    assert.equal(state.dispatched.length, 0);
    assert.equal(state.components[0].checkbox.checked, true);
    state.ready();
    assert.equal(state.dispatched.length, 1);
    assert.equal(state.dispatched[0][2].mode, 'prepared');
    state.ready();
    assert.equal(state.dispatched.length, 1);
});
