import test from 'node:test';
import assert from 'node:assert/strict';
import { restoreCookingState, remainingSeconds, formatClock } from '../../resources/js/cooking-state.js';

test('stale saved state cannot select nonexistent steps or timers', () => {
    const restored = restoreCookingState({ step: 99, done: [0, 1, 9, -1, '1'], size: 99, timers: { 0: 5000, 9: 9000, 1: 'bad', '-1': 6000 } }, 2);
    assert.equal(restored.step, 1);
    assert.deepEqual([...restored.done], [0, 1]);
    assert.deepEqual(restored.timers, { 0: 5000 });
    assert.equal(restored.size, 4);
});
test('malformed session data falls back to usable controls', () => {
    assert.deepEqual(restoreCookingState(null, 2), { step: 0, done: new Set(), size: 1, timers: {} });
    assert.equal(restoreCookingState({ step: -5, size: -3, done: {}, timers: [] }, 2).step, 0);
});
test('timers use elapsed wall time after a background tab resumes', () => {
    assert.equal(remainingSeconds(10000, 1000), 9);
    assert.equal(remainingSeconds(10000, 9999), 1);
    assert.equal(remainingSeconds(10000, 15000), 0);
    assert.equal(remainingSeconds(undefined, 1000), null);
});
test('timer clocks show hours without wrapping', () => {
    assert.equal(formatClock(0), '00:00');
    assert.equal(formatClock(65), '01:05');
    assert.equal(formatClock(3661), '1:01:01');
});
