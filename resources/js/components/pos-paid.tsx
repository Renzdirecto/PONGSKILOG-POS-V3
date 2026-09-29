import {
    ArrowLeft,
    Camera,
    Check,
    Plus,
    Printer,
    QrCode,
    ReceiptText,
} from 'lucide-react';
import { useState } from 'react';
import { PosReceiptQr } from './pos-receipt-qr';
import { pesos } from '@/lib/pos-money';
import { ReceiptDocument } from '@/components/receipt-document';
import { TransactionInvoiceDialog } from '@/components/transaction-invoice-dialog';
import { customerDisplayLabel } from '@/lib/pos-order';
import { showsInvoiceProof } from '@/lib/pos-payment-proof';
import type { PaidReceipt, ReceiptSummary } from '@/types/pos';

export function PosPaid({
    receipt,
    showReceipt,
    onReceipt,
    onBack,
    onNewOrder,
    showNewOrderAction = true,
    canShareQr = true,
}: {
    receipt: ReceiptSummary;
    showReceipt: boolean;
    onReceipt: () => void;
    onBack: () => void;
    onNewOrder: () => void;
    showNewOrderAction?: boolean;
    /** Show QR creates a POS receipt share link, so only POS-authorized viewers get it. */
    canShareQr?: boolean;
}) {
    const [showQr, setShowQr] = useState(false);
    const [invoiceOpen, setInvoiceOpen] = useState(false);
    const [savedInvoice, setSavedInvoice] = useState<{
        name: string;
        url: string;
    } | null>(null);
    const firstGroupId =
        receipt.payments[0]?.payment_group_id ??
        receipt.payments[0]?.id ??
        'initial';
    const firstPayments = receipt.payments.filter(
        (payment) =>
            (payment.payment_group_id ?? payment.id) === firstGroupId,
    );
    const cash = firstPayments.find((payment) => payment.method === 'cash');
    const cashless = firstPayments.find(
        (payment) => payment.method === 'cashless',
    );
    const paymentMethod =
        cash && cashless ? 'split' : cash ? 'cash' : cashless ? 'cashless' : null;
    const method = {
        cash: 'Cash',
        cashless: 'Cashless',
        split: 'Split · Cash + Cashless',
    }[paymentMethod ?? 'cash'] ?? 'Pending';
    const customer = customerDisplayLabel(
        receipt.customer_label,
        receipt.table_name,
    );

    if (showQr) {
        return (
            <PosReceiptQr
                key={receipt.id}
                receipt={receipt as PaidReceipt}
                onBack={() => setShowQr(false)}
            />
        );
    }

    if (showReceipt) {
        return (
            <>
                <ReceiptHeader title="Receipt" onBack={onBack} />
                <div
                    className="pos-receipt-print min-h-0 flex-1 overflow-y-auto bg-neutral-50 px-4 py-[18px]"
                    data-pos-receipt
                >
                    <ReceiptDocument receipt={receipt} variant="paper" />
                </div>
                <div className="flex shrink-0 flex-col gap-2 border-t border-neutral-200 bg-white px-3.5 pt-3 pb-3.5 print:hidden">
                    <button
                        onClick={() => window.print()}
                        className="flex h-12 items-center justify-center gap-2 rounded-xl bg-neutral-950 text-[15px] font-semibold text-white"
                    >
                        <Printer className="size-[18px]" />
                        Print receipt
                    </button>
                    <div className="flex gap-2">
                        {canShareQr && receipt.payment_status === 'paid' && (
                            <button
                                onClick={() => setShowQr(true)}
                                className="flex h-[50px] flex-1 items-center justify-center gap-2 rounded-xl border border-neutral-400 text-[13.5px] font-semibold"
                            >
                                <QrCode className="size-4" />
                                Show QR
                            </button>
                        )}
                        {showNewOrderAction && (
                            <button
                                onClick={onNewOrder}
                                className="flex h-[50px] flex-1 items-center justify-center gap-2 rounded-xl border border-neutral-400 text-[13.5px] font-semibold"
                            >
                                <Plus className="size-4" />
                                New order
                            </button>
                        )}
                    </div>
                </div>
            </>
        );
    }

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
            <div className="min-h-0 flex-1 overflow-y-auto px-[18px] pt-[26px] pb-[18px]">
                <div className="flex flex-col items-center gap-2 text-center">
                    <span className="mb-0.5 flex h-[50px] w-[58px] items-center justify-center rounded-full border border-green-200 bg-green-50 text-green-700">
                        <Check className="size-7" />
                    </span>
                    <p className="text-[40px] leading-none font-bold tracking-tight wrap-anywhere text-red-700">
                        #{receipt.order_number}
                    </p>
                    <span className="flex h-[26px] items-center rounded-full bg-neutral-950 px-[13px] text-[11.5px] font-bold tracking-widest text-white">
                        PAID
                    </span>
                    {customer && (
                        <p className="text-[13px] font-semibold wrap-anywhere">
                            {customer}
                        </p>
                    )}
                    {receipt.reference_number && (
                        <p className="text-[10.5px] break-all text-neutral-500">
                            Reference: {receipt.reference_number}
                        </p>
                    )}
                </div>
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
                <div className="flex gap-2">
                    <button
                        onClick={onReceipt}
                        className="flex h-[50px] min-w-0 flex-1 items-center justify-center gap-2 rounded-xl border border-neutral-400 text-[13.5px] font-semibold"
                    >
                        <ReceiptText className="size-4" />
                        View receipt
                    </button>
                    {paymentMethod !== null &&
                        showsInvoiceProof(paymentMethod) && (
                        <button
                            type="button"
                            onClick={() => setInvoiceOpen(true)}
                            className="flex h-[50px] min-w-[106px] shrink-0 items-center justify-center gap-2 rounded-xl border border-neutral-400 bg-white px-3 text-neutral-800"
                        >
                            <Camera className="size-4 shrink-0" />
                            <span className="flex flex-col items-start leading-none">
                                <span className="text-[13px] font-semibold text-neutral-700">
                                    Invoice
                                </span>
                                <span className="mt-1 text-[9px] font-medium tracking-wide uppercase">Add proof</span>
                            </span>
                        </button>
                        )}
                </div>
            </div>
            {cashless && (
                <TransactionInvoiceDialog
                    paymentId={cashless.id}
                    invoice={savedInvoice ?? cashless.invoice}
                    open={invoiceOpen}
                    mutable
                    onClose={() => setInvoiceOpen(false)}
                    onChanged={setSavedInvoice}
                />
            )}
        </>
    );
}

function ReceiptHeader({
    title,
    onBack,
}: {
    title: string;
    onBack: () => void;
}) {
    return (
        <header className="flex shrink-0 items-center justify-between gap-2 border-b border-neutral-200 px-2 py-2 print:hidden">
            <button
                onClick={onBack}
                className="flex h-10 items-center gap-2 rounded-[10px] px-2 text-[13px] font-semibold hover:bg-neutral-100"
            >
                <ArrowLeft className="size-4" />
                Back
            </button>
            <h2 className="text-sm font-bold">{title}</h2>
            <span className="size-10" aria-hidden />
        </header>
    );
}
