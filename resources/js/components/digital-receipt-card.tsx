import type { Ref } from 'react';
import { pesos } from '@/lib/pos-money';
import type { QrOrder } from '@/types/qr';
import type { CanonicalReceipt } from '@/types/receipt';
import { ReceiptDocument } from './receipt-document';
import { qrPanel } from './customer-qr-product';

export function QrItems({
    order,
}: {
    order: Pick<QrOrder, 'items' | 'total'>;
}) {
    return (
        <div className={qrPanel}>
            <div className="divide-y divide-neutral-100">
                {order.items.map((item, index) => (
                    <div key={index} className="py-3 first:pt-0">
                        <div className="flex justify-between gap-3 text-sm">
                            <strong>
                                {item.quantity}×{' '}
                                {item.display_name ?? item.name}
                            </strong>
                            <strong className="shrink-0 text-red-700">
                                {pesos(item.line_total)}
                            </strong>
                        </div>
                        {item.modifiers
                            .filter(
                                (modifier) => modifier.semantic_role !== 'size',
                            )
                            .map((modifier, i) => (
                                <p
                                    key={i}
                                    className="mt-1 text-[11px] text-neutral-500"
                                >
                                    {modifier.semantic_role === 'instruction'
                                        ? 'Instructions: '
                                        : ''}
                                    {modifier.name}
                                </p>
                            ))}
                        {item.notes && (
                            <p className="mt-2 rounded-lg bg-orange-50 p-2 text-[11px] text-orange-900">
                                Note: {item.notes}
                            </p>
                        )}
                    </div>
                ))}
            </div>
            <div className="mt-3 flex justify-between border-t border-neutral-200 pt-3 font-bold">
                <span>Total</span>
                <span className="text-red-700">{pesos(order.total)}</span>
            </div>
        </div>
    );
}

/** The digital receipt of the customer pages (receipt link, Customer QR, Pickup): the canonical ReceiptDocument card. */
export function DigitalReceiptCard({
    receipt,
    ref,
}: {
    receipt: CanonicalReceipt;
    ref?: Ref<HTMLElement>;
}) {
    return <ReceiptDocument receipt={receipt} ref={ref} variant="card" />;
}
