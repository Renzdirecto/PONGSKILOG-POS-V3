import { Head, Link, usePage } from '@inertiajs/react';
import {
    Banknote,
    ChefHat,
    ChevronRight,
    CircleCheck,
    Clock3,
    Flame,
    PackageSearch,
    ReceiptText,
    ShoppingBag,
    Smartphone,
    Store,
    TrendingUp,
    UtensilsCrossed,
    Wallet,
} from 'lucide-react';
import { useEffect, useState } from 'react';
import type { ComponentType, ReactNode } from 'react';
import { useBranchRealtimeRefresh } from '@/hooks/use-branch-realtime-refresh';
import { useStoreSessionDetails } from '@/hooks/use-store-session-details';
import { pesos } from '@/lib/pos-money';
import { formatStoreSessionOpenedAt } from '@/lib/store-session';
import { cashier, kitchen, transactionHistory } from '@/routes/workspaces';
import type { Auth, BranchContext } from '@/types';

type CashierDashboardData = {
    store: {
        is_open: boolean;
        session: {
            id: string;
            opened_at: string;
            opened_by: string;
        } | null;
    };
    summary: {
        orders: number;
        sales: string;
        cash: string;
        cashless: string;
        corrections: string;
        unallocated_corrections: string;
        split: { count: number; cash: string; cashless: string };
    } | null;
    kitchen: { kitchen: number; preparing: number; ready: number } | null;
    payments: { pending: number; balance_due: string } | null;
    expenses: { count: number; total: string } | null;
    inventory: { low_stock: number; out_of_stock: number };
};

type SharedProps = {
    auth: Auth;
    branchContext: BranchContext;
};

type Tile = {
    key: string;
    label: string;
    value: string;
    hint: string;
    icon: ComponentType<{ className?: string }>;
    tone: string;
    flag?: string;
};

const DASHBOARD_ORDER_EVENTS = [
    '.order.committed',
    '.order.updated',
    '.order.voided',
    '.kitchen.ticket_created',
    '.kitchen.status_changed',
] as const;
const DASHBOARD_EXPENSE_EVENTS = ['.store.expense_recorded'] as const;
const DASHBOARD_INVENTORY_EVENTS = ['.inventory.changed'] as const;
const DASHBOARD_REFRESH_PROPS = ['dashboard', 'storeContext'];

const cardClass =
    'rounded-2xl border border-[#e5e5e5] bg-white shadow-[0_1px_2px_rgba(17,17,17,.05),0_8px_20px_-12px_rgba(17,17,17,.18)]';

function sessionDuration(openedAt: string, now: number): string {
    const minutes = Math.max(
        0,
        Math.floor((now - new Date(openedAt).getTime()) / 60_000),
    );
    const hours = Math.floor(minutes / 60);

    return hours > 0 ? `${hours}h ${minutes % 60}m` : `${minutes}m`;
}

function useMinuteClock(): number {
    const [now, setNow] = useState(() => Date.now());

    useEffect(() => {
        const timer = window.setInterval(() => setNow(Date.now()), 60_000);

        return () => window.clearInterval(timer);
    }, []);

    return now;
}

function DashboardSection({
    title,
    note,
    columns,
    children,
}: {
    title: string;
    note?: string;
    columns: string;
    children: ReactNode;
}) {
    return (
        <section className="flex min-w-0 flex-col gap-2">
            <div className="flex items-baseline justify-between gap-3 px-0.5">
                <h2 className="text-[14px] font-bold">{title}</h2>
                {note && (
                    <span className="text-right text-[11px] text-[#767676]">
                        {note}
                    </span>
                )}
            </div>
            <div className={`grid gap-2.5 ${columns}`}>
                {children}
            </div>
        </section>
    );
}

function DashboardTile({ tile }: { tile: Tile }) {
    const Icon = tile.icon;

    return (
        <div
            className={`${cardClass} flex min-w-0 flex-col items-start gap-2 p-[11px]`}
        >
            <span className="flex w-full items-center justify-between gap-2">
                <span
                    className={`inline-flex size-8 shrink-0 items-center justify-center rounded-[10px] ${tile.tone}`}
                >
                    <Icon className="size-[18px]" />
                </span>
                {tile.flag && (
                    <span className="inline-flex h-5 items-center rounded-full bg-[#fef2f2] px-2 text-[10px] font-bold tracking-[.04em] text-[#b91c1c]">
                        {tile.flag}
                    </span>
                )}
            </span>
            <strong className="max-w-full text-[20px] leading-none font-bold tracking-[-0.03em] break-words tabular-nums sm:text-[24px]">
                {tile.value}
            </strong>
            <span className="flex w-full flex-col gap-0.5">
                <span className="text-[12.5px] font-semibold">
                    {tile.label}
                </span>
                <span className="text-[11px] leading-[1.4] text-pretty text-[#767676]">
                    {tile.hint}
                </span>
            </span>
        </div>
    );
}

