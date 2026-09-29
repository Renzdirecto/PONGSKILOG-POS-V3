import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { registerHooks } from 'node:module';
import { test } from 'node:test';
import type { CanonicalReceipt, ReceiptLayoutSettings } from '../resources/js/types/receipt.ts';

// Node's native TypeScript runner requires explicit extensions; Vite resolves these in the app.
registerHooks({
    resolve(specifier, context, nextResolve) {
        return nextResolve(
            specifier === './pos-money' && context.parentURL?.endsWith('/receipt.ts')
                ? './pos-money.ts'
                : specifier,
            context,
        );
    },
});
const receipt = await import('../resources/js/lib/receipt.ts');

const source = (path: string) => readFileSync(new URL(`../resources/js/${path}`, import.meta.url), 'utf8');

const defaults: ReceiptLayoutSettings = {
    hidden: ['order_qr'],
    details: ['order', 'date', 'cashier', 'customer'],
    footer: ['custom_rows', 'footer', 'order_qr'],
    header_text: null,
    custom_rows: [],
    separator: 'dashed',
};

const branch = {
    name: 'Main',
    code: 'MAIN',
    address: '12 Rizal St.',
    contact: '0917 000 0000',
    qr_ordering_enabled: true,
    qr_url: 'https://pos.test/kiosk/MAIN',
    qr_image: 'data:image/svg+xml;base64,AAAA',
};

const draft = {
    receipt_name: '',
    receipt_address: '',
    receipt_contact: '',
    receipt_footer: '',
    receipt_show_logo: true,
};

test('receipt blocks mirror ReceiptLayout: fixed zones, configured order, required blocks never hidden', () => {
    assert.deepEqual(receipt.receiptLayoutBlocks(defaults, true), [
        'logo', 'store', 'address', 'contact', 'header_text',
        'order', 'date', 'cashier', 'customer',
        'items', 'totals', 'payments',
        'custom_rows', 'footer',
    ]);
    const custom: ReceiptLayoutSettings = {
        ...defaults,
        hidden: ['address', 'cashier', 'payments', 'store', 'items'] as ReceiptLayoutSettings['hidden'],
        details: ['date', 'order', 'customer', 'cashier'],
        footer: ['order_qr', 'footer', 'custom_rows'],
    };
    assert.deepEqual(receipt.receiptLayoutBlocks(custom, false), [
        'store', 'contact', 'header_text',
        'date', 'order', 'customer',
        'items', 'totals',
        'order_qr', 'footer', 'custom_rows',
    ]);
    assert.deepEqual(
        receipt.receiptSections(['store', 'address', 'order', 'date', 'items', 'totals', 'payments', 'footer']).map(
            (section: { zone: string; blocks: string[] }) => `${section.zone}:${section.blocks.join('+')}`,
        ),
        ['header:store+address', 'details:order+date', 'body:items', 'body:totals', 'body:payments', 'footer:footer'],
    );
});

test('receipt lines come from the saved snapshot: size prefix, priced add-ons, instructions then the note', () => {
    const item = {
        name: 'Tapsilog',
        quantity: 2,
        unit_price: '95.00',
        line_total: '190.00',
        notes: 'Less salt',
        modifiers: [
            { group_name: 'Size', semantic_role: 'size' as const, name: 'Large', price_delta: '0.00', quantity: 1 },
            { group_name: 'Add-ons', semantic_role: null, name: 'Extra egg', price_delta: '15.00', quantity: 1 },
            { group_name: 'Egg', semantic_role: null, name: 'Scrambled', price_delta: '0.00', quantity: 1 },
            { group_name: 'Notes', semantic_role: 'instruction' as const, name: 'No onions', price_delta: '0.00', quantity: 1 },
        ],
    };
    assert.deepEqual(receipt.receiptAddOns(item), ['Extra egg (+₱15.00)', 'Scrambled']);
    assert.equal(receipt.receiptInstructions(item), 'No onions, Less salt');
    assert.equal(receipt.receiptInstructions({ modifiers: [], notes: '  ' }), '');
});

test('every payment of the order prints, with cash received and change, and balances only when owed', () => {
    const single = [{ method: 'cash' as const, amount: '190.00', amount_received: '200.00', change_amount: '10.00' }];
    assert.equal(receipt.receiptPaymentMethod(single), 'Cash');
    assert.deepEqual(receipt.receiptPaymentRows(single), [
        { label: 'Amount received', value: '₱200.00' },
        { label: 'Change', value: '₱10.00', strong: true },
    ]);
    const edited = [
        { method: 'cashless' as const, amount: '100.00', amount_received: null, change_amount: null },
        { method: 'cash' as const, amount: '90.00', amount_received: '100.00', change_amount: '10.00' },
    ];
    assert.equal(receipt.receiptPaymentMethod(edited), 'Split · Cash + Cashless');
    assert.deepEqual(
        receipt.receiptPaymentRows(edited).map((row: { label: string }) => row.label),
        ['Cashless', 'Cash', 'Amount received', 'Change'],
    );
    assert.equal(receipt.receiptPaymentMethod([]), 'Not paid yet');
    assert.deepEqual(
        receipt.receiptBalanceRows({
            money: { paid: '100.00', refunded: '0.00', balance: '90.00' },
            payment_status: 'partial',
            commercial_status: 'active',
        }),
        [
            { label: 'Paid', value: '₱100.00' },
            { label: 'Balance due', value: '₱90.00', strong: true },
        ],
    );
    assert.deepEqual(
        receipt.receiptBalanceRows({
            money: { paid: '190.00', refunded: '0.00', balance: '0.00' },
            payment_status: 'paid',
            commercial_status: 'completed',
        }),
        [],
    );
});

