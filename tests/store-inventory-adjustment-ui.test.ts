import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import {
    STOCK_CORRECTION_REASONS,
    sessionActivity,
    stockCorrectionError,
    stockCorrectionPreview,
    stockCorrectionReasons,
} from '../resources/js/lib/store-inventory-adjustment.ts';

const source = (path: string) =>
    readFileSync(new URL(`../resources/js/${path}`, import.meta.url), 'utf8');
const form = source('components/store-inventory-adjustment-form.tsx');
const dialog = source('components/store-session-details-dialog.tsx');
const realtime = source('hooks/use-store-expense-realtime.ts');

test('a decrease preview is integer-only and never goes below zero', () => {
    assert.deepEqual(stockCorrectionPreview(50, 'decrease', '1'), {
        valid: true,
        quantity: 1,
        delta: -1,
        after: 49,
        message: null,
    });
    assert.equal(stockCorrectionPreview(50, 'decrease', '50').after, 0);
    assert.equal(stockCorrectionPreview(50, 'decrease', '51').valid, false);
    assert.match(
        stockCorrectionPreview(50, 'decrease', '51').message ?? '',
        /Only 50 in stock/,
    );
    for (const invalid of ['0', '-1', '1.5', 'abc', '1000001']) {
        assert.equal(
            stockCorrectionPreview(50, 'decrease', invalid).valid,
            false,
        );
    }
    assert.equal(stockCorrectionPreview(null, 'decrease', '1').valid, false);
});

test('an increase preview adds stock, even from zero', () => {
    assert.deepEqual(stockCorrectionPreview(0, 'increase', '4'), {
        valid: true,
        quantity: 4,
        delta: 4,
        after: 4,
        message: null,
    });
    assert.equal(stockCorrectionPreview(50, 'increase', '51').after, 101);
});

test('reason codes are stable, direction-aware and exclude free items', () => {
    assert.deepEqual(
        STOCK_CORRECTION_REASONS.map((reason) => reason.value),
        [
            'physical_count',
            'found_stock',
            'missing_stock',
            'wastage',
            'damaged',
            'other',
        ],
    );
    assert.deepEqual(
        stockCorrectionReasons('increase').map((reason) => reason.value),
        ['physical_count', 'found_stock', 'other'],
    );
    assert.deepEqual(
        stockCorrectionReasons('decrease').map((reason) => reason.value),
        ['physical_count', 'missing_stock', 'wastage', 'damaged', 'other'],
    );
    assert.doesNotMatch(form, /complimentary|staff_meal|Staff meal/i);
    assert.equal(
        stockCorrectionError({
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
        stockCorrectionError({ response: { status: 409 } }),
        /already used/,
    );
});

test('Stock correction lives inside the Store Session dialog and never posts money', () => {
    assert.match(dialog, /Add expense \/ purchase/);
    assert.match(dialog, /Stock correction/);
    assert.doesNotMatch(dialog, /Adjust inventory/);
    assert.match(
        dialog,
        /view === 'adjust' && session && \(\s*<StockCorrectionForm/,
    );
    assert.match(form, /This does not affect Cash or Cashless totals\./);
    assert.match(form, /Record\s+free items as a Giveaway/);
    assert.match(form, /Save stock correction/);
    assert.match(form, /Stock correction recorded\./);
    assert.match(form, /System stock: \{item\.on_hand\}/);
    assert.match(form, /direction,\s*reason_code: reason,/);
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

test('stock corrections join the session history newest-first without money', () => {
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
        /<StockCorrectionRow\s+adjustment=\{entry\.item\}\s+compact/,
    );
    assert.match(form, /Stock only/);
    assert.match(form, /\{increase \? '\+' : '−'\}/);
});
