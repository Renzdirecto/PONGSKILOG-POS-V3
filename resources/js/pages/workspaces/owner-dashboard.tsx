import { Head, Link, router, usePage, usePoll } from '@inertiajs/react';
import {
    Banknote,
    ChevronRight,
    Clock3,
    LineChart,
    Package,
    QrCode,
    ReceiptText,
} from 'lucide-react';
import { useState } from 'react';
import {
    AnalyticsCard,
    BranchComparison,
    CategoryBars,
    CompareLegend,
    CompareToggle,
    EmptyNote,
    HourBars,
    KpiGrid,
    PaymentMix,
    SegmentedTabs,
    TopProductBars,
    TrendChart,
    ownerLabelClass,
    peso,
} from '@/components/owner-analytics';
import {
    OwnerPage,
    OwnerStatusBadge,
    ownerPanelClass,
    ownerSecondaryActionClass,
} from '@/components/owner-ui';
import {
    KITCHEN_CHIP,
    PAYMENT_METHOD_LABELS,
    countLabel,
    waitingLabel,
} from '@/lib/owner-analytics';
import type { Analytics, KitchenNow } from '@/lib/owner-analytics';
import { DASHBOARD_PERIODS, SESSION_RESULTS } from '@/lib/reports';
import type { ReportSessionResult } from '@/lib/reports';
import { index as inventoryIndex } from '@/routes/inventory';
import { owner, reports, transactions } from '@/routes/workspaces';
import type { Auth } from '@/types';

type DashboardPeriod = (typeof DASHBOARD_PERIODS)[number][0];

type SessionSummary = {
    id: string;
    status: 'open' | 'closed';
    result: ReportSessionResult;
    branch: { id: string; name: string; code: string };
    business_date_short: string;
    time_range: string;
    opened_by: string;
    closed_by: string | null;
    orders: number;
    net_sales: string;
    collections: { cash: string; cashless: string; total: string };
    expenses: { total: string };
};

type InventoryAttention =
    | {
          mode: 'branch';
          branch: { id: string; name: string; code: string };
          out_of_stock: number;
          low_stock: number;
          items: {
              id: string;
              name: string;
              status: 'out_of_stock' | 'low_stock';
              on_hand: number;
              low_stock_threshold: number | null;
          }[];
      }
    | {
          mode: 'branches';
          branches: {
              branch: { id: string; name: string; code: string };
              out_of_stock: number;
              low_stock: number;
          }[];
      };

type RecentTransaction = {
    id: string;
    order_number: string;
    customer_label: string | null;
    table_name: string | null;
    order_type: 'dine_in' | 'take_out';
    payment_status: 'paid' | 'unpaid' | 'partial';
    payment_method: 'cash' | 'cashless' | 'split' | null;
    kitchen_status: string;
    total: string;
    time_label: string | null;
    branch: { id: string; name: string; code: string };
};

type Props = {
    period: DashboardPeriod;
    analytics: Analytics;
    report: {
        period: { label: string; days: number };
        scope: { id: string; name: string; code: string } | null;
        summary: { expenses: { total: string }; sessions: { count: number; open: number } };
        sessions: SessionSummary[];
        sessions_total: number;
    };
    kitchen: KitchenNow;
    inventory: InventoryAttention | null;
    recentTransactions: RecentTransaction[];
};

const LIVE_PROPS = ['kitchen', 'inventory', 'recentTransactions'];

function manilaTime(iso: string): string {
    return new Date(iso).toLocaleTimeString('en-PH', {
        timeZone: 'Asia/Manila',
        hour: 'numeric',
        minute: '2-digit',
    });
}

