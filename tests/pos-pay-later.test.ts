import assert from 'node:assert/strict';
import { test } from 'node:test';
import {
    confirmedPayLaterState,
    payLaterAttemptForOrder,
} from '../resources/js/lib/pos-pay-later.ts';

test('Pay Later retries keep one stable key for the same saved order', () => {
    const first = payLaterAttemptForOrder(null, 'order-1043', () => 'key-1');
    const retry = payLaterAttemptForOrder(first, 'order-1043', () => 'key-2');
    const other = payLaterAttemptForOrder(first, 'order-1044', () => 'key-3');

    assert.equal(retry, first);
    assert.deepEqual(other, {
        order_id: 'order-1044',
        idempotency_key: 'key-3',
    });
});

test('Pay Later success requires the complete committed operational state', () => {
    const committed = {
        commercial_status: 'active',
        payment_status: 'unpaid',
        payment_term: 'pay_later',
        kitchen_status: 'kitchen',
        committed_at: '2026-09-20T10:00:00+08:00',
    };

    assert.equal(confirmedPayLaterState(committed), true);
    assert.equal(
        confirmedPayLaterState({ ...committed, committed_at: null }),
        false,
    );
    assert.equal(
        confirmedPayLaterState({ ...committed, payment_status: 'paid' }),
        false,
    );
});
