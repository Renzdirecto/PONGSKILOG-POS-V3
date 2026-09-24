import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import {
    INVENTORY_ADJUSTMENT_REASONS,
    inventoryAdjustmentError,
    inventoryAdjustmentPreview,
    sessionActivity,
} from '../resources/js/lib/store-inventory-adjustment.ts';

const source = (path: string) =>
    readFileSync(new URL(`../resources/js/${path}`, import.meta.url), 'utf8');
const form = source('components/store-inventory-adjustment-form.tsx');
const dialog = source('components/store-session-details-dialog.tsx');
const realtime = source('hooks/use-store-expense-realtime.ts');

test('stock preview is integer-only and never goes below zero', () => {
    assert.deepEqual(inventoryAdjustmentPreview(50, '1'), {
        valid: true,
        quantity: 1,
        after: 49,
        message: null,
    });
    assert.equal(inventoryAdjustmentPreview(50, '50').after, 0);
    assert.equal(inventoryAdjustmentPreview(50, '51').valid, false);
    assert.match(
        inventoryAdjustmentPreview(50, '51').message ?? '',
        /Only 50 in stock/,
    );
    for (const invalid of ['0', '-1', '1.5', 'abc']) {
        assert.equal(inventoryAdjustmentPreview(50, invalid).valid, false);
    }
    assert.equal(inventoryAdjustmentPreview(null, '1').valid, false);
});

test('reason codes are stable and errors stay specific', () => {
    assert.deepEqual(
        INVENTORY_ADJUSTMENT_REASONS.map((reason) => reason.value),
        ['complimentary', 'wastage', 'damaged', 'staff_meal', 'other'],
    );
    assert.equal(
        inventoryAdjustmentError({
            response: {
                status: 422,
                data: JSON.stringify({
                    errors: {
                        quantity: [
                            'The quantity is more than the current stock.',
                        ],
                    },
                }),
            },
        }),
        'The quantity is more than the current stock.',
    );
    assert.match(
        inventoryAdjustmentError({ response: { status: 409 } }),
        /already used/,
    );
});

test('Adjust inventory lives inside the Store Session dialog and never posts money', () => {
    assert.match(dialog, /Add expense \/ purchase/);
    assert.match(dialog, /Adjust inventory/);
    assert.match(
        dialog,
        /view === 'adjust' && session && \(\s*<StoreInventoryAdjustmentForm/,
    );
    assert.match(form, /This does not\s+affect Cash or Cashless totals\./);
    assert.match(form, /Save inventory adjustment/);
    assert.match(form, /Inventory adjustment recorded\./);
    assert.match(form, /Current stock: \{item\.on_hand\}/);
    assert.doesNotMatch(form, /amount|payment_source/);
    assert.match(
        realtime,
        /\['\.inventory\.changed', '\.ingredients\.changed'\]/,
    );
});

test('a discarded session returns session-bound views to the overview load message', () => {
    assert.match(
        dialog,
        /\{\(view === 'overview' \|\|\s*\(view !== 'close' && session === null\)\) && \(/,
    );
});

test('stock adjustments join the session history newest-first without money', () => {
    const activity = sessionActivity(
        [{ id: 'e1', created_at: '2026-09-23T10:00:00+08:00' }],
        [{ id: 'a1', created_at: '2026-09-23T11:00:00+08:00' }],
    );
    assert.deepEqual(
        activity.map((entry) => `${entry.kind}:${entry.item.id}`),
        ['adjustment:a1', 'expense:e1'],
    );
    const flow = source('components/store-close-flow.tsx');
    assert.match(
        dialog,
        /sessionActivity\(session\.expenses, session\.inventory_adjustments, session\.giveaways \?\? \[\]\)/,
    );
    assert.match(
        flow,
        /<InventoryAdjustmentRow\s+adjustment=\{entry\.item\}\s+compact/,
    );
    assert.match(form, /Stock only/);
});
