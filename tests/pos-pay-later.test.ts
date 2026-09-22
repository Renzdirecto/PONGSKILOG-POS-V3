import assert from 'node:assert/strict';
import { registerHooks } from 'node:module';
import { test } from 'node:test';

registerHooks({
    resolve(specifier, context, nextResolve) {
        return nextResolve(
            specifier === './client-uuid' && context.parentURL?.endsWith('/pos-pay-later.ts')
                ? './client-uuid.ts'
                : specifier,
            context,
        );
    },
});
const { confirmedPayLaterState, payLaterAttemptForOrder } =
    await import('../resources/js/lib/pos-pay-later.ts');

test('Pay Later retries keep one stable key and local-cart payload', () => {
    const details = {
        order_id: 'order-1043',
        order_type: 'take_out' as const,
        customer_label: 'Alex',
        branch_table_id: null,
        items: [
            {
                product_id: 'product-1',
                quantity: 2,
                notes: '',
                modifiers: [],
            },
        ],
    };
    const first = payLaterAttemptForOrder(null, details, () => 'key-1');
    const retry = payLaterAttemptForOrder(
        first,
        { ...details, customer_label: 'Changed after submit' },
        () => 'key-2',
    );
    const other = payLaterAttemptForOrder(
        first,
        { order_id: 'order-1044' },
        () => 'key-3',
    );

    assert.equal(retry, first);
    assert.equal(retry.customer_label, 'Alex');
    assert.deepEqual(other, {
        order_id: 'order-1044',
        idempotency_key: 'key-3',
    });
});

test('Pay Later creates and retains its retry key on LAN HTTP without randomUUID', (context) => {
    context.mock.getter(globalThis, 'crypto', () => ({
        getRandomValues: (bytes: Uint8Array) => bytes.fill(7),
    }));
    const first = payLaterAttemptForOrder(null, { order_id: 'order-1043' });
    assert.equal(first.idempotency_key, '07070707-0707-4707-8707-070707070707');
    assert.equal(payLaterAttemptForOrder(first, { order_id: 'order-1043' }), first);
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
