import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';

const page = readFileSync(
    new URL(
        '../resources/js/pages/workspaces/transaction-history.tsx',
        import.meta.url,
    ),
    'utf8',
);

test('transaction history keeps filters and pagination server-driven', () => {
    assert.match(page, /router\.get\(transactionHistory\(\)/);
    assert.match(page, /only: \['transactions', 'metrics', 'filters'\]/);
    assert.doesNotMatch(page, /transactions\.data\.filter\(/);
});

test('transaction controls expose editing, balance payment, proof capture, and a disabled Phase 13 void', () => {
    assert.match(page, /Edit committed order/);
    assert.match(page, /Pay outstanding balance/);
    assert.match(page, /TransactionInvoiceDialog/);
    assert.match(page, /Void · Phase 13/);
    assert.match(page, /disabled title="Available in Phase 13"/);
});

test('history view preference is local while transaction state stays authoritative', () => {
    assert.match(page, /localStorage\.getItem\('transaction-history-view'\)/);
    assert.match(page, /useBranchRealtimeRefresh/);
    assert.match(page, /events: \['order\.committed', 'order\.updated'\]/);
});
