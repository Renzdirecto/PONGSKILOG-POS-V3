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
    assert.match(page, /router\.get\(listRoute\(\)/);
    assert.match(
        page,
        /const listRoute = business \? businessTransactions : transactionHistory;/,
    );
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
    assert.match(page, /disabled=\{!item\.can_void\}/);
    assert.match(page, /\? 'Void transaction'/);
    assert.match(page, /<AlertTriangle[^>]*\/> Void/);
    assert.match(page, /function VoidDialog/);
    assert.match(page, /reason_code: reasonCode/);
    assert.match(page, /authorization_pin: authorizationPin/);
    assert.match(page, /inputMode="numeric"/);
    assert.match(page, /This permanently marks the transaction as voided/);
    assert.doesNotMatch(page, /authorizationPin\s*=\s*['"]\d{4}['"]/);
    assert.match(page, /bg-green-700[^"]*hover:bg-green-800/);
    assert.match(page, /text-xs leading-\[1\.4\] font-semibold/);
    assert.match(page, /h-5 items-center rounded-full border px-2 text-\[9\.5px\]/);
});

test('edit preserves take-out tables and resolves paid-order price changes explicitly', () => {
    assert.match(page, /sm:max-w-\[760px\]/);
    assert.match(
        page,
        /sm:grid-cols-\[minmax\(0,1fr\)_128px_72px_44px\]/,
    );
    assert.match(page, /aria-label=\{`Edit \$\{line\.product\.name\}`\}/);
    assert.match(page, /branch_table_id: tableId \|\| null/);
    assert.match(page, /setConfirmLower\(true\)/);
    assert.match(page, /Confirm adjustment/);
    assert.match(page, /if \(settled > 0 && difference < 0\)/);
    assert.match(page, /<BalanceResolutionDialog/);
    assert.match(page, />\s*Pay later\s*</);
    assert.match(page, /> Pay now/);
});

test('edit stages kitchen status until Save changes is clicked', () => {
    assert.match(page, /function stageKitchenStatus\(status: KitchenStatus\)/);
    assert.match(page, /setKitchenStatus\(status\)/);
    assert.match(page, /onClick=\{\(\) =>\s*stageKitchenStatus\(/);
    assert.match(
        page,
        /async function persist\(\)[\s\S]*updateKitchenStatus\(detail\.id\)/,
    );
    assert.doesNotMatch(page, /async function transitionKitchen/);
});

test('null customers never receive a fabricated display label', () => {
    const helper = page.match(
        /function truthfulCustomer[\s\S]*?\n}/,
    )?.[0];
    assert.ok(helper);
    assert.match(helper, /return item\.customer_label \|\| item\.table_name \|\| null/);
    assert.doesNotMatch(helper, /Walk-in|No customer label/);
});

test('invoice selection and capture create a local preview before confirm uploads', () => {
    assert.match(invoice, /async function choosePreview\(file: File\)/);
    assert.match(invoice, /await prepareInvoiceImage\(file\)/);
    assert.match(invoice, /MAX_INVOICE_EDGE = 1600/);
    assert.match(invoice, /MAX_INVOICE_UPLOAD_BYTES = 1_500_000/);
    assert.match(invoice, /canvas\.toBlob[\s\S]*'image\/jpeg'/);
    assert.match(invoice, /url: URL\.createObjectURL\(preparedFile\)/);
    assert.match(invoice, /onClick=\{retry\}/);
    assert.match(invoice, /onClick=\{\(\) => void confirm\(\)\}/);
    assert.match(invoice, /async function confirm\(\)[\s\S]*\.\.\.store\(paymentId\)/);
    assert.match(invoice, /toast\.error\(invoiceUploadError\(error\)\)/);
    assert.match(invoice, /const \[viewingInvoice, setViewingInvoice\]/);
    assert.match(invoice, /onClick=\{\(\) => setViewingInvoice\(true\)\}/);
    assert.match(invoice, /Invoice receipt/);
    assert.doesNotMatch(invoice, /target="_blank"/);
    assert.doesNotMatch(invoice, /function retry\(\)[\s\S]{0,180}store\(paymentId\)/);
});

test('history view preference is local while transaction state stays authoritative', () => {
    assert.match(page, /localStorage\.getItem\('transaction-history-view'\)/);
    assert.match(page, /useBranchRealtimeRefresh/);
    for (const event of [
        '.order.committed',
        '.order.updated',
        '.kitchen.ticket_created',
        '.kitchen.status_changed',
    ]) {
        assert.match(page, new RegExp(`'\\${event}'`));
    }
    assert.match(page, /events: HISTORY_REALTIME_EVENTS/);
    assert.match(page, /orderId === selected\.id/);
});

test('action dialogs close without revealing another transaction modal', () => {
    assert.match(
        page,
        /onClose=\{\(\) => \{\s*setEditing\(false\);\s*setSelected\(null\);/,
    );
    assert.match(
        page,
        /onClose=\{\(\) => \{\s*setReceiptOpen\(false\);\s*setSelected\(null\);/,
    );
});

test('history payment reuses the wide POS payment dialog', () => {
    assert.match(page, /className=\{`\$\{posDialogClass\} pos-payment-dialog`\}/);
    assert.match(page, /const idempotencyKey = useMemo\(\(\) => createClientUuid\(\)/);
    assert.match(page, /idempotency_key: idempotencyKey/);
    assert.match(page, /attempt=\{null\}/);
});
