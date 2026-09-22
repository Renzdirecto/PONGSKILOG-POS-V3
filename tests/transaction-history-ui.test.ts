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
const invoice = readFileSync(
    new URL(
        '../resources/js/components/transaction-invoice-dialog.tsx',
        import.meta.url,
    ),
    'utf8',
);

test('transaction history keeps filters, metrics, and pagination server-driven', () => {
    assert.match(page, /router\.get\(transactionHistory\(\)/);
    assert.match(
        page,
        /only: \['transactions', 'history_total', 'metrics', 'filters'\]/,
    );
    assert.doesNotMatch(page, /transactions\.data\.filter\(/);
});

test('kpi cards map directly to the shared status and payment filters', () => {
    for (const label of ['In kitchen', 'Preparing', 'Done', 'Paid', 'Pending']) {
        assert.match(page, new RegExp(`label: '${label}'`));
    }
    assert.match(page, /kitchen_status: 'kitchen', payment_status: undefined/);
    assert.match(
        page,
        /kitchen_status: 'preparing',[\s\S]{0,80}payment_status: undefined/,
    );
    assert.match(page, /kitchen_status: 'done', payment_status: undefined/);
    assert.match(page, /payment_status: 'paid', kitchen_status: undefined/);
    assert.match(page, /payment_status: 'pending', kitchen_status: undefined/);
});

test('toolbar uses the approved date choices and one clear action', () => {
    assert.match(page, /\['last_7_days', 'Last 7 days'\]/);
    assert.doesNotMatch(page, /This week/);
    assert.match(page, /function DateFilter/);
    assert.match(page, /onClick=\{clearFilters\}/);
    assert.match(page, /Search order or customer/);
});

test('cards provide direct payment, balance, receipt, and Phase 13 void actions', () => {
    assert.match(page, /\? 'Settle balance'/);
    assert.match(page, /: 'Take payment'/);
    assert.match(page, /detail \? setReceiptOpen\(true\)/);
    assert.match(page, /<PosPaid/);
    assert.match(page, /showNewOrderAction=\{false\}/);
    assert.match(page, /title="Available in Phase 13"/);
    assert.match(page, /<AlertTriangle[^>]*\/> Void/);
});

test('edit preserves take-out tables and resolves paid-order price changes explicitly', () => {
    assert.match(page, /branch_table_id: tableId \|\| null/);
    assert.match(page, /setConfirmLower\(true\)/);
    assert.match(page, /Confirm adjustment/);
    assert.match(page, /if \(settled > 0 && difference < 0\)/);
    assert.match(page, /<BalanceResolutionDialog/);
    assert.match(page, />\s*Pay later\s*</);
    assert.match(page, /> Pay now/);
});

test('null customers never receive a fabricated display label', () => {
    assert.match(page, /return item\.customer_label \|\| item\.table_name \|\| null/);
    assert.doesNotMatch(page, /Walk-in|No customer label/);
});

test('invoice selection and capture create a local preview before confirm uploads', () => {
    assert.match(invoice, /function choosePreview\(file: File\)/);
    assert.match(invoice, /setPreview\(\{ file, url: URL\.createObjectURL\(file\) \}\)/);
    assert.match(invoice, /onClick=\{retry\}/);
    assert.match(invoice, /onClick=\{\(\) => void confirm\(\)\}/);
    assert.match(invoice, /async function confirm\(\)[\s\S]*\.\.\.store\(paymentId\)/);
    assert.doesNotMatch(invoice, /function retry\(\)[\s\S]{0,180}store\(paymentId\)/);
});

test('history view preference is local while transaction state stays authoritative', () => {
    assert.match(page, /localStorage\.getItem\('transaction-history-view'\)/);
    assert.match(page, /useBranchRealtimeRefresh/);
    assert.match(page, /events: \['order\.committed', 'order\.updated'\]/);
});
