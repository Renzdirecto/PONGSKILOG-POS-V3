import type { PayLaterAttempt } from '@/types/pos';
import { createClientUuid } from './client-uuid';

export function payLaterAttemptForOrder(
    current: PayLaterAttempt | null,
    details: Omit<PayLaterAttempt, 'idempotency_key'>,
    createUuid: () => string = createClientUuid,
): PayLaterAttempt {
    if (current?.order_id === details.order_id) return current;

    return { ...details, idempotency_key: createUuid() };
}

export function confirmedPayLaterState(order: {
    commercial_status: string;
    payment_status: string;
    payment_term: string | null;
    kitchen_status: string;
    committed_at: string | null;
}): boolean {
    return (
        order.commercial_status === 'active' &&
        order.payment_status === 'unpaid' &&
        order.payment_term === 'pay_later' &&
        order.kitchen_status === 'kitchen' &&
        order.committed_at !== null
    );
}
