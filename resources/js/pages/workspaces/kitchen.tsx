import { Head, Link, http, usePage, useRemember } from '@inertiajs/react';
import {
    BellRing,
    ChefHat,
    CircleCheckBig,
    ClipboardList,
    Expand,
    Flame,
    MonitorUp,
    Search,
    Shrink,
    Store,
    UtensilsCrossed,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import {
    useCallback,
    useEffect,
    useMemo,
    useRef,
    useState,
    useSyncExternalStore,
} from 'react';
import { toast } from 'sonner';
import { useBranchRealtimeRefresh } from '@/hooks/use-branch-realtime-refresh';
import {
    canTransitionKitchenStatus,
    filterKitchenTickets,
    kitchenItemLabel,
    KITCHEN_REALTIME_EVENTS,
    orderTypeLabel,
    relativePlacedTime,
    statusLabel,
} from '@/lib/kitchen';
import { update as updateKitchenStatus } from '@/routes/orders/kitchen-status';
import {
    KitchenTransitionStore,
    projectKitchenBoard,
} from '@/lib/kitchen-transitions';
import type { KitchenTransitionResult } from '@/lib/kitchen-transitions';
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

const TABS: {
    key: 'all' | KitchenStatus;
    label: string;
    icon: LucideIcon;
}[] = [
    { key: 'all', label: 'All orders', icon: ClipboardList },
    { key: 'kitchen', label: 'Kitchen', icon: ChefHat },
    { key: 'preparing', label: 'Preparing', icon: Flame },
    { key: 'ready', label: 'Ready', icon: BellRing },
    { key: 'done', label: 'Done', icon: CircleCheckBig },
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
    const transitions = useMemo(
        () => new KitchenTransitionStore(),
        [branch?.id],
    );
    const pendingTransitions = useSyncExternalStore(
        transitions.subscribe,
        transitions.snapshot,
        transitions.snapshot,
    );
    const projectedBoard = useMemo(
        () => projectKitchenBoard(kitchenBoard, pendingTransitions),
        [kitchenBoard, pendingTransitions],
    );
    useEffect(
        () => transitions.reconcile(kitchenBoard),
        [kitchenBoard, transitions],
    );
    const [now, setNow] = useState(() => Date.now());
    const knownTicketIds = useRef(
        new Set(kitchenBoard.tickets.map((ticket) => ticket.id)),
    );
    const pendingNewTicketIds = useRef(new Set<string>());
    const { playNewOrderSounds, playReadySound } = useKitchenAudio();

    const handleRealtimeEvent = useCallback(
        (event: Record<string, unknown>) => {
            if (event.event_type !== 'kitchen.ticket_created') {
                return;
            }

            const ticketId =
                typeof event.order_id === 'string'
                    ? event.order_id
                    : typeof event.entity_id === 'string'
                      ? event.entity_id
                      : null;

            if (ticketId !== null && !knownTicketIds.current.has(ticketId)) {
                pendingNewTicketIds.current.add(ticketId);
            }
        },
        [],
    );

    const { scheduleRefresh } = useBranchRealtimeRefresh({
        branchId: branch?.id ?? '',
        channel: 'kitchen',
        debounceMs: 35,
        events: KITCHEN_REALTIME_EVENTS,
        only: ['kitchenBoard'],
        onEvent: handleRealtimeEvent,
    });

    useEffect(() => {
        const interval = window.setInterval(() => setNow(Date.now()), 30_000);

        return () => window.clearInterval(interval);
    }, []);

    useEffect(() => {
        const currentIds = new Set(
            kitchenBoard.tickets.map((ticket) => ticket.id),
        );
        const newlyArrivedIds = [...pendingNewTicketIds.current].filter(
            (ticketId) =>
                currentIds.has(ticketId) &&
                !knownTicketIds.current.has(ticketId),
        );

        knownTicketIds.current = currentIds;
        newlyArrivedIds.forEach((ticketId) =>
            pendingNewTicketIds.current.delete(ticketId),
        );

        if (newlyArrivedIds.length > 0) {
            playNewOrderSounds(newlyArrivedIds.length);
        }
    }, [kitchenBoard.tickets, playNewOrderSounds]);

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
        () => filterKitchenTickets(projectedBoard.tickets, tab, search),
        [projectedBoard.tickets, search, tab],
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
        if (!kitchenBoard.is_open) {
            return;
        }
        void transitions.run(
            ticket,
            status,
            async () => {
                try {
                    const response = await http.getClient().request({
                        ...updateKitchenStatus(ticket.id),
                        data: { status },
                        headers: { Accept: 'application/json' },
                        signal: AbortSignal.timeout(15_000),
                    });
                    return (
                        JSON.parse(response.data) as {
                            kitchenTransition: KitchenTransitionResult;
                        }
                    ).kitchenTransition;
                } catch (error) {
                    const response = (error as { response?: { data: string } })
                        .response;
                    if (response) {
                        const data = JSON.parse(response.data) as {
                            errors?: { status?: string[] };
                            message?: string;
                        };
                        throw new Error(
                            data.errors?.status?.[0] ??
                                data.message ??
                                'The kitchen status could not be changed.',
                        );
                    }
                    throw error;
                }
            },
            playReadySound,
            (error) =>
                toast.error(
                    error instanceof Error
                        ? error.message
                        : 'The kitchen status could not be changed.',
                ),
            scheduleRefresh,
        );
    }

    return (
        <>
            <Head title="Kitchen display" />
            <div
                ref={surface}
                className={`pos-surface flex min-h-full flex-col bg-[#f5f5f3] text-[#111] ${fullscreen ? 'fixed inset-0 z-[100] overflow-y-auto' : ''}`}
            >
                <header className="sticky top-0 z-20 border-b border-neutral-200 bg-white">
                    <div className="flex flex-wrap items-center gap-2 overflow-hidden px-3 py-2.5 sm:flex-nowrap md:px-4">
                        <nav
                            aria-label="Kitchen status filters"
                            className="order-2 flex min-w-0 basis-full gap-1 overflow-x-auto py-px sm:order-none sm:flex-1 sm:basis-auto"
                        >
                            {TABS.map(({ key, label, icon: Icon }) => (
                                <button
                                    key={key}
                                    type="button"
                                    onClick={() => setTab(key)}
                                    aria-pressed={tab === key}
                                    className={`flex h-[42px] shrink-0 items-center gap-1.5 rounded-full border px-2.5 text-[12px] font-semibold whitespace-nowrap transition ${tab === key ? tabClass(key) : 'border-[#e5e5e5] bg-white text-[#111] hover:border-[#949494]'}`}
                                >
                                    <Icon className="size-3.5" />
                                    {label}
                                    <span
                                        className={`text-[10.5px] font-bold tabular-nums ${tab === key ? activeCountClass(key) : 'text-[#949494]'}`}
                                    >
                                        {projectedBoard.counts[key]}
                                    </span>
                                </button>
                            ))}
                        </nav>
                        {!fullscreen ? (
                            <label className="relative min-w-0 flex-1 min-[1280px]:w-[240px] sm:w-[180px] sm:flex-none">
                                <Search className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-neutral-400" />
                                <span className="sr-only">Search orders</span>
                                <input
                                    value={search}
                                    onChange={(event) =>
                                        setSearch(event.target.value)
                                    }
                                    placeholder="Search ticket or customer"
                                    className="h-[42px] w-full rounded-[10px] border border-[#e5e5e5] bg-[#f7f7f7] pr-3 pl-9 text-sm outline-none focus:border-neutral-500"
                                />
                            </label>
                        ) : (
                            <p className="shrink-0 px-2 text-[11px] font-black tracking-[0.16em] whitespace-nowrap text-neutral-800">
                                KITCHEN DISPLAY
                            </p>
                        )}
                        <button
                            type="button"
                            onClick={toggleFullscreen}
                            className="inline-flex h-[42px] shrink-0 items-center gap-2 rounded-[10px] bg-[#111] px-3 text-[12.5px] font-semibold text-white transition hover:bg-neutral-800 min-[520px]:px-4"
                        >
                            {fullscreen ? (
                                <Shrink className="size-4" />
                            ) : (
                                <Expand className="size-4" />
                            )}
                            <span className="hidden min-[520px]:inline">
                                {fullscreen
                                    ? 'Exit full screen'
                                    : 'Full screen'}
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
                        <h2 className="text-lg font-black">
                            No matching orders
                        </h2>
                        <p className="max-w-sm text-sm text-neutral-500">
                            New and updated tickets will appear here
                            automatically.
                        </p>
                    </div>
                ) : (
                    <main
                        className={`grid items-start gap-3 p-3 pb-24 md:p-4 md:pb-24 ${fullscreen ? 'grid-cols-2 min-[480px]:grid-cols-3 min-[768px]:grid-cols-4 min-[1100px]:grid-cols-5 min-[1500px]:grid-cols-6' : 'grid-cols-1 md:grid-cols-2 min-[1180px]:grid-cols-3'}`}
                    >
                        {tickets.map((ticket) => (
                            <TicketCard
                                key={ticket.id}
                                ticket={ticket}
                                compact={fullscreen}
                                now={now}
                                disabled={
                                    pendingTransitions[ticket.id]?.pending ??
                                    false
                                }
                                onTransition={transition}
                            />
                        ))}
                    </main>
                )}

                {!fullscreen && (
                    <footer className="sticky bottom-0 z-20 mt-auto flex min-h-[72px] flex-wrap items-center gap-5 border-t border-white/10 bg-[#111] px-4 py-3 text-white">
                        <Summary
                            label="Total active"
                            value={projectedBoard.counts.all}
                        />
                        <Summary
                            label="In prep"
                            value={projectedBoard.counts.preparing}
                        />
                        <Summary
                            label="Ready"
                            value={projectedBoard.counts.ready}
                        />
                        <Link
                            href={customerDisplay()}
                            className="ml-auto inline-flex min-h-11 items-center gap-2 rounded-xl border border-white/20 px-4 text-xs font-bold transition hover:bg-white/10"
                        >
                            <MonitorUp className="size-4" /> Open customer
                            display
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
    now,
    disabled,
    onTransition,
}: {
    ticket: KitchenTicket;
    compact: boolean;
    now: number;
    disabled: boolean;
    onTransition: (ticket: KitchenTicket, status: KitchenStatus) => void;
}) {
    return (
        <article
            aria-busy={disabled}
            className={`overflow-hidden rounded-[14px] border border-t-[3px] bg-white shadow-[0_1px_2px_rgba(17,17,17,0.05),0_10px_26px_-14px_rgba(17,17,17,0.22)] ${ticketCardClass(ticket.order_type)}`}
        >
            <header
                className={`grid grid-cols-[minmax(0,1fr)_auto] items-start gap-x-2 gap-y-1 border-b px-3 py-2 ${ticketHeaderClass(ticket.order_type)}`}
            >
                <p className="min-w-0 text-sm font-black tracking-tight wrap-anywhere">
                    <span className="whitespace-nowrap">#{ticket.number}</span>{' '}
                    {disabled && (
                        <span className="text-[9px] font-normal text-neutral-500">
                            Saving...{' '}
                        </span>
                    )}
                    <span className="text-red-700">
                        {ticket.customer || 'Walk-in'}
                    </span>
                </p>
                <span
                    className={`shrink-0 rounded-full border bg-white px-2 py-1 text-[9px] font-black tracking-wide uppercase ${orderTypeChipClass(ticket.order_type)}`}
                >
                    {orderTypeLabel(ticket.order_type)}
                </span>
                <p className="col-span-2 text-[9px] font-bold tabular-nums">
                    <span className="text-neutral-500">
                        {placedTimeLabel(ticket.placed_at)} ·{' '}
                    </span>
                    <span className="text-red-700">
                        {relativePlacedTime(ticket.placed_at, now)}
                    </span>
                </p>
            </header>
            <div className={`space-y-3 ${compact ? 'p-2.5' : 'p-3'}`}>
                {ticket.items.map((item) => (
                    <div
                        key={item.id}
                        className="grid grid-cols-[auto_1fr] gap-2"
                    >
                        <span className="text-sm font-black">
                            {item.quantity}×
                        </span>
                        <div className="min-w-0">
                            <p className="text-sm leading-5 font-bold wrap-break-word">
                                {kitchenItemLabel(
                                    item.display_name,
                                    item.standard_modifiers,
                                )}
                            </p>
                            {item.instructions.length > 0 && (
                                <p className="mt-1 inline-flex max-w-full rounded-md border border-[#fde68a] bg-[#fffbeb] px-2 py-1 text-[11px] leading-4 font-semibold wrap-break-word text-[#92400e]">
                                    {item.instructions.join(', ')}
                                </p>
                            )}
                            {item.note && (
                                <p className="mt-1 rounded-md border border-red-100 bg-red-50 px-2 py-1 text-[11px] leading-4 font-semibold wrap-break-word text-red-800">
                                    Note: {item.note}
                                </p>
                            )}
                        </div>
                    </div>
                ))}
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
                            className={`min-h-9 rounded-[7px] border px-1 text-[9px] font-bold uppercase transition sm:text-[10px] ${current ? statusButtonClass(status) : allowed ? 'border-[#c9c9c9] bg-white text-[#111] hover:border-[#949494]' : 'border-[#ededed] bg-white text-[#c9c9c9]'}`}
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
        kitchen: 'border-[#f59e0b] bg-[#fef3c7] text-[#92400e]',
        preparing: 'border-[#3b82f6] bg-[#dbeafe] text-[#1d4ed8]',
        ready: 'border-[#22c55e] bg-[#dcfce7] text-[#15803d]',
        done: 'border-[#a1a1aa] bg-[#ededed] text-[#52525b]',
    }[status];
}

