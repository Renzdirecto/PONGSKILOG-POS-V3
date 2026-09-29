import { qrIdentity } from '@/lib/qr-order';
import {
    Check,
    Download,
    Clock3,
    ChefHat,
    ShoppingBag,
    Eye,
    ReceiptText,
    ArrowLeft,
    Plus,
} from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { flushSync } from 'react-dom';
import { qrButton, qrPanel, qrPrimary } from './customer-qr-product';
import { DigitalReceiptCard } from './digital-receipt-card';
import { pesos } from '@/lib/pos-money';
export { QrItems } from './digital-receipt-card';
import { canStartQrOrder, qrStatus } from '@/lib/qr-order';
import { receiptPng } from '@/lib/receipt-png';
import { qrError, qrRequest } from '@/lib/qr-http';
import { receipt as receiptRoute } from '@/routes/qr/orders';
import type { QrOrder, QrReceipt } from '@/types/qr';
import { QrSocials } from './customer-qr';
import type { QrView } from './customer-qr';
import type { BranchSummary } from '@/types';

export function CustomerQrTracking({
    branch,
    order,
    view,
    go,
    begin,
    busy,
    details,
}: {
    branch: BranchSummary;
    order: QrOrder | null;
    view: 'track' | 'receipt';
    go: (view: QrView) => void;
    begin: () => void;
    busy: boolean;
    details: () => void;
}) {
    const [receipt, setReceipt] = useState<QrReceipt | null>(null);
    const [error, setError] = useState('');
    const [saving, setSaving] = useState(false);
    const receiptCard = useRef<HTMLDivElement>(null);
    const [expired, setExpired] = useState(false);
    const tracking = order?.public_tracking_id;
    useEffect(() => {
        if (view !== 'receipt' || !tracking) return;
        let current = true;
        void qrRequest<{ receipt: QrReceipt }>(
            receiptRoute({ branch: branch.id, tracking }),
        )
            .then((result) => {
                if (current) {
                    setReceipt(result.receipt);
                    setError('');
                }
            })
            .catch((reason) => {
                if (current) setError(qrError(reason).message);
            });
        return () => {
            current = false;
        };
    }, [branch.id, tracking, view, order?.version]);
    useEffect(() => {
        const expiry = order?.receipt_expires_at;
        if (!expiry) return;
        const delay = new Date(expiry).getTime() - Date.now();
        const timer = window.setTimeout(
            () => {
                setExpired(true);
                setReceipt(null);
            },
            Math.max(0, delay),
        );
        return () => window.clearTimeout(timer);
    }, [order?.receipt_expires_at]);
    const save = async () => {
        if (!tracking || saving) return;
        setSaving(true);
        let exporting = false;
        try {
            const { receipt: fresh } = await qrRequest<{ receipt: QrReceipt }>(
                receiptRoute({ branch: branch.id, tracking }),
            );
            flushSync(() => setReceipt(fresh));
            exporting = true;
            if (!receiptCard.current)
                throw new Error('Receipt is unavailable.');
            const url = URL.createObjectURL(
                await receiptPng(receiptCard.current),
            );
            const link = document.createElement('a');
            link.href = url;
            link.download = `Pongskilog-${fresh.reference_number}.png`;
            document.body.appendChild(link);
            link.click();
            link.remove();
            window.setTimeout(() => URL.revokeObjectURL(url), 60_000);
            setError('');
        } catch (reason) {
            setError(
                exporting
                    ? 'Unable to save the PNG. Please try again.'
                    : qrError(reason).message,
            );
            if (!exporting) setReceipt(null);
        } finally {
            setSaving(false);
        }
    };
    if (!order)
        return (
            <main className="mx-auto flex max-w-[520px] flex-col gap-4 px-4 py-10 text-center">
                <h1 className="text-xl font-bold">No current order</h1>
                <button className={qrPrimary} onClick={() => go('menu')}>
                    Browse menu
                </button>
            </main>
        );
    const archived = order.commercial_status === 'archived_unclaimed';
    const voided = order.commercial_status === 'voided';
    const done = order.kitchen_status === 'done';
    const stage = ['not_sent', 'kitchen', 'preparing', 'ready', 'done'].indexOf(
        order.kitchen_status,
    );
    return (
        <main className="mx-auto flex max-w-[560px] flex-col gap-4 px-4 py-6 pb-12">
            <div className="flex items-center justify-between gap-2">
                <button
                    className={qrButton}
                    onClick={() => go(view === 'receipt' ? 'track' : 'menu')}
                >
                    <ArrowLeft size={16} />
                    {view === 'receipt' ? 'Back to Order' : 'Menu'}
                </button>
                <h1 className="text-sm font-bold">
                    {view === 'receipt'
                        ? 'Digital receipt'
                        : 'Track your order'}
                </h1>
                {view === 'track' && (
                    <button
                        className={`${qrButton} border-green-200 bg-green-50 text-green-800`}
                        onClick={details}
                    >
                        <Eye size={16} />
                        View Order
                    </button>
                )}
            </div>
            {view === 'receipt' ? (
                <>
                    {error && (
                        <p
                            role="alert"
                            className="rounded-xl bg-red-50 p-3 text-sm text-red-800"
                        >
                            {error}
                        </p>
                    )}
                    {expired || !order.receipt_available ? (
                        <div className={`${qrPanel} text-center`}>
                            <h2 className="font-bold">
                                {order.payment_status === 'paid'
                                    ? 'Receipt expired'
                                    : 'Receipt unavailable'}
                            </h2>
                            <p className="mt-2 text-xs text-neutral-500">
                                {order.payment_status === 'paid'
                                    ? 'The digital receipt is available for 24 hours after payment.'
                                    : 'Your receipt will be available once payment is confirmed.'}
                            </p>
                        </div>
                    ) : receipt ? (
                        <>
                            <DigitalReceiptCard
                                receipt={receipt}
                                ref={receiptCard}
                            />
                            <p className="text-center text-[11px] text-neutral-500">
                                Available for 24 hours after payment. Save a
                                copy before it expires.
                            </p>
                            <button
                                disabled={saving}
                                className={qrPrimary}
                                onClick={save}
                            >
                                <Download size={16} />
                                {saving ? 'Saving…' : 'Save receipt as PNG'}
                            </button>
                        </>
                    ) : (
                        !error && (
                            <p
                                role="status"
                                className="animate-pulse py-10 text-center text-sm"
                            >
                                Loading receipt…
                            </p>
                        )
                    )}
                    <button className={qrButton} onClick={() => go('track')}>
                        Back to order
                    </button>
                </>
            ) : (
                <>
                    <div
                        className={`${qrPanel} flex flex-col items-center gap-3 py-6 text-center`}
                    >
                        <p className="text-xs text-neutral-500">
                            Your order number
                        </p>
                        <h2 className="text-5xl font-extrabold">
                            {qrIdentity(order)}
                        </h2>
                        {order.reference_number && (
                            <p className="text-xs text-neutral-500">
                                REF: {order.reference_number}
                            </p>
                        )}
                        <span
                            className={`rounded-full px-4 py-2 text-sm font-semibold ${voided ? 'bg-red-50 text-red-800' : archived ? 'bg-neutral-100' : 'bg-amber-50 text-amber-800'}`}
                        >
                            {qrStatus(order)}
                        </span>
                        <p className="text-xs leading-5 text-neutral-500">
                            {voided
                                ? 'This order was voided. Please speak with the counter if you need help.'
                                : archived
                                ? 'This unclaimed order was archived. Start a new order when you are ready.'
                                : done
                                  ? 'Salamat! We hope you enjoyed your order.'
                                  : order.kitchen_status === 'ready'
                                    ? 'Ready na ang order mo! Please approach the counter.'
                                    : order.payment_status !== 'paid' &&
                                        !order.committed_at
                                      ? 'Pakita ang order number sa cashier para ma-process ang payment.'
                                      : order.payment_status !== 'paid'
                                        ? 'Your order is in the kitchen. Payment is still due at the cashier.'
                                        : 'Payment confirmed. Hintayin ang live update ng order mo.'}
                        </p>
                    </div>
                    {!archived && !voided && (
                        <div className={`${qrPanel} space-y-5`}>
                            <div className="flex items-center gap-3">
                                <Check className="size-8 rounded-full bg-green-50 p-2 text-green-700" />
                                <span className="text-xs font-semibold">
                                    Order submitted
                                </span>
                                {order.submitted_at && (
                                    <time
                                        dateTime={order.submitted_at}
                                        className="ml-auto shrink-0 text-[11px] tabular-nums"
                                    >
                                        {new Date(
                                            order.submitted_at,
                                        ).toLocaleTimeString([], {
                                            hour: '2-digit',
                                            minute: '2-digit',
                                        })}
                                    </time>
                                )}
                            </div>
                            <div className="flex items-center gap-3">
                                <Clock3
                                    className={`size-8 rounded-full p-2 ${order.payment_status === 'paid' ? 'bg-green-50 text-green-700' : 'bg-amber-50 text-amber-700'}`}
                                />
                                <span className="text-xs font-semibold">
                                    {order.payment_status === 'paid'
                                        ? 'Payment confirmed'
                                        : 'Payment pending'}
                                </span>
                                {order.payment_status === 'paid' &&
                                    order.paid_at && (
                                        <time
                                            dateTime={order.paid_at}
                                            className="ml-auto shrink-0 text-[11px] tabular-nums"
                                        >
                                            {new Date(
                                                order.paid_at,
                                            ).toLocaleTimeString([], {
                                                hour: '2-digit',
                                                minute: '2-digit',
                                            })}
                                        </time>
                                    )}
                            </div>
                            {[
                                ['In kitchen', ChefHat],
                                ['Preparing', ChefHat],
                                ['Ready for pickup', ShoppingBag],
                                ['Completed', Check],
                            ].map(([label, Icon], index) => {
                                const StatusIcon = Icon as typeof Check;
                                return (
                                    <div
                                        key={String(label)}
                                        className={`flex items-center gap-3 ${stage < index + 1 ? 'text-neutral-400' : ''}`}
                                    >
                                        <StatusIcon
                                            className={`size-8 rounded-full p-2 ${stage >= index + 1 ? ['bg-amber-50 text-amber-700', 'bg-sky-50 text-sky-700', 'bg-green-50 text-green-700', 'bg-neutral-100 text-neutral-600'][index] : 'bg-neutral-100'}`}
                                        />
                                        <span className="text-xs font-semibold">
                                            {String(label)}
                                        </span>
                                        {[
                                            order.committed_at,
                                            order.preparing_at,
                                            order.ready_at,
                                            order.completed_at,
                                        ][index] && (
                                            <time className="ml-auto text-[11px] tabular-nums">
                                                {new Date(
                                                    [
                                                        order.committed_at,
                                                        order.preparing_at,
                                                        order.ready_at,
                                                        order.completed_at,
                                                    ][index]!,
                                                ).toLocaleTimeString([], {
                                                    hour: '2-digit',
                                                    minute: '2-digit',
                                                })}
                                            </time>
                                        )}
                                    </div>
                                );
                            })}
                        </div>
                    )}
                    <div className={`${qrPanel} flex justify-between text-sm`}>
                        <span>
                            {order.payment_status === 'paid'
                                ? 'Total paid'
                                : 'Amount due'}
                        </span>
                        <strong>{pesos(order.total)}</strong>
                    </div>
                    <div className="grid grid-cols-3 gap-2">
                        <button
                            className={`${qrButton} flex-col rounded-2xl py-4`}
                            disabled={busy}
                            onClick={() =>
                                canStartQrOrder(order) ? begin() : go('menu')
                            }
                        >
                            {canStartQrOrder(order) ? (
                                <Plus size={20} />
                            ) : (
                                <ShoppingBag size={20} />
                            )}
                            <span>
                                {canStartQrOrder(order)
                                    ? 'New Order'
                                    : 'Browse Menu'}
                            </span>
                        </button>
                        <button
                            className={`${qrButton} flex-col rounded-2xl py-4`}
                            onClick={details}
                        >
                            <Eye size={20} />
                            View Order
                        </button>
                        <button
                            className={`${qrButton} flex-col rounded-2xl py-4 disabled:bg-neutral-100 disabled:text-neutral-400`}
                            disabled={!order.receipt_available || expired}
                            onClick={() => go('receipt')}
                        >
                            <ReceiptText size={20} />
                            Receipt
                        </button>
                    </div>
                    {done && (
                        <a
                            href={`https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(`Pongskilog ${branch.name}`)}`}
                            target="_blank"
                            rel="noreferrer"
                            className={`${qrButton} text-center`}
                        >
                            Leave us a review
                        </a>
                    )}
                </>
            )}
            <footer className="mt-5 flex flex-col items-center gap-3 border-t border-neutral-200 pt-6">
                <p className="text-[10px] font-bold tracking-widest text-neutral-500 uppercase">
                    Stay connected
                </p>
                <QrSocials branch={branch} />
            </footer>
        </main>
    );
}
