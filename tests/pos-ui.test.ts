import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import {
    invoiceProofDeferredLabel,
    showsInvoiceProof,
} from '../resources/js/lib/pos-payment-proof.ts';
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
    assert.equal(
        posItemDescription(modifiers, '   '),
        'Scramble, Plain Rice',
    );
});

test('POS product images cover card media while detail images remain contained', () => {
    const productMedia = readFileSync(
        new URL(
            '../resources/js/components/pos-product-media.tsx',
            import.meta.url,
        ),
        'utf8',
    );

    assert.match(
        productMedia,
        /detail \? 'object-contain' : 'object-cover'/,
    );
});

test('Store Session detail rows render persisted opening money and Manila time', () => {
    assert.deepEqual(openStoreSessionDialogState(), {
        open: true,
        session: null,
        unavailable: false,
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

test('invoice proof placeholder is limited to the Cashless payment leg and clearly deferred', () => {
    assert.equal(showsInvoiceProof('cash'), false);
    assert.equal(showsInvoiceProof('cashless'), true);
    assert.equal(showsInvoiceProof('split'), true);
    assert.equal(invoiceProofDeferredLabel, 'Coming in Transaction History');
});

test('POS subscribes to the compact catalog events and refetches after reconnect', () => {
    assert.deepEqual(POS_CATALOG_REALTIME_EVENTS, [
        '.inventory.changed',
        '.product.availability_changed',
        '.product.branch_configuration_changed',
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
