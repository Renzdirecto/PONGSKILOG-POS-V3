import assert from 'node:assert/strict';
import { existsSync, readFileSync, statSync } from 'node:fs';
import { test } from 'node:test';
import {
    canOpenCustomerDisplay,
    canTransitionKitchenStatus,
    filterKitchenTickets,
    kitchenItemLabel,
    orderTypeLabel,
    POS_READY_REALTIME_EVENTS,
    relativePlacedTime,
    statusLabel,
} from '../resources/js/lib/kitchen.ts';
import type { KitchenTicket } from '../resources/js/types/kitchen.ts';
import { KitchenTransitionStore } from '../resources/js/lib/kitchen-transitions.ts';

const tickets: KitchenTicket[] = [
    {
        id: 'order-1',
        number: '1043',
        customer: 'Maria Santos',
        order_type: 'dine_in',
        table: 'Table 2',
        status: 'kitchen',
        placed_at: '2026-09-22T10:00:00+08:00',
        version: 2,
        items: [],
    },
    {
        id: 'order-2',
        number: '1044',
        customer: null,
        order_type: 'take_out',
        table: null,
        status: 'ready',
        placed_at: '2026-09-22T10:02:00+08:00',
        version: 4,
        items: [],
    },
    {
        id: 'order-3',
        number: '1045',
        customer: 'Done customer',
        order_type: 'take_out',
        table: null,
        status: 'done',
        placed_at: '2026-09-22T09:30:00+08:00',
        version: 5,
        items: [],
    },
];

test('customer display navigation follows its launch permission', () => {
    assert.equal(
        canOpenCustomerDisplay(['pos.access', 'customer_display.launch']),
        true,
    );
    assert.equal(canOpenCustomerDisplay(['pos.access']), false);
});

test('kitchen transitions allow all forward moves and only a one-step rollback', () => {
    assert.equal(canTransitionKitchenStatus('kitchen', 'done'), true);
    assert.equal(canTransitionKitchenStatus('ready', 'preparing'), true);
    assert.equal(canTransitionKitchenStatus('done', 'ready'), true);
    assert.equal(canTransitionKitchenStatus('done', 'preparing'), false);
    assert.equal(canTransitionKitchenStatus('ready', 'kitchen'), false);
    assert.equal(canTransitionKitchenStatus('preparing', 'preparing'), true);
});

test('all orders excludes done while tabs and search use status order number or customer', () => {
    assert.deepEqual(
        filterKitchenTickets(tickets, 'all', '').map((ticket) => ticket.id),
        ['order-1', 'order-2'],
    );
    assert.deepEqual(
        filterKitchenTickets(tickets, 'done', '').map((ticket) => ticket.id),
        ['order-3'],
    );
    assert.deepEqual(
        filterKitchenTickets(tickets, 'all', 'maria').map(
            (ticket) => ticket.id,
        ),
        ['order-1'],
    );
    assert.deepEqual(
        filterKitchenTickets(tickets, 'all', '#1044').map(
            (ticket) => ticket.id,
        ),
        ['order-2'],
    );
    assert.deepEqual(
        filterKitchenTickets(tickets, 'all', '1044').map((ticket) => ticket.id),
        ['order-2'],
    );
});

test('kitchen labels and elapsed time remain presentation-only helpers', () => {
    assert.equal(statusLabel('preparing'), 'Preparing');
    assert.equal(orderTypeLabel('dine_in'), 'Dine in');
    assert.equal(orderTypeLabel('take_out'), 'Take out');
    assert.equal(
        relativePlacedTime(
            '2026-09-22T10:00:00+08:00',
            new Date('2026-09-22T11:07:00+08:00').getTime(),
        ),
        '1 hr 7 min ago',
    );
});

test('kitchen item labels keep size in the name and compact standard groups', () => {
    assert.equal(
        kitchenItemLabel('Large Tapsilog', ['Rice: Plain', 'Egg: Sunny Side']),
        'Large Tapsilog + Rice: Plain + Egg: Sunny Side',
    );
});

test('POS refreshes both ready orders and kitchen status for ticket lifecycle and Buzz state events', () => {
    assert.deepEqual(POS_READY_REALTIME_EVENTS, [
        '.kitchen.ticket_created',
        '.kitchen.status_changed',
        '.pickup.notify_changed',
    ]);
});

