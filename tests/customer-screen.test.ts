import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import {
    activeLayer,
    changedLineKeys,
    connectionNotice,
    createCartSequencer,
    createSlideTimer,
    customerScreenCommitHeaders,
    formatPairingCode,
    fitQueueRows,
    fitScale,
    fullscreenSupported,
    orderTypeText,
    orderTypeTone,
    overallPositionText,
    pendingTakeover,
    POS_STATION_STORAGE_KEY,
    queueGrid,
    queueRangeText,
    readStationId,
    refreshDelayMs,
    showsLiveCart,
    stepSlide,
    swipeDirection,
    takeoverShowMs,
    takeoverWaitsForQr,
    typeQueueLabel,
    type CustomerScreenCart,
    type CustomerScreenTakeover,
} from '../resources/js/lib/customer-screen.ts';

const source = (path: string) =>
    readFileSync(new URL(`../resources/js/${path}`, import.meta.url), 'utf8');

const line = (key: string, quantity = 1, amount = '90.00') => ({
    key,
    name: 'Lemonade',
    quantity,
    details: [],
    instructions: [],
    amount,
});

const cart = (lines = [line('a')]): CustomerScreenCart => ({
    lines,
    total: '90.00',
    item_count: lines.length,
    order_type: 'take_out',
    updated_at: '2026-09-27T10:00:00+08:00',
});

test('the confirmation sits above every mode and an unpaired screen only shows its pairing code', () => {
    assert.equal(
        activeLayer({ status: 'unpaired', mode: 'menu' }, true),
        'pairing',
    );
    assert.equal(
        activeLayer({ status: 'paired', mode: 'menu' }, true),
        'takeover',
    );
    assert.equal(
        activeLayer({ status: 'paired', mode: 'menu' }, false),
        'menu',
    );
    assert.equal(activeLayer({ status: 'paired', mode: 'ads' }, false), 'ads');
    assert.equal(
        activeLayer({ status: 'paired', mode: 'customer_display' }, false),
        'customer_display',
    );
});

test('Ads with an active cart shows the full order summary, Menu keeps its split and clearing the cart returns to Ads', () => {
    const paired = { status: 'paired' as const };
    assert.equal(
        activeLayer({ ...paired, mode: 'ads', cart: cart() }, false),
        'order_summary',
    );
    assert.equal(
        activeLayer({ ...paired, mode: 'ads', cart: cart([]) }, false),
        'ads',
    );
    assert.equal(
        activeLayer({ ...paired, mode: 'ads', cart: null }, false),
        'ads',
    );
    assert.equal(
        activeLayer({ ...paired, mode: 'menu', cart: cart() }, false),
        'menu',
    );
    assert.equal(showsLiveCart('menu', cart()), true);
    assert.equal(
        activeLayer(
            { ...paired, mode: 'customer_display', cart: cart() },
            false,
        ),
        'customer_display',
    );
    assert.equal(
        activeLayer({ ...paired, mode: 'ads', cart: cart() }, true),
        'takeover',
    );
});

test('the Live Cart shows above the Menu only while the paired station has lines', () => {
    assert.equal(showsLiveCart('menu', cart()), true);
    assert.equal(showsLiveCart('menu', cart([])), false);
    assert.equal(showsLiveCart('menu', null), false);
    assert.equal(showsLiveCart('ads', cart()), false);
    assert.equal(showsLiveCart('customer_display', cart()), false);
});

test('only new or changed cart lines are highlighted', () => {
    assert.deepEqual(changedLineKeys(null, [line('a')]), []);
    assert.deepEqual(
        changedLineKeys(
            [line('a'), line('b')],
            [line('a'), line('b', 2, '180.00'), line('c')],
        ),
        ['b', 'c'],
    );
    assert.deepEqual(changedLineKeys([line('a')], [line('a')]), []);
});

const takeover = (
    overrides: Partial<CustomerScreenTakeover> = {},
): CustomerScreenTakeover => ({
    id: 't1',
    order_number: '1053',
    order_type: 'take_out',
    duration_ms: 5000,
    remaining_ms: null,
    overall_position: 10,
    type_position: 6,
    queue: [],
    queue_total: 10,
    pickup: {
        url: 'https://pos.test/pickup/x',
        qr_image: 'data:image/svg+xml;base64,',
    },
    ...overrides,
});

