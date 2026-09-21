import assert from 'node:assert/strict';
import { test } from 'node:test';
import {
    cents,
    exactCash,
    paymentTotals,
    validPayment,
} from '../resources/js/lib/pos-money.ts';
import {
    cartItemName,
    savedItemName,
} from '../resources/js/lib/pos-item-name.ts';
import type { CartLine, OrderSummary } from '../resources/js/types/pos.ts';

test('cash exact and denominations reconcile the requested 235 peso examples', () => {
    const total = cents('235.00');
    assert.equal(exactCash(total, 0n), '235.00');
    assert.deepEqual(paymentTotals(total, cents(exactCash(total, 0n)), 0n), {
        received: 23500n,
        remaining: 0n,
        change: 0n,
    });
    assert.deepEqual(paymentTotals(total, cents('500.00'), 0n), {
        received: 50000n,
        remaining: 0n,
        change: 26500n,
    });
    assert.deepEqual(paymentTotals(total, cents('100.00'), 0n), {
        received: 10000n,
        remaining: 13500n,
        change: 0n,
    });
});

test('split exact pays the remainder after cashless without negative cash', () => {
    assert.equal(exactCash(50000n, 20000n), '300.00');
    assert.deepEqual(
        paymentTotals(50000n, cents(exactCash(50000n, 20000n)), 20000n),
        {
            received: 50000n,
            remaining: 0n,
            change: 0n,
        },
    );
    assert.equal(exactCash(50000n, 50000n), '0.00');
    assert.equal(exactCash(50000n, 60000n), '0.00');
});

test('fractional and large amounts retain exact cents without floating point drift', () => {
    assert.equal(exactCash(cents('0.30'), cents('0.10')), '0.20');
    assert.deepEqual(
        paymentTotals(cents('0.30'), cents('0.20'), cents('0.10')),
        {
            received: 30n,
            remaining: 0n,
            change: 0n,
        },
    );
    assert.equal(
        exactCash(cents('999999999999.99'), cents('0.01')),
        '999999999999.98',
    );
    assert.deepEqual(paymentTotals(cents('235.01'), cents('500.00'), 0n), {
        received: 50000n,
        remaining: 0n,
        change: 26499n,
    });
});

test('confirmation validates exact tender, split boundaries, and zero-total payments', () => {
    assert.equal(validPayment(23500n, 'cash', '235.00', ''), true);
    assert.equal(validPayment(23500n, 'cash', '100.00', ''), false);
    assert.equal(validPayment(23500n, 'cashless', '', ''), true);
    assert.equal(validPayment(50000n, 'split', '500.00', '200.00'), true);
    assert.equal(validPayment(50000n, 'split', '299.99', '200.00'), false);
    for (const portion of ['0', '500', '501', '', '1e2']) assert.equal(validPayment(50000n, 'split', '500', portion), false);
    for (const cash of ['', '.', '1.001', '-1', '1000000000000']) assert.equal(validPayment(0n, 'cash', cash, ''), false);
    assert.equal(validPayment(0n, 'cash', '0.00', ''), true);
    assert.equal(validPayment(0n, 'cashless', '', ''), true);
    assert.equal(validPayment(0n, 'split', '0', '0'), false);
});

test('only an explicit size group prefixes the canonical product name', () => {
    const line = {
        key: 'yakult-small',
        quantity: 1,
        notes: '',
        modifiers: [
            { group_id: 'size', option_id: 'small' },
            { group_id: 'extras', option_id: 'pearls' },
        ],
        product: {
            id: 'yakult',
            name: 'Yakult',
            category_id: 'drinks',
            category_name: 'Drinks',
            effective_price: '55.00',
            is_available: true,
            stock_status: 'not_tracked',
            tracks_inventory: false,
            on_hand: null,
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
                            id: 'small',
                            name: 'Small',
                            price_delta: '0.00',
                            sort_order: 0,
                        },
                    ],
                },
                {
                    id: 'extras',
                    name: 'Extras',
                    semantic_role: null,
                    selection_type: 'multiple',
                    min_select: 0,
                    max_select: 2,
                    options: [
                        {
                            id: 'pearls',
                            name: 'Pearls',
                            price_delta: '10.00',
                            sort_order: 0,
                        },
                    ],
                },
            ],
        },
    } satisfies CartLine;

    assert.deepEqual(cartItemName(line), {
        name: 'Yakult',
        sizePrefix: 'Small',
        displayName: 'Small Yakult',
    });

    const saved = {
        name: 'Yakult',
        size_prefix: 'Small',
        display_name: 'Small Yakult',
    } as OrderSummary['items'][number];
    assert.equal(savedItemName(saved).displayName, 'Small Yakult');

    line.product.modifier_groups[0].semantic_role = null;
    assert.equal(cartItemName(line).displayName, 'Yakult');
});
