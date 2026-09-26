import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import {
    activeLayer,
    changedLineKeys,
    connectionNotice,
    createCartSequencer,
    formatPairingCode,
    orderTypeText,
    POS_STATION_STORAGE_KEY,
    queuePositionText,
    readStationId,
    refreshDelayMs,
    showsLiveCart,
    takeoverRemainingMs,
    TAKEOVER_MS,
    toggledMode,
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

test('MENU and CUSTOMER DISPLAY are mutually exclusive; pressing the active control returns to Ads', () => {
    assert.equal(toggledMode('ads', 'menu'), 'menu');
    assert.equal(toggledMode('menu', 'customer_display'), 'customer_display');
    assert.equal(toggledMode('customer_display', 'customer_display'), 'ads');
    assert.equal(toggledMode('menu', 'menu'), 'ads');
});

test('the takeover sits above every mode and an unpaired screen only shows its pairing code', () => {
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

test('takeovers last 3 s for Dine In and 5 s for Take Out and never longer than the server allows', () => {
    assert.deepEqual(TAKEOVER_MS, { dine_in: 3000, take_out: 5000 });
    const takeover = (
        order_type: 'dine_in' | 'take_out',
        remaining_ms: number,
    ): CustomerScreenTakeover => ({
        id: 't',
        order_number: '024',
        order_type,
        queue_position: 3,
        remaining_ms,
        duration_ms: TAKEOVER_MS[order_type],
        pickup: null,
    });
    assert.equal(takeoverRemainingMs(takeover('dine_in', 2800)), 2800);
    assert.equal(takeoverRemainingMs(takeover('dine_in', 9000)), 3000);
    assert.equal(takeoverRemainingMs(takeover('take_out', 9000)), 5000);
    assert.equal(takeoverRemainingMs(takeover('take_out', -5)), 0);
});

test('queue and order-type texts come only from the server-derived position', () => {
    assert.equal(
        queuePositionText('dine_in', 3),
        'You are #3 in the Dine-In queue',
    );
    assert.equal(
        queuePositionText('take_out', 1),
        'You are #1 in the Take-Out queue',
    );
    assert.equal(queuePositionText('take_out', null), null);
    assert.equal(queuePositionText('dine_in', 0), null);
    assert.equal(orderTypeText('dine_in'), 'DINE IN');
    assert.equal(orderTypeText('take_out'), 'TAKE OUT');
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
    const pos = source('components/cashier-pos.tsx');
    assert.match(
        pos,
        /setReceipt\(result\.receipt\);\s*announceOrder\(result\.receipt\.id\);/,
    );
    assert.match(
        pos,
        /setPayLaterSuccess\(result\.order\);\s*announceOrder\(result\.order\.id\);/,
    );
});
