import { Head, Link, router, usePage, useRemember } from '@inertiajs/react';
import {
    Expand,
    MonitorUp,
    Search,
    Shrink,
    Store,
    UtensilsCrossed,
} from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';
import { toast } from 'sonner';
import { useBranchRealtimeRefresh } from '@/hooks/use-branch-realtime-refresh';
import {
    canTransitionKitchenStatus,
    filterKitchenTickets,
    KITCHEN_REALTIME_EVENTS,
    orderTypeLabel,
    relativePlacedTime,
    statusLabel,
} from '@/lib/kitchen';
import { update as updateKitchenStatus } from '@/routes/orders/kitchen-status';
import { customerDisplay } from '@/routes/workspaces';
import type {
    BranchContext,
    KitchenBoardData,
    KitchenStatus,
    KitchenTicket,
} from '@/types';

type Props = {
    workspace: string;
    kitchenBoard: KitchenBoardData;
};

type SharedProps = {
    branchContext: BranchContext;
};

const TABS: { key: 'all' | KitchenStatus; label: string }[] = [
    { key: 'all', label: 'All orders' },
    { key: 'kitchen', label: 'Kitchen' },
    { key: 'preparing', label: 'Preparing' },
    { key: 'ready', label: 'Ready' },
    { key: 'done', label: 'Done' },
];

const STATUSES: KitchenStatus[] = ['kitchen', 'preparing', 'ready', 'done'];

