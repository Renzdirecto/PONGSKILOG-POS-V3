import assert from 'node:assert/strict';
import { existsSync, readFileSync, statSync } from 'node:fs';
import { test } from 'node:test';
import {
    canOpenCustomerDisplay,
    canTransitionKitchenStatus,
    filterKitchenTickets,
    isActiveKitchenWork,
    kitchenItemLabel,
    orderTypeLabel,
    POS_READY_REALTIME_EVENTS,
    relativePlacedTime,
    statusLabel,
} from '../resources/js/lib/kitchen.ts';
import type { KitchenTicket } from '../resources/js/types/kitchen.ts';
import { registerHooks } from 'node:module';

// Node's native TypeScript runner requires explicit extensions; Vite resolves these in the app.
registerHooks({
    resolve(specifier, context, nextResolve) {
        return nextResolve(
            specifier === './kitchen' &&
                context.parentURL?.endsWith('/kitchen-transitions.ts')
                ? './kitchen.ts'
                : specifier,
            context,
        );
    },
});
const { KitchenTransitionStore } = await import(
    '../resources/js/lib/kitchen-transitions.ts'
);

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

test('all orders is the active kitchen queue only, with Ready and Done in their own tabs', () => {
    assert.deepEqual(
        filterKitchenTickets(tickets, 'all', '').map((ticket) => ticket.id),
        ['order-1'],
    );
    assert.deepEqual(
        filterKitchenTickets(tickets, 'ready', '').map((ticket) => ticket.id),
        ['order-2'],
    );
    assert.deepEqual(
        filterKitchenTickets(tickets, 'done', '').map((ticket) => ticket.id),
        ['order-3'],
    );
    assert.equal(isActiveKitchenWork('kitchen'), true);
    assert.equal(isActiveKitchenWork('preparing'), true);
    assert.equal(isActiveKitchenWork('ready'), false);
    assert.equal(isActiveKitchenWork('done'), false);
});

test('search matches the order number or the customer inside the chosen tab', () => {
    assert.deepEqual(
        filterKitchenTickets(tickets, 'all', 'maria').map(
            (ticket) => ticket.id,
        ),
        ['order-1'],
    );
    assert.deepEqual(
        filterKitchenTickets(tickets, 'ready', '#1044').map(
            (ticket) => ticket.id,
        ),
        ['order-2'],
    );
    assert.deepEqual(
        filterKitchenTickets(tickets, 'ready', '1044').map(
            (ticket) => ticket.id,
        ),
        ['order-2'],
    );
    assert.deepEqual(filterKitchenTickets(tickets, 'all', '1044'), []);
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
        '.order.voided',
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
    /** The production landscape header: no absolute clock, only the elapsed timer. */
    assert.doesNotMatch(kitchenPage, /placedTimeLabel/);
    assert.doesNotMatch(kitchenPage, /toLocaleTimeString/);
    assert.match(
        kitchenPage,
        /ticket\.customer && \([\s\S]*\{' \| '\}[\s\S]*\{ticket\.customer\}/,
    );
    /** The order number is the smaller half of the title; the customer keeps its emphasis and colour. */
    assert.match(
        kitchenPage,
        /<span className="text-\[10px\] whitespace-nowrap">\s*#\{ticket\.number\}/,
    );
    /** UPDATED sits under the title instead of widening the header row. */
    assert.match(
        kitchenPage,
        /\{\(updated \|\| disabled\) && \([\s\S]{0,400}Updated/,
    );
    /** The order-type pill is compact and the elapsed timer sits under it. */
    assert.match(
        kitchenPage,
        /flex shrink-0 flex-col items-end gap-0\.5[\s\S]*orderTypeLabel\(ticket\.order_type\)[\s\S]*relativePlacedTime\(ticket\.placed_at, now\)/,
    );
    assert.match(
        kitchenPage,
        /rounded-md border bg-white px-1 py-px text-\[8px\]/,
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

test('Done is confirmed before the authoritative transition and other statuses are not', () => {
    const kitchenPage = readFileSync(
        new URL(
            '../resources/js/pages/workspaces/kitchen.tsx',
            import.meta.url,
        ),
        'utf8',
    );

    assert.match(
        kitchenPage,
        /status === 'done'\s*\?\s*onConfirmDone\(ticket\)\s*:\s*onTransition\(ticket, status\)/,
    );
    assert.match(
        kitchenPage,
        /Are you sure you want to mark this order as Done\?/,
    );
    assert.match(kitchenPage, /role="alertdialog"/);
    assert.match(kitchenPage, /onClick=\{onCancel\}[\s\S]{0,400}Cancel/);
    assert.match(
        kitchenPage,
        /bg-\[#15803d\][\s\S]{0,120}>\s*Done\s*<\/button>/,
    );
    assert.match(
        kitchenPage,
        /setDoneConfirmation\(null\);\s*transition\(ticket, 'done'\);/,
    );
    /** Rendered inside the board surface so it is visible while the KDS is in native full screen. */
    assert.doesNotMatch(kitchenPage, /from '@\/components\/ui\/dialog'/);
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

test('Kitchen full screen falls back to a fixed focus view where the Fullscreen API is missing, with the round logo', () => {
    const kitchen = readFileSync(
        new URL('../resources/js/pages/workspaces/kitchen.tsx', import.meta.url),
        'utf8',
    );
    const layout = readFileSync(
        new URL('../resources/js/layouts/workspace-layout.tsx', import.meta.url),
        'utf8',
    );
    assert.match(kitchen, /const fullscreen = nativeFullscreen \|\| focusView;/);
    assert.match(kitchen, /if \(!fullscreenSupported\(document\)\) \{\s*setFocusView\(true\);/);
    assert.match(kitchen, /catch \{\s*setFocusView\(true\);/);
    assert.match(kitchen, /event\.key === 'Escape'\) setFocusView\(false\)/);
    assert.match(kitchen, /\{fullscreen && \(\s*<img\s+src="\/images\/branding\/logo\.png"[\s\S]*?rounded-full/);
    assert.match(kitchen, /min-h-11 min-w-0 truncate rounded-\[8px\] border px-1 text-\[10\.5px\]/);
    assert.match(layout, /className="theme-static size-9 shrink-0 rounded-full bg-\[#111\] object-contain p-1 md:hidden"/);
});
