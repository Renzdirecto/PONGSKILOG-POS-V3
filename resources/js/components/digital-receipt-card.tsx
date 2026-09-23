import type { Ref } from 'react';
import { pesos } from '@/lib/pos-money';
import type { QrOrder, PublicReceipt } from '@/types/qr';
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

export function DigitalReceiptCard({
    receipt,
    ref,
}: {
    receipt: PublicReceipt;
    ref?: Ref<HTMLDivElement>;
}) {
    return (
        <div
            ref={ref}
            className="rounded-2xl border border-neutral-200 bg-white p-5"
        >
            <div className="text-center">
                {receipt.branch.show_logo !== false && (
                    <img
                        src={
                            receipt.branch.logo_url ??
                            '/images/branding/logo.png'
                        }
                        alt="Pongskilog"
                        className="mx-auto mb-3 h-10"
                    />
                )}
                <h2 className="font-bold">{receipt.branch.name}</h2>
                <p className="mt-1 text-[11px] text-neutral-500">
                    {receipt.branch.address}
                </p>
                {receipt.branch.contact && (
                    <p className="mt-1 text-[11px] text-neutral-500">
                        {receipt.branch.contact}
                    </p>
                )}
                <p className="mt-4 text-3xl font-bold">
                    #{receipt.order_number}
                </p>
                <p className="mt-2 text-xs text-neutral-500">
                    REF: {receipt.reference_number}
                </p>
                <span className={`mt-3 inline-block rounded-full px-3 py-1 text-xs font-bold ${receipt.commercial_status === 'voided' ? 'bg-red-50 text-red-800' : 'bg-green-50 text-green-700'}`}>
                    {receipt.commercial_status === 'voided' ? 'VOIDED' : 'PAID'}
                </span>
            </div>
            <dl className="my-5 grid grid-cols-2 gap-2 text-xs">
                <dt>Date</dt>
                <dd className="text-right">
                    {receipt.paid_at &&
                        new Date(receipt.paid_at).toLocaleString()}
                </dd>
                <dt>Order type</dt>
                <dd className="text-right">
                    {receipt.order_type === 'dine_in' ? 'Dine in' : 'Take out'}
                </dd>
                {receipt.customer_label && (
                    <>
                        <dt>Name</dt>
                        <dd className="text-right">{receipt.customer_label}</dd>
                    </>
                )}
            </dl>
            <QrItems order={receipt} />
            <div className="mt-4 space-y-2 text-xs">
                {receipt.payments.map((payment, index) => (
                    <div key={index}>
                        <div className="flex justify-between">
                            <span>
                                {payment.method === 'cash'
                                    ? 'Cash'
                                    : 'Cashless'}
                            </span>
                            <span>{pesos(payment.amount)}</span>
                        </div>
                        {payment.amount_received && (
                            <>
                                <div className="mt-1 flex justify-between text-neutral-500">
                                    <span>Amount received</span>
                                    <span>
                                        {pesos(payment.amount_received)}
                                    </span>
                                </div>
                                <div className="flex justify-between text-neutral-500">
                                    <span>Change</span>
                                    <span>
                                        {pesos(payment.change_amount ?? '0.00')}
                                    </span>
                                </div>
                            </>
                        )}
                    </div>
                ))}
            </div>
            <p className="mt-5 text-center text-[11px] text-neutral-500">
                {receipt.branch.footer || 'Salamat sa pag-order sa Pongskilog!'}
            </p>
        </div>
    );
}