test('status, order type, customer and Manila date/time are stated once and in literal text', () => {
    assert.deepEqual(receipt.receiptStatus({ commercial_status: 'voided', payment_status: 'paid' }), { label: 'VOIDED', tone: 'voided' });
    assert.deepEqual(receipt.receiptStatus({ commercial_status: 'completed', payment_status: 'partial' }), { label: 'BALANCE DUE', tone: 'due' });
    assert.equal(receipt.receiptOrderType('take_out'), 'Take out');
    assert.equal(receipt.receiptCustomer({ customer_label: 'Juan', table_name: 'Juan' }), 'Juan');
    assert.equal(receipt.receiptCustomer({ customer_label: 'Juan', table_name: 'Table 4' }), 'Juan / Table 4');
    assert.equal(receipt.receiptCustomer({ customer_label: null, table_name: null }), '');
    const paid = receipt.receiptDateTime({ paid_at: '2026-09-07T12:32:00Z', committed_at: '2026-09-07T12:00:00Z' });
    assert.match(paid, /Sep 7, 2026/);
    assert.match(paid, /8:32/);
    assert.match(receipt.receiptDateTime({ paid_at: null, committed_at: '2026-09-07T12:00:00Z' }), /8:00/);
});

test('the Settings preview resolves blank store texts to the Branch details and hides what the layout hides', () => {
    const sample: CanonicalReceipt = receipt.receiptPreview(branch, draft, defaults, '/images/branding/logo.png');
    assert.equal(sample.branch.name, 'Main');
    assert.equal(sample.branch.address, '12 Rizal St.');
    assert.equal(sample.branch.footer, null);
    assert.equal(sample.layout.order_qr, null);
    assert.equal(sample.cashier, 'Ana');

    const custom = receipt.receiptPreview(
        branch,
        { ...draft, receipt_name: ' Pongskilog Main ', receipt_footer: 'Thanks!' },
        {
            ...defaults,
            hidden: ['address', 'cashier'],
            header_text: '  Open daily  ',
            custom_rows: ['Wi-Fi: pongskilog', ' '],
            separator: 'none',
        },
        '/logo.webp',
    );
    assert.equal(custom.branch.name, 'Pongskilog Main');
    assert.equal(custom.branch.address, null);
    assert.equal(custom.cashier, null);
    assert.equal(custom.branch.footer, 'Thanks!');
    assert.equal(custom.layout.header_text, 'Open daily');
    assert.deepEqual(custom.layout.custom_rows, ['Wi-Fi: pongskilog']);
    assert.deepEqual(custom.layout.order_qr, { url: branch.qr_url, image: branch.qr_image });
    assert.equal(custom.layout.separator, 'none');
    assert.ok(!custom.layout.blocks.includes('address'));

    assert.equal(
        receipt.receiptPreview({ ...branch, qr_ordering_enabled: false }, draft, { ...defaults, hidden: [] }, '/l.png').layout.order_qr,
        null,
    );
    assert.deepEqual(receipt.receiptLayoutPayload({ ...defaults, header_text: '  ', custom_rows: [' a ', ''] }), {
        ...defaults,
        header_text: null,
        custom_rows: ['a'],
    });
    assert.deepEqual(receipt.moveReceiptBlock(['order', 'date', 'cashier'], 'date', -1), ['date', 'order', 'cashier']);
    assert.deepEqual(receipt.moveReceiptBlock(['order', 'date'], 'order', -1), ['order', 'date']);
});

test('every receipt surface renders the one ReceiptDocument; no surface keeps its own receipt markup', () => {
    const paid = source('components/pos-paid.tsx');
    assert.match(paid, /<ReceiptDocument receipt=\{receipt\} variant="paper" \/>/);
    assert.doesNotMatch(paid, /PONGSKILOG|label="Invoice"|Not attached/);
    const card = source('components/digital-receipt-card.tsx');
    assert.match(card, /<ReceiptDocument receipt=\{receipt\} ref=\{ref\} variant="card" \/>/);
    assert.doesNotMatch(card, /toLocaleString|Salamat/);
    assert.match(source('pages/public-receipt.tsx'), /<DigitalReceiptCard receipt=\{receipt\} ref=\{card\} \/>/);
    assert.match(source('pages/pickup.tsx'), /<DigitalReceiptCard receipt=\{receipt\} \/>/);
    assert.match(source('components/customer-qr-tracking.tsx'), /<DigitalReceiptCard[\s\S]*?receipt=\{receipt\}/);
    const settings = source('components/receipt-settings.tsx');
    assert.match(settings, /<ReceiptDocument receipt=\{sample\} variant="paper" \/>/);
    assert.doesNotMatch(settings, /branch\.receipt_name \?\? branch\.name/);
    assert.doesNotMatch(source('pages/branches/index.tsx'), /Receipt preview · Sample|function ReceiptSettings/);
    assert.doesNotMatch(source('lib/qr-order.ts'), /receiptText/);

    const documentSource = source('components/receipt-document.tsx');
    assert.match(documentSource, /theme-static/);
    assert.doesNotMatch(documentSource, /\buppercase\b|dangerouslySetInnerHTML/);

    const css = readFileSync(new URL('../resources/css/app.css', import.meta.url), 'utf8');
    assert.match(css, /@page pos-receipt \{/);
    assert.doesNotMatch(css, /@page \{/);
});
