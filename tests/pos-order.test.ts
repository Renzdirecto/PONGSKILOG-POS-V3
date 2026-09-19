import assert from 'node:assert/strict';
import { test } from 'node:test';
import {
    orderNumberLabel,
    stockAvailabilityLabel,
} from '../resources/js/lib/pos-order.ts';

test('fresh POS ordering context presents the allocated numeric order number', () => {
    assert.equal(orderNumberLabel('1043'), '#1043');
    assert.equal(orderNumberLabel(null), 'Preparing order…');
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