test('the confirmation countdown is the full configured duration once shown, or only the time left after a reload', () => {
    assert.equal(takeoverShowMs(takeover()), 5000);
    assert.equal(takeoverShowMs(takeover({ duration_ms: 12000 })), 12000);
    assert.equal(takeoverShowMs(takeover({ duration_ms: 90000 })), 15000);
    assert.equal(takeoverShowMs(takeover({ duration_ms: 1000 })), 3000);
    assert.equal(takeoverShowMs(takeover({ remaining_ms: 2200 })), 2200);
    assert.equal(takeoverShowMs(takeover({ remaining_ms: 99999 })), 5000);
    assert.equal(takeoverShowMs(takeover({ remaining_ms: -5 })), 0);
});

test('Take Out waits for its QR before counting down; Dine In or a Take Out without a QR is ready at once', () => {
    assert.equal(takeoverWaitsForQr(takeover()), true);
    assert.equal(takeoverWaitsForQr(takeover({ pickup: null })), false);
    assert.equal(
        takeoverWaitsForQr(takeover({ order_type: 'dine_in', pickup: null })),
        false,
    );
});

test('a finished confirmation is never shown again and an ended one is not restarted', () => {
    const finished = new Set<string>();
    assert.deepEqual(pendingTakeover(takeover(), finished), takeover());
    assert.equal(pendingTakeover(null, finished), null);
    assert.equal(
        pendingTakeover(takeover({ remaining_ms: 0 }), finished),
        null,
    );
    finished.add('t1');
    assert.equal(pendingTakeover(takeover(), finished), null);
    assert.equal(pendingTakeover(takeover({ id: 't2' }), finished)?.id, 't2');
});

test('queue and order-type texts come only from the server-derived positions', () => {
    assert.equal(overallPositionText(27), 'You are #27 overall');
    assert.equal(overallPositionText(null), null);
    assert.equal(overallPositionText(0), null);
    assert.equal(typeQueueLabel('take_out'), 'TAKE OUT QUEUE');
    assert.equal(typeQueueLabel('dine_in'), 'DINE IN QUEUE');
    assert.equal(orderTypeText('dine_in'), 'DINE IN');
    assert.equal(orderTypeText('take_out'), 'TAKE OUT');
});

test('ad navigation wraps both ways and only a clear horizontal swipe changes the slide', () => {
    assert.equal(stepSlide(0, 3, 1), 1);
    assert.equal(stepSlide(2, 3, 1), 0);
    assert.equal(stepSlide(0, 3, -1), 2);
    assert.equal(stepSlide(0, 0, 1), 0);
    assert.equal(swipeDirection(-80, 10), 'next');
    assert.equal(swipeDirection(90, -20), 'previous');
    assert.equal(swipeDirection(-20, 0), null);
    assert.equal(swipeDirection(-60, 120), null);
});

test('an ad countdown can be paused and resumed with the time it had left and never leaves a second timer', () => {
    let now = 0;
    const pending = new Map<number, { at: number; run: () => void }>();
    let nextId = 1;
    const clock = {
        now: () => now,
        set: (run: () => void, ms: number) => {
            const id = nextId++;
            pending.set(id, { at: now + ms, run });

            return id;
        },
        clear: (id: unknown) => void pending.delete(id as number),
    };
    const advance = (ms: number) => {
        now += ms;
        for (const [id, timer] of pending) {
            if (timer.at <= now) {
                pending.delete(id);
                timer.run();
            }
        }
    };
    let elapsed = 0;
    const timer = createSlideTimer(() => (elapsed += 1), clock);

    timer.start(3000);
    advance(1000);
    timer.pause();
    assert.equal(timer.isPaused(), true);
    assert.equal(pending.size, 0);
    advance(10_000);
    assert.equal(elapsed, 0);
    timer.resume();
    advance(1999);
    assert.equal(elapsed, 0);
    advance(1);
    assert.equal(elapsed, 1);

    timer.start(5000);
    timer.start(3000);
    assert.equal(pending.size, 1);
    timer.stop();
    assert.equal(pending.size, 0);
    assert.equal(timer.isRunning(), false);
});

