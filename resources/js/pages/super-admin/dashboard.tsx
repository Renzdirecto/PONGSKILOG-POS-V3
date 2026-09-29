import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    AlertOctagon,
    AlertTriangle,
    ArrowRight,
    Banknote,
    BellRing,
    ChefHat,
    ChevronRight,
    ClipboardList,
    KeyRound,
    LineChart,
    Package,
    QrCode,
    ReceiptText,
    Settings,
    ShieldBan,
    ShieldCheck,
    Store,
    Users,
    Wallet,
    type LucideIcon,
} from 'lucide-react';
import { useState } from 'react';
import {
    AnalyticsCard,
    BranchComparison,
    CategoryBars,
    CompareLegend,
    CompareToggle,
    EmptyNote,
    KpiGrid,
    MiniStat,
    PaymentMethodDonut,
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
} from '@/components/owner-ui';
import { useNotificationsPageRefresh } from '@/hooks/use-notification-center';
import { useReportsRealtimeRefresh } from '@/hooks/use-reports-realtime-refresh';
import { auditActionLabel } from '@/lib/audit-actions';
import {
    ATTENTION_TONES,
    storeOpenLabel,
    type AttentionItem,
    type ExecutiveAudit,
    type ExecutivePeople,
    type ExecutiveStore,
} from '@/lib/executive-dashboard';
import type { Analytics, KitchenNow } from '@/lib/owner-analytics';
import { countLabel } from '@/lib/owner-analytics';
import { DASHBOARD_PERIODS, SESSION_RESULTS } from '@/lib/reports';
import type { ReportSessionResult } from '@/lib/reports';
import { index as branchesIndex } from '@/routes/branches';
import { index as inventoryIndex } from '@/routes/inventory';
import { stock as ingredientStock } from '@/routes/operations';
import { accessControl, notifications } from '@/routes/super-admin';
import { index as staffIndex } from '@/routes/super-admin/staff';
import {
    auditTrail,
    reports,
    superAdmin,
    voidOrders,
} from '@/routes/workspaces';
import type { Auth } from '@/types';

type DashboardPeriod = (typeof DASHBOARD_PERIODS)[number][0];

type ExecutiveAnalytics = Pick<
    Analytics,
    | 'comparison'
    | 'kpis'
    | 'trend'
    | 'collections'
    | 'payment_mix'
    | 'categories'
    | 'branches'
    | 'products'
>;

type LatestSession = {
    id: string;
    status: 'open' | 'closed';
    result: ReportSessionResult;
    branch: { id: string; name: string; code: string };
    business_date_short: string;
    time_range: string;
    orders: number;
    net_sales: string;
    collections: { cash: string; cashless: string; total: string };
    expenses: { total: string };
};

type InventoryAttention =
    | { mode: 'branch'; out_of_stock: number; low_stock: number }
    | {
          mode: 'branches';
          branches: {
              branch: { code: string };
              out_of_stock: number;
              low_stock: number;
          }[];
      };

type Props = {
    period: DashboardPeriod;
    analytics: ExecutiveAnalytics;
    report: {
        period: { label: string };
        scope: { id: string; name: string; code: string } | null;
        summary: {
            expenses: { total: string; count: number };
            sessions: { count: number; open: number };
            voids: { count: number; reversal: string };
        };
        latest_session: LatestSession | null;
    };
    kitchen: KitchenNow;
    inventory: InventoryAttention;
    stores: ExecutiveStore[];
    ingredients: { total: number; branches: { code: string; count: number }[] };
    people: ExecutivePeople;
    security: { unread: number; audit: ExecutiveAudit[] };
    attention: AttentionItem[];
};

/** Figures and live operating state refresh from the existing reports signal; people/security from the bell signal. */
const REPORT_PROPS = [
    'analytics',
    'report',
    'kitchen',
    'inventory',
    'stores',
    'ingredients',
    'attention',
];
const SECURITY_PROPS = ['security', 'people', 'attention'];

