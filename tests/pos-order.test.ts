import assert from 'node:assert/strict';
import { test } from 'node:test';
import {
    customerDisplayLabel,
    customerLabelAfterTableChange,
    freshOrderDetails,
    needsOrderReservation,
    orderNumberLabel,
    stockAvailabilityLabel,
} from '../resources/js/lib/pos-order.ts';
import { lineCents } from '../resources/js/lib/pos-money.ts';

test('a new order starts without remembered customer or table details', () => {
    assert.deepEqual(freshOrderDetails(), {
        order_type: '',
        branch_table_id: '',
        customer_label: '',
        items: [],
    });
});

test('fresh POS ordering context presents the allocated numeric order number', () => {
    assert.equal(orderNumberLabel('1043'), '#1043');
    assert.equal(orderNumberLabel(null), 'Preparing order…');
});

test('a selected table fills the customer label and removes duplicate display text', () => {
    const tables = [
        { id: 'table-1', name: 'Table 1' },
        { id: 'table-2', name: 'Table 2' },
    ];

    assert.equal(
        customerLabelAfterTableChange(tables, '', '', 'table-1'),
        'Table 1',
    );
    assert.equal(
        customerLabelAfterTableChange(tables, 'table-1', 'Table 1', ''),
        '',
    );
    assert.equal(customerDisplayLabel('Table 1', 'Table 1'), 'Table 1');
    assert.equal(
        customerLabelAfterTableChange(
            tables,
            'table-1',
            'Custom pickup label',
            '',
        ),
        'Custom pickup label',
    );
    assert.equal(
        customerLabelAfterTableChange(
            tables,
            '',
            'Custom pickup label',
            'table-2',
        ),
        'Table 2',
    );
    assert.equal(customerDisplayLabel('', undefined), '');
});

test('a paid order can reserve the next number while its receipt remains open', () => {
    assert.equal(needsOrderReservation('take_out', false, false, false), true);
    assert.equal(needsOrderReservation('take_out', false, true, false), false);
});

test('product customization distinguishes tracked quantities from untracked availability', () => {
    assert.equal(
        stockAvailabilityLabel({
            tracks_inventory: true,
            on_hand: 12,
            stock_status: 'in_stock',
        }),
        'In stock · 12 left',
    );
    assert.equal(
        stockAvailabilityLabel({
            tracks_inventory: true,
            on_hand: 3,
            stock_status: 'low_stock',
        }),
        'Low stock · 3 left',
    );
    assert.equal(
        stockAvailabilityLabel({
            tracks_inventory: true,
            on_hand: 0,
            stock_status: 'out_of_stock',
        }),
        'Out of stock · 0 left',
    );
    assert.equal(
        stockAvailabilityLabel({
            tracks_inventory: false,
            on_hand: null,
            stock_status: 'not_tracked',
        }),
        'Available',
    );
});

test('instruction selections remain price neutral in the client cart', () => {
    assert.equal(
        lineCents({
            key: 'line-1',
            product: {
                id: 'product-1',
                name: 'Bangsilog',
                description: null,
                category_id: 'category-1',
                category_name: 'Meals',
                effective_price: '105.00',
                is_available: true,
                availability_reason: null,
                stock_status: 'not_tracked',
                tracks_inventory: false,
                on_hand: null,
                image_url: null,
                has_modifiers: true,
                modifier_groups: [
                    {
                        id: 'group-1',
                        name: 'Instructions',
                        semantic_role: 'instruction',
                        selection_type: 'multiple',
                        min_select: 0,
                        max_select: 3,
                        options: [
                            {
                                id: 'option-1',
                                name: 'Scramble',
                                price_delta: '99.00',
                                sort_order: 0,
                            },
                        ],
                    },
                ],
            },
            quantity: 2,
            notes: 'No ketchup please',
            modifiers: [{ group_id: 'group-1', option_id: 'option-1' }],
        }),
        21000n,
    );
});
