import { router } from '@inertiajs/react';
import { useConnectionStatus, useEcho } from '@laravel/echo-react';
import { Search, QrCode } from 'lucide-react';
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
import { index, load, destroy } from '@/routes/pos/qr-orders';
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
        ['.qr.order_submitted', '.qr.order_loaded', '.qr.order_archived'],
        (event) => {
            if (accept(event)) {
                refresh.schedule();
                if (event.event_type === 'qr.order_submitted')
                    toast('New QR order', {
                        description: `#${String(event.order_number)}`,
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
                <button
                    className={qrButton}
                    onClick={() => refresh.schedule(0)}
                >
                    Refresh
                </button>
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
                        You loaded <strong>#{loaded.order_number}</strong>.
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
                            <div className="flex justify-between gap-2">
                                <h2 className="text-lg font-bold">
                                    #{order.order_number}
                                </h2>
                                <span className="rounded-full bg-amber-50 px-2 py-1 text-[10px] font-semibold text-amber-800">
                                    {archived
                                        ? 'Archived / Unclaimed'
                                        : 'Waiting for payment'}
                                </span>
                            </div>
                            <div className="flex flex-wrap gap-2 text-[11px] text-neutral-500">
                                <span>
                                    {order.order_type === 'dine_in'
                                        ? 'Dine in'
                                        : 'Take out'}
                                </span>
                                {order.customer_label && (
                                    <span>· {order.customer_label}</span>
                                )}
                                {order.table_name && (
                                    <span>· {order.table_name}</span>
                                )}
                                <time dateTime={order.submitted_at}>
                                    {new Date(
                                        order.submitted_at,
                                    ).toLocaleTimeString([], {
                                        hour: '2-digit',
                                        minute: '2-digit',
                                    })}
                                </time>
                            </div>
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
                                <span>Total</span>
                                <span>{pesos(order.total)}</span>
                            </div>
                            <div className="flex gap-2">
                                <button
                                    className={`${qrButton} flex-1`}
                                    onClick={() => setSelected(order)}
                                >
                                    View
                                </button>
                                {!archived && (
                                    <>
                                        <button
                                            className={`${qrPrimary} flex-1`}
                                            disabled={busy || hasCurrentCart}
                                            onClick={() => claim(order)}
                                        >
                                            LOAD
                                        </button>
                                        <button
                                            className={`${qrButton} text-red-700`}
                                            disabled={busy}
                                            onClick={() => setDeleting(order)}
                                        >
                                            Delete
                                        </button>
                                    </>
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
                        <DialogTitle>
                            {deleting
                                ? 'Delete QR order?'
                                : `#${selected?.order_number}`}
                        </DialogTitle>
                        <DialogDescription>
                            {deleting
                                ? `Archive #${deleting.order_number} from the waiting queue? Its history will be retained.`
                                : 'Review the submitted order before loading it into POS.'}
                        </DialogDescription>
                        {deleting ? (
                            <div className="flex gap-2">
                                <button
                                    className={qrButton}
                                    disabled={busy}
                                    onClick={() => setDeleting(null)}
                                >
                                    Cancel
                                </button>
                                <button
                                    className={`${qrPrimary} bg-red-700`}
                                    disabled={busy}
                                    onClick={archive}
                                >
                                    {busy ? 'Deleting…' : 'Delete'}
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