export default function KitchenWorkspace({ kitchenBoard }: Props) {
    const { branchContext } = usePage<SharedProps>().props;
    const branch = branchContext.current;
    const surface = useRef<HTMLDivElement>(null);
    const [tab, setTab] = useRemember<'all' | KitchenStatus>(
        'all',
        'kitchen-active-tab',
    );
    const [search, setSearch] = useRemember('', 'kitchen-search');
    const [fullscreen, setFullscreen] = useState(false);
    const [updatingOrder, setUpdatingOrder] = useState<string | null>(null);

    useBranchRealtimeRefresh({
        branchId: branch?.id ?? '',
        channel: 'kitchen',
        events: KITCHEN_REALTIME_EVENTS,
        only: ['kitchenBoard'],
    });

    useEffect(() => {
        const synchronizeFullscreen = () => {
            setFullscreen(document.fullscreenElement === surface.current);
        };

        document.addEventListener('fullscreenchange', synchronizeFullscreen);

        return () =>
            document.removeEventListener(
                'fullscreenchange',
                synchronizeFullscreen,
            );
    }, []);

    const tickets = useMemo(
        () => filterKitchenTickets(kitchenBoard.tickets, tab, search),
        [kitchenBoard.tickets, search, tab],
    );

    async function toggleFullscreen() {
        try {
            if (document.fullscreenElement === surface.current) {
                await document.exitFullscreen();
            } else {
                await surface.current?.requestFullscreen();
            }
        } catch {
            toast.error('Fullscreen could not be opened in this browser.');
        }
    }

    function transition(ticket: KitchenTicket, status: KitchenStatus) {
        if (updatingOrder !== null || !kitchenBoard.is_open) {
            return;
        }

        setUpdatingOrder(ticket.id);
        router.patch(
            updateKitchenStatus.url(ticket.id),
            { status },
            {
                only: ['kitchenBoard'],
                preserveScroll: true,
                preserveState: true,
                onError: (errors) =>
                    toast.error(
                        typeof errors.status === 'string'
                            ? errors.status
                            : 'The kitchen status could not be changed.',
                    ),
                onFinish: () => setUpdatingOrder(null),
            },
        );
    }

    return (
        <>
            <Head title="Kitchen display" />
            <div
                ref={surface}
                className={`flex min-h-full flex-col bg-[#f5f5f3] text-[#111] ${fullscreen ? 'fixed inset-0 z-[100] overflow-y-auto' : ''}`}
            >
                <header className="sticky top-0 z-20 border-b border-neutral-200 bg-white">
                    <div className="flex flex-wrap items-center gap-2 px-3 py-2.5 md:px-4">
                        <nav
                            aria-label="Kitchen status filters"
                            className="order-3 flex w-full gap-1 overflow-x-auto min-[1100px]:order-1 min-[1100px]:w-auto"
                        >
                            {TABS.map(({ key, label }) => (
                                <button
                                    key={key}
                                    type="button"
                                    onClick={() => setTab(key)}
                                    aria-pressed={tab === key}
                                    className={`flex min-h-11 shrink-0 items-center gap-2 rounded-xl px-3 text-xs font-bold transition ${tab === key ? 'bg-[#111] text-white' : 'bg-neutral-50 text-neutral-800 hover:bg-neutral-100'}`}
                                >
                                    {label}
                                    <span
                                        className={`rounded-full px-1.5 py-0.5 text-[10px] ${tab === key ? 'bg-white/15' : 'bg-neutral-100'}`}
                                    >
                                        {kitchenBoard.counts[key]}
                                    </span>
                                </button>
                            ))}
                        </nav>
                        <p className="hidden text-[11px] font-black tracking-[0.18em] text-neutral-900 uppercase min-[1100px]:order-2 min-[1100px]:ml-2 min-[1100px]:block">
                            Kitchen display
                        </p>
                        {!fullscreen && (
                            <label className="relative order-1 min-w-0 flex-1 min-[1100px]:order-3 min-[1100px]:ml-auto min-[1100px]:w-[260px] min-[1100px]:flex-none">
                                <Search className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-neutral-400" />
                                <span className="sr-only">Search orders</span>
                                <input
                                    value={search}
                                    onChange={(event) =>
                                        setSearch(event.target.value)
                                    }
                                    placeholder="Search ticket or customer"
                                    className="h-11 w-full rounded-xl border border-neutral-200 bg-[#fafafa] pr-3 pl-9 text-sm outline-none focus:border-neutral-500"
                                />
                            </label>
                        )}
                        <button
                            type="button"
                            onClick={toggleFullscreen}
                            className="order-2 inline-flex h-11 items-center gap-2 rounded-xl bg-[#111] px-3 text-xs font-bold text-white transition hover:bg-neutral-800 min-[1100px]:order-4 min-[1100px]:px-4"
                        >
                            {fullscreen ? (
                                <Shrink className="size-4" />
                            ) : (
                                <Expand className="size-4" />
                            )}
                            <span className="hidden min-[520px]:inline">
                                {fullscreen ? 'Exit full screen' : 'Full screen'}
                            </span>
                        </button>
                    </div>
                </header>

                {!kitchenBoard.is_open ? (
                    <ClosedKitchen />
                ) : tickets.length === 0 ? (
                    <div className="flex flex-1 flex-col items-center justify-center gap-3 px-6 py-20 text-center">
                        <span className="flex size-16 items-center justify-center rounded-2xl bg-white shadow-sm">
                            <UtensilsCrossed className="size-7 text-neutral-400" />
                        </span>
                        <h2 className="text-lg font-black">No matching orders</h2>
                        <p className="max-w-sm text-sm text-neutral-500">
                            New and updated tickets will appear here automatically.
                        </p>
                    </div>
                ) : (
                    <main
                        className={`grid items-start gap-3 p-3 pb-24 md:p-4 md:pb-24 ${fullscreen ? 'grid-cols-2 min-[480px]:grid-cols-3 min-[768px]:grid-cols-4 min-[1100px]:grid-cols-5 min-[1500px]:grid-cols-6' : 'grid-cols-1 sm:grid-cols-2 xl:grid-cols-3'}`}
                    >
                        {tickets.map((ticket) => (
                            <TicketCard
                                key={ticket.id}
                                ticket={ticket}
                                compact={fullscreen}
                                disabled={updatingOrder !== null}
                                onTransition={transition}
                            />
                        ))}
                    </main>
                )}

                {!fullscreen && (
                    <footer className="sticky bottom-0 z-20 mt-auto flex min-h-[72px] flex-wrap items-center gap-5 border-t border-white/10 bg-[#111] px-4 py-3 text-white">
                        <Summary label="Total active" value={kitchenBoard.counts.all} />
                        <Summary label="In prep" value={kitchenBoard.counts.preparing} />
                        <Summary label="Ready" value={kitchenBoard.counts.ready} />
                        <Link
                            href={customerDisplay()}
                            className="ml-auto inline-flex min-h-11 items-center gap-2 rounded-xl border border-white/20 px-4 text-xs font-bold transition hover:bg-white/10"
                        >
                            <MonitorUp className="size-4" /> Open customer display
                        </Link>
                    </footer>
                )}
            </div>
        </>
    );
}