test('fullscreen is offered only where the browser supports it and never touches the screen state', () => {
    assert.equal(
        fullscreenSupported({
            fullscreenEnabled: true,
            documentElement: { requestFullscreen: () => undefined },
        }),
        true,
    );
    assert.equal(
        fullscreenSupported({ fullscreenEnabled: false, documentElement: {} }),
        false,
    );
    assert.equal(fullscreenSupported(null), false);
    const header = source('components/customer-screen-header.tsx');
    assert.match(header, /requestFullscreen\(\)/);
    assert.doesNotMatch(header, /qrRequest|modeRoute|setScreen/);
});

test('the POS commit carries its station and last cart number only when a screen is paired', () => {
    const last = { instance: 'instance-1', sequence: 7 };
    assert.deepEqual(
        customerScreenCommitHeaders('station-id-1234567', true, last),
        {
            'X-POS-Station': 'station-id-1234567',
            'X-Customer-Screen-Cart': 'instance-1:7',
        },
    );
    assert.deepEqual(
        customerScreenCommitHeaders('station-id-1234567', false, last),
        {},
    );
    assert.deepEqual(customerScreenCommitHeaders(null, true, last), {});
});

test('cart sends are numbered per page instance so the newest always wins', () => {
    const sequencer = createCartSequencer('instance-1');
    assert.deepEqual(sequencer.last(), { instance: 'instance-1', sequence: 0 });
    assert.deepEqual(sequencer.next(), { instance: 'instance-1', sequence: 1 });
    assert.deepEqual(sequencer.next(), { instance: 'instance-1', sequence: 2 });
    assert.deepEqual(sequencer.last(), { instance: 'instance-1', sequence: 2 });
});

test('the POS station id is a stable random id and blocked storage never invents one', () => {
    const values = new Map<string, string>();
    const storage = {
        getItem: (key: string) => values.get(key) ?? null,
        setItem: (key: string, value: string) => void values.set(key, value),
    };
    const id = readStationId(
        storage,
        () => '0f8fad5b-d9cb-469f-a165-70867728950e',
    );
    assert.equal(id, '0f8fad5b-d9cb-469f-a165-70867728950e');
    assert.equal(
        readStationId(storage, () => 'another-id-that-is-long'),
        id,
    );
    assert.equal(values.get(POS_STATION_STORAGE_KEY), id);
    assert.equal(
        readStationId(null, () => 'x'),
        null,
    );
    assert.equal(
        readStationId(
            {
                getItem: () => {
                    throw new Error('blocked');
                },
                setItem: () => undefined,
            },
            () => 'x',
        ),
        null,
    );
});

test('pairing codes read in two groups and link renewals never run more often than once a minute', () => {
    assert.equal(formatPairingCode('AB3K7Q'), 'AB3 K7Q');
    const now = Date.parse('2026-09-27T10:00:00Z');
    assert.equal(refreshDelayMs('2026-09-27T11:00:00Z', now), 55 * 60_000);
    assert.equal(refreshDelayMs('2026-09-27T10:01:00Z', now), 60_000);
    assert.equal(refreshDelayMs('not a date', now), 5 * 60_000);
});

test('the connection state is shown honestly', () => {
    assert.equal(connectionNotice('connected'), null);
    assert.equal(connectionNotice('connecting'), 'Reconnecting…');
    assert.equal(connectionNotice('unavailable'), 'Live updates unavailable');
    assert.equal(connectionNotice('failed'), 'Live updates unavailable');
});