export default function OwnerDashboard({
    period,
    analytics,
    report,
    kitchen,
    inventory,
    recentTransactions,
}: Props) {
    const { auth } = usePage<{ auth: Auth }>().props;
    const [compare, setCompare] = useState(false);
    const [loading, setLoading] = useState(false);
    const scopeLabel = report.scope
        ? `${report.scope.code} · ${report.scope.name}`
        : 'All Branches';
    const comparison = analytics.comparison.available
        ? `vs ${analytics.comparison.description}`
        : null;
    const canTransactions = auth.permissions.includes('transactions.view');
    const reportsHref = reports({
        query: period === 'today' ? {} : { date: period },
    });

    /** Kitchen, inventory and the latest transactions refresh on their own; period analytics reload on demand. */
    usePoll(30_000, { only: LIVE_PROPS });

    function choose(next: DashboardPeriod) {
        router.get(owner(), next === 'today' ? {} : { period: next }, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            only: ['period', 'analytics', 'report'],
            onStart: () => setLoading(true),
            onFinish: () => setLoading(false),
        });
    }

    return (
        <>
            <Head title="Dashboard" />
            <OwnerPage
                title="Dashboard"
                description="Sales, order flow and everything that needs attention, for the selected Branch scope."
            >
                <section
                    aria-label="Reporting period"
                    className={`${ownerPanelClass} flex flex-wrap items-center gap-2.5 px-3.5 py-3`}
                >
                    <div className="flex min-w-0 flex-[1_1_200px] flex-col gap-0.5">
                        <span className="text-[10.5px] font-semibold tracking-[0.07em] text-[#767676] uppercase">
                            Reporting period
                        </span>
                        <span className="truncate text-sm font-bold tracking-[-0.01em]">
                            {report.period.label}
                        </span>
                        <span className="text-[11px] text-[#8a8a8a]">
                            {scopeLabel} · by Store Session opening date
                        </span>
                    </div>
                    <SegmentedTabs
                        label="Reporting period"
                        value={period}
                        options={DASHBOARD_PERIODS}
                        onChange={choose}
                    />
                </section>

                <div
                    aria-busy={loading}
                    className={`flex flex-col gap-3 transition-opacity ${loading ? 'opacity-60' : ''}`}
                >
                    <KpiGrid
                        analytics={analytics}
                        comparison={comparison}
                        href={reportsHref}
                        icons={[
                            <Banknote key="sales" className="size-4" />,
                            <ReceiptText key="tx" className="size-4" />,
                            <LineChart key="aov" className="size-4" />,
                            <Package key="items" className="size-4" />,
                            <QrCode key="cashless" className="size-4" />,
                        ]}
                    />

                    <div className="grid gap-3 min-[1120px]:grid-cols-[minmax(0,1.7fr)_minmax(0,1fr)]">
                        <AnalyticsCard
                            title="Sales trend"
                            hint={`${peso(analytics.kpis.sales.value ?? '0.00')} · ${countLabel(analytics.kpis.transactions.value ?? 0, 'order')} · peak ${peso(analytics.trend.peak.sales)}`}
                            action={
                                <CompareToggle
                                    on={compare}
                                    onToggle={() => setCompare((value) => !value)}
                                    disabled={!analytics.comparison.available}
                                />
                            }
                        >
                            <TrendChart
                                buckets={analytics.trend.buckets}
                                metric="sales"
                                compare={compare}
                                height={182}
                            />
                            {compare && <CompareLegend />}
                        </AnalyticsCard>
                        <AnalyticsCard
                            title="Payment mix"
                            hint={`${peso(analytics.collections.total)} collected · net of corrections and voids`}
                        >
                            <PaymentMix collections={analytics.collections} />
                        </AnalyticsCard>
                    </div>

                    <div className="grid gap-3 min-[1000px]:grid-cols-2">
                        <AnalyticsCard
                            title="Sales by category"
                            hint="Share of sales in this period, grouped by each product's current category"
                        >
                            <CategoryBars categories={analytics.categories} />
                        </AnalyticsCard>
                        <AnalyticsCard
                            title="Peak sales hours"
                            hint={
                                analytics.peak_hour
                                    ? `${peso(analytics.peak_hour.sales)} · ${countLabel(analytics.peak_hour.transactions, 'order')} in the busiest hour`
                                    : 'No sales in this period'
                            }
                            action={
                                analytics.peak_hour && (
                                    <span className="inline-flex h-[26px] shrink-0 items-center rounded-full bg-[#111] px-2.5 text-[11px] font-bold whitespace-nowrap text-white">
                                        Peak {analytics.peak_hour.label}
                                    </span>
                                )
                            }
                        >
                            <HourBars
                                bars={analytics.dayparts}
                                metric="sales"
                                height={168}
                            />
                            <p className="text-[11px] text-[#8a8a8a]">
                                Two-hour blocks in Philippine time.
                            </p>
                        </AnalyticsCard>
                    </div>

                    <div className="grid gap-3 [grid-template-columns:repeat(auto-fit,minmax(290px,1fr))]">
                        <AnalyticsCard title="Top products">
                            <TopProductBars
                                products={analytics.products}
                                metric="sales"
                                limit={5}
                            />
                        </AnalyticsCard>
                        {inventory && (
                            <InventoryAttentionCard inventory={inventory} />
                        )}
                        <KitchenSnapshot kitchen={kitchen} scoped={report.scope !== null} />
                    </div>

                    {canTransactions && (
                        <AnalyticsCard
                            title="Recent transactions"
                            hint="Tap an order for its details and receipt"
                            action={
                                <Link
                                    href={transactions()}
                                    className={`${ownerSecondaryActionClass} inline-flex items-center gap-1`}
                                >
                                    All transactions
                                    <ChevronRight className="size-4" aria-hidden="true" />
                                </Link>
                            }
                        >
                            {recentTransactions.length === 0 ? (
                                <EmptyNote>No committed transactions yet in {scopeLabel}.</EmptyNote>
                            ) : (
                                <ul className="flex flex-col gap-2">
                                    {recentTransactions.map((transaction) => (
                                        <li key={transaction.id}>
                                            <RecentTransactionRow
                                                transaction={transaction}
                                                showBranch={report.scope === null}
                                            />
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </AnalyticsCard>
                    )}

                    {analytics.branches && (
                        <AnalyticsCard
                            title="Branch comparison"
                            hint="Factual figures per Branch for this period, alphabetical. Stock is never summed across Branches."
                        >
                            <BranchComparison branches={analytics.branches} />
                        </AnalyticsCard>
                    )}

                    <AnalyticsCard
                        title="Store Sessions"
                        hint={`${countLabel(report.sessions_total, 'Store Session')} in this period${report.summary.sessions.open > 0 ? ' · live figures are provisional' : ''}`}
                        action={
                            <Link
                                href={reportsHref}
                                className={`${ownerSecondaryActionClass} inline-flex items-center gap-1`}
                            >
                                Open reports
                                <ChevronRight className="size-4" aria-hidden="true" />
                            </Link>
                        }
                    >
                        {report.sessions.length === 0 ? (
                            <EmptyNote>No Store Session was opened in this period for {scopeLabel}.</EmptyNote>
                        ) : (
                            <ul className="grid gap-2.5 md:grid-cols-2">
                                {report.sessions.map((session) => (
                                    <li key={session.id}>
                                        <Link
                                            href={reports({
                                                query: {
                                                    ...(period === 'today' ? {} : { date: period }),
                                                    session: session.id,
                                                },
                                            })}
                                            className="flex flex-col gap-2 rounded-xl border border-[#efefef] bg-[#fafafa] p-3.5 transition hover:border-[#111] focus-visible:ring-2 focus-visible:ring-[#111] focus-visible:outline-none"
                                        >
                                            <span className="flex items-start justify-between gap-3">
                                                <span className="min-w-0">
                                                    <span className="block truncate text-[13.5px] font-bold">
                                                        {session.branch.code} · {session.business_date_short}
                                                    </span>
                                                    <span className="block text-[11.5px] text-[#767676]">
                                                        {session.time_range}
                                                    </span>
                                                </span>
                                                <OwnerStatusBadge tone={SESSION_RESULTS[session.result].tone}>
                                                    {SESSION_RESULTS[session.result].label}
                                                </OwnerStatusBadge>
                                            </span>
                                            <span className="grid grid-cols-2 gap-x-3 gap-y-1 text-[12px] tabular-nums sm:grid-cols-4">
                                                {(
                                                    [
                                                        ['Net sales', peso(session.net_sales)],
                                                        ['Cash', peso(session.collections.cash)],
                                                        ['Cashless', peso(session.collections.cashless)],
                                                        ['Expenses', peso(session.expenses.total)],
                                                    ] as const
                                                ).map(([label, value]) => (
                                                    <span key={label} className="min-w-0">
                                                        <span className="block text-[10px] tracking-[0.06em] text-[#8a8a8a] uppercase">
                                                            {label}
                                                        </span>
                                                        <span className="block font-semibold [overflow-wrap:anywhere]">
                                                            {value}
                                                        </span>
                                                    </span>
                                                ))}
                                            </span>
                                        </Link>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </AnalyticsCard>
                </div>
            </OwnerPage>
        </>
    );
}

function InventoryAttentionCard({ inventory }: { inventory: InventoryAttention }) {
    if (inventory.mode === 'branches') {
        const needing = inventory.branches.filter(
            (row) => row.out_of_stock + row.low_stock > 0,
        );

        return (
            <AnalyticsCard
                title="Inventory attention"
                hint="Stock is Branch-specific, so each Branch is counted on its own."
                action={
                    <Link
                        href={inventoryIndex()}
                        className="inline-flex min-h-11 shrink-0 items-center rounded-[9px] border border-[#e5e5e5] bg-white px-[11px] text-xs font-semibold hover:border-[#111] focus-visible:ring-2 focus-visible:ring-[#111] focus-visible:outline-none md:min-h-8"
                    >
                        Open
                    </Link>
                }
            >
                {needing.length === 0 ? (
                    <AllStocked />
                ) : (
                    <ul className="flex flex-col gap-[9px]">
                        {needing.map((row) => (
                            <li key={row.branch.id}>
                                <Link
                                    href={inventoryIndex({ query: { branch_id: row.branch.id } })}
                                    className="flex items-center gap-2.5 rounded-[11px] border border-[#efefef] bg-[#fafafa] px-[11px] py-2.5 hover:border-[#111] focus-visible:ring-2 focus-visible:ring-[#111] focus-visible:outline-none"
                                >
                                    <span className="flex min-w-0 flex-1 flex-col">
                                        <span className="truncate text-[13px] font-semibold">
                                            {row.branch.code} · {row.branch.name}
                                        </span>
                                        <span className="text-[11px] text-[#767676]">
                                            {countLabel(row.out_of_stock, 'product')} out · {countLabel(row.low_stock, 'product')} low
                                        </span>
                                    </span>
                                    <ChevronRight className="size-4 text-[#767676]" aria-hidden="true" />
                                </Link>
                            </li>
                        ))}
                    </ul>
                )}
            </AnalyticsCard>
        );
    }
    const remaining =
        inventory.out_of_stock + inventory.low_stock - inventory.items.length;

    return (
        <AnalyticsCard
            title="Inventory attention"
            hint={`${inventory.branch.code} · ${countLabel(inventory.out_of_stock, 'product')} out · ${countLabel(inventory.low_stock, 'product')} low`}
            action={
                <Link
                    href={inventoryIndex()}
                    className="inline-flex min-h-11 shrink-0 items-center rounded-[9px] border border-[#e5e5e5] bg-white px-[11px] text-xs font-semibold hover:border-[#111] focus-visible:ring-2 focus-visible:ring-[#111] focus-visible:outline-none md:min-h-8"
                >
                    Open
                </Link>
            }
        >
            {inventory.items.length === 0 ? (
                <AllStocked />
            ) : (
                <ul className="flex flex-col gap-[9px]">
                    {inventory.items.map((item) => {
                        const out = item.status === 'out_of_stock';

                        return (
                            <li
                                key={item.id}
                                className="flex items-center gap-2.5 rounded-[11px] border border-[#efefef] bg-[#fafafa] px-[11px] py-2.5"
                            >
                                <span className="flex min-w-0 flex-1 flex-col">
                                    <span className="truncate text-[13px] font-semibold">
                                        {item.name}
                                    </span>
                                    <span className="text-[11px] text-[#767676]">
                                        {out
                                            ? 'Hidden from POS and QR menu'
                                            : `${item.on_hand.toLocaleString('en-PH')} left · threshold ${item.low_stock_threshold ?? '—'}`}
                                    </span>
                                </span>
                                <span
                                    className={`inline-flex h-[22px] shrink-0 items-center rounded-full border px-[9px] text-[10px] font-bold tracking-[0.05em] ${out ? 'border-[#EFC5C5] bg-[#FEF2F2] text-[#B91C1C]' : 'border-[#FDE68A] bg-[#FFFBEB] text-[#92400E]'}`}
                                >
                                    {out ? 'OUT' : 'LOW'}
                                </span>
                                <Link
                                    href={inventoryIndex({
                                        query: {
                                            search: item.name,
                                            stock_status: item.status,
                                        },
                                    })}
                                    className="inline-flex min-h-11 shrink-0 items-center justify-center rounded-[10px] border border-[#e5e5e5] bg-white px-3 text-xs font-semibold hover:border-[#111] focus-visible:ring-2 focus-visible:ring-[#111] focus-visible:outline-none md:min-h-9"
                                >
                                    Adjust
                                </Link>
                            </li>
                        );
                    })}
                    {remaining > 0 && (
                        <li className="text-[11.5px] text-[#767676]">
                            {countLabel(remaining, 'more product')} need attention in Inventory.
                        </li>
                    )}
                </ul>
            )}
        </AnalyticsCard>
    );
}

function AllStocked() {
    return (
        <div className="flex flex-col items-center gap-2 px-3 py-[26px] text-center">
            <span className="inline-flex size-11 items-center justify-center rounded-xl bg-[#F0FDF4] text-[#15803D]">
                <Package className="size-5" aria-hidden="true" />
            </span>
            <span className="text-[13px] font-semibold">
                All tracked products are sufficiently stocked.
            </span>
        </div>
    );
}

function KitchenSnapshot({ kitchen, scoped }: { kitchen: KitchenNow; scoped: boolean }) {
    return (
        <AnalyticsCard
            title="Kitchen snapshot"
            action={
                <span className="text-[10.5px] whitespace-nowrap text-[#8a8a8a] tabular-nums">
                    As of {manilaTime(kitchen.as_of)}
                </span>
            }
        >
            {kitchen.open_sessions === 0 ? (
                <EmptyNote>
                    {scoped ? 'This Branch has no open Store Session.' : 'No Branch has an open Store Session.'}
                </EmptyNote>
            ) : (
                <>
                    <div className="grid grid-cols-3 gap-[9px]">
                        {(
                            [
                                ['Kitchen', kitchen.kitchen],
                                ['Preparing', kitchen.preparing],
                                ['Ready', kitchen.ready],
                            ] as const
                        ).map(([label, value]) => (
                            <div
                                key={label}
                                className="flex min-w-0 flex-col gap-1 rounded-xl border border-[#efefef] bg-[#fafafa] px-[11px] py-[13px]"
                            >
                                <span className={`${ownerLabelClass} truncate`}>{label}</span>
                                <span className="text-2xl leading-[1.05] font-bold tracking-[-0.02em] tabular-nums">
                                    {value}
                                </span>
                            </div>
                        ))}
                    </div>
                    <div className="flex items-center gap-[9px] rounded-[11px] border border-[#e5e5e5] bg-white px-3 py-[11px]">
                        <Clock3 className="size-4 shrink-0 text-[#767676]" aria-hidden="true" />
                        <span className="min-w-0 flex-1 text-xs text-[#666]">
                            Oldest ticket waiting
                            {kitchen.oldest && (
                                <span className="block truncate text-[11px] text-[#8a8a8a]">
                                    #{kitchen.oldest.order_number}
                                    {!scoped && ` · ${kitchen.oldest.branch_code}`} ·{' '}
                                    {KITCHEN_CHIP[kitchen.oldest.status]?.label.toLowerCase() ?? kitchen.oldest.status}
                                </span>
                            )}
                        </span>
                        <span className="text-[13px] font-bold tabular-nums">
                            {kitchen.oldest ? waitingLabel(kitchen.oldest.waiting_seconds) : '—'}
                        </span>
                    </div>
                </>
            )}
        </AnalyticsCard>
    );
}

function RecentTransactionRow({
    transaction,
    showBranch,
}: {
    transaction: RecentTransaction;
    showBranch: boolean;
}) {
    const pending = transaction.payment_status !== 'paid';
    const chip = pending
        ? {
              label: transaction.payment_status === 'partial' ? 'BALANCE' : 'PENDING',
              className: 'border-[#FDE68A] bg-[#FFFBEB] text-[#92400E]',
          }
        : (KITCHEN_CHIP[transaction.kitchen_status] ?? KITCHEN_CHIP.kitchen);
    const customer = transaction.customer_label || transaction.table_name || 'Walk-in';
    const method = transaction.payment_method
        ? PAYMENT_METHOD_LABELS[transaction.payment_method]
        : 'Unpaid';

    return (
        <Link
            href={transactions({
                query: { search: transaction.order_number, open: transaction.id },
            })}
            className="flex w-full items-center gap-3 rounded-xl border border-[#efefef] bg-white px-3 py-[11px] text-left transition hover:border-[#111] hover:bg-[#fafafa] focus-visible:ring-2 focus-visible:ring-[#111] focus-visible:outline-none"
        >
            <span className="flex min-w-0 flex-1 flex-col">
                <span className="text-[13.5px] font-bold tabular-nums">
                    #{transaction.order_number}
                    {showBranch && (
                        <span className="ml-1.5 text-[11px] font-semibold text-[#767676]">
                            {transaction.branch.code}
                        </span>
                    )}
                </span>
                <span className="truncate text-[11.5px] text-[#767676]">
                    {customer} · {transaction.time_label} · {method}
                </span>
            </span>
            <span
                className={`inline-flex h-[22px] shrink-0 items-center rounded-full border px-[9px] text-[10px] font-bold tracking-[0.05em] whitespace-nowrap ${chip.className}`}
            >
                {chip.label}
            </span>
            <span className="shrink-0 text-sm font-bold whitespace-nowrap tabular-nums">
                {peso(transaction.total)}
            </span>
        </Link>
    );
}
