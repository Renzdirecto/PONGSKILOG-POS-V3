import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import { showsInvoiceProof } from '../resources/js/lib/pos-payment-proof.ts';
import {
    formatStoreSessionMoney,
    formatStoreSessionOpenedAt,
    openStoreSessionDialogState,
    storeSessionDetailRows,
} from '../resources/js/lib/store-session.ts';
import {
    POS_CATALOG_REALTIME_EVENTS,
    shouldRefetchCatalogAfterConnectionChange,
} from '../resources/js/lib/pos-catalog-realtime.ts';
import { posItemDescription } from '../resources/js/lib/pos-item-description.ts';

test('POS displays instruction choices and free-text notes as one description', () => {
    const modifiers = [
        { name: 'Scramble', semantic_role: 'instruction' as const },
        { name: 'Plain Rice', semantic_role: 'instruction' as const },
        { name: 'Large', semantic_role: 'size' as const },
    ];

    assert.equal(
        posItemDescription(modifiers, 'bang'),
        'Scramble, Plain Rice, bang',
    );
    assert.equal(posItemDescription(modifiers, '   '), 'Scramble, Plain Rice');
});

test('POS product images cover card media while detail images remain contained', () => {
    const productMedia = readFileSync(
        new URL(
            '../resources/js/components/pos-product-media.tsx',
            import.meta.url,
        ),
        'utf8',
    );

    assert.match(productMedia, /detail \? 'object-contain' : 'object-cover'/);
});

test('Store Session detail rows render persisted opening money and Manila time', () => {
    assert.deepEqual(openStoreSessionDialogState(), {
        open: true,
        session: null,
        loadState: 'loading',
    });

    const rows = storeSessionDetailRows({
        id: 'session-1',
        opened_at: '2026-09-19T17:25:00+00:00',
        opening_cash_amount: '5000.00',
        opening_cashless_amount: '2500.25',
        opened_by: { name: 'Cashier Tester' },
        branch: { id: 'branch-1', code: 'MAIN', name: 'Main Branch' },
    });

    assert.equal(formatStoreSessionMoney('5000.00'), '₱5,000.00');
    assert.match(
        formatStoreSessionOpenedAt('2026-09-19T17:25:00+00:00'),
        /September 20, 2026.*1:25 AM/,
    );
    assert.deepEqual(
        rows.map(({ label, value }) => [label, value]),
        [
            ['Opened', rows[0].value],
            ['Opening Cash', '₱5,000.00'],
            ['Opening Cashless', '₱2,500.25'],
            ['Branch', 'MAIN · Main Branch'],
            ['Opened by', 'Cashier Tester'],
        ],
    );
});

test('invoice proof capture is limited to transactions with a Cashless payment leg', () => {
    assert.equal(showsInvoiceProof('cash'), false);
    assert.equal(showsInvoiceProof('cashless'), true);
    assert.equal(showsInvoiceProof('split'), true);
    const paid = readFileSync(
        new URL('../resources/js/components/pos-paid.tsx', import.meta.url),
        'utf8',
    );
    assert.match(paid, /TransactionInvoiceDialog/);
    assert.doesNotMatch(paid, /Phase 12/);
});

test('POS subscribes to the compact catalog events and refetches after reconnect', () => {
    assert.deepEqual(POS_CATALOG_REALTIME_EVENTS, [
        '.inventory.changed',
        '.product.availability_changed',
        '.product.branch_configuration_changed',
        '.ingredients.changed',
    ]);
    assert.equal(
        shouldRefetchCatalogAfterConnectionChange(
            'unavailable',
            'connected',
            true,
        ),
        true,
    );
    assert.equal(
        shouldRefetchCatalogAfterConnectionChange(
            'connecting',
            'connected',
            false,
        ),
        false,
    );
    assert.equal(
        shouldRefetchCatalogAfterConnectionChange(
            'connected',
            'connected',
            true,
        ),
        false,
    );
});

test('Pay Now shows no empty invoice rows, a green Exact option and no keypad on phones', () => {
    const preview = readFileSync(
        new URL('../resources/js/components/pos-payment-preview.tsx', import.meta.url),
        'utf8',
    );
    assert.doesNotMatch(preview, /Invoice: —|Cashless invoice/);
    assert.match(preview, /attach the invoice photo\s+after confirming/);
    assert.match(
        preview,
        /aria-pressed=\{\s*exact \? exactSelected : undefined\s*\}/,
    );
    assert.match(preview, /border-2 border-green-600 bg-green-50 text-green-800/);
    assert.match(preview, /border-green-700 bg-green-700 text-white/);
    assert.match(preview, /className="hidden grid-cols-3 gap-1\.5 md:grid"/);
    assert.match(preview, /inputMode="decimal"/);
});

test('mobile docks keep the active pill inside the dock and the Ready panel above View cart', () => {
    const source = (path: string) =>
        readFileSync(new URL(`../resources/js/${path}`, import.meta.url), 'utf8');
    const layout = source('layouts/workspace-layout.tsx');
    const owner = source('components/owner-workspace-shell.tsx');
    const superAdmin = source('components/super-admin-shell.tsx');
    const ready = source('components/pos-ready-notifications.tsx');

    assert.match(layout, /relative flex h-full min-w-0 flex-col items-center justify-center gap-0\.5 rounded-xl/);
    assert.match(layout, /absolute top-1 right-1 min-w-4 rounded-full bg-red-700/);
    assert.match(owner, /dock: `relative flex h-full w-full min-w-0 flex-col/);
    assert.match(owner, /variant="dock"/);
    assert.match(superAdmin, /\$\{dock \? 'h-full min-w-0 gap-1 rounded-\[14px\] px-0\.5' : 'h-\[70px\] gap-1\.5 rounded-xl px-1'\}/);
    assert.match(superAdmin, /compact\s+dock\s+\/>/);
    assert.match(ready, /aboveCart \?\s*'bottom-\[calc\(max\(12px,env\(safe-area-inset-bottom\)\)\+132px\)\]'/);
    assert.doesNotMatch(ready, /bottom-\[84px\]/);
    assert.match(layout, /aboveCart=\{!isQr\}/);
});
