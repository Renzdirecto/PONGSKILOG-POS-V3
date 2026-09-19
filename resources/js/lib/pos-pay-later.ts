export type PayLaterAttemptIdentity = {
    order_id: string;
    idempotency_key: string;
};

export function payLaterAttemptForOrder(
    current: PayLaterAttemptIdentity | null,
    orderId: string,
    createUuid: () => string = () => crypto.randomUUID(),
): PayLaterAttemptIdentity {
    if (current?.order_id === orderId) return current;

    return { order_id: orderId, idempotency_key: createUuid() };
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