test('the customer screen is browse-only and refetches authoritative state on every signal', () => {
    const menu = source('components/customer-screen-menu.tsx');
    const page = source('pages/customer-screen.tsx');
    const hook = source('hooks/use-customer-screen.ts');

    for (const code of [menu, page]) {
        assert.doesNotMatch(
            code,
            /stationRequest|pos\/customer-screen|payments|router\.(post|put|patch|delete|visit)/,
        );
    }
    assert.doesNotMatch(menu, /qrRequest/);
    /** Invalidation events carry no data the screen renders; it refetches its projection. */
    assert.match(hook, /listen\('\.customer_screen\.changed'/);
    assert.match(hook, /stateRefresh\.schedule\(\)/);
    assert.doesNotMatch(hook, /setInterval/);
    /** Reconnect (not the first connection), the network returning and waking up refetch everything once. */
    assert.match(hook, /if \(hasConnected\) \{\s*refreshAll\(\);/);
    assert.match(hook, /addEventListener\('online', refreshAll\)/);
});

test('the Store Operations shell renders one customer screen control for POS accounts', () => {
    const layout = source('layouts/workspace-layout.tsx');
    assert.match(
        layout,
        /auth\.permissions\.includes\('pos\.access'\) &&\s*branchContext\.current && \(\s*<CustomerScreenControl/,
    );
    /** Both canonical commits carry the station, so the server confirms Dine In and Take Out alike. */
    const pos = source('components/cashier-pos.tsx');
    assert.match(
        pos,
        /payment\.submit\(payNow\(\), \{\s*headers: customerScreenHeaders\(\),\s*\}\)/,
    );
    assert.match(
        pos,
        /payLater\.submit\(commitPayLater\(orderId\), \{\s*headers: customerScreenHeaders\(\),\s*\}\)/,
    );
    assert.doesNotMatch(pos, /announceOrder|takeoverRoute/);
    /** The POS header refetches when the screen changes the shared mode itself. */
    const control = source('components/customer-screen-control.tsx');
    assert.match(control, /'\.customer_screen\.status_changed'/);
});

test('the order confirmation is its own view, separate from the Customer Display board, and shows no customer name', () => {
    const takeoverView = source('components/customer-screen-takeover.tsx');
    const page = source('pages/customer-screen.tsx');
    assert.doesNotMatch(
        takeoverView,
        /CustomerOrderBoard|customer_label|customer_name/,
    );
    assert.match(page, /<CustomerOrderBoard/);
    /** The countdown starts from the component's readiness, not from when the order was committed. */
    assert.match(takeoverView, /onLoad=\{markReady\}/);
    assert.match(page, /onReady=\{confirmation\.ready\}/);
});

const queueRow = (position: number, current = false) => ({
    position,
    order_number: String(1000 + position),
    order_type:
        position % 2 === 0 ? ('take_out' as const) : ('dine_in' as const),
    current,
});

test('the confirmation queue shows only the rows that fit and always keeps the customer’s own row', () => {
    const rows = Array.from({ length: 10 }, (_, index) =>
        queueRow(18 + index, index === 9),
    );
    /** Room for everything: the server window is shown unchanged. */
    assert.deepEqual(fitQueueRows(rows, 12), rows);
    /** Own row last: the rows leading up to it. */
    assert.deepEqual(
        fitQueueRows(rows, 4).map((row) => row.position),
        [24, 25, 26, 27],
    );
    /** Own row in the middle: rows before it and one after. */
    const middle = rows.map((row, index) => ({ ...row, current: index === 4 }));
    assert.deepEqual(
        fitQueueRows(middle, 4).map((row) => row.position),
        [20, 21, 22, 23],
    );
    /** Own row first, and a single-row screen still shows it. */
    const first = rows.map((row, index) => ({ ...row, current: index === 0 }));
    assert.deepEqual(
        fitQueueRows(first, 3).map((row) => row.position),
        [18, 19, 20],
    );
    assert.deepEqual(
        fitQueueRows(middle, 0).map((row) => row.position),
        [22],
    );
    /** No own row (no longer waiting): the front of the queue. */
    const none = rows.map((row) => ({ ...row, current: false }));
    assert.deepEqual(
        fitQueueRows(none, 2).map((row) => row.position),
        [18, 19],
    );
    /** Positions are the server's: the window never renumbers a row. */
    for (const row of fitQueueRows(rows, 3)) {
        assert.equal(
            row,
            rows.find((r) => r.position === row.position),
        );
    }
    assert.equal(queueRangeText(fitQueueRows(rows, 4), 27), '#24–#27 of 27');
    assert.equal(
        queueRangeText(
            rows.slice(0, 2).map((row, i) => ({ ...row, position: i + 1 })),
            2,
        ),
        null,
    );
    assert.equal(queueRangeText([], 0), null);
});

test('the confirmation fits the screen: whole queue rows per column and a scale that never enlarges or scrolls', () => {
    /** 400 px list, 44 px rows, 8 px gaps → 7 whole rows; 560 px wide → two 240 px columns. */
    assert.deepEqual(
        queueGrid({
            height: 400,
            width: 560,
            rowHeight: 44,
            gap: 8,
            minColumnWidth: 240,
        }),
        { rowsPerColumn: 7, columns: 2 },
    );
    assert.deepEqual(
        queueGrid({
            height: 20,
            width: 200,
            rowHeight: 44,
            gap: 8,
            minColumnWidth: 240,
        }),
        { rowsPerColumn: 1, columns: 1 },
    );
    assert.equal(
        fitScale({ width: 600, height: 700 }, { width: 600, height: 500 }),
        1,
    );
    assert.equal(
        fitScale({ width: 600, height: 400 }, { width: 600, height: 800 }),
        0.5,
    );
    assert.equal(
        fitScale({ width: 300, height: 900 }, { width: 600, height: 500 }),
        0.5,
    );
    assert.equal(
        fitScale({ width: 100, height: 100 }, { width: 600, height: 2000 }),
        0.35,
    );
    assert.equal(
        fitScale({ width: 0, height: 0 }, { width: 10, height: 10 }),
        1,
    );

    const takeoverView = source('components/customer-screen-takeover.tsx');
    assert.doesNotMatch(takeoverView, /overflow-y-auto|overflow-auto/);
    assert.match(
        takeoverView,
        /fixed inset-0 z-50 flex flex-col overflow-hidden/,
    );
    assert.match(
        takeoverView,
        /fitQueueRows\(rows, grid\.rowsPerColumn \* grid\.columns\)/,
    );
    /** Number, type, both positions and the Take Out QR are all still part of the view. */
    assert.match(takeoverView, /\{takeover\.order_number\}/);
    assert.match(takeoverView, /orderTypeText\(takeover\.order_type\)/);
    assert.match(takeoverView, /typeQueueLabel\(takeover\.order_type\)/);
    assert.match(takeoverView, /#\{takeover\.type_position\}/);
    assert.match(
        takeoverView,
        /overallPositionText\(takeover\.overall_position\)/,
    );
    assert.match(takeoverView, /src=\{pickup\.qr_image\}/);
});

test('Dine In is green and Take Out is blue on the customer-facing queue, always with its text label', () => {
    const dineIn = orderTypeTone('dine_in');
    const takeOut = orderTypeTone('take_out');
    for (const classes of Object.values(dineIn)) {
        assert.match(classes, /emerald/);
        assert.doesNotMatch(classes, /blue/);
    }
    for (const classes of Object.values(takeOut)) {
        assert.match(classes, /blue/);
        assert.doesNotMatch(classes, /emerald/);
    }
    /** Readable text on both: light text on the dark tint, near-black text on the bright badge. */
    assert.match(dineIn.tint, /text-emerald-50/);
    assert.match(takeOut.tint, /text-blue-50/);
    assert.match(dineIn.solid, /text-neutral-950/);
    assert.match(takeOut.solid, /text-neutral-950/);

    const takeoverView = source('components/customer-screen-takeover.tsx');
    const board = source('components/customer-order-board.tsx');
    /** Color is never the only cue: rows and board numbers keep DINE IN / TAKE OUT, the own row says YOU. */
    assert.match(takeoverView, /orderTypeText\(row\.order_type\)/);
    assert.match(takeoverView, /orderTypeTone\(row\.order_type\)/);
    assert.match(takeoverView, /row\.current \?[\s\S]*ring-4 ring-white/);
    assert.match(takeoverView, />\s*YOU\s*</);
    assert.match(board, /orderTypeText\(order_type\)/);
    assert.match(board, /orderTypeTone\(order_type\)/);
});

test('the board’s Dine In / Take Out counts come from the server and nothing re-derives the queue in React', () => {
    const board = source('components/customer-order-board.tsx');
    const takeoverView = source('components/customer-screen-takeover.tsx');
    assert.match(board, /<QueueCounts counts=\{display\.counts\} \/>/);
    assert.match(board, /\{type === 'dine_in' \? 'Dine In' : 'Take Out'\}:/);
    assert.match(board, /\{counts\[type\]\}/);
    for (const view of [board, takeoverView]) {
        assert.doesNotMatch(
            view,
            /\.filter\([^)]*order_type|\.length\s*\+|committed_at|kitchen_status/,
        );
    }
    /** The takeover never ranks orders itself: positions are read from the server rows. */
    assert.doesNotMatch(takeoverView, /position:\s*(index|i)\s*\+/);
});
