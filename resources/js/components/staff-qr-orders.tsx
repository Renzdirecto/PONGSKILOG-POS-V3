import { qrIdentity, qrElapsed } from '@/lib/qr-order';
import { router } from '@inertiajs/react';
import { useConnectionStatus, useEcho } from '@laravel/echo-react';
import {
    Search,
    QrCode,
    ShoppingCart,
    Eye,
    Trash2,
    RotateCcw,
} from 'lucide-react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { toast } from 'sonner';
import {
    Dialog,
    DialogContent,
    DialogTitle,
    DialogDescription,
} from '@/components/ui/dialog';
import { QrItems } from '@/components/customer-qr-tracking';
import { qrButton, qrPanel, qrPrimary } from '@/components/customer-qr-product';
import { qrError, qrRequest } from '@/lib/qr-http';
import { pesos } from '@/lib/pos-money';
import {
    createBranchEventGuard,
    createRealtimeRefresh,
} from '@/lib/realtime-refresh';
import { index, load, destroy, restore } from '@/routes/pos/qr-orders';
import { cashier } from '@/routes/workspaces';
import type { StaffQrOrder } from '@/types/qr';

type Queue = {
    data: StaffQrOrder[];
    current_page: number;
    last_page: number;
    total: number;
};
export function StaffQrOrders({
    branchId,
    hasCurrentCart,
    loaded,
    onLoad,
}: {
    branchId: string;
    hasCurrentCart: boolean;
    loaded: StaffQrOrder | null;
    onLoad: (order: StaffQrOrder) => void;
}) {
    const connection = useConnectionStatus();
    const [now, setNow] = useState(Date.now);
    useEffect(() => {
        const timer = window.setInterval(() => setNow(Date.now()), 1000);
        return () => window.clearInterval(timer);
    }, []);
    const restoreOrder = async (order: StaffQrOrder) => {
        if (submitting.current) return;
        submitting.current = true;
        setBusy(true);
        try {
            await qrRequest(restore(order.id));
            setSelected(null);
            refresh.schedule(0);
        } catch (reason) {
            setError(qrError(reason).message);
        } finally {
            submitting.current = false;
            setBusy(false);
        }
    };
    const [search, setSearch] = useState('');
    const [archived, setArchived] = useState(false);
    const [page, setPage] = useState(1);
    const [queue, setQueue] = useState<Queue | null>(null);
    const [error, setError] = useState('');
    const [busy, setBusy] = useState(false);
    const [loading, setLoading] = useState(true);
    const [selected, setSelected] = useState<StaffQrOrder | null>(null);
    const [deleting, setDeleting] = useState<StaffQrOrder | null>(null);
    const submitting = useRef(false);
    const requestVersion = useRef(0);
    const fetchQueue = useCallback(async () => {
        const version = ++requestVersion.current;
        setLoading(true);
        try {
            const response = await qrRequest<{ orders: Queue }>(
                index({ query: { search, archived: archived ? 1 : 0, page } }),
            );
            if (version === requestVersion.current) {
                setQueue(response.orders);
                setError('');
            }
        } catch (reason) {
            if (version === requestVersion.current)
                setError(qrError(reason).message);
        } finally {
            if (version === requestVersion.current) setLoading(false);
        }
    }, [search, archived, page]);
    const refresh = useMemo(
        () =>
            createRealtimeRefresh((finish) => {
                void fetchQueue().finally(finish);
            }, 35),
        [fetchQueue],
    );
    const accept = useMemo(() => createBranchEventGuard(branchId), [branchId]);
    useEcho<Record<string, unknown>>(
        `branch.${branchId}.pos`,
        [
            '.qr.order_submitted',
            '.qr.order_loaded',
            '.qr.order_archived',
            '.qr.order_released',
            '.qr.order_restored',
        ],
        (event) => {
            if (accept(event)) {
                refresh.schedule();
                if (event.event_type === 'qr.order_submitted')
                    toast('New QR order', {
                        description: String(event.qr_number),
                    });
            }
        },
        [branchId, refresh, accept],
    );
    useEffect(() => {
        refresh.activate();
        refresh.schedule(160);
        return () => {
            refresh.dispose();
            requestVersion.current++;
        };
    }, [refresh]);
    useEffect(() => {
        if (connection === 'connected') refresh.schedule(0);
        const retry = () => refresh.schedule(0);
        window.addEventListener('online', retry);
        window.addEventListener('focus', retry);
        return () => {
            window.removeEventListener('online', retry);
            window.removeEventListener('focus', retry);
        };
    }, [connection, refresh]);
    const claim = async (order: StaffQrOrder) => {
        if (submitting.current) return;
        if (hasCurrentCart) {
            setError(
                'Finish or clear the current POS cart before loading a QR order. Your current cart is preserved.',
            );
            return;
        }
        if (!navigator.onLine) {
            setError('You are offline. Reconnect before loading an order.');
            return;
        }
        submitting.current = true;
        setBusy(true);
        try {
            const response = await qrRequest<{ order: StaffQrOrder }>(
                load(order.id),
            );
            onLoad(response.order);
        } catch (reason) {
            setError(qrError(reason).message);
            router.reload({ only: ['loadedQr'] });
            refresh.schedule(0);
        } finally {
            submitting.current = false;
            setBusy(false);
        }
    };
    const archive = async () => {
        if (!deleting || submitting.current || !navigator.onLine) return;
        submitting.current = true;
        setBusy(true);
        try {
            await qrRequest(destroy(deleting.id));
            setDeleting(null);
            setSelected(null);
            refresh.schedule(0);
            toast.success('QR order archived');
        } catch (reason) {
            setError(qrError(reason).message);
            refresh.schedule(0);
        } finally {
            submitting.current = false;
            setBusy(false);
        }
    };
    return (
        <section className="pos-surface flex flex-col gap-4 p-3 text-neutral-950 min-[900px]:p-5">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 className="text-lg font-bold">QR Orders</h1>
                    <p className="mt-1 text-xs text-neutral-500">
                        Retrieve a customer order, then process payment in POS.
                    </p>
                </div>
                {(connection !== 'connected' || error) && (
                    <button
                        className={qrButton}
                        onClick={() => refresh.schedule(0)}
                    >
                        Retry
                    </button>
                )}
            </div>
            {connection !== 'connected' && (
                <p
                    role="status"
                    className="rounded-xl bg-amber-50 p-3 text-xs text-amber-800"
                >
                    Live updates are unavailable. Reconnect or refresh to get
                    the latest queue.
                </p>
            )}
            {error && (
                <p
                    role="alert"
                    className="rounded-xl bg-red-50 p-3 text-xs text-red-800"
                >
                    {error}
                </p>
            )}
            {loaded && (
                <div
                    className={`${qrPanel} flex flex-wrap items-center justify-between gap-3 text-sm`}
                >
                    <span>
                        You loaded <strong>{qrIdentity(loaded)}</strong>.
                        Continue its payment in POS.
                    </span>
                    <button
                        className={qrPrimary}
                        onClick={() => onLoad(loaded)}
                    >
                        Resume order
                    </button>
                </div>
            )}
            <div className="flex flex-wrap gap-2">
                <label className="flex min-w-0 flex-1 items-center gap-2 rounded-xl border border-neutral-200 bg-white px-3">
                    <Search size={16} />
                    <input
                        className="h-11 min-w-0 flex-1 bg-transparent text-sm outline-none"
                        placeholder="Search order number or customer"
                        aria-label="Search QR orders"
                        value={search}
                        onChange={(event) => {
                            setSearch(event.target.value);
                            setPage(1);
                        }}
                    />
                </label>
                <button
                    className={!archived ? qrPrimary : qrButton}
                    onClick={() => {
                        setArchived(false);
                        setPage(1);
                    }}
                >
                    Waiting
                </button>
                <button
                    className={archived ? qrPrimary : qrButton}
                    onClick={() => {
                        setArchived(true);
                        setPage(1);
                    }}
                >
                    Archived
                </button>
            </div>
            {loading && !queue ? (
                <div
                    role="status"
                    className="grid animate-pulse gap-3 min-[760px]:grid-cols-2"
                >
                    {[0, 1, 2].map((id) => (
                        <div
                            key={id}
                            className="h-56 rounded-2xl bg-neutral-100"
                        />
                    ))}
                </div>
            ) : queue?.data.length ? (
                <div className="grid gap-3 min-[760px]:grid-cols-2 min-[1300px]:grid-cols-3">
                    {queue.data.map((order) => (
                        <article
                            key={order.id}
                            className={`${qrPanel} flex flex-col gap-3`}
                        >
                            <div className="flex items-start justify-between gap-2">
                                <div>
                                    <h2 className="text-[17px] font-bold text-red-700">
                                        {qrIdentity(order)}
                                    </h2>
                                    {order.customer_label && (
                                        <p className="mt-1 text-[13px] font-semibold text-black">
                                            {order.customer_label}
                                        </p>
                                    )}
                                </div>
                                <span
                                    className={`rounded-full border px-2.5 py-1 text-[10px] font-bold ${archived ? 'border-neutral-200 bg-neutral-100 text-neutral-600' : order.order_type === 'dine_in' ? 'border-green-200 bg-green-50 text-green-800' : 'border-sky-200 bg-sky-50 text-sky-800'}`}
                                >
                                    {archived
                                        ? 'ARCHIVED'
                                        : order.order_type === 'dine_in'
                                          ? 'DINE IN'
                                          : 'TAKE OUT'}
                                </span>
                            </div>
                            <p className="text-[11px] text-neutral-500">
                                Order Time:{' '}
                                {new Date(
                                    order.submitted_at,
                                ).toLocaleTimeString([], {
                                    hour: '2-digit',
                                    minute: '2-digit',
                                })}{' '}
                                · {qrElapsed(order.submitted_at, now)}{' '}
                                {order.table_name && `· ${order.table_name}`}
                            </p>
                            <div className="flex-1 space-y-1 text-xs">
                                {order.items.slice(0, 4).map((item) => (
                                    <div
                                        key={item.id}
                                        className="flex justify-between gap-2"
                                    >
                                        <span>
                                            {item.quantity}×{' '}
                                            {item.display_name ?? item.name}
                                        </span>
                                        <span>{pesos(item.line_total)}</span>
                                    </div>
                                ))}
                                {order.items.length > 4 && (
                                    <p className="text-neutral-500">
                                        +{order.items.length - 4} more items
                                    </p>
                                )}
                            </div>
                            <div className="flex justify-between border-t border-neutral-100 pt-3 text-sm font-bold">
                                <span className="rounded-full border border-red-200 bg-red-50 px-2 py-1 text-[10px] text-red-700">
                                    UNPAID
                                </span>
                                <span>{pesos(order.total)}</span>
                            </div>
                            <div className="-mx-4 -mb-4 flex flex-wrap gap-2 border-t border-neutral-200 bg-neutral-50 px-3 py-2.5">
                                {!archived && (
                                    <button
                                        className={`${qrPrimary} h-[46px] flex-1`}
                                        disabled={busy || hasCurrentCart}
                                        onClick={() => claim(order)}
                                    >
                                        <ShoppingCart size={16} />
                                        {busy ? 'Loading\u2026' : 'LOAD'}
                                    </button>
                                )}
                                <button
                                    className={`${qrButton} h-[46px] flex-1`}
                                    onClick={() => setSelected(order)}
                                >
                                    <Eye size={16} />
                                    VIEW
                                </button>
                                {archived ? (
                                    <button
                                        className={`${qrButton} h-[46px] flex-1`}
                                        disabled={busy}
                                        onClick={() => restoreOrder(order)}
                                    >
                                        <RotateCcw size={16} />
                                        RESTORE
                                    </button>
                                ) : (
                                    <button
                                        className={`${qrButton} h-[46px] text-red-700`}
                                        disabled={busy}
                                        onClick={() => setDeleting(order)}
                                    >
                                        <Trash2 size={16} />
                                        DELETE
                                    </button>
                                )}
                            </div>
                        </article>
                    ))}
                </div>
            ) : (
                <div className="py-16 text-center">
                    <QrCode
                        size={32}
                        className="mx-auto mb-4 text-neutral-400"
                    />
                    <h2 className="font-bold">
                        {search
                            ? 'No matching QR orders'
                            : archived
                              ? 'No archived QR orders'
                              : 'No QR orders yet'}
                    </h2>
                    <p className="mt-2 text-xs text-neutral-500">
                        {search
                            ? 'Try another order number or customer name.'
                            : archived
                              ? 'Archived orders are retained here.'
                              : 'Customer orders will appear here when submitted.'}
                    </p>
                </div>
            )}
            {hasCurrentCart && (
                <p className="text-xs text-neutral-500">
                    Finish or clear your current POS cart before using LOAD.{' '}
                    <button
                        className="font-semibold underline"
                        onClick={() =>
                            router.visit(cashier(), { preserveState: true })
                        }
                    >
                        Go to POS
                    </button>
                </p>
            )}
            {queue && queue.last_page > 1 && (
                <div className="flex items-center justify-center gap-3 text-xs">
                    <button
                        className={qrButton}
                        disabled={page <= 1 || loading}
                        onClick={() => setPage(page - 1)}
                    >
                        Previous
                    </button>
                    <span>
                        {page} / {queue.last_page}
                    </span>
                    <button
                        className={qrButton}
                        disabled={page >= queue.last_page || loading}
                        onClick={() => setPage(page + 1)}
                    >
                        Next
                    </button>
                </div>
            )}
            {(selected || deleting) && (
                <Dialog
                    open
                    onOpenChange={(open) => {
                        if (!open && !busy) {
                            setSelected(null);
                            setDeleting(null);
                        }
                    }}
                >
                    <DialogContent className="pos-surface max-h-[90dvh] overflow-y-auto rounded-2xl bg-white text-neutral-950">
                        {deleting && <div className="flex size-[46px] items-center justify-center rounded-xl bg-red-50 text-red-700"><Trash2 size={22}/></div>}
                        <DialogTitle>
                            {deleting
                                ? `Delete ${qrIdentity(deleting)}?`
                                : selected
                                  ? qrIdentity(selected)
                                  : ''}
                        </DialogTitle>
                        <DialogDescription>
                            {deleting
                                ? "The customer's QR order is removed from the queue. Nothing was paid, reserved or sent to the kitchen, so no stock or sales record is affected."
                                : 'Review the submitted order before loading it into POS.'}
                        </DialogDescription>
                        {deleting ? (
                            <div className="flex gap-2">
                                <button
                                    className={`${qrButton} h-[52px] flex-1`}
                                    disabled={busy}
                                    onClick={() => setDeleting(null)}
                                >
                                    Keep it
                                </button>
                                <button
                                    className={`${qrPrimary} h-[52px] flex-1 bg-red-700`}
                                    disabled={busy}
                                    onClick={archive}
                                >
                                    {busy ? 'Deleting…' : 'Delete order'}
                                </button>
                            </div>
                        ) : (
                            selected && (
                                <>
                                    <QrItems order={selected} />
                                    {selected.archive_reason && (
                                        <p className="text-xs text-neutral-500">
                                            Archived:{' '}
                                            {selected.archive_reason.replaceAll(
                                                '_',
                                                ' ',
                                            )}
                                        </p>
                                    )}
                                    {!selected.archived_at && (
                                        <div className="flex gap-2">
                                            <button
                                                className={qrPrimary}
                                                disabled={
                                                    busy || hasCurrentCart
                                                }
                                                onClick={() => claim(selected)}
                                            >
                                                LOAD
                                            </button>
                                            <button
                                                className={`${qrButton} text-red-700`}
                                                disabled={busy}
                                                onClick={() =>
                                                    setDeleting(selected)
                                                }
                                            >
                                                Delete
                                            </button>
                                        </div>
                                    )}
                                </>
                            )
                        )}
                    </DialogContent>
                </Dialog>
            )}
        </section>
    );
}