test('KDS audio is local, transition-confirmed, and does not label structured instructions', async () => {
    const kitchenPage = readFileSync(
        new URL(
            '../resources/js/pages/workspaces/kitchen.tsx',
            import.meta.url,
        ),
        'utf8',
    );

    assert.match(kitchenPage, /\/audio\/kitchen-new-order\.mp3/);
    assert.match(kitchenPage, /\/audio\/kitchen-pa-serve\.mp3/);
    assert.match(kitchenPage, /playNewOrderSounds\(newlyArrivedIds\.length\)/);
    assert.match(kitchenPage, /playAudioToEndSafely/);
    const transitions = new KitchenTransitionStore();
    let sounds = 0;
    await transitions.run(
        tickets[0],
        'ready',
        async () => ({
            order_id: tickets[0].id,
            from: 'kitchen',
            to: 'ready',
            changed: true,
            version: 3,
        }),
        () => {
            sounds += 1;
        },
        assert.fail,
        () => {},
    );
    assert.equal(sounds, 1);
    assert.doesNotMatch(kitchenPage, /Instruction:/);

    for (const file of ['kitchen-new-order.mp3', 'kitchen-pa-serve.mp3']) {
        const path = new URL(`../public/audio/${file}`, import.meta.url);
        assert.equal(existsSync(path), true);
        assert.ok(statSync(path).size > 0);
    }
});

test('KDS renders one-row controls and split ticket timing', () => {
    const kitchenPage = readFileSync(
        new URL(
            '../resources/js/pages/workspaces/kitchen.tsx',
            import.meta.url,
        ),
        'utf8',
    );

    assert.match(
        kitchenPage,
        /flex flex-wrap items-center gap-2 overflow-hidden[\s\S]*sm:flex-nowrap[\s\S]*!fullscreen \? \([\s\S]*Search orders[\s\S]*KITCHEN DISPLAY/,
    );
    assert.match(
        kitchenPage,
        /text-neutral-500[\s\S]*placedTimeLabel\(ticket\.placed_at\)/,
    );
    assert.match(
        kitchenPage,
        /ticket\.customer && \([\s\S]*\{' \| '\}[\s\S]*\{ticket\.customer\}/,
    );
    assert.match(
        kitchenPage,
        /flex shrink-0 items-center gap-1\.5[\s\S]*\{updated && \([\s\S]*Updated[\s\S]*orderTypeLabel\(ticket\.order_type\)/,
    );
    assert.doesNotMatch(kitchenPage, /8_000/);
    assert.doesNotMatch(kitchenPage, /next\.delete\(orderId\)/);
    assert.match(
        kitchenPage,
        /\[\.\.\.current\]\.filter\(\(orderId\) => currentIds\.has\(orderId\)\)/,
    );
    assert.match(
        kitchenPage,
        /text-red-700[\s\S]*relativePlacedTime\(ticket\.placed_at, now\)/,
    );
    assert.doesNotMatch(kitchenPage, /<span>Status<\/span>/);
});

test('operational sidebar stays visible on iPad Mini and floating navigation is phone only', () => {
    const kitchenPage = readFileSync(
        new URL(
            '../resources/js/pages/workspaces/kitchen.tsx',
            import.meta.url,
        ),
        'utf8',
    );
    const workspaceLayout = readFileSync(
        new URL(
            '../resources/js/layouts/workspace-layout.tsx',
            import.meta.url,
        ),
        'utf8',
    );

    assert.match(workspaceLayout, /w-\[94px\][^"\n]*md:flex/);
    assert.match(workspaceLayout, /shadow-xl md:hidden/);
    /** 76px clears the floating nav; on phones with a home indicator the safe-area inset replaces its 12px offset. */
    assert.match(
        workspaceLayout,
        /pb-\[calc\(max\(12px,env\(safe-area-inset-bottom\)\)\+64px\)\] md:pb-0/,
    );
    assert.match(kitchenPage, /grid-cols-1 md:grid-cols-2 min-\[1180px\]:grid-cols-3/);
    assert.match(kitchenPage, /flex-wrap[\s\S]*sm:flex-nowrap/);
});

test('Vite keeps hot assets and generated development font URLs on one fixed server', () => {
    const viteConfig = readFileSync(
        new URL('../vite.config.ts', import.meta.url),
        'utf8',
    );

    assert.match(viteConfig, /host: '127\.0\.0\.1'/);
    assert.match(viteConfig, /port: 5173/);
    assert.match(viteConfig, /strictPort: true/);
});
