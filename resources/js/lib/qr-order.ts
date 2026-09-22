import type { QrLine, QrOrder, QrReceipt } from '../types/qr';
import { cents, pesos } from './pos-money';
export function qrLineCents(line: QrLine): bigint {
    return (
        (line.product.modifier_groups ?? []).reduce(
            (sum, group) =>
                sum +
                (group.semantic_role === 'instruction'
                    ? 0n
                    : group.options
                          .filter((option) =>
                              line.modifiers.some(
                                  (selected) =>
                                      selected.group_id === group.id &&
                                      selected.option_id === option.id,
                              ),
                          )
                          .reduce(
                              (total, option) =>
                                  total + cents(option.price_delta),
                              0n,
                          )),
            cents(line.product.effective_price),
        ) * BigInt(line.quantity)
    );
}
export function qrStatus(order: QrOrder): string {
    if (order.commercial_status === 'archived_unclaimed')
        return 'Archived / Unclaimed';
    return (
        (
            {
                kitchen: 'In kitchen',
                preparing: 'Preparing',
                ready: 'Ready for pickup',
                done: 'Completed',
            } as Record<string, string>
        )[order.kitchen_status] ??
        (order.payment_status === 'paid'
            ? 'Payment confirmed'
            : 'Waiting for payment')
    );
}
export function canStartQrOrder(order: QrOrder): boolean {
    return (
        order.kitchen_status === 'done' ||
        order.commercial_status === 'archived_unclaimed'
    );
}
export function receiptText(receipt: QrReceipt): string {
    return [
        'PONGSKILOG',
        receipt.branch.name,
        '',
        `Order number : #${receipt.order_number}`,
        `Date : ${receipt.paid_at}`,
        `Order type : ${receipt.order_type === 'dine_in' ? 'Dine in' : 'Take out'}`,
        'PAID',
        '',
        ...receipt.items.flatMap((item) => [
            `${item.quantity}x ${item.display_name ?? item.name}  ${pesos(item.line_total)}`,
            ...item.modifiers
                .filter((mod) => mod.semantic_role !== 'size')
                .map(
                    (mod) =>
                        `   ${mod.semantic_role === 'instruction' ? 'Instructions: ' : ''}${mod.name}`,
                ),
            ...(item.notes ? [`   Note: ${item.notes}`] : []),
        ]),
        '',
        `TOTAL : ${pesos(receipt.total)}`,
        ...receipt.payments.flatMap((payment) => [
            `${payment.method === 'cash' ? 'Cash' : 'Cashless'} : ${pesos(payment.amount)}`,
            ...(payment.amount_received
                ? [
                      `Received : ${pesos(payment.amount_received)}`,
                      `Change : ${pesos(payment.change_amount ?? '0.00')}`,
                  ]
                : []),
        ]),
        '',
        'Salamat sa pag-order sa Pongskilog!',
    ].join('\n');
}

export function mergeQrLine(cart: QrLine[], line: QrLine): QrLine[] {
    const selection = (row: QrLine) =>
        row.modifiers
            .map((mod) => `${mod.group_id}:${mod.option_id}`)
            .sort()
            .join('|');
    const existing = cart.find(
        (row) =>
            row.product.id === line.product.id &&
            row.notes.trim() === line.notes.trim() &&
            selection(row) === selection(line),
    );
    return existing && existing.quantity + line.quantity <= 999
        ? cart.map((row) =>
              row.key === existing.key
                  ? { ...row, quantity: row.quantity + line.quantity }
                  : row,
          )
        : [...cart, line];
}

export function qrIdentity(order: {
    order_number: string | null;
    qr_number?: string | null;
}): string {
    return order.order_number
        ? `#${order.order_number}`
        : (order.qr_number ?? 'QR order');
}
export function qrElapsed(submittedAt: string, now: number): string {
    const seconds = Math.max(
        0,
        Math.floor((now - new Date(submittedAt).getTime()) / 1000),
    );
    return `${Math.floor(seconds / 60)}m ${seconds % 60}s`;
}
