import assert from 'node:assert/strict';
import { test } from 'node:test';
import {
    connectivityCopy,
    createConnectivityMonitor,
    lastSyncedText,
    OFFLINE_AFTER_FAILED_ATTEMPTS,
    reconnectDelay,
    type RevalidationOutcome,
} from '../resources/js/lib/pwa-connectivity.ts';

/** A controllable browser: network flag, visibility, clock, timers, server probe and authoritative reload. */
function harness(options: { online?: boolean } = {}) {
    const state = {
        navigatorOnline: options.online ?? true,
        visible: true,
        now: 1_000,
        probeResults: [] as boolean[],
        revalidations: [] as RevalidationOutcome[],
        probes: 0,
        reloads: 0,
        timers: new Map<number, { callback: () => void; delay: number }>(),
        nextTimer: 1,
    };
    const monitor = createConnectivityMonitor({
        probe: async () => {
            state.probes += 1;

            return state.probeResults.shift() ?? false;
        },
        revalidate: async () => {
            state.reloads += 1;

            return state.revalidations.shift() ?? 'revalidated';
        },
        isNavigatorOnline: () => state.navigatorOnline,
        isDocumentVisible: () => state.visible,
        now: () => state.now,
        setTimeout: (callback, delay) => {
            const id = state.nextTimer++;
            state.timers.set(id, { callback, delay });

            return id;
        },
        clearTimeout: (id) => {
            state.timers.delete(id as number);
        },
    });
    const settle = () => new Promise((resolve) => setImmediate(resolve));
    const runTimers = async () => {
        const due = [...state.timers.entries()];
        state.timers.clear();
        for (const [, timer] of due) {
            timer.callback();
        }
        await settle();
    };

    return { state, monitor, settle, runTimers };
}

test('the app starts online, and an offline event blocks writes without probing', () => {
    const { state, monitor } = harness();

    assert.equal(monitor.getSnapshot().phase, 'online');
    assert.equal(monitor.allowsServerWrites(), true);

    state.navigatorOnline = false;
    monitor.handleOffline();

    assert.equal(monitor.getSnapshot().phase, 'offline');
    assert.equal(monitor.allowsServerWrites(), false);
    assert.equal(state.probes, 0);
    assert.equal(state.timers.size, 0);
});

test('network back: Reconnecting, then the server is verified and the page revalidated before writes resume', async () => {
    const { state, monitor, settle } = harness({ online: false });
    const phases: string[] = [];
    monitor.subscribe(() => phases.push(monitor.getSnapshot().phase));

    state.navigatorOnline = true;
    state.probeResults = [true];
    state.now = 5_000;
    monitor.handleOnline();
    assert.equal(monitor.getSnapshot().phase, 'reconnecting');
    assert.equal(monitor.allowsServerWrites(), false);
    await settle();

    assert.deepEqual(phases, ['reconnecting', 'online']);
    assert.equal(state.probes, 1);
    assert.equal(state.reloads, 1);
    assert.equal(monitor.getSnapshot().lastSyncedAt, 5_000);
    assert.equal(monitor.allowsServerWrites(), true);
});

test('an unreachable server is retried with bounded backoff and shown as Offline after a few attempts', async () => {
    const { state, monitor, settle, runTimers } = harness();
    state.probeResults = [false, false, false, false];

    monitor.reportNetworkFailure();
    await settle();
    assert.equal(monitor.getSnapshot().phase, 'reconnecting');
    assert.deepEqual([...state.timers.values()].map((timer) => timer.delay), [
        reconnectDelay(1),
    ]);

    for (let attempt = 2; attempt <= OFFLINE_AFTER_FAILED_ATTEMPTS; attempt++) {
        await runTimers();
    }

    assert.equal(monitor.getSnapshot().phase, 'offline');
    assert.equal(state.probes, OFFLINE_AFTER_FAILED_ATTEMPTS);
    assert.equal(state.reloads, 0);
    assert.ok(reconnectDelay(99) <= 30_000);
    assert.equal(reconnectDelay(99), reconnectDelay(5));
});

test('retries pause while the page is hidden and resume at once when it is shown again', async () => {
    const { state, monitor, settle } = harness();
    state.probeResults = [false];
    monitor.reportNetworkFailure();
    await settle();
    assert.equal(state.timers.size, 1);

    state.visible = false;
    monitor.handleVisibilityChange();
    assert.equal(state.timers.size, 0);

    state.visible = true;
    state.probeResults = [true];
    monitor.handleVisibilityChange();
    await settle();

    assert.equal(monitor.getSnapshot().phase, 'online');
    assert.equal(state.probes, 2);
});

test('once online there is no steady-state connectivity polling', async () => {
    const { state, monitor, settle } = harness({ online: false });
    state.navigatorOnline = true;
    state.probeResults = [true];
    monitor.handleOnline();
    await settle();

    assert.equal(monitor.getSnapshot().phase, 'online');
    assert.equal(state.timers.size, 0);
    monitor.handleVisibilityChange();
    monitor.reportServerResponse();
    assert.equal(state.timers.size, 0);
    assert.equal(state.probes, 1);
});

test('a revalidation that cannot reach the server keeps writes blocked and retries', async () => {
    const { state, monitor, settle } = harness({ online: false });
    state.navigatorOnline = true;
    state.probeResults = [true];
    state.revalidations = ['unreachable'];

    monitor.handleOnline();
    await settle();

    assert.notEqual(monitor.getSnapshot().phase, 'online');
    assert.equal(monitor.allowsServerWrites(), false);
    assert.equal(state.timers.size, 1);
});

test('going offline during a verification wins over its late result', async () => {
    const { state, monitor, settle } = harness({ online: false });
    state.navigatorOnline = true;
    state.probeResults = [true];
    monitor.handleOnline();
    state.navigatorOnline = false;
    monitor.handleOffline();
    await settle();

    assert.equal(monitor.getSnapshot().phase, 'offline');
});

test('server responses refresh Last synced while online', () => {
    const { state, monitor } = harness();
    state.now = 42_000;
    monitor.reportServerResponse();

    assert.equal(monitor.getSnapshot().lastSyncedAt, 42_000);
    assert.equal(
        lastSyncedText(42_000, () => '10:42 PM'),
        'Last synced 10:42 PM',
    );
    assert.equal(lastSyncedText(null), null);
});

test('each state is described in words, not color alone', () => {
    assert.equal(connectivityCopy('offline').label, 'Offline');
    assert.match(connectivityCopy('offline').detail, /need internet/);
    assert.match(connectivityCopy('offline').detail, /may be out of date/);
    assert.equal(connectivityCopy('reconnecting').label, 'Reconnecting…');
    assert.equal(connectivityCopy('online').label, 'Online');
});
