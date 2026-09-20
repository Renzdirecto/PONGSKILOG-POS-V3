import assert from 'node:assert/strict';
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
