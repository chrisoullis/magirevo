import test from 'node:test';
import assert from 'node:assert/strict';
import { componentStateKey, restoreCookingState, remainingSeconds } from '../../resources/js/cooking-state.js';

test('occurrences and revisions have independent progress keys', () => {
    const key = componentStateKey(1, 2, 'a/b', 3);
    assert.notEqual(key, componentStateKey(1, 2, 'a/c', 3));
    assert.notEqual(key, componentStateKey(1, 2, 'a/b', 4));
    assert.notEqual(key, componentStateKey(1, 5, 'a/b', 3));
});
test('opening a component leaves parent progress and absolute timers intact', () => {
    const parent = restoreCookingState({ step: 2, done: [0], timers: { 1: 20000 } }, 4);
    const child = restoreCookingState({}, 2);
    child.done.add(1);
    child.timers[0] = 30000;
    assert.equal(parent.step, 2);
    assert.deepEqual([...parent.done], [0]);
    assert.equal(remainingSeconds(parent.timers[1], 15000), 5);
});
