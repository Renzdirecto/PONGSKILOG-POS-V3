import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import ts from 'typescript';

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
    /** Choose image / Replace open the library; only Take photo (or its fallback) asks for the camera. */
    const pickers = [...invoice.matchAll(/<input\s+ref=\{(\w+)\}[\s\S]*?\/>/g)];
    assert.deepEqual(
        pickers.map(([markup, ref]) => [ref, markup.includes('capture=')]),
        [
            ['input', false],
            ['photoInput', true],
        ],
    );
    assert.match(invoice, /onClick=\{\(\) => input\.current\?\.click\(\)\}[\s\S]*Replace/);
    assert.match(invoice, /getUserMedia\) \{\s*photoInput\.current\?\.click\(\);/);
    assert.match(invoice, /const problem = invoiceFileProblem\(file\);/);
    assert.match(invoice, /Choose a JPG, PNG or WebP photo of the invoice\./);
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

test('a view-only business viewer gets no cashier pay, void or edit controls', () => {
    assert.match(page, /readOnly=\{!operational\}/);
    assert.match(page, /\{!readOnly && !isVoided && Number\(item\.outstanding\) > 0 && \(/);
    assert.match(page, /\{!readOnly && \(\s+<>\s+<button[\s\S]+?Void[\s\S]+?Edit/);
    assert.match(page, /\{detail\.operational !== false && \(\s+<>/);
    assert.match(page, /try \{\s+return localStorage\.getItem\('transaction-history-view'\)/);
});

test('a successful edit returns to the list while settlement still returns to refreshed details', () => {
    /** Edit clears selection and invalidates a detail refresh already in flight. */
    assert.match(
        page,
        /onSaved=\{\(detail\) => \{\s*detailRequest\.current \+= 1;\s*setSelected\(null\);\s*setEditing\(false\);\s*refresh\(\);/,
    );
    /** Settlement re-reads the transaction before showing it again. */
    assert.match(
        page,
        /await http\.getClient\(\)\.request\(\{\s*\.\.\.show\(detail\.id\),[\s\S]{0,300}onPaid\(/,
    );
    assert.match(
        page,
        /onPaid=\{\(detail\) => \{\s*setSelected\(detail\);\s*setSettling\(false\);\s*setResolution\(null\);\s*refresh\(\);/,
    );
    /** Deferring an edited balance also returns to the list. */
    assert.match(
        page,
        /onLater=\{\(\) => \{\s*setResolution\(null\);\s*setSelected\(null\);/,
    );
    assert.match(
        page,
        /if \(request !== detailRequest\.current\) return null;/,
    );
    /** The details dialog is what opens once no action dialog is in the way. */
    assert.match(
        page,
        /open=\{\s*selected !== null &&\s*!editing &&\s*!settling &&\s*!voiding &&\s*!receiptOpen &&\s*resolution === null\s*\}/,
    );
});

test('History requests the current edit catalog only when opening Edit', () => {
    assert.match(
        page,
        /async function openEdit\(id: string\)[\s\S]*?loadDetail\(id, false\)[\s\S]*?only: \['catalog'\]/,
    );
    assert.match(page, /onEdit=\{\(\) => void openEdit\(item\.id\)\}/);
    assert.match(
        page,
        /onEdit=\{\(\) => selected && void openEdit\(selected\.id\)\}/,
    );
    assert.match(page, /open=\{editing && editLoading\}/);
    assert.match(page, /selected && editing && catalog && !editLoading/);
    assert.match(page, /if \(select\) setSelected\(detail\)/);
});

function editHarness() {
    const state = {
        editing: false,
        editLoading: false,
        selected: { id: 'stale' } as { id: string } | null,
    };
    const detailRequest = { current: 0 };
    const errors: string[] = [];
    const reloads: {
        only: string[];
        onSuccess: (page: { props: { catalog: unknown } }) => void;
        onFinish: () => void;
    }[] = [];
    let resolve!: (response: { data: string }) => void;
    let reject!: (error: Error) => void;
    const response = new Promise<{ data: string }>((yes, no) => {
        resolve = yes;
        reject = no;
    });
    const handlers = ts.transpileModule(
        page.slice(
            page.indexOf('    async function openEdit('),
            page.indexOf('    const onRealtimeEvent'),
        ) +
            page.slice(
                page.indexOf('    async function loadDetail('),
                page.indexOf('    async function reloadDetail('),
            ),
        {
            compilerOptions: {
                target: ts.ScriptTarget.ESNext,
                module: ts.ModuleKind.None,
            },
        },
    ).outputText;
    const { openEdit } = new Function(
        'dependencies',
        `
        const { setEditing, setEditLoading, setSelected, setLoading, detailRequest, router, http, showRoute, toast } = dependencies;
        ${handlers}
        return { openEdit };
    `,
    )({
        setEditing: (value: boolean) => {
            state.editing = value;
        },
        setEditLoading: (value: boolean) => {
            state.editLoading = value;
        },
        setSelected: (value: { id: string } | null) => {
            state.selected = value;
        },
        setLoading: () => {},
        detailRequest,
        router: {
            reload: (options: (typeof reloads)[number]) =>
                reloads.push(options),
        },
        http: { getClient: () => ({ request: () => response }) },
        showRoute: (id: string) => ({ url: id }),
        toast: { error: (message: string) => errors.push(message) },
    }) as { openEdit: (id: string) => Promise<void> };
    return { state, detailRequest, errors, reloads, resolve, reject, openEdit };
}

test('direct Edit hides stale Details throughout authoritative detail and catalog loading', async () => {
    const ui = editHarness();
    const pending = ui.openEdit('current');
    assert.deepEqual(ui.state, {
        editing: true,
        editLoading: true,
        selected: null,
    });
    assert.equal(ui.reloads.length, 0);

    ui.resolve({ data: JSON.stringify({ transaction: { id: 'current' } }) });
    await pending;
    assert.equal(ui.state.selected, null);
    assert.equal(ui.state.editLoading, true);
    assert.deepEqual(ui.reloads[0].only, ['catalog']);

    ui.reloads[0].onSuccess({ props: { catalog: {} } });
    ui.reloads[0].onFinish();
    assert.deepEqual(ui.state, {
        editing: true,
        editLoading: false,
        selected: { id: 'current' },
    });
    assert.deepEqual(ui.errors, []);
});

test('detail or catalog failure returns to the list without showing Details', async () => {
    const detailFailure = editHarness();
    const first = detailFailure.openEdit('current');
    detailFailure.reject(new Error('offline'));
    await first;
    assert.deepEqual(detailFailure.state, {
        editing: false,
        editLoading: false,
        selected: null,
    });
    assert.equal(detailFailure.reloads.length, 0);
    assert.equal(detailFailure.errors.length, 1);

    const catalogFailure = editHarness();
    const second = catalogFailure.openEdit('current');
    catalogFailure.resolve({
        data: JSON.stringify({ transaction: { id: 'current' } }),
    });
    await second;
    catalogFailure.reloads[0].onFinish();
    assert.deepEqual(catalogFailure.state, {
        editing: false,
        editLoading: false,
        selected: null,
    });
    assert.equal(catalogFailure.errors.length, 1);

    const catalogUnavailable = editHarness();
    const third = catalogUnavailable.openEdit('current');
    catalogUnavailable.resolve({
        data: JSON.stringify({ transaction: { id: 'current' } }),
    });
    await third;
    catalogUnavailable.reloads[0].onSuccess({ props: { catalog: null } });
    catalogUnavailable.reloads[0].onFinish();
    assert.deepEqual(catalogUnavailable.state, {
        editing: false,
        editLoading: false,
        selected: null,
    });
    assert.equal(catalogUnavailable.errors.length, 1);
});

for (const stage of ['detail', 'catalog']) {
    test(`cancelling Edit during ${stage} loading ignores the late response`, async () => {
        const ui = editHarness();
        const pending = ui.openEdit('current');
        if (stage === 'catalog') {
            ui.resolve({
                data: JSON.stringify({ transaction: { id: 'current' } }),
            });
            await pending;
        }
        ui.detailRequest.current += 1;
        ui.state.editing = false;
        ui.state.editLoading = false;
        if (stage === 'detail') {
            ui.resolve({
                data: JSON.stringify({ transaction: { id: 'current' } }),
            });
            await pending;
            assert.equal(ui.reloads.length, 0);
        } else {
            ui.reloads[0].onSuccess({ props: { catalog: {} } });
            ui.reloads[0].onFinish();
        }
        assert.equal(ui.state.selected, null);
        assert.equal(ui.state.editing, false);
    });
}