const ATTENTION_ICONS: Record<AttentionItem['tone'], LucideIcon> = {
    critical: AlertOctagon,
    warning: AlertTriangle,
    notice: Store,
    security: BellRing,
};

export default function SuperAdminDashboard({
    period,
    analytics,
    report,
    kitchen,
    inventory,
    stores,
    ingredients,
    people,
    security,
    attention,
}: Props) {
    const { auth } = usePage<{ auth: Auth }>().props;
    const [compare, setCompare] = useState(false);
    const [includeSplit, setIncludeSplit] = useState(false);
    const [loading, setLoading] = useState(false);
    const scopeLabel = report.scope
        ? `${report.scope.code} · ${report.scope.name}`
        : 'All Branches';
    const comparison = analytics.comparison.available
        ? `vs ${analytics.comparison.description}`
        : null;
    const reportsHref = reports({
        query: period === 'today' ? {} : { date: period },
    });

    useReportsRealtimeRefresh(REPORT_PROPS, report.scope?.id ?? null);
    useNotificationsPageRefresh(auth.user?.id ?? 0, SECURITY_PROPS);

    function choose(next: DashboardPeriod) {
        router.get(superAdmin(), next === 'today' ? {} : { period: next }, {
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
            <Head title="Executive Overview" />
            <OwnerPage
                title="Executive Overview"
                description="Sales, operating health and security for the whole business, from the same figures as Reports."
                maxWidth="max-w-[1320px]"
            >
                <section
                    aria-label="Scope and reporting period"
                    className="relative flex flex-wrap items-center gap-3 overflow-hidden rounded-[20px] bg-[#111] px-4 py-4 text-white sm:px-5"
                >
                    <div
                        aria-hidden="true"
                        className="pointer-events-none absolute -top-16 -right-10 size-48 rounded-full bg-emerald-500/20 blur-3xl"
                    />
                    <div className="relative flex min-w-0 flex-[1_1_220px] flex-col gap-0.5">
                        <span className="text-[10.5px] font-semibold tracking-[0.1em] text-white/60 uppercase">
                            Super Admin · Control Center
                        </span>
                        <h1 className="text-[20px] leading-tight font-bold tracking-[-0.02em] md:hidden">
                            Executive Overview
                        </h1>
                        <span className="truncate text-sm font-semibold">
                            {scopeLabel} · {report.period.label}
                        </span>
                        <span className="text-[11px] text-white/55">
                            Change the Branch from the header. Figures follow
                            the Store Session opening date.
                        </span>
                    </div>
                    <div className="relative rounded-[13px] bg-white p-[3px]">
                        <SegmentedTabs
                            label="Reporting period"
                            value={period}
                            options={DASHBOARD_PERIODS}
                            onChange={choose}
                        />
                    </div>
                </section>

                <AttentionPanel items={attention} />

                <div
                    aria-busy={loading}
                    className={`flex flex-col gap-3 transition-opacity ${loading ? 'opacity-60' : ''}`}
                >
                    <KpiGrid
                        analytics={analytics as Analytics}
                        comparison={comparison}
                        href={reportsHref}
                        icons={[
                            <Banknote
                                key="sales"
                                className="size-4 text-emerald-700"
                            />,
                            <ReceiptText
                                key="tx"
                                className="size-4 text-blue-700"
                            />,
                            <LineChart
                                key="aov"
                                className="size-4 text-blue-700"
                            />,
                            <Package key="items" className="size-4" />,
                            <QrCode
                                key="cashless"
                                className="size-4 text-blue-700"
                            />,
                        ]}
                    />
                    <section
                        aria-label="Money movement"
                        className="grid grid-cols-2 gap-3 lg:grid-cols-4"
                    >
                        <MiniStat
                            label="Collected"
                            value={peso(analytics.collections.total)}
                            note="Cash + cashless, net of corrections and voids"
                        />
                        <MiniStat
                            label="Expenses"
                            value={peso(report.summary.expenses.total)}
                            note={countLabel(
                                report.summary.expenses.count,
                                'Store expense',
                            )}
                        />
                        <MiniStat
                            label="Voided orders"
                            value={report.summary.voids.count.toLocaleString(
                                'en-PH',
                            )}
                            note={`${peso(report.summary.voids.reversal)} reversed`}
                        />
                        <MiniStat
                            label="Store Sessions"
                            value={report.summary.sessions.count.toLocaleString(
                                'en-PH',
                            )}
                            note={
                                report.summary.sessions.open > 0
                                    ? `${report.summary.sessions.open} open · live figures are provisional`
                                    : 'All closed and reconciled'
                            }
                        />
                    </section>

                    <div className="grid gap-3 min-[1120px]:grid-cols-[minmax(0,1.7fr)_minmax(0,1fr)]">
                        <AnalyticsCard
                            title="Sales trend"
                            hint={`${peso(analytics.kpis.sales.value ?? '0.00')} · ${countLabel(analytics.kpis.transactions.value ?? 0, 'order')} · peak ${peso(analytics.trend.peak.sales)}`}
                            action={
                                <CompareToggle
                                    on={compare}
                                    onToggle={() =>
                                        setCompare((value) => !value)
                                    }
                                    disabled={!analytics.comparison.available}
                                />
                            }
                        >
                            <TrendChart
                                buckets={analytics.trend.buckets}
                                metric="sales"
                                compare={compare}
                                height={206}
                            />
                            {compare && <CompareLegend />}
                        </AnalyticsCard>
                        <AnalyticsCard
                            title="Payment mix"
                            hint="Share of paid sales"
                            action={
                                <button
                                    type="button"
                                    aria-pressed={includeSplit}
                                    onClick={() =>
                                        setIncludeSplit((value) => !value)
                                    }
                                    className={`inline-flex min-h-11 items-center rounded-[9px] border px-[11px] text-xs font-semibold focus-visible:ring-2 focus-visible:ring-[#111] focus-visible:outline-none md:min-h-8 ${includeSplit ? 'border-[#111] bg-[#111] text-white' : 'border-[#e5e5e5] bg-white hover:border-[#111]'}`}
                                >
                                    Show Split
                                </button>
                            }
                        >
                            <PaymentMethodDonut
                                mix={analytics.payment_mix}
                                includeSplit={includeSplit}
                            />
                        </AnalyticsCard>
                    </div>

                    <div className="grid gap-3 min-[1000px]:grid-cols-3">
                        <OperationsHealth
                            stores={stores}
                            kitchen={kitchen}
                            inventory={inventory}
                            ingredients={ingredients}
                        />
                        <AnalyticsCard
                            title="Top products"
                            hint="By sales in this period"
                        >
                            <TopProductBars
                                products={analytics.products}
                                metric="sales"
                                limit={5}
                            />
                        </AnalyticsCard>
                        <AnalyticsCard
                            title="Sales by category"
                            hint="Grouped by each product's current category"
                        >
                            <CategoryBars categories={analytics.categories} />
                        </AnalyticsCard>
                    </div>

                    <div className="grid gap-3 min-[1120px]:grid-cols-[minmax(0,1.7fr)_minmax(0,1fr)]">
                        <AnalyticsCard
                            title="Branch performance"
                            hint={
                                analytics.branches &&
                                analytics.branches.length > 1
                                    ? 'Factual figures per Branch for this period, alphabetical.'
                                    : undefined
                            }
                        >
                            {analytics.branches === null ? (
                                <EmptyNote>
                                    Showing {scopeLabel} only. Choose All
                                    Branches in the header to compare Branches.
                                </EmptyNote>
                            ) : analytics.branches.length > 1 ? (
                                <BranchComparison
                                    branches={analytics.branches}
                                />
                            ) : (
                                <EmptyNote>
                                    One active Branch so far, so there is
                                    nothing to compare. Its figures are above.
                                </EmptyNote>
                            )}
                        </AnalyticsCard>
                        <LatestSessionCard
                            session={report.latest_session}
                            period={period}
                            scopeLabel={scopeLabel}
                        />
                    </div>

                    <PeopleSecurity people={people} security={security} />

                    <QuickActions permissions={auth.permissions} />
                </div>
            </OwnerPage>
        </>
    );
}

function AttentionPanel({ items }: { items: AttentionItem[] }) {
    if (items.length === 0) {
        return (
            <section
                aria-label="Attention needed"
                className="flex items-center gap-3 rounded-[16px] border border-emerald-200 bg-emerald-50 px-4 py-3 text-emerald-900"
            >
                <ShieldCheck className="size-5 shrink-0" aria-hidden="true" />
                <p className="text-[13px] font-semibold">
                    Nothing needs attention right now. Stock, Stores and
                    notifications are clear.
                </p>
            </section>
        );
    }

    return (
        <section aria-labelledby="attention-title" className="grid gap-2">
            <h2
                id="attention-title"
                className="text-[10.5px] font-semibold tracking-[0.1em] text-[#767676] uppercase"
            >
                Attention needed · {items.length}
            </h2>
            <ul className="grid gap-2 sm:grid-cols-2 xl:grid-cols-4">
                {items.map((item) => {
                    const Icon = ATTENTION_ICONS[item.tone];
                    const tone = ATTENTION_TONES[item.tone];

                    return (
                        <li key={item.key} className="min-w-0">
                            <Link
                                href={item.href}
                                className={`group flex h-full min-h-[76px] items-start gap-3 rounded-[16px] border p-3.5 transition focus-visible:ring-2 focus-visible:ring-[#111] focus-visible:outline-none ${tone.panel}`}
                            >
                                <span
                                    className={`inline-flex size-9 shrink-0 items-center justify-center rounded-xl ${tone.icon}`}
                                >
                                    <Icon
                                        className="size-[18px]"
                                        aria-hidden="true"
                                    />
                                </span>
                                <span className="min-w-0 flex-1">
                                    <span className="sr-only">
                                        {tone.label}:{' '}
                                    </span>
                                    <span className="block text-[13.5px] leading-5 font-bold">
                                        {item.title}
                                    </span>
                                    <span className="block text-[12px] leading-5 opacity-80">
                                        {item.detail}
                                    </span>
                                </span>
                                <ChevronRight
                                    className="mt-1 size-4 shrink-0 opacity-50 transition group-hover:opacity-100"
                                    aria-hidden="true"
                                />
                            </Link>
                        </li>
                    );
                })}
            </ul>
        </section>
    );
}

function OperationsHealth({
    stores,
    kitchen,
    inventory,
    ingredients,
}: {
    stores: ExecutiveStore[];
    kitchen: KitchenNow;
    inventory: InventoryAttention;
    ingredients: Props['ingredients'];
}) {
    const [out, low] =
        inventory.mode === 'branch'
            ? [inventory.out_of_stock, inventory.low_stock]
            : inventory.branches.reduce(
                  ([o, l], row) => [o + row.out_of_stock, l + row.low_stock],
                  [0, 0],
              );
    const stockTiles: [string, number, string, typeof inventoryIndex][] = [
        [
            'Products out',
            out,
            out > 0 ? 'text-red-700' : 'text-[#111]',
            inventoryIndex,
        ],
        [
            'Products low',
            low,
            low > 0 ? 'text-amber-700' : 'text-[#111]',
            inventoryIndex,
        ],
    ];

    return (
        <AnalyticsCard title="Operations health" hint="Live, right now">
            <ul className="flex flex-col gap-2">
                {stores.length === 0 ? (
                    <li>
                        <EmptyNote>No active Branch in this scope.</EmptyNote>
                    </li>
                ) : (
                    stores.map((store) => (
                        <li
                            key={store.branch.id}
                            className="flex items-center gap-2.5 rounded-[11px] border border-[#efefef] bg-[#fafafa] px-3 py-2.5"
                        >
                            <span
                                aria-hidden="true"
                                className={`size-2 shrink-0 rounded-full ${store.open ? 'bg-emerald-600' : 'bg-neutral-400'}`}
                            />
                            <span className="min-w-0 flex-1">
                                <span className="block truncate text-[13px] font-semibold">
                                    {store.branch.code} · {store.branch.name}
                                </span>
                                <span className="block truncate text-[11px] text-[#767676]">
                                    {storeOpenLabel(store)}
                                </span>
                            </span>
                            <OwnerStatusBadge
                                tone={store.open ? 'green' : 'neutral'}
                            >
                                {store.open ? 'OPEN' : 'CLOSED'}
                            </OwnerStatusBadge>
                        </li>
                    ))
                )}
            </ul>
            <div className="grid grid-cols-2 gap-2">
                <div className="flex min-w-0 flex-col gap-1 rounded-xl border border-[#efefef] bg-[#fafafa] px-3 py-2.5">
                    <span
                        className={`${ownerLabelClass} flex items-center gap-1`}
                    >
                        <ChefHat className="size-3.5" aria-hidden="true" />
                        Preparing
                    </span>
                    <span className="text-xl font-bold tabular-nums">
                        {kitchen.kitchen + kitchen.preparing}
                    </span>
                </div>
                <div className="flex min-w-0 flex-col gap-1 rounded-xl border border-[#efefef] bg-[#fafafa] px-3 py-2.5">
                    <span className={ownerLabelClass}>Ready to serve</span>
                    <span className="text-xl font-bold text-emerald-700 tabular-nums">
                        {kitchen.ready}
                    </span>
                </div>
                {stockTiles.map(([label, value, color, route]) => (
                    <Link
                        key={label}
                        href={route()}
                        className="flex min-w-0 flex-col gap-1 rounded-xl border border-[#efefef] bg-white px-3 py-2.5 hover:border-[#111] focus-visible:ring-2 focus-visible:ring-[#111] focus-visible:outline-none"
                    >
                        <span className={ownerLabelClass}>{label}</span>
                        <span
                            className={`text-xl font-bold tabular-nums ${color}`}
                        >
                            {value}
                        </span>
                    </Link>
                ))}
            </div>
            <Link
                href={ingredientStock()}
                className="flex items-center gap-2 rounded-[11px] border border-[#e5e5e5] bg-white px-3 py-2.5 text-[12.5px] hover:border-[#111] focus-visible:ring-2 focus-visible:ring-[#111] focus-visible:outline-none"
            >
                <span className="min-w-0 flex-1">
                    Ingredients at zero
                    {ingredients.branches.length > 0 && (
                        <span className="block truncate text-[11px] text-[#767676]">
                            {ingredients.branches
                                .map((row) => `${row.code} ${row.count}`)
                                .join(' · ')}
                        </span>
                    )}
                </span>
                <span
                    className={`text-[15px] font-bold tabular-nums ${ingredients.total > 0 ? 'text-red-700' : ''}`}
                >
                    {ingredients.total}
                </span>
            </Link>
        </AnalyticsCard>
    );
}

function LatestSessionCard({
    session,
    period,
    scopeLabel,
}: {
    session: LatestSession | null;
    period: DashboardPeriod;
    scopeLabel: string;
}) {
    return (
        <AnalyticsCard title="Latest Store Session">
            {session === null ? (
                <EmptyNote>
                    No Store Session was opened in this period for {scopeLabel}.
                </EmptyNote>
            ) : (
                <Link
                    href={reports({
                        query: {
                            ...(period === 'today' ? {} : { date: period }),
                            session: session.id,
                        },
                    })}
                    className="flex flex-col gap-3 rounded-xl border border-[#efefef] bg-[#fafafa] p-3.5 transition hover:border-[#111] focus-visible:ring-2 focus-visible:ring-[#111] focus-visible:outline-none"
                >
                    <span className="flex items-start justify-between gap-3">
                        <span className="min-w-0">
                            <span className="block truncate text-[13.5px] font-bold">
                                {session.branch.code} ·{' '}
                                {session.business_date_short}
                            </span>
                            <span className="block text-[11.5px] text-[#767676]">
                                {session.time_range}
                            </span>
                        </span>
                        <OwnerStatusBadge
                            tone={SESSION_RESULTS[session.result].tone}
                        >
                            {SESSION_RESULTS[session.result].label}
                        </OwnerStatusBadge>
                    </span>
                    <span className="grid grid-cols-2 gap-x-3 gap-y-2 text-[12px] tabular-nums">
                        {(
                            [
                                ['Net sales', peso(session.net_sales)],
                                ['Orders', String(session.orders)],
                                ['Cash', peso(session.collections.cash)],
                                [
                                    'Cashless',
                                    peso(session.collections.cashless),
                                ],
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
            )}
        </AnalyticsCard>
    );
}

function PeopleSecurity({
    people,
    security,
}: {
    people: ExecutivePeople;
    security: Props['security'];
}) {
    const tiles: [string, number, string][] = [
        ['Active staff', people.active, 'text-[#111]'],
        ['Inactive', people.inactive, 'text-[#767676]'],
        ['Super Admins', people.super_admins, 'text-violet-700'],
        ['Custom roles', people.custom_roles, 'text-violet-700'],
    ];

    return (
        <section
            aria-labelledby="people-security-title"
            className={`${ownerPanelClass} grid gap-4 p-4 min-[1000px]:grid-cols-[minmax(0,1fr)_minmax(0,1.4fr)] sm:p-[18px]`}
        >
            <div className="flex min-w-0 flex-col gap-3">
                <div className="flex items-center justify-between gap-2">
                    <h2
                        id="people-security-title"
                        className="flex items-center gap-2 text-[15px] font-bold tracking-[-0.01em]"
                    >
                        <span className="inline-flex size-7 items-center justify-center rounded-lg bg-violet-50 text-violet-700">
                            <Users className="size-4" aria-hidden="true" />
                        </span>
                        People &amp; security
                    </h2>
                    <Link
                        href={notifications()}
                        className="inline-flex min-h-11 items-center gap-1.5 rounded-[9px] border border-[#e5e5e5] bg-white px-[11px] text-xs font-semibold hover:border-[#111] focus-visible:ring-2 focus-visible:ring-[#111] focus-visible:outline-none md:min-h-8"
                    >
                        <BellRing className="size-3.5" aria-hidden="true" />
                        {security.unread === 0
                            ? 'No unread'
                            : `${security.unread} unread`}
                    </Link>
                </div>
                <div className="grid grid-cols-2 gap-2">
                    {tiles.map(([label, value, color]) => (
                        <div
                            key={label}
                            className="flex min-w-0 flex-col gap-1 rounded-xl border border-[#efefef] bg-[#fafafa] px-3 py-2.5"
                        >
                            <span className={ownerLabelClass}>{label}</span>
                            <span
                                className={`text-xl font-bold tabular-nums ${color}`}
                            >
                                {value}
                            </span>
                        </div>
                    ))}
                </div>
            </div>
            <div className="flex min-w-0 flex-col gap-2">
                <div className="flex items-center justify-between gap-2">
                    <h3 className="text-[13px] font-semibold">
                        Recent audit activity
                    </h3>
                    <Link
                        href={auditTrail()}
                        className="inline-flex min-h-11 items-center gap-1 text-xs font-semibold text-violet-800 hover:underline focus-visible:ring-2 focus-visible:ring-[#111] focus-visible:outline-none md:min-h-8"
                    >
                        Audit Trail
                        <ArrowRight className="size-3.5" aria-hidden="true" />
                    </Link>
                </div>
                {security.audit.length === 0 ? (
                    <EmptyNote>No audit activity recorded yet.</EmptyNote>
                ) : (
                    <ol className="flex flex-col divide-y divide-[#f0f0f0] rounded-[13px] border border-[#efefef]">
                        {security.audit.map((entry) => (
                            <li
                                key={entry.id}
                                className="flex items-center gap-2.5 px-3 py-2"
                            >
                                <span
                                    aria-hidden="true"
                                    className="size-1.5 shrink-0 rounded-full bg-violet-500"
                                />
                                <span className="min-w-0 flex-1">
                                    <span className="block truncate text-[12.5px] font-semibold">
                                        {auditActionLabel(entry.action)}
                                    </span>
                                    <span className="block truncate text-[11px] text-[#767676]">
                                        {entry.actor ?? 'System'}
                                        {entry.branch
                                            ? ` · ${entry.branch}`
                                            : ''}
                                    </span>
                                </span>
                                {entry.at && (
                                    <time
                                        dateTime={entry.at}
                                        className="shrink-0 text-[11px] text-[#8a8a8a] tabular-nums"
                                    >
                                        {new Date(entry.at).toLocaleString(
                                            'en-PH',
                                            {
                                                timeZone: 'Asia/Manila',
                                                month: 'short',
                                                day: 'numeric',
                                                hour: 'numeric',
                                                minute: '2-digit',
                                            },
                                        )}
                                    </time>
                                )}
                            </li>
                        ))}
                    </ol>
                )}
            </div>
        </section>
    );
}

type QuickAction = {
    label: string;
    icon: LucideIcon;
    href: ReturnType<typeof auditTrail>;
    permission: string;
};

const QUICK_ACTIONS: QuickAction[] = [
    {
        label: 'Staff',
        icon: Users,
        href: staffIndex(),
        permission: 'access_control.manage',
    },
    {
        label: 'Access Control',
        icon: KeyRound,
        href: accessControl(),
        permission: 'access_control.manage',
    },
    {
        label: 'Audit Trail',
        icon: ClipboardList,
        href: auditTrail(),
        permission: 'audit.view',
    },
    {
        label: 'Void Orders',
        icon: ShieldBan,
        href: voidOrders(),
        permission: 'void_orders.manage',
    },
    {
        label: 'Reports',
        icon: Wallet,
        href: reports(),
        permission: 'reports.view',
    },
    {
        label: 'Settings',
        icon: Settings,
        href: branchesIndex(),
        permission: 'settings.manage',
    },
];

function QuickActions({ permissions }: { permissions: string[] }) {
    const actions = QUICK_ACTIONS.filter((action) =>
        permissions.includes(action.permission),
    );

    return (
        <nav aria-labelledby="quick-actions-title" className="grid gap-2">
            <h2
                id="quick-actions-title"
                className="text-[10.5px] font-semibold tracking-[0.1em] text-[#767676] uppercase"
            >
                Quick admin actions
            </h2>
            <ul className="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-6">
                {actions.map(({ label, icon: Icon, href }) => (
                    <li key={label}>
                        <Link
                            href={href}
                            className="flex min-h-12 items-center gap-2 rounded-[12px] border border-[#e5e5e5] bg-white px-3 text-[12.5px] font-semibold transition hover:border-[#111] focus-visible:ring-2 focus-visible:ring-[#111] focus-visible:outline-none"
                        >
                            <Icon
                                className="size-4 shrink-0 text-[#666]"
                                aria-hidden="true"
                            />
                            <span className="truncate">{label}</span>
                        </Link>
                    </li>
                ))}
            </ul>
        </nav>
    );
}
