import { Check, Plus, Printer, ReceiptText, ArrowLeft } from 'lucide-react';
import { pesos } from '@/lib/pos-money';
import type { PaidReceipt } from '@/types/pos';

export function PosPaid({
    receipt,
    showReceipt,
    onReceipt,
    onBack,
    onNewOrder,
}: {
    receipt: PaidReceipt;
    showReceipt: boolean;
    onReceipt: () => void;
    onBack: () => void;
    onNewOrder: () => void;
}) {
    const cash = receipt.payments.find((payment) => payment.method === 'cash');
    const method =
        receipt.payments.length === 2
            ? 'Split · Cash + Cashless'
            : cash
              ? 'Cash'
              : 'Cashless';
    const rows = [
        ['Amount', pesos(receipt.total)],
        ['Method', method],
        [
            'Order type',
            receipt.order_type === 'dine_in' ? 'Dine in' : 'Take out',
        ],
        ...(receipt.payments.length === 2
            ? receipt.payments.map((payment) => [
                  payment.method === 'cash' ? 'Cash applied' : 'Cashless',
                  pesos(payment.amount),
              ])
            : []),
        ...(cash
            ? [
                  ['Cash received', pesos(cash.amount_received ?? '0.00')],
                  ['Change', pesos(cash.change_amount ?? '0.00')],
              ]
            : []),
    ];
    return (
        <>
            <div
                className="min-h-0 flex-1 overflow-y-auto px-[18px] pt-[26px] pb-[18px]"
                data-pos-receipt={showReceipt || undefined}
            >
                <div className="flex flex-col items-center gap-2 text-center">
                    {showReceipt ? (
                        <>
                            <p className="text-xl font-bold tracking-tight">
                                PONGSKILOG
                            </p>
                            <p className="text-xs font-semibold wrap-anywhere">
                                {receipt.branch.name}
                            </p>
                            <p className="text-[11px] wrap-anywhere text-neutral-500">
                                {[
                                    receipt.branch.address,
                                    receipt.branch.contact,
                                ]
                                    .filter(Boolean)
                                    .join(' · ')}
                            </p>
                        </>
                    ) : (
                        <span className="mb-0.5 flex h-[50px] w-[58px] items-center justify-center rounded-full border border-green-200 bg-green-50 text-green-700">
                            <Check className="size-7" />
                        </span>
                    )}
                    <p className="text-[40px] leading-none font-bold tracking-tight wrap-anywhere text-red-700">
                        #{receipt.order_number}
                    </p>
                    <span className="flex h-[26px] items-center rounded-full bg-neutral-950 px-[13px] text-[11.5px] font-bold tracking-widest text-white">
                        PAID
                    </span>
                    {(receipt.customer_label || receipt.table_name) && (
                        <p className="text-[13px] font-semibold wrap-anywhere">
                            {[receipt.customer_label, receipt.table_name]
                                .filter(Boolean)
                                .join(' / ')}
                        </p>
                    )}
                </div>
                {showReceipt && (
                    <>
                        <dl className="my-4 space-y-2 text-xs">
                            <div className="flex justify-between gap-3">
                                <dt>Date / time</dt>
                                <dd>
                                    {new Date(receipt.paid_at).toLocaleString(
                                        'en-PH',
                                        { timeZone: 'Asia/Manila' },
                                    )}
                                </dd>
                            </div>
                            <div className="flex justify-between gap-3">
                                <dt>Cashier</dt>
                                <dd className="min-w-0 text-right wrap-anywhere">
                                    {receipt.cashier}
                                </dd>
                            </div>
                        </dl>
                        <div className="border-y border-dashed border-neutral-300 py-2">
                            {receipt.items.map((item) => (
                                <div
                                    key={item.id}
                                    className="flex gap-3 py-2 text-xs"
                                >
                                    <div className="min-w-0 flex-1">
                                        <p className="font-semibold wrap-anywhere">
                                            {item.quantity}× {item.name}
                                        </p>
                                        <p className="text-neutral-500">
                                            {pesos(item.unit_price)} each
                                        </p>
                                        {item.modifiers.map((modifier) => (
                                            <p
                                                key={modifier.id}
                                                className="text-[11px] wrap-anywhere text-neutral-500"
                                            >
                                                {modifier.name} (+
                                                {pesos(modifier.price_delta)})
                                            </p>
                                        ))}
                                        {item.notes && (
                                            <p className="text-[11px] wrap-anywhere whitespace-pre-wrap text-neutral-500">
                                                {item.notes}
                                            </p>
                                        )}
                                    </div>
                                    <p className="font-semibold">
                                        {pesos(item.line_total)}
                                    </p>
                                </div>
                            ))}
                        </div>
                        <div className="my-3 flex justify-between text-xs">
                            <span>Subtotal</span>
                            <span>{pesos(receipt.subtotal)}</span>
                        </div>
                    </>
                )}
                <dl className="mt-4 overflow-hidden rounded-[14px] border border-neutral-200">
                    {rows.map(([label, value]) => (
                        <div
                            key={label}
                            className="flex items-center justify-between gap-3 border-b border-neutral-100 px-3.5 py-[13px] last:border-0"
                        >
                            <dt className="text-xs text-neutral-500">
                                {label}
                            </dt>
                            <dd
                                className={`text-right text-[13px] font-semibold ${label === 'Amount' ? 'text-[15px] font-bold' : ''} ${label === 'Change' ? 'text-[15px] font-bold text-green-700' : ''}`}
                            >
                                {value}
                            </dd>
                        </div>
                    ))}
                </dl>
            </div>
            <div className="flex shrink-0 flex-col gap-2 border-t border-neutral-200 px-3.5 pt-3 pb-3.5 print:hidden">
                <button
                    onClick={onNewOrder}
                    className="flex h-12 items-center justify-center gap-2 rounded-xl bg-neutral-950 text-[15.5px] font-semibold text-white"
                >
                    <Plus className="size-[18px]" />
                    New order
                </button>
                {showReceipt ? (
                    <div className="flex gap-2">
                        <button
                            onClick={onBack}
                            className="flex h-[50px] flex-1 items-center justify-center gap-2 rounded-xl border border-neutral-400 text-[13.5px] font-semibold"
                        >
                            <ArrowLeft className="size-4" />
                            Back
                        </button>
                        <button
                            onClick={() => window.print()}
                            className="flex h-[50px] flex-1 items-center justify-center gap-2 rounded-xl border border-neutral-400 text-[13.5px] font-semibold"
                        >
                            <Printer className="size-4" />
                            Print receipt
                        </button>
                    </div>
                ) : (
                    <button
                        onClick={onReceipt}
                        className="flex h-[50px] items-center justify-center gap-2 rounded-xl border border-neutral-400 text-[13.5px] font-semibold"
                    >
                        <ReceiptText className="size-4" />
                        View receipt
                    </button>
                )}
            </div>
        </>
    );
}
