import assert from 'node:assert/strict';
import { registerHooks } from 'node:module';
import { test } from 'node:test';
import type { QrLine, QrOrder, QrReceipt } from '../resources/js/types/qr.ts';

// Node's native TypeScript runner requires explicit extensions; Vite resolves these in the app.
registerHooks({
    resolve(specifier, context, nextResolve) {
        return nextResolve(
            specifier === './pos-money' &&
                context.parentURL?.endsWith('/qr-order.ts')
                ? './pos-money.ts'
                : specifier,
            context,
        );
    },
});
const { qrLineCents, qrStatus, canStartQrOrder, receiptText, mergeQrLine } =
    await import('../resources/js/lib/qr-order.ts');

const line: QrLine = {
    key: 'first',
    quantity: 2,
    notes: 'Less salt',
    modifiers: [
        { group_id: 'size', option_id: 'large' },
        { group_id: 'egg', option_id: 'scrambled' },
    ],
    product: {
        id: 'silog',
        name: 'Tapsilog',
        category_id: 'meals',
        category_name: 'Meals',
        effective_price: '95.10',
        is_available: true,
        stock_status: 'available',
        image_url: null,
        has_modifiers: true,
        modifier_groups: [
            {
                id: 'size',
                name: 'Size',
                semantic_role: 'size',
                selection_type: 'single',
                min_select: 1,
                max_select: 1,
                options: [
                    {
                        id: 'large',
                        name: 'Large',
                        price_delta: '10.25',
                        sort_order: 0,
                    },
                ],
            },
            {
                id: 'egg',
                name: 'Egg',
                semantic_role: 'instruction',
                selection_type: 'single',
                min_select: 1,
                max_select: 1,
                options: [
                    {
                        id: 'scrambled',
                        name: 'Scrambled',
                        price_delta: '0.00',
                        sort_order: 0,
                    },
                ],
            },
        ],
    },
};
test('customer customization uses exact cents and never charges instruction choices', () => {
    assert.equal(qrLineCents(line), 21070n);
    const invalidCatalog = structuredClone(line);
    invalidCatalog.product.modifier_groups![1].options[0].price_delta =
        '123.45';
    assert.equal(qrLineCents(invalidCatalog), 21070n);
});
test('matching customizations merge quantities while different notes and options stay separate', () => {
    const same = {
        ...line,
        key: 'second',
        quantity: 1,
        modifiers: [...line.modifiers].reverse(),
    };
    assert.equal(mergeQrLine([line], same).length, 1);
    assert.equal(mergeQrLine([line], same)[0].quantity, 3);
    assert.equal(mergeQrLine([line], { ...same, notes: 'No salt' }).length, 2);
    assert.equal(mergeQrLine([line], { ...same, modifiers: [] }).length, 2);
    assert.equal(line.quantity, 2);
});
test('Pay Later tracks kitchen progress without falsely claiming payment or permitting early reset', () => {
    const order = {
        commercial_status: 'active',
        payment_status: 'unpaid',
        payment_term: 'pay_later',
        kitchen_status: 'preparing',
    } as QrOrder;
    assert.equal(qrStatus(order), 'Preparing');
    assert.equal(canStartQrOrder(order), false);
    assert.equal(
        qrStatus({ ...order, kitchen_status: 'not_sent' }),
        'Waiting for payment',
    );
    assert.equal(canStartQrOrder({ ...order, kitchen_status: 'done' }), true);
    assert.equal(
        canStartQrOrder({ ...order, commercial_status: 'archived_unclaimed' }),
        true,
    );
    assert.equal(
        qrStatus({ ...order, commercial_status: 'archived_unclaimed' }),
        'Archived / Unclaimed',
    );
});
test('receipt download contains persisted line names instructions and tender change', () => {
    const receipt = {
        branch: { name: 'Main' },
        order_number: '1048',
        paid_at: '2026-09-22T08:00:00Z',
        order_type: 'take_out',
        total: '190.00',
        items: [
            {
                name: 'Tapsilog',
                display_name: 'Large Tapsilog',
                quantity: 2,
                line_total: '190.00',
                notes: 'Less salt',
                modifiers: [
                    { name: 'Large', semantic_role: 'size' },
                    { name: 'Scrambled', semantic_role: 'instruction' },
                ],
            },
        ],
        payments: [
            {
                method: 'cash',
                amount: '190.00',
                amount_received: '200.00',
                change_amount: '10.00',
            },
        ],
    } as QrReceipt;
    const text = receiptText(receipt);
    assert.match(text, /Order number : #1048/);
    assert.match(text, /2x Large Tapsilog/);
    assert.match(text, /Instructions: Scrambled/);
    assert.match(text, /Note: Less salt/);
    assert.match(text, /Change : ₱10.00/);
    assert.doesNotMatch(text, /cashier|idempotency|token_hash/);
});