function TicketCard({
    ticket,
    compact,
    disabled,
    onTransition,
}: {
    ticket: KitchenTicket;
    compact: boolean;
    disabled: boolean;
    onTransition: (ticket: KitchenTicket, status: KitchenStatus) => void;
}) {
    return (
        <article className={`overflow-hidden rounded-2xl border border-neutral-200 border-t-2 bg-white shadow-sm ${ticketBorderClass(ticket.status)}`}>
            <header className={`flex items-center justify-between gap-2 border-b border-neutral-200 px-3 py-2 ${ticketHeaderClass(ticket.status)}`}>
                <p className="flex min-w-0 items-center gap-1 truncate text-sm font-black tracking-tight">
                    <span>#{ticket.number}</span>
                    <span className="text-red-700">
                        {ticket.customer || 'Walk-in'}
                    </span>
                </p>
                <span className="shrink-0 rounded-full border border-current/15 bg-white/50 px-2 py-1 text-[9px] font-black tracking-wide uppercase">
                    {orderTypeLabel(ticket.order_type)}
                </span>
            </header>
            <div className={`space-y-3 ${compact ? 'p-2.5' : 'p-3'}`}>
                {ticket.items.map((item) => (
                    <div key={item.id} className="grid grid-cols-[auto_1fr] gap-2">
                        <span className="text-sm font-black">{item.quantity}×</span>
                        <div className="min-w-0">
                            <p className="text-sm leading-5 font-bold wrap-break-word">
                                {item.display_name}
                            </p>
                            {item.standard_modifiers.map((modifier) => (
                                <p key={modifier} className="text-[11px] leading-4 text-neutral-500">
                                    + {modifier}
                                </p>
                            ))}
                            {item.instructions.map((instruction) => (
                                <p key={instruction} className="text-[11px] leading-4 font-semibold text-amber-700">
                                    Instruction: {instruction}
                                </p>
                            ))}
                            {item.note && (
                                <p className="mt-1 rounded-md bg-red-50 px-2 py-1 text-[11px] leading-4 font-semibold text-red-800">
                                    Note: {item.note}
                                </p>
                            )}
                        </div>
                    </div>
                ))}
            </div>
            <div className="flex items-center justify-between border-t border-neutral-100 bg-neutral-50 px-3 py-2 text-[9px] font-black tracking-[0.14em] text-neutral-400 uppercase">
                <span>Status</span>
                <span className="tracking-normal text-red-700 normal-case tabular-nums">
                    {placedTimeLabel(ticket.placed_at)} · {relativePlacedTime(ticket.placed_at)}
                </span>
            </div>
            <div className="grid grid-cols-4 gap-1 border-t border-neutral-100 bg-neutral-50 p-2">
                {STATUSES.map((status) => {
                    const current = ticket.status === status;
                    const allowed = canTransitionKitchenStatus(
                        ticket.status,
                        status,
                    );

                    return (
                        <button
                            key={status}
                            type="button"
                            disabled={disabled || current || !allowed}
                            onClick={() => onTransition(ticket, status)}
                            className={`min-h-11 rounded-lg border px-1 text-[9px] font-black uppercase transition sm:text-[10px] ${current ? statusButtonClass(status) : 'border-neutral-200 bg-white text-neutral-700 hover:border-neutral-400 disabled:bg-neutral-100 disabled:text-neutral-300'}`}
                        >
                            {compact && status === 'preparing'
                                ? 'Prep'
                                : statusLabel(status)}
                        </button>
                    );
                })}
            </div>
        </article>
    );
}

function statusButtonClass(status: KitchenStatus): string {
    return {
        kitchen: 'border-amber-500 bg-amber-100 text-amber-950',
        preparing: 'border-blue-600 bg-blue-100 text-blue-800',
        ready: 'border-emerald-700 bg-emerald-600 text-white',
        done: 'border-neutral-400 bg-neutral-300 text-neutral-700',
    }[status];
}

function ticketBorderClass(status: KitchenStatus): string {
    return {
        kitchen: 'border-t-amber-500',
        preparing: 'border-t-blue-600',
        ready: 'border-t-emerald-600',
        done: 'border-t-neutral-400',
    }[status];
}

function ticketHeaderClass(status: KitchenStatus): string {
    return {
        kitchen: 'bg-amber-50',
        preparing: 'bg-blue-50',
        ready: 'bg-emerald-50',
        done: 'bg-neutral-100',
    }[status];
}

function placedTimeLabel(placedAt: string): string {
    return new Date(placedAt).toLocaleTimeString([], {
        hour: '2-digit',
        minute: '2-digit',
    });
}

function Summary({ label, value }: { label: string; value: number }) {
    return (
        <span className="inline-flex flex-col text-[9px] font-bold tracking-[0.12em] text-white/50 uppercase">
            {label}
            <strong className="text-base tracking-normal text-white">{value}</strong>
        </span>
    );
}

function ClosedKitchen() {
    return (
        <div className="flex flex-1 flex-col items-center justify-center gap-4 px-6 py-20 text-center">
            <span className="flex size-16 items-center justify-center rounded-2xl bg-neutral-200 text-neutral-500">
                <Store className="size-7" />
            </span>
            <div>
                <h2 className="text-xl font-black">Store closed</h2>
                <p className="mt-2 max-w-md text-sm leading-6 text-neutral-500">
                    Kitchen tickets are inactive until a cashier opens the store for this branch.
                </p>
            </div>
        </div>
    );
}