function tabClass(tab: 'all' | KitchenStatus): string {
    return {
        all: 'border-[#111] bg-[#111] text-white',
        kitchen: 'border-[#f59e0b] bg-[#fef3c7] text-[#92400e]',
        preparing: 'border-[#3b82f6] bg-[#dbeafe] text-[#1d4ed8]',
        ready: 'border-[#22c55e] bg-[#dcfce7] text-[#15803d]',
        done: 'border-[#a1a1aa] bg-[#ededed] text-[#52525b]',
    }[tab];
}

function activeCountClass(tab: 'all' | KitchenStatus): string {
    return {
        all: 'text-white/70',
        kitchen: 'text-[#b45309]',
        preparing: 'text-[#3b82f6]',
        ready: 'text-[#22c55e]',
        done: 'text-[#767676]',
    }[tab];
}

function ticketCardClass(type: KitchenTicket['order_type']): string {
    return type === 'dine_in'
        ? 'border-[#bbf7d0] border-t-[#15803d]'
        : 'border-[#bfdbfe] border-t-[#1d4ed8]';
}

function ticketHeaderClass(type: KitchenTicket['order_type']): string {
    return type === 'dine_in'
        ? 'border-[#bbf7d0] bg-[#f0fdf4]'
        : 'border-[#bfdbfe] bg-[#eff6ff]';
}

