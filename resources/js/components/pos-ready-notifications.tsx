import { router } from '@inertiajs/react';
import { Bell, Check, ReceiptText } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { toast } from 'sonner';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { useBranchRealtimeRefresh } from '@/hooks/use-branch-realtime-refresh';
import {
    orderTypeLabel,
    POS_READY_REALTIME_EVENTS,
    relativePlacedTime,
} from '@/lib/kitchen';
import { update as updateKitchenStatus } from '@/routes/orders/kitchen-status';
import type { PosReadyOrder } from '@/types';

export function PosReadyNotifications({
    branchId,
    orders,
}: {
    branchId: string;
    orders: PosReadyOrder[];
}) {
    const [selectedId, setSelectedId] = useState<string | null>(null);
    const [processing, setProcessing] = useState(false);
    const selected = useMemo(
        () => orders.find((order) => order.id === selectedId) ?? null,
        [orders, selectedId],
    );

    useBranchRealtimeRefresh({
        branchId,
        channel: 'pos',
        events: POS_READY_REALTIME_EVENTS,
        only: ['readyOrders', 'kitchenStatus'],
    });

    useEffect(() => {
        if (selectedId !== null && selected === null) {
            setSelectedId(null);
        }
    }, [selected, selectedId]);

    function markDone() {
        if (selected === null || processing) {
            return;
        }

        setProcessing(true);
        router.patch(
            updateKitchenStatus.url(selected.id),
            { status: 'done' },
            {
                only: ['readyOrders', 'kitchenStatus'],
                preserveScroll: true,
                preserveState: true,
                onSuccess: () => setSelectedId(null),
                onError: (errors) =>
                    toast.error(
                        typeof errors.status === 'string'
                            ? errors.status
                            : 'The order could not be marked done.',
                    ),
                onFinish: () => setProcessing(false),
            },
        );
    }

    return (
        <>
            <DropdownMenu>
                <DropdownMenuTrigger asChild>
                    <button
                        aria-label={`${orders.length} ready for pickup`}
                        className="relative flex size-11 shrink-0 items-center justify-center rounded-xl border border-neutral-200 bg-white"
                    >
                        <Bell className="size-[18px]" />
                        {orders.length > 0 && (
                            <span className="absolute -top-1 -right-1 flex min-w-5 items-center justify-center rounded-full bg-red-700 px-1 text-[10px] font-black text-white">
                                {orders.length}
                            </span>
                        )}
                    </button>
                </DropdownMenuTrigger>
                <DropdownMenuContent
                    align="end"
                    sideOffset={8}
                    className="pos-surface w-[340px] max-w-[calc(100vw-24px)] rounded-[14px] border-neutral-200 bg-white p-0 text-neutral-950 shadow-xl"
                >
                    <div className="flex items-center justify-between border-b border-neutral-200 px-4 py-3">
                        <h2 className="text-[13px] font-semibold">
                            Ready for pickup
                        </h2>
                        <span className="rounded-full bg-emerald-100 px-2 py-0.5 text-[10px] font-black text-emerald-800">
                            {orders.length}
                        </span>
                    </div>
                    {orders.length === 0 ? (
                        <p className="px-4 py-8 text-center text-[12px] text-neutral-500">
                            No ready orders yet.
                        </p>
                    ) : (
                        <div className="max-h-[360px] overflow-y-auto p-2">
                            {orders.map((order) => (
                                <DropdownMenuItem key={order.id} asChild>
                                    <button
                                        type="button"
                                        onClick={() => setSelectedId(order.id)}
                                        className="flex min-h-14 w-full items-center gap-3 rounded-xl px-3 text-left"
                                    >
                                        <span className="flex size-9 items-center justify-center rounded-lg bg-emerald-100 text-xs font-black text-emerald-800">
                                            #{order.number}
                                        </span>
                                        <span className="min-w-0 flex-1">
                                            <strong className="block truncate text-xs">
                                                {order.customer ||
                                                    'Walk-in customer'}
                                            </strong>
                                            <span className="text-[10px] text-neutral-500">
                                                {orderTypeLabel(
                                                    order.order_type,
                                                )}{' '}
                                                ·{' '}
                                                {relativePlacedTime(
                                                    order.placed_at,
                                                )}
                                            </span>
                                        </span>
                                    </button>
                                </DropdownMenuItem>
                            ))}
                        </div>
                    )}
                </DropdownMenuContent>
            </DropdownMenu>

            {orders.length > 0 && (
                <aside className="fixed right-3 bottom-[84px] left-3 z-20 overflow-hidden rounded-2xl border border-emerald-200 bg-white shadow-xl md:right-auto md:bottom-4 md:left-[110px] md:w-[300px]">
                    <div className="flex items-center justify-between bg-emerald-700 px-3 py-2 text-white">
                        <span className="text-[10px] font-black tracking-[0.14em] uppercase">
                            Ready for pickup
                        </span>
                        <span className="rounded-full bg-white/15 px-2 py-0.5 text-[10px] font-black">
                            {orders.length}
                        </span>
                    </div>
                    <div className="flex gap-2 overflow-x-auto p-2 md:max-h-[210px] md:flex-col md:overflow-y-auto">
                        {orders.map((order) => (
                            <button
                                key={order.id}
                                type="button"
                                onClick={() => setSelectedId(order.id)}
                                className="flex min-w-[210px] items-center gap-2 rounded-xl border border-neutral-200 bg-white p-2 text-left transition hover:border-emerald-400 md:min-w-0"
                            >
                                <span className="text-sm font-black">
                                    #{order.number}
                                </span>
                                <span className="min-w-0 flex-1 truncate text-[11px] text-neutral-500">
                                    {order.customer || 'Walk-in'}
                                </span>
                                <Check className="size-4 text-emerald-700" />
                            </button>
                        ))}
                    </div>
                </aside>
            )}

            <Dialog
                open={selected !== null}
                onOpenChange={(open) => !open && setSelectedId(null)}
            >
                {selected && (
                    <DialogContent className="pos-surface max-h-[calc(100dvh-24px)] overflow-y-auto rounded-2xl p-0 sm:max-w-[560px]">
                        <DialogHeader className="border-b border-neutral-200 p-5 pr-12">
                            <div className="flex flex-wrap items-center gap-2">
                                <DialogTitle className="text-xl font-black">
                                    Ready for pickup
                                </DialogTitle>
                                <span className="rounded-full bg-emerald-100 px-2 py-1 text-[10px] font-black text-emerald-800">
                                    READY
                                </span>
                            </div>
                            <DialogDescription>
                                Order #{selected.number} ·{' '}
                                {selected.customer || 'Walk-in customer'}
                            </DialogDescription>
                        </DialogHeader>

                        <div className="space-y-4 p-5">
                            <dl className="grid grid-cols-2 gap-2 text-xs sm:grid-cols-4">
                                <ReadyDetail
                                    label="Customer"
                                    value={selected.customer || 'Walk-in'}
                                />
                                <ReadyDetail
                                    label="Order type"
                                    value={orderTypeLabel(selected.order_type)}
                                />
                                <ReadyDetail
                                    label="Placed"
                                    value={relativePlacedTime(
                                        selected.placed_at,
                                    )}
                                />
                                <ReadyDetail
                                    label="Payment"
                                    value={paymentLabel(selected)}
                                />
                            </dl>

                            <section className="overflow-hidden rounded-xl border border-neutral-200">
                                {selected.items.map((item) => (
                                    <div
                                        key={item.id}
                                        className="grid grid-cols-[auto_1fr] gap-3 border-b border-neutral-100 p-3 last:border-0"
                                    >
                                        <span className="font-black">
                                            {item.quantity}×
                                        </span>
                                        <div>
                                            <p className="text-sm font-bold">
                                                {item.display_name}
                                            </p>
                                            {item.standard_modifiers.map(
                                                (modifier) => (
                                                    <p
                                                        key={modifier}
                                                        className="text-xs text-neutral-500"
                                                    >
                                                        + {modifier}
                                                    </p>
                                                ),
                                            )}
                                            {item.instructions.map(
                                                (instruction) => (
                                                    <p
                                                        key={instruction}
                                                        className="text-xs font-semibold text-amber-700"
                                                    >
                                                        Instruction:{' '}
                                                        {instruction}
                                                    </p>
                                                ),
                                            )}
                                            {item.note && (
                                                <p className="mt-1 text-xs font-semibold text-red-700">
                                                    Note: {item.note}
                                                </p>
                                            )}
                                        </div>
                                    </div>
                                ))}
                            </section>

                            <div className="flex items-center justify-between rounded-xl bg-neutral-950 px-4 py-3 text-white">
                                <span className="flex items-center gap-2 text-xs font-semibold">
                                    <ReceiptText className="size-4" /> Order
                                    total
                                </span>
                                <strong className="text-lg">
                                    ₱{selected.total}
                                </strong>
                            </div>
                        </div>

                        <DialogFooter className="border-t border-neutral-200 p-4">
                            <button
                                type="button"
                                disabled={processing}
                                onClick={() => setSelectedId(null)}
                                className="min-h-11 rounded-xl border border-neutral-200 px-5 text-sm font-bold"
                            >
                                Not yet
                            </button>
                            <button
                                type="button"
                                disabled={processing}
                                onClick={markDone}
                                className="inline-flex min-h-11 items-center justify-center gap-2 rounded-xl bg-emerald-700 px-5 text-sm font-bold text-white disabled:opacity-50"
                            >
                                <Check className="size-4" />
                                {processing ? 'Updating…' : 'Mark as done'}
                            </button>
                        </DialogFooter>
                    </DialogContent>
                )}
            </Dialog>
        </>
    );
}

function ReadyDetail({ label, value }: { label: string; value: string }) {
    return (
        <div className="rounded-xl bg-neutral-100 p-3">
            <dt className="mb-1 text-[9px] font-bold tracking-wide text-neutral-400 uppercase">
                {label}
            </dt>
            <dd className="font-bold wrap-break-word">{value}</dd>
        </div>
    );
}

function paymentLabel(order: PosReadyOrder): string {
    if (order.payment_status === 'unpaid') {
        return 'Pay later';
    }

    if (order.payment_methods.length === 0) {
        return order.payment_status === 'paid' ? 'Paid' : 'Partial';
    }

    return order.payment_methods
        .map((method) => method.charAt(0).toUpperCase() + method.slice(1))
        .join(' + ');
}