export default function CashierDashboard({
    dashboard,
}: {
    dashboard: CashierDashboardData;
}) {
    const { auth, branchContext } = usePage<SharedProps>().props;
    const branch = branchContext.current;
    const openStoreSession = useStoreSessionDetails();
    const now = useMinuteClock();
    const { store, summary, payments, expenses, inventory } = dashboard;
    const kitchenCounts = dashboard.kitchen;
    const session = store.session;
    const closedHint = 'Store closed';
    const canUseKitchen = auth.permissions.includes('kitchen.access');

    useBranchRealtimeRefresh({
        branchId: branch?.id ?? '',
        channel: 'pos',
        events: DASHBOARD_ORDER_EVENTS,
        only: DASHBOARD_REFRESH_PROPS,
    });
    useBranchRealtimeRefresh({
        branchId: branch?.id ?? '',
        channel: 'store-session',
        events: DASHBOARD_EXPENSE_EVENTS,
        only: DASHBOARD_REFRESH_PROPS,
    });
    useBranchRealtimeRefresh({
        branchId: branch?.id ?? '',
        channel: 'inventory',
        events: DASHBOARD_INVENTORY_EVENTS,
        only: DASHBOARD_REFRESH_PROPS,
    });

    const splitHint =
        summary && summary.split.count > 0
            ? `Includes ${summary.split.count} split · ${pesos(summary.split.cash)} cash + ${pesos(summary.split.cashless)} cashless`
            : null;
    const hasCorrections = summary !== null && summary.corrections !== '0.00';
    const channelHint = (channel: string) =>
        hasCorrections
            ? `${channel} collected, net of corrections`
            : `${channel} payments this Store Session`;
    const unallocatedHint =
        summary && summary.unallocated_corrections !== '0.00'
            ? ` · ${pesos(summary.unallocated_corrections)} awaiting Cash/Cashless allocation`
            : '';
    const sessionTiles: Tile[] = [
        {
            key: 'orders',
            label: 'Orders',
            value: summary ? String(summary.orders) : '—',
            hint: summary ? 'Committed this Store Session' : closedHint,
            icon: ShoppingBag,
            tone: 'bg-[#f2f2f2] text-[#111]',
        },
        {
            key: 'sales',
            label: 'Sales',
            value: summary ? pesos(summary.sales) : '—',
            hint: summary
                ? hasCorrections
                    ? `Payments collected, less ${pesos(summary.corrections)} corrections${unallocatedHint}`
                    : 'Payments collected this Store Session'
                : closedHint,
            icon: TrendingUp,
            tone: 'bg-[#111] text-white',
        },
        {
            key: 'cash',
            label: 'Cash',
            value: summary ? pesos(summary.cash) : '—',
            hint: summary ? channelHint('Cash') : closedHint,
            icon: Banknote,
            tone: 'bg-[#f0fdf4] text-[#15803d]',
        },
        {
            key: 'cashless',
            label: 'Cashless',
            value: summary ? pesos(summary.cashless) : '—',
            hint: summary
                ? (splitHint ?? channelHint('Cashless'))
                : closedHint,
            icon: Smartphone,
            tone: 'bg-[#eff6ff] text-[#1d4ed8]',
        },
    ];
    const operationTiles: Tile[] = [
        {
            key: 'kitchen',
            label: 'In kitchen',
            value: kitchenCounts ? String(kitchenCounts.kitchen) : '—',
            hint: kitchenCounts ? 'Tickets not started yet' : closedHint,
            icon: ChefHat,
            tone: 'bg-[#fefce8] text-[#a16207]',
        },
        {
            key: 'preparing',
            label: 'Preparing',
            value: kitchenCounts ? String(kitchenCounts.preparing) : '—',
            hint: kitchenCounts ? 'Being cooked now' : closedHint,
            icon: Flame,
            tone: 'bg-[#eff6ff] text-[#1d4ed8]',
        },
        {
            key: 'ready',
            label: 'Ready',
            value: kitchenCounts ? String(kitchenCounts.ready) : '—',
            hint: kitchenCounts ? 'Waiting to be served' : closedHint,
            icon: CircleCheck,
            tone: 'bg-[#f0fdf4] text-[#15803d]',
        },
        {
            key: 'pending',
            label: 'Pending payments',
            value: payments ? String(payments.pending) : '—',
            hint: payments
                ? `${pesos(payments.balance_due)} balance due`
                : closedHint,
            icon: Clock3,
            tone: 'bg-[#fffbeb] text-[#b45309]',
            flag: payments && payments.pending > 0 ? 'Unpaid' : undefined,
        },
        {
            key: 'expenses',
            label: 'Expenses',
            value: expenses ? pesos(expenses.total) : '—',
            hint: expenses
                ? `${expenses.count} recorded this Store Session`
                : closedHint,
            icon: Wallet,
            tone: 'bg-[#f2f2f2] text-[#111]',
        },
        {
            key: 'low-stock',
            label: 'Low stock',
            value: String(inventory.low_stock),
            hint: `Tracked products · ${inventory.out_of_stock} out of stock`,
            icon: PackageSearch,
            tone: 'bg-[#fef2f2] text-[#b91c1c]',
            flag: inventory.low_stock > 0 ? 'Restock' : undefined,
        },
    ];
    const quickActions = [
        {
            key: 'pos',
            label: 'Go to POS',
            hint: 'Take new orders',
            icon: UtensilsCrossed,
            href: cashier(),
        },
        {
            key: 'history',
            label: 'Transaction History',
            hint: 'Receipts, balances and edits',
            icon: ReceiptText,
            href: transactionHistory(),
        },
        ...(canUseKitchen
            ? [
                  {
                      key: 'kitchen',
                      label: 'Kitchen',
                      hint: 'Kitchen display',
                      icon: ChefHat,
                      href: kitchen(),
                  },
              ]
            : []),
    ];

    return (
        <>
            <Head title="Dashboard" />
            <div className="px-3 pt-2.5 pb-4 md:px-[18px] md:pt-4 md:pb-[22px]">
                <div className="mb-3 flex flex-wrap items-center gap-2.5">
                    <div className="flex min-w-0 flex-[1_1_240px] flex-col gap-0.5">
                        <span className="flex min-w-0 items-center gap-2">
                            <span className="truncate text-[17px] font-bold tracking-[-0.02em]">
                                {branch?.name}
                            </span>
                            {branch && (
                                <span className="inline-flex h-6 shrink-0 items-center rounded-full border border-[#e5e5e5] bg-white px-2.5 text-[11px] font-semibold text-[#666]">
                                    {branch.code}
                                </span>
                            )}
                        </span>
                        <span className="text-[12.5px] text-[#666]">
                            Current branch and Store Session overview
                        </span>
                    </div>
                    <Link
                        href={cashier()}
                        className="inline-flex h-[46px] shrink-0 items-center justify-center gap-2 rounded-xl border border-[#111] bg-[#111] px-[18px] text-sm font-semibold text-white hover:bg-black active:scale-[.985]"
                    >
                        <UtensilsCrossed className="size-[18px]" />
                        Go to POS
                    </Link>
                </div>

                <div className="grid items-start gap-2.5 min-[1100px]:grid-cols-[minmax(0,1.5fr)_minmax(0,1fr)]">
                    <div className="flex min-w-0 flex-col gap-3.5 max-[1099px]:order-2">
                        <DashboardSection
                            title="Current Store Session"
                            note={
                                summary && summary.split.count > 0
                                    ? 'Split legs are already in Cash and Cashless'
                                    : undefined
                            }
                            columns="grid-cols-2 min-[680px]:grid-cols-4 min-[1100px]:grid-cols-2 min-[1400px]:grid-cols-4"
                        >
                            {sessionTiles.map((tile) => (
                                <DashboardTile key={tile.key} tile={tile} />
                            ))}
                        </DashboardSection>
                        <DashboardSection
                            title="Operations"
                            columns="grid-cols-2 min-[680px]:grid-cols-3"
                        >
                            {operationTiles.map((tile) => (
                                <DashboardTile key={tile.key} tile={tile} />
                            ))}
                        </DashboardSection>
                    </div>

                    <div className="flex min-w-0 flex-col gap-2.5 max-[1099px]:order-1">
                        <section
                            aria-label="Store status"
                            className="flex flex-col gap-2.5 rounded-2xl bg-[#111] p-[13px] text-white"
                        >
                            <div className="flex items-center justify-between gap-3">
                                <span className="flex items-center gap-2.5">
                                    <span className="inline-flex size-9 items-center justify-center rounded-[10px] bg-white/10">
                                        <Store className="size-[18px]" />
                                    </span>
                                    <span className="text-[15px] font-bold tracking-[.04em]">
                                        {store.is_open
                                            ? 'STORE OPEN'
                                            : 'STORE CLOSED'}
                                    </span>
                                </span>
                                {store.is_open ? (
                                    <span className="inline-flex h-7 items-center gap-1.5 rounded-full bg-[#f0fdf4] px-2.5 text-[11px] font-bold tracking-[.06em] text-[#15803d]">
                                        <span className="size-[7px] rounded-full bg-[#15803d]" />
                                        LIVE
                                    </span>
                                ) : (
                                    <span className="inline-flex h-7 items-center gap-1.5 rounded-full bg-white/10 px-2.5 text-[11px] font-bold tracking-[.06em] text-white/70">
                                        <span className="size-[7px] rounded-full bg-white/50" />
                                        CLOSED
                                    </span>
                                )}
                            </div>
                            <div className="flex flex-col gap-[9px]">
                                {[
                                    {
                                        key: 'Branch',
                                        value: branch
                                            ? `${branch.code} · ${branch.name}`
                                            : '—',
                                    },
                                    ...(session
                                        ? [
                                              {
                                                  key: 'Opened at',
                                                  value: formatStoreSessionOpenedAt(
                                                      session.opened_at,
                                                  ),
                                              },
                                              {
                                                  key: 'Opened by',
                                                  value: session.opened_by,
                                              },
                                              {
                                                  key: 'Duration',
                                                  value: sessionDuration(
                                                      session.opened_at,
                                                      now,
                                                  ),
                                              },
                                          ]
                                        : []),
                                ].map((row) => (
                                    <div
                                        key={row.key}
                                        className="flex items-center justify-between gap-3"
                                    >
                                        <span className="shrink-0 text-[11.5px] text-white/60">
                                            {row.key}
                                        </span>
                                        <span className="min-w-0 text-right text-[12.5px] font-semibold tabular-nums">
                                            {row.value}
                                        </span>
                                    </div>
                                ))}
                            </div>
                            {store.is_open ? (
                                openStoreSession && (
                                    <button
                                        type="button"
                                        onClick={openStoreSession}
                                        className="mt-0.5 inline-flex min-h-11 items-center justify-center rounded-xl bg-white px-4 text-[13px] font-semibold text-[#111] hover:bg-white/90 focus-visible:ring-2 focus-visible:ring-white focus-visible:outline-none"
                                    >
                                        View Store Session
                                    </button>
                                )
                            ) : (
                                <>
                                    <p className="text-[12px] leading-[1.5] text-white/65">
                                        No Store Session is open. Open the store
                                        from POS to start taking orders.
                                    </p>
                                    <Link
                                        href={cashier()}
                                        className="inline-flex min-h-11 items-center justify-center rounded-xl bg-white px-4 text-[13px] font-semibold text-[#111] hover:bg-white/90"
                                    >
                                        Go to POS
                                    </Link>
                                </>
                            )}
                        </section>

                        <section
                            aria-label="Quick actions"
                            className={`${cardClass} flex flex-col overflow-hidden`}
                        >
                            <div className="border-b border-[#e5e5e5] px-3.5 py-[11px] text-[14px] font-bold">
                                Quick actions
                            </div>
                            {quickActions.map((action) => {
                                const Icon = action.icon;

                                return (
                                    <Link
                                        key={action.key}
                                        href={action.href}
                                        className="flex min-h-14 items-center gap-[11px] border-b border-[#f2f2f2] px-3.5 py-2.5 last:border-b-0 hover:bg-[#f7f7f7]"
                                    >
                                        <span className="inline-flex size-[34px] shrink-0 items-center justify-center rounded-[10px] bg-[#f2f2f2] text-[#111]">
                                            <Icon className="size-[15px]" />
                                        </span>
                                        <span className="flex min-w-0 flex-1 flex-col gap-0.5">
                                            <span className="text-[13px] font-semibold">
                                                {action.label}
                                            </span>
                                            <span className="text-[11.5px] text-[#767676]">
                                                {action.hint}
                                            </span>
                                        </span>
                                        <ChevronRight className="size-4 shrink-0 text-[#b8b8b8]" />
                                    </Link>
                                );
                            })}
                        </section>
                    </div>
                </div>
            </div>
        </>
    );
}