function orderTypeChipClass(type: KitchenTicket['order_type']): string {
    return type === 'dine_in'
        ? 'border-[#bbf7d0] text-[#15803d]'
        : 'border-[#bfdbfe] text-[#1d4ed8]';
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
            <strong className="text-base tracking-normal text-white">
                {value}
            </strong>
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
                    Kitchen tickets are inactive until a cashier opens the store
                    for this branch.
                </p>
            </div>
        </div>
    );
}

function useKitchenAudio() {
    const newOrderAudio = useRef<HTMLAudioElement | null>(null);
    const readyAudio = useRef<HTMLAudioElement | null>(null);
    const newOrderPlaybackQueue = useRef(Promise.resolve());
    const readyPlaybackQueue = useRef(Promise.resolve());

    useEffect(() => {
        const newOrder = new Audio('/audio/kitchen-new-order.mp3');
        const ready = new Audio('/audio/kitchen-pa-serve.mp3');
        newOrder.preload = 'auto';
        ready.preload = 'auto';
        newOrderAudio.current = newOrder;
        readyAudio.current = ready;

        const unlock = () => {
            for (const audio of [newOrder, ready]) {
                audio.muted = true;
                const playback = audio.play();
                void playback
                    .then(() => {
                        audio.pause();
                        audio.currentTime = 0;
                        audio.muted = false;
                    })
                    .catch(() => {
                        audio.muted = false;
                    });
            }

            document.removeEventListener('pointerdown', unlock, true);
            document.removeEventListener('keydown', unlock, true);
        };

        document.addEventListener('pointerdown', unlock, true);
        document.addEventListener('keydown', unlock, true);

        return () => {
            document.removeEventListener('pointerdown', unlock, true);
            document.removeEventListener('keydown', unlock, true);
            newOrder.pause();
            ready.pause();
            newOrderAudio.current = null;
            readyAudio.current = null;
        };
    }, []);

    const playNewOrderSounds = useCallback((count: number) => {
        for (let index = 0; index < count; index += 1) {
            newOrderPlaybackQueue.current = newOrderPlaybackQueue.current
                .then(() => playAudioToEndSafely(newOrderAudio.current))
                .catch(() => undefined);
        }
    }, []);
    const playReadySound = useCallback(() => {
        readyPlaybackQueue.current = readyPlaybackQueue.current
            .then(() => playAudioToEndSafely(readyAudio.current))
            .catch(() => undefined);
    }, []);

    return { playNewOrderSounds, playReadySound };
}

function playAudioToEndSafely(audio: HTMLAudioElement | null): Promise<void> {
    if (audio === null) {
        return Promise.resolve();
    }

    return new Promise((resolve) => {
        const finish = () => {
            audio.removeEventListener('ended', finish);
            audio.removeEventListener('error', finish);
            resolve();
        };

        audio.addEventListener('ended', finish, { once: true });
        audio.addEventListener('error', finish, { once: true });
        audio.pause();
        audio.currentTime = 0;
        void audio.play().catch(finish);
    });
}
