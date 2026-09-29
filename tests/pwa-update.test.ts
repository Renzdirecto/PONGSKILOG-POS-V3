import assert from 'node:assert/strict';
import { test } from 'node:test';
import {
    ACTIVATION_FALLBACK_MS,
    createUpdateController,
    SKIP_WAITING_MESSAGE,
    UPDATE_SNOOZE_MS,
} from '../resources/js/lib/pwa-update.ts';

function harness() {
    const state = {
        reloads: 0,
        now: 10_000,
        timers: [] as { callback: () => void; delay: number }[],
        messages: [] as unknown[],
    };
    const update = createUpdateController({
        reload: () => {
            state.reloads += 1;
        },
        now: () => state.now,
        setTimeout: (callback, delay) => {
            state.timers.push({ callback, delay });

            return state.timers.length;
        },
    });
    const worker = { postMessage: (message: unknown) => state.messages.push(message) };

    return { state, update, worker };
}

test('a new service worker waits and is announced as an update, without reloading', () => {
    const { state, update, worker } = harness();

    update.markWaiting(worker);

    assert.equal(update.getSnapshot().available, true);
    assert.equal(update.getSnapshot().source, 'service-worker');
    assert.equal(update.isPromptDue(), true);
    assert.deepEqual(state.messages, []);
    assert.equal(state.reloads, 0);
});

test('Update now while a POS order is in progress is refused with the reason', () => {
    const { state, update, worker } = harness();
    update.markWaiting(worker);
    update.setBlocker('pos', 'Finish or clear the current order first.');

    assert.equal(update.apply(), 'blocked');
    assert.deepEqual(update.getSnapshot().blockers, [
        'Finish or clear the current order first.',
    ]);
    assert.deepEqual(state.messages, []);
    assert.equal(state.reloads, 0);
});

test('Update now when safe activates the waiting worker and reloads exactly once', () => {
    const { state, update, worker } = harness();
    update.markWaiting(worker);

    assert.equal(update.apply(), 'applying');
    assert.deepEqual(state.messages, [SKIP_WAITING_MESSAGE]);
    assert.equal(update.getSnapshot().applying, true);

    update.handleControllerChange();
    update.handleControllerChange();
    state.timers.forEach((timer) => timer.callback());

    assert.equal(state.reloads, 1);
    assert.equal(state.timers[0].delay, ACTIVATION_FALLBACK_MS);
});

test('another window activating the update never reloads this one; it asks to reload when safe', () => {
    const { state, update } = harness();
    update.setBlocker('pos', 'Finish or clear the current order first.');

    update.handleControllerChange();

    assert.equal(state.reloads, 0);
    assert.equal(update.getSnapshot().source, 'activated-elsewhere');
    assert.equal(update.apply(), 'blocked');

    update.setBlocker('pos', null);
    assert.equal(update.apply(), 'reloading');
    assert.equal(state.reloads, 1);
});

test('work that starts while the update is activating stops the reload', () => {
    const { state, update, worker } = harness();
    update.markWaiting(worker);
    update.apply();
    update.setBlocker('saving', 'A change is still being saved.');

    update.handleControllerChange();

    assert.equal(state.reloads, 0);
    assert.equal(update.getSnapshot().source, 'activated-elsewhere');
    assert.equal(update.getSnapshot().applying, false);
});

test('a server asset-version change is an update too, and applying it reloads once', () => {
    const { state, update } = harness();

    update.markServerVersionChanged();
    assert.equal(update.getSnapshot().source, 'server');
    assert.equal(update.apply(), 'reloading');
    assert.equal(update.apply(), 'reloading');
    assert.equal(state.reloads, 1);
});

test('Later hides the prompt for an hour without dropping the update', () => {
    const { state, update, worker } = harness();
    update.markWaiting(worker);

    update.snooze();
    assert.equal(update.isPromptDue(), false);
    assert.equal(update.getSnapshot().available, true);

    state.now += UPDATE_SNOOZE_MS;
    assert.equal(update.isPromptDue(), true);
});

test('blockers are keyed by screen and cleared independently', () => {
    const { update } = harness();
    update.setBlocker('pos', 'Finish or clear the current order first.');
    update.setBlocker('saving', 'A change is still being saved.');
    update.setBlocker('pos', null);

    assert.deepEqual(update.getSnapshot().blockers, [
        'A change is still being saved.',
    ]);
});
