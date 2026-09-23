import { Head, router, usePage } from '@inertiajs/react';
import {
    CalendarDays,
    Check,
    Download,
    FileSpreadsheet,
    FileText,
    Filter,
    Printer,
    X,
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
    MiniStat,
    PaymentMethodDonut,
    SegmentedTabs,
    TopProductBars,
    TrendChart,
} from '@/components/owner-analytics';
import {
    OwnerPage,
    ownerControlClass,
    ownerPanelClass,
    ownerPrimaryActionClass,
    ownerSecondaryActionClass,
} from '@/components/owner-ui';
import {
    Notice,
    SessionDetail,
    StoreSessionList,
    insetClass,
    isZero,
    labelClass,
    plural,
} from '@/components/report-store-sessions';
import type { SessionRow } from '@/components/report-store-sessions';
import { useReportsRealtimeRefresh } from '@/hooks/use-reports-realtime-refresh';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    PRODUCT_SORTS,
    SERIES_COLORS,
    durationLabel,
    nextCategorySelection,
    shareLabel,
    sortProducts,
} from '@/lib/owner-analytics';
import type {
    Analytics,
    KitchenNow,
    ProductSort,
} from '@/lib/owner-analytics';
import {
    REPORT_TABS,
    appliedSelection,
    customRangeError,
    draftSelection,
    reportQuery,
    toggleFilterValue,
} from '@/lib/reports';
import type {
    ReportFilters,
    ReportOrderFilters,
    ReportPreset,
} from '@/lib/reports';
import { formatDecimalPeso } from '@/lib/store-close';
import { reports } from '@/routes/workspaces';
import { exportMethod as exportReport } from '@/routes/workspaces/reports';

type ChannelMoney = { cash: string; cashless: string };

type Report = {
    period: {
        preset: ReportPreset;
        from: string;
        to: string;
        label: string;
        days: number;
        granularity: 'hour' | 'day' | 'month';
        kicker: string;
        comparison: {
            from: string;
            to: string;
            label: string;
            description: string;
        };
        session_filter_available: boolean;
    };
    scope: { id: string; name: string; code: string } | null;
    session_filter: {
        selected: string | null;
        ignored: boolean;
        available: boolean;
        options: { id: string; label: string; status: 'open' | 'closed' }[];
    };
    summary: {
        net_sales: string;
        orders: number;
        cash: string;
        cashless: string;
        collected: string;
        expenses: ChannelMoney & { total: string; count: number };
        sessions: { count: number; open: number };
        split: ChannelMoney & { count: number; total: string };
        corrections: ChannelMoney & {
            total: string;
            unallocated: string;
            count: number;
        };
        voids: ChannelMoney & { count: number; reversal: string };
    };
    days: {
        date: string;
        label: string;
        sessions: number;
        orders: number;
        net_sales: string;
        cash: string;
        cashless: string;
        expenses: string;
    }[];
    sessions: SessionRow[];
    sessions_listed: { shown: number; total: number };
};

type Props = {
    report: Report;
    analytics: Analytics;
    kitchenNow?: KitchenNow;
    filters: ReportFilters;
};

const peso = formatDecimalPeso;

export default function Reports({
    report,
    analytics,
    kitchenNow,
    filters,
}: Props) {
    const { errors } = usePage<{ errors: Record<string, string> }>().props;
    const [customOpen, setCustomOpen] = useState(
        report.period.preset === 'custom',
    );
    const [from, setFrom] = useState(filters.from ?? report.period.from);
    const [to, setTo] = useState(filters.to ?? report.period.to);
    const [customError, setCustomError] = useState<string | null>(null);
    const [loading, setLoading] = useState(false);
    const [detailId, setDetailId] = useState<string | null>(null);
    const [filtersOpen, setFiltersOpen] = useState(false);
    const [exportOpen, setExportOpen] = useState(false);
    const [trendMetric, setTrendMetric] = useState<'sales' | 'transactions'>(
        'sales',
    );
    const [compare, setCompare] = useState(false);
    const [hourMetric, setHourMetric] = useState<'sales' | 'transactions'>(
        'sales',
    );
    const [topMetric, setTopMetric] = useState<'sales' | 'quantity'>('sales');
    const [productSort, setProductSort] = useState<ProductSort>('sales_desc');
    const [includeSplit, setIncludeSplit] = useState(false);
    const [cashierSort, setCashierSort] = useState<'sales' | 'transactions'>(
        'sales',
    );
    const detail =
        report.sessions.find((session) => session.id === detailId) ?? null;
    const { summary, period } = report;
    const activePreset: ReportPreset = customOpen ? 'custom' : period.preset;
    const tabValue = REPORT_TABS.some(([key]) => key === activePreset)
        ? activePreset
        : 'custom';
    const scopeLabel = report.scope
        ? `${report.scope.code} · ${report.scope.name}`
        : 'All Branches';
    const pendingAllocation = !isZero(summary.corrections.unallocated);
    const comparison = analytics.comparison.available
        ? `vs ${analytics.comparison.description}`
        : null;
    const chips = filterChips(analytics);
    const categoryOptions = analytics.filter_options.categories;
    const selectedCategories = analytics.filters.categories;
    const categoryScope =
        selectedCategories.length === 0
            ? null
            : selectedCategories
                  .map(
                      (value) =>
                          categoryOptions.find(
                              (option) => option.value === value,
                          )?.label ?? 'Not in this period',
                  )
                  .join(', ');
    /** Category narrows the product views only, so it reloads the report with the same order filters. */
    const pickCategories = (categories: string[]) => visit({ categories });
    const productRows = sortProducts(analytics.products, productSort, null);
    const cashiers = [...analytics.cashiers].sort((a, b) =>
        cashierSort === 'transactions'
            ? b.transactions - a.transactions || b.sales_cents - a.sales_cents
            : b.sales_cents - a.sales_cents || b.transactions - a.transactions,
    );
    const cashierMax = Math.max(
        0,
        ...analytics.cashiers.map((cashier) => cashier.sales_cents),
    );
    const typeMax = Math.max(
        0,
        ...analytics.order_types.map((type) => type.sales_cents),
    );
    const prepMax = Math.max(
        0,
        ...analytics.kitchen.by_hour.map((hour) => hour.average_seconds ?? 0),
    );
    const currentQuery = reportQuery(filters, {});

    /** New orders, payments, voids, expenses and Store changes reload the report in place, keeping the filters. */
    useReportsRealtimeRefresh(
        ['report', 'analytics', 'kitchenNow'],
        report.scope?.id ?? null,
    );

    function visit(next: ReportFilters) {
        router.get(reports(), reportQuery(filters, next), {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            only: ['report', 'analytics', 'filters', 'errors'],
            onStart: () => setLoading(true),
            onFinish: () => setLoading(false),
        });
    }

    function choosePreset(preset: ReportPreset) {
        if (preset === 'custom') {
            setCustomOpen(true);

            return;
        }
        setCustomOpen(false);
        setCustomError(null);
        visit({ date: preset, from: undefined, to: undefined });
    }

    function applyCustom() {
        const error = customRangeError(from, to);
        setCustomError(error);
        if (error === null) {
            visit({ date: 'custom', from, to });
        }
    }

    /** Product performance chips toggle the same category filter as the modal and Sales by category. */
    function toggleCategoryChip(value: string) {
        pickCategories(
            appliedSelection(
                toggleFilterValue(
                    draftSelection(selectedCategories, categoryOptions),
                    value,
                ),
                categoryOptions,
            ),
        );
    }

    return (
        <>
            <Head title="Reports" />
            <OwnerPage
                title="Reports & analytics"
                description="Review sales, products, payments and operational performance. A business date is the Philippine date a Store Session opened."
            >
                <section
                    aria-label="Report period"
                    className={`${ownerPanelClass} flex flex-wrap items-center gap-3 px-4 py-3.5`}
                >
                    <SegmentedTabs
                        label="Business date"
                        value={tabValue}
                        options={REPORT_TABS}
                        onChange={choosePreset}
                        size="lg"
                    />
                    <div className="flex min-w-0 flex-[1_1_200px] flex-col items-center gap-px text-center">
                        <span className="text-[10.5px] font-semibold tracking-[0.07em] text-[#767676] uppercase">
                            {period.kicker}
                        </span>
                        <span className="max-w-full truncate text-[15.5px] font-bold tracking-[0.01em] tabular-nums">
                            {period.label}
                        </span>
                        <span className="text-[11px] whitespace-nowrap text-[#8a8a8a] tabular-nums">
                            {plural(period.days, 'day')} ·{' '}
                            {plural(
                                analytics.kpis.transactions.value ?? 0,
                                'transaction',
                            )}{' '}
                            · {scopeLabel}
                        </span>
                    </div>
                    <div className="flex shrink-0 flex-wrap items-center gap-2 print:hidden">
                        <button
                            type="button"
                            onClick={() => window.print()}
                            title="Open the print view for this report"
                            className={`${ownerSecondaryActionClass} inline-flex items-center gap-2 px-3.5 text-[13px]`}
                        >
                            <Printer className="size-4" aria-hidden="true" />
                            Print
                        </button>
                        <button
                            type="button"
                            onClick={() => setExportOpen(true)}
                            title="Export this report as CSV or PDF"
                            className={`${ownerSecondaryActionClass} inline-flex items-center gap-2 px-3.5 text-[13px]`}
                        >
                            <Download className="size-4" aria-hidden="true" />
                            Export
                        </button>
                        <button
                            type="button"
                            onClick={() => setFiltersOpen(true)}
                            aria-haspopup="dialog"
                            className={`inline-flex min-h-11 items-center gap-2 rounded-[11px] border px-[15px] text-[13px] font-semibold focus-visible:ring-2 focus-visible:ring-[#111] focus-visible:ring-offset-2 focus-visible:outline-none ${chips.length > 0 ? 'border-[#111] bg-[#111] text-white' : 'border-[#e5e5e5] bg-white text-[#111]'}`}
                        >
                            <Filter className="size-4" aria-hidden="true" />
                            {chips.length > 0
                                ? `Filters · ${chips.length}`
                                : 'Filters'}
                        </button>
                    </div>
                </section>

                <section
                    aria-label="Report scope"
                    className={`${ownerPanelClass} flex flex-col gap-3 px-4 py-3 min-[900px]:flex-row min-[900px]:flex-wrap min-[900px]:items-end print:hidden`}
                >
                    {customOpen && (
                        <div className="flex flex-col gap-1.5">
                            <span className={labelClass}>Custom range</span>
                            <div className="flex flex-wrap items-center gap-2">
                                <div className="flex min-h-11 items-center gap-[7px] rounded-[11px] border border-[#e5e5e5] bg-white px-[11px]">
                                    <CalendarDays
                                        className="size-4 text-[#767676]"
                                        aria-hidden="true"
                                    />
                                    <input
                                        type="date"
                                        value={from}
                                        max={to || undefined}
                                        aria-label="Report from date"
                                        onChange={(event) =>
                                            setFrom(event.target.value)
                                        }
                                        className="w-[128px] border-0 bg-transparent text-base outline-none sm:text-[12.5px]"
                                    />
                                    <span className="text-[#c9c9c9]">–</span>
                                    <input
                                        type="date"
                                        value={to}
                                        min={from || undefined}
                                        aria-label="Report to date"
                                        onChange={(event) =>
                                            setTo(event.target.value)
                                        }
                                        className="w-[128px] border-0 bg-transparent text-base outline-none sm:text-[12.5px]"
                                    />
                                </div>
                                <button
                                    type="button"
                                    onClick={applyCustom}
                                    disabled={loading}
                                    className={`${ownerPrimaryActionClass} inline-flex items-center justify-center gap-2 disabled:opacity-60`}
                                >
                                    Apply range
                                </button>
                            </div>
                            <span className="text-[11px] text-[#767676]">
                                Up to 31 business dates, in Philippine time.
                            </span>
                            {(customError ?? errors.from ?? errors.to) && (
                                <p
                                    role="alert"
                                    className="text-[12.5px] font-semibold text-red-700"
                                >
                                    {customError ?? errors.from ?? errors.to}
                                </p>
                            )}
                        </div>
                    )}
                    <label className="flex min-w-0 flex-col gap-1.5 min-[900px]:w-[320px]">
                        <span className={labelClass}>Store Session</span>
                        <select
                            value={report.session_filter.selected ?? ''}
                            onChange={(event) =>
                                visit({
                                    session: event.target.value || undefined,
                                })
                            }
                            disabled={
                                !report.session_filter.available ||
                                report.session_filter.options.length === 0
                            }
                            className={`${ownerControlClass} min-h-11 w-full font-semibold disabled:opacity-60`}
                        >
                            <option value="">All sessions</option>
                            {report.session_filter.options.map((option) => (
                                <option key={option.id} value={option.id}>
                                    {option.label}
                                </option>
                            ))}
                        </select>
                    </label>
                    <p className="min-w-0 flex-1 text-[11.5px] leading-5 text-[#767676]">
                        {report.session_filter.available
                            ? `${plural(report.session_filter.options.length, 'Store Session')} on these business dates. A date includes every session opened on it; choose one to drill into it.`
                            : 'Choose a period of 31 days or fewer to drill into a single Store Session.'}
                    </p>
                </section>

                {chips.length > 0 && (
                    <div className="flex flex-wrap items-center gap-2 print:hidden">
                        <span className="text-[10.5px] font-semibold tracking-[0.07em] text-[#767676] uppercase">
                            Active filters
                        </span>
                        {chips.map((chip) => (
                            <button
                                key={chip.key}
                                type="button"
                                onClick={() => visit({ [chip.key]: [] })}
                                aria-label={`Remove filter ${chip.label}`}
                                className="inline-flex min-h-8 max-w-full items-center gap-[7px] rounded-full border border-[#111] bg-white px-[11px] text-[11.5px] font-semibold focus-visible:ring-2 focus-visible:ring-[#111] focus-visible:outline-none"
                            >
                                <span className="truncate">{chip.label}</span>
                                <X
                                    className="size-3.5 text-[#767676]"
                                    aria-hidden="true"
                                />
                            </button>
                        ))}
                        <button
                            type="button"
                            onClick={() =>
                                visit({
                                    order_types: [],
                                    payment_methods: [],
                                    cashiers: [],
                                    categories: [],
                                })
                            }
                            className="inline-flex min-h-8 items-center px-[11px] text-[11.5px] font-semibold text-[#B91C1C]"
                        >
                            Reset all
                        </button>
                    </div>
                )}

                {report.session_filter.ignored && (
                    <Notice tone="amber">
                        The selected Store Session is not in this Branch scope
                        or period, so all Store Sessions are shown.
                    </Notice>
                )}
                {!analytics.comparison.available && (
                    <Notice tone="neutral">
                        One Store Session is selected, so figures are not
                        compared with a previous period.
                    </Notice>
                )}
                {pendingAllocation && (
                    <Notice tone="amber">
                        Payment correction allocation is pending for{' '}
                        {peso(summary.corrections.unallocated)}. It is not yet
                        deducted from Cash or Cashless and is resolved from
                        Close Store.
                    </Notice>
                )}

                <div
                    aria-busy={loading}
                    className={`flex flex-col gap-3 transition-opacity ${loading ? 'opacity-60' : ''}`}
                >
                    <KpiGrid analytics={analytics} comparison={comparison} />

                    <div className="grid gap-3 min-[1100px]:grid-cols-2">
                        <AnalyticsCard
                            title="Sales by category"
                            hint={
                                categoryScope
                                    ? 'Top products and Product performance show only the selected category — tap it again to show all. Sales, payments and collections are unchanged.'
                                    : 'Tap a category to filter Top products and Product performance.'
                            }
                            action={
                                categoryScope && (
                                    <CategoryScopePill
                                        label={categoryScope}
                                        onClear={() => pickCategories([])}
                                    />
                                )
                            }
                        >
                            <CategoryBars
                                categories={analytics.categories}
                                selected={selectedCategories}
                                onPick={(category) =>
                                    pickCategories(
                                        nextCategorySelection(
                                            category,
                                            selectedCategories,
                                        ),
                                    )
                                }
                            />
                            <p className="text-[11px] leading-[1.5] text-[#8a8a8a]">
                                Grouped by each product’s current category —
                                order items do not record the category at the
                                time of sale. Payments are recorded per order,
                                so a category never filters Cash, Cashless or
                                other money figures.
                            </p>
                        </AnalyticsCard>
                        <AnalyticsCard
                            title="Payment method"
                            hint="How paid transactions were paid in this period and filters"
                            action={
                                <label className="inline-flex min-h-11 shrink-0 cursor-pointer items-center gap-2 rounded-[9px] border border-[#e5e5e5] bg-white px-3 text-xs font-semibold text-[#666] select-none has-checked:border-[#111] has-checked:text-[#111] has-focus-visible:ring-2 has-focus-visible:ring-[#111] md:min-h-[34px]">
                                    <input
                                        type="checkbox"
                                        checked={includeSplit}
                                        onChange={(event) =>
                                            setIncludeSplit(
                                                event.target.checked,
                                            )
                                        }
                                        className="size-4 shrink-0 cursor-pointer accent-[#111] outline-none"
                                    />
                                    Include split
                                </label>
                            }
                        >
                            <PaymentMethodDonut
                                mix={analytics.payment_mix}
                                includeSplit={includeSplit}
                            />
                        </AnalyticsCard>
                    </div>

                    <div className="grid gap-3 min-[1100px]:grid-cols-2">
                        <AnalyticsCard
                            title="Order type"
                            hint="Dine in against take out for this period"
                        >
                            <ul className="flex flex-col gap-[13px]">
                                {analytics.order_types.map((type, index) => (
                                    <li
                                        key={type.type}
                                        className={`${insetClass} flex flex-col gap-2 p-[13px]`}
                                    >
                                        <div className="flex items-center gap-2.5">
                                            <span className="min-w-0 flex-1 text-[13.5px] font-bold">
                                                {type.label}
                                            </span>
                                            <span className="text-[15px] font-bold whitespace-nowrap tabular-nums">
                                                {peso(type.sales)}
                                            </span>
                                            <span className="w-[52px] text-right text-xs text-[#767676] tabular-nums">
                                                {shareLabel(type.share)}
                                            </span>
                                        </div>
                                        <div className="h-2 overflow-hidden rounded-full bg-[#ededed]">
                                            <div
                                                className="h-full rounded-full"
                                                style={{
                                                    width: `${typeMax > 0 ? Math.max(type.sales_cents > 0 ? 1.5 : 0, (type.sales_cents / typeMax) * 100) : 0}%`,
                                                    background:
                                                        index === 0
                                                            ? '#111111'
                                                            : '#8A8A8A',
                                                }}
                                            />
                                        </div>
                                        <div className="flex flex-wrap gap-3.5 text-[11.5px] text-[#666] tabular-nums">
                                            <span>
                                                {plural(
                                                    type.transactions,
                                                    'transaction',
                                                )}
                                            </span>
                                            <span>
                                                {plural(type.items, 'item')}
                                            </span>
                                            <span>
                                                Avg order{' '}
                                                {type.average === null
                                                    ? '—'
                                                    : peso(type.average)}
                                            </span>
                                        </div>
                                    </li>
                                ))}
                            </ul>
                        </AnalyticsCard>
                        <AnalyticsCard
                            title="Peak sales hours"
                            hint={
                                analytics.peak_hour
                                    ? `${peso(analytics.peak_hour.sales)} · ${plural(analytics.peak_hour.transactions, 'transaction')} · Philippine time`
                                    : 'No sales in this period'
                            }
                            action={
                                <SegmentedTabs
                                    label="Peak hours metric"
                                    value={hourMetric}
                                    options={[
                                        ['sales', 'Sales'],
                                        ['transactions', 'Transactions'],
                                    ]}
                                    onChange={setHourMetric}
                                    size="sm"
                                />
                            }
                        >
                            {analytics.peak_hour && (
                                <span className="inline-flex h-7 items-center self-start rounded-full bg-[#111] px-[11px] text-[11.5px] font-bold whitespace-nowrap text-white">
                                    Peak {analytics.peak_hour.label}
                                </span>
                            )}
                            <HourBars
                                bars={analytics.hours}
                                metric={hourMetric}
                                height={176}
                                labelEvery={2}
                            />
                        </AnalyticsCard>
                    </div>

                    <AnalyticsCard
                        title="Sales trend"
                        hint={`${trendMetric === 'sales' ? peso(analytics.kpis.sales.value ?? '0.00') : plural(analytics.kpis.transactions.value ?? 0, 'transaction')} · peak ${trendMetric === 'sales' ? peso(analytics.trend.peak.sales) : plural(analytics.trend.peak.transactions, 'order')} · grouped by ${analytics.trend.granularity}`}
                        action={
                            <div className="flex flex-wrap items-center gap-2">
                                <SegmentedTabs
                                    label="Trend metric"
                                    value={trendMetric}
                                    options={[
                                        ['sales', 'Sales'],
                                        ['transactions', 'Transactions'],
                                    ]}
                                    onChange={setTrendMetric}
                                    size="sm"
                                />
                                <CompareToggle
                                    on={compare}
                                    onToggle={() =>
                                        setCompare((value) => !value)
                                    }
                                    disabled={!analytics.comparison.available}
                                />
                            </div>
                        }
                    >
                        <TrendChart
                            buckets={analytics.trend.buckets}
                            metric={trendMetric}
                            compare={compare}
                            height={260}
                        />
                        {compare && <CompareLegend />}
                    </AnalyticsCard>

                    <AnalyticsCard
                        title="Top products"
                        hint={
                            categoryScope
                                ? `Ranked for the selected period and filters · Category: ${categoryScope}`
                                : 'Ranked for the selected period and filters'
                        }
                        action={
                            <SegmentedTabs
                                label="Top products metric"
                                value={topMetric}
                                options={[
                                    ['sales', 'Sales'],
                                    ['quantity', 'Qty sold'],
                                ]}
                                onChange={setTopMetric}
                                size="sm"
                            />
                        }
                    >
                        <TopProductBars
                            products={analytics.products}
                            metric={topMetric}
                            limit={10}
                        />
                    </AnalyticsCard>

                    <AnalyticsCard
                        title="Product performance"
                        hint={`${plural(productRows.length, 'product')}${categoryScope ? ` in ${categoryScope}` : ''} · % of sales is of all sales in this period`}
                        action={
                            <label className="flex items-center gap-2">
                                <span className="sr-only">Sort products</span>
                                <select
                                    value={productSort}
                                    onChange={(event) =>
                                        setProductSort(
                                            event.target.value as ProductSort,
                                        )
                                    }
                                    className={`${ownerControlClass} min-h-11 font-semibold md:h-[42px]`}
                                >
                                    {PRODUCT_SORTS.map(([value, label]) => (
                                        <option key={value} value={value}>
                                            {label}
                                        </option>
                                    ))}
                                </select>
                            </label>
                        }
                    >
                        {categoryOptions.length > 1 && (
                            <div
                                role="group"
                                aria-label="Product performance categories"
                                className="flex flex-wrap gap-[7px] print:hidden"
                            >
                                {categoryOptions.map((category) => {
                                    const on =
                                        selectedCategories.length === 0 ||
                                        selectedCategories.includes(
                                            category.value,
                                        );

                                    return (
                                        <button
                                            key={category.value}
                                            type="button"
                                            aria-pressed={on}
                                            onClick={() =>
                                                toggleCategoryChip(
                                                    category.value,
                                                )
                                            }
                                            className={`inline-flex min-h-11 items-center gap-1.5 rounded-full border px-[11px] text-xs font-semibold whitespace-nowrap focus-visible:ring-2 focus-visible:ring-[#111] focus-visible:outline-none md:min-h-[34px] ${on ? 'border-[#111] bg-[#111] text-white' : 'border-[#d8d8d8] bg-white text-[#666]'}`}
                                        >
                                            {on && (
                                                <Check
                                                    className="size-3"
                                                    aria-hidden="true"
                                                />
                                            )}
                                            {category.label}
                                        </button>
                                    );
                                })}
                            </div>
                        )}
                        {productRows.length === 0 ? (
                            <EmptyNote>
                                {categoryScope
                                    ? `No products in ${categoryScope} sold in this period.`
                                    : 'No products sold in this period.'}
                            </EmptyNote>
                        ) : (
                            <>
                                <div className="hidden overflow-hidden rounded-[13px] border border-[#efefef] min-[1000px]:block">
                                    <div className="owner-scrollbar max-h-[680px] overflow-y-auto print:max-h-none">
                                        <table className="w-full table-fixed text-left tabular-nums">
                                            <caption className="sr-only">
                                                Product performance
                                            </caption>
                                            <thead className="sticky top-0 bg-[#fafafa]">
                                                <tr className="text-[10px] font-semibold tracking-[0.07em] text-[#949494] uppercase">
                                                    <th scope="col" className="w-12 px-3.5 py-[11px] font-semibold">
                                                        Rank
                                                    </th>
                                                    <th scope="col" className="px-2.5 py-[11px] font-semibold">
                                                        Product
                                                    </th>
                                                    <th scope="col" className="w-28 px-2.5 py-[11px] font-semibold">
                                                        Category
                                                    </th>
                                                    <th scope="col" className="w-[78px] px-2.5 py-[11px] text-right font-semibold">
                                                        Qty
                                                    </th>
                                                    <th scope="col" className="w-[104px] px-2.5 py-[11px] text-right font-semibold">
                                                        Orders
                                                    </th>
                                                    <th scope="col" className="w-[118px] px-2.5 py-[11px] text-right font-semibold">
                                                        Total sales
                                                    </th>
                                                    <th scope="col" className="w-[88px] px-2.5 py-[11px] text-right font-semibold">
                                                        % of sales
                                                    </th>
                                                    <th scope="col" className="w-28 px-3.5 py-[11px] text-right font-semibold">
                                                        Avg price
                                                    </th>
                                                </tr>
                                            </thead>
                                            <tbody className="divide-y divide-[#f7f7f7] text-[13px]">
                                                {productRows.map(
                                                    (product, index) => (
                                                        <tr key={product.key}>
                                                            <td className="px-3.5 py-[11px] text-xs font-bold text-[#8a8a8a]">
                                                                {index + 1}
                                                            </td>
                                                            <th
                                                                scope="row"
                                                                className="truncate px-2.5 py-[11px] text-left font-semibold"
                                                            >
                                                                {product.name}
                                                            </th>
                                                            <td className="truncate px-2.5 py-[11px] text-xs text-[#666]">
                                                                <CategoryDot
                                                                    category={
                                                                        product.category
                                                                    }
                                                                    categories={analytics.categories.map(
                                                                        (row) =>
                                                                            row.name,
                                                                    )}
                                                                />
                                                                {
                                                                    product.category
                                                                }
                                                            </td>
                                                            <td className="px-2.5 py-[11px] text-right">
                                                                {product.quantity.toLocaleString(
                                                                    'en-PH',
                                                                )}
                                                            </td>
                                                            <td className="px-2.5 py-[11px] text-right text-[#666]">
                                                                {product.orders.toLocaleString(
                                                                    'en-PH',
                                                                )}
                                                            </td>
                                                            <td className="px-2.5 py-[11px] text-right font-bold">
                                                                {peso(
                                                                    product.sales,
                                                                )}
                                                            </td>
                                                            <td className="px-2.5 py-[11px] text-right text-[12.5px] text-[#666]">
                                                                {shareLabel(
                                                                    product.share,
                                                                )}
                                                            </td>
                                                            <td className="px-3.5 py-[11px] text-right text-[12.5px] text-[#666]">
                                                                {product.average_price ===
                                                                null
                                                                    ? '—'
                                                                    : peso(
                                                                          product.average_price,
                                                                      )}
                                                            </td>
                                                        </tr>
                                                    ),
                                                )}
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                                <ul className="flex flex-col gap-[9px] min-[1000px]:hidden">
                                    {productRows.map((product, index) => (
                                        <li
                                            key={product.key}
                                            className={`${insetClass} flex flex-col gap-[9px] p-[13px]`}
                                        >
                                            <div className="flex items-center gap-2.5">
                                                <span className="inline-flex size-6 shrink-0 items-center justify-center rounded-[7px] border border-[#e5e5e5] bg-white text-[11px] font-bold tabular-nums">
                                                    {index + 1}
                                                </span>
                                                <span className="flex min-w-0 flex-1 flex-col">
                                                    <span className="truncate text-[13.5px] font-bold">
                                                        {product.name}
                                                    </span>
                                                    <span className="text-[11px] text-[#767676]">
                                                        {product.category}
                                                    </span>
                                                </span>
                                                <span className="text-sm font-bold whitespace-nowrap tabular-nums">
                                                    {peso(product.sales)}
                                                </span>
                                            </div>
                                            <dl className="grid grid-cols-2 gap-2 text-[12.5px] tabular-nums">
                                                {(
                                                    [
                                                        [
                                                            'Qty sold',
                                                            product.quantity.toLocaleString(
                                                                'en-PH',
                                                            ),
                                                        ],
                                                        [
                                                            'Orders',
                                                            product.orders.toLocaleString(
                                                                'en-PH',
                                                            ),
                                                        ],
                                                        [
                                                            '% of sales',
                                                            shareLabel(
                                                                product.share,
                                                            ),
                                                        ],
                                                        [
                                                            'Avg price',
                                                            product.average_price ===
                                                            null
                                                                ? '—'
                                                                : peso(
                                                                      product.average_price,
                                                                  ),
                                                        ],
                                                    ] as const
                                                ).map(([label, value]) => (
                                                    <div
                                                        key={label}
                                                        className="flex flex-col gap-px"
                                                    >
                                                        <dt className="text-[10px] tracking-[0.06em] text-[#8a8a8a] uppercase">
                                                            {label}
                                                        </dt>
                                                        <dd className="font-semibold">
                                                            {value}
                                                        </dd>
                                                    </div>
                                                ))}
                                            </dl>
                                        </li>
                                    ))}
                                </ul>
                            </>
                        )}
                    </AnalyticsCard>

                    <div className="grid gap-3 min-[1100px]:grid-cols-2">
                        <AnalyticsCard
                            title="Kitchen performance"
                            hint="Preparation load for the selected period · committed to ready"
                        >
                            <div className="grid gap-[9px] [grid-template-columns:repeat(auto-fit,minmax(140px,1fr))]">
                                <MiniStat
                                    label="Orders completed"
                                    value={analytics.kitchen.completed.toLocaleString(
                                        'en-PH',
                                    )}
                                    delta={analytics.kitchen.completed_delta}
                                    note="Served from the kitchen board"
                                />
                                <MiniStat
                                    label="Average prep time"
                                    value={durationLabel(
                                        analytics.kitchen.average_prep_seconds,
                                    )}
                                    delta={analytics.kitchen.prep_delta}
                                    note={`Lower is better · ${plural(analytics.kitchen.timed_orders, 'timed order')}`}
                                />
                                <MiniStat
                                    label="Preparing now"
                                    value={
                                        kitchenNow
                                            ? String(kitchenNow.preparing)
                                            : '—'
                                    }
                                    note="Live kitchen snapshot"
                                />
                                <MiniStat
                                    label="Ready to serve"
                                    value={
                                        kitchenNow
                                            ? String(kitchenNow.ready)
                                            : '—'
                                    }
                                    note="Waiting to be handed over"
                                />
                            </div>
                            <div className="flex flex-col gap-2">
                                <span className="text-[10.5px] font-semibold tracking-[0.07em] text-[#767676] uppercase">
                                    Average prep time by hour
                                </span>
                                {analytics.kitchen.timed_orders === 0 ? (
                                    <EmptyNote>
                                        No order reached Ready in this period.
                                    </EmptyNote>
                                ) : (
                                    <>
                                        <ul
                                            aria-label="Average prep time by hour"
                                            className="flex h-[120px] items-end gap-1"
                                        >
                                            {analytics.kitchen.by_hour.map(
                                                (hour, index) => {
                                                    const seconds =
                                                        hour.average_seconds ??
                                                        0;
                                                    const slowest =
                                                        analytics.kitchen
                                                            .slowest?.full ===
                                                        hour.full;
                                                    const fastest =
                                                        analytics.kitchen
                                                            .fastest?.full ===
                                                        hour.full;
                                                    const title = `${hour.full} · ${hour.average_seconds === null ? 'no timed orders' : `average prep ${durationLabel(hour.average_seconds)}`}`;

                                                    return (
                                                        <li
                                                            key={hour.hour}
                                                            className="flex h-full min-w-0 flex-1 flex-col items-center justify-end gap-1.5"
                                                        >
                                                            <span className="sr-only">
                                                                {title}
                                                            </span>
                                                            <div
                                                                aria-hidden="true"
                                                                title={title}
                                                                className="w-full rounded-t-[5px]"
                                                                style={{
                                                                    height: `${prepMax > 0 && hour.average_seconds !== null ? Math.max(4, Math.round((seconds / prepMax) * 100)) : 4}%`,
                                                                    background:
                                                                        hour.average_seconds ===
                                                                        null
                                                                            ? '#F2F2F2'
                                                                            : slowest
                                                                              ? '#B45309'
                                                                              : fastest
                                                                                ? '#15803D'
                                                                                : '#D8D8D8',
                                                                }}
                                                            />
                                                            <span
                                                                aria-hidden="true"
                                                                className="h-[11px] text-[9px] whitespace-nowrap text-[#949494]"
                                                            >
                                                                {index % 2 === 0
                                                                    ? hour.label
                                                                    : ''}
                                                            </span>
                                                        </li>
                                                    );
                                                },
                                            )}
                                        </ul>
                                        <div className="flex flex-wrap gap-3.5 text-[11px] text-[#666]">
                                            {analytics.kitchen.fastest && (
                                                <span className="inline-flex items-center gap-[7px]">
                                                    <span className="size-[9px] rounded-[3px] bg-[#15803D]" />
                                                    Fastest{' '}
                                                    {
                                                        analytics.kitchen
                                                            .fastest.full
                                                    }{' '}
                                                    ·{' '}
                                                    {durationLabel(
                                                        analytics.kitchen
                                                            .fastest
                                                            .average_seconds,
                                                    )}
                                                </span>
                                            )}
                                            {analytics.kitchen.slowest && (
                                                <span className="inline-flex items-center gap-[7px]">
                                                    <span className="size-[9px] rounded-[3px] bg-[#B45309]" />
                                                    Slowest{' '}
                                                    {
                                                        analytics.kitchen
                                                            .slowest.full
                                                    }{' '}
                                                    ·{' '}
                                                    {durationLabel(
                                                        analytics.kitchen
                                                            .slowest
                                                            .average_seconds,
                                                    )}
                                                </span>
                                            )}
                                        </div>
                                    </>
                                )}
                            </div>
                        </AnalyticsCard>

                        <AnalyticsCard
                            title="Cashier performance"
                            hint="Transactions handled and how they were paid"
                            action={
                                <SegmentedTabs
                                    label="Cashier ranking"
                                    value={cashierSort}
                                    options={[
                                        ['sales', 'Highest sales'],
                                        ['transactions', 'Most transactions'],
                                    ]}
                                    onChange={setCashierSort}
                                    size="sm"
                                />
                            }
                        >
                            {cashiers.length === 0 ? (
                                <EmptyNote>
                                    No transactions in this period.
                                </EmptyNote>
                            ) : (
                                <ul className="flex flex-col gap-2.5">
                                    {cashiers.map((cashier) => (
                                        <li
                                            key={cashier.id ?? 'unattributed'}
                                            className={`${insetClass} flex flex-col gap-[9px] p-[13px]`}
                                        >
                                            <div className="flex items-center gap-2.5">
                                                <span className="flex min-w-0 flex-1 flex-col">
                                                    <span className="truncate text-[13.5px] font-bold">
                                                        {cashier.name}
                                                    </span>
                                                    <span className="text-[11px] text-[#767676] tabular-nums">
                                                        {plural(
                                                            cashier.transactions,
                                                            'transaction',
                                                        )}{' '}
                                                        · avg order{' '}
                                                        {cashier.average ===
                                                        null
                                                            ? '—'
                                                            : peso(
                                                                  cashier.average,
                                                              )}
                                                    </span>
                                                </span>
                                                <span className="text-[15px] font-bold whitespace-nowrap tabular-nums">
                                                    {peso(cashier.sales)}
                                                </span>
                                            </div>
                                            <div
                                                aria-hidden="true"
                                                className="h-[7px] rounded-full bg-[#111]"
                                                style={{
                                                    width: `${cashierMax > 0 ? Math.max(2, (cashier.sales_cents / cashierMax) * 100) : 0}%`,
                                                }}
                                            />
                                            <dl className="grid grid-cols-3 gap-2 text-[12.5px] tabular-nums">
                                                {(
                                                    [
                                                        ['Cash', cashier.cash],
                                                        [
                                                            'Cashless',
                                                            cashier.cashless,
                                                        ],
                                                        ['Split', cashier.split],
                                                    ] as const
                                                ).map(([label, value]) => (
                                                    <div
                                                        key={label}
                                                        className="flex min-w-0 flex-col gap-px"
                                                    >
                                                        <dt className="text-[10px] tracking-[0.06em] text-[#8a8a8a] uppercase">
                                                            {label}
                                                        </dt>
                                                        <dd className="font-semibold [overflow-wrap:anywhere]">
                                                            {peso(value)}
                                                        </dd>
                                                    </div>
                                                ))}
                                            </dl>
                                            {!isZero(cashier.unpaid) && (
                                                <p className="text-[11px] text-[#767676] tabular-nums">
                                                    {peso(cashier.unpaid)}{' '}
                                                    still unpaid (Pay Later)
                                                </p>
                                            )}
                                        </li>
                                    ))}
                                </ul>
                            )}
                            <p className="text-[11px] leading-[1.5] text-[#8a8a8a]">
                                Sales by how each order was paid. An order is
                                credited to the cashier who created it, or who
                                loaded it from the customer QR queue.
                            </p>
                        </AnalyticsCard>
                    </div>

                    <AnalyticsCard
                        title="Period highlights"
                        hint="Calculated from the figures above — no estimates or projections"
                    >
                        <div className="grid gap-[9px] [grid-template-columns:repeat(auto-fit,minmax(190px,1fr))]">
                            {analytics.highlights.map((highlight) => (
                                <div
                                    key={highlight.label}
                                    className={`${insetClass} flex min-w-0 flex-col gap-1 px-[13px] py-3.5`}
                                >
                                    <span className={labelClass}>
                                        {highlight.label}
                                    </span>
                                    <span className="text-[15px] font-bold tracking-[-0.01em] [overflow-wrap:anywhere]">
                                        {highlight.value}
                                    </span>
                                    <span className="text-[11.5px] leading-[1.45] text-[#666] tabular-nums">
                                        {highlight.amount !== null &&
                                            `${peso(highlight.amount)} · `}
                                        {highlight.detail}
                                    </span>
                                </div>
                            ))}
                        </div>
                    </AnalyticsCard>

                    {analytics.branches && (
                        <AnalyticsCard
                            title="Branch comparison"
                            hint="Factual figures per Branch for this period and filters, alphabetical"
                        >
                            <BranchComparison branches={analytics.branches} />
                        </AnalyticsCard>
                    )}

                    <AnalyticsCard
                        title="Collections & drawer effects"
                        hint={`Store Session totals for every order: Net sales ${peso(summary.net_sales)} · collected ${peso(summary.collected)}. These explain the Cash and Cashless totals and are not added again.`}
                    >
                        {analytics.filters.active && (
                            <Notice tone="neutral">
                                Order filters do not apply here: Store Session
                                reconciliation always covers the whole drawer.
                            </Notice>
                        )}
                        <div className="grid gap-2.5 md:grid-cols-2 min-[1250px]:grid-cols-4">
                            <MiniStat
                                label="Split payments"
                                value={peso(summary.split.total)}
                                note={`${plural(summary.split.count, 'order')} · Cash ${peso(summary.split.cash)} · Cashless ${peso(summary.split.cashless)} · already in Cash and Cashless`}
                            />
                            <MiniStat
                                label="Expenses"
                                value={peso(summary.expenses.total)}
                                note={`${plural(summary.expenses.count, 'expense')} · Cash ${peso(summary.expenses.cash)} · Cashless ${peso(summary.expenses.cashless)}`}
                            />
                            <MiniStat
                                label="Corrections"
                                value={peso(summary.corrections.total)}
                                note={`Cash ${peso(summary.corrections.cash)} · Cashless ${peso(summary.corrections.cashless)}${pendingAllocation ? ` · ${peso(summary.corrections.unallocated)} pending allocation` : ''}`}
                            />
                            <MiniStat
                                label="Void reversals"
                                value={peso(summary.voids.reversal)}
                                note={`${plural(summary.voids.count, 'voided order')} · payments reversed, excluded from sales`}
                            />
                        </div>
                    </AnalyticsCard>

                    {period.days > 1 && report.days.length > 0 && (
                        <AnalyticsCard
                            title="Daily summary"
                            hint="Each business date includes every Store Session opened on it."
                        >
                            <div className="hidden overflow-hidden rounded-[13px] border border-[#efefef] md:block">
                                <table className="w-full text-left text-[13px] tabular-nums">
                                    <thead className="bg-[#fafafa]">
                                        <tr className={labelClass}>
                                            {[
                                                'Business date',
                                                'Sessions',
                                                'Orders',
                                                'Net sales',
                                                'Cash',
                                                'Cashless',
                                                'Expenses',
                                            ].map((heading, index) => (
                                                <th
                                                    key={heading}
                                                    scope="col"
                                                    className={`px-3.5 py-2.5 font-semibold ${index > 0 ? 'text-right' : ''}`}
                                                >
                                                    {heading}
                                                </th>
                                            ))}
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-[#f3f3f3]">
                                        {report.days.map((day) => (
                                            <tr key={day.date}>
                                                <th
                                                    scope="row"
                                                    className="px-3.5 py-2.5 font-semibold"
                                                >
                                                    {day.label}
                                                </th>
                                                <td className="px-3.5 py-2.5 text-right">
                                                    {day.sessions}
                                                </td>
                                                <td className="px-3.5 py-2.5 text-right">
                                                    {day.orders}
                                                </td>
                                                <td className="px-3.5 py-2.5 text-right font-bold">
                                                    {peso(day.net_sales)}
                                                </td>
                                                <td className="px-3.5 py-2.5 text-right">
                                                    {peso(day.cash)}
                                                </td>
                                                <td className="px-3.5 py-2.5 text-right">
                                                    {peso(day.cashless)}
                                                </td>
                                                <td className="px-3.5 py-2.5 text-right">
                                                    {peso(day.expenses)}
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                            <ul className="flex flex-col gap-2 md:hidden">
                                {report.days.map((day) => (
                                    <li
                                        key={day.date}
                                        className={`${insetClass} flex flex-col gap-2 p-3`}
                                    >
                                        <div className="flex items-baseline justify-between gap-3">
                                            <span className="text-[13.5px] font-bold">
                                                {day.label}
                                            </span>
                                            <span className="text-[14px] font-bold tabular-nums">
                                                {peso(day.net_sales)}
                                            </span>
                                        </div>
                                        <p className="text-[11.5px] leading-4 text-[#666] tabular-nums">
                                            {plural(day.sessions, 'session')} ·{' '}
                                            {plural(day.orders, 'order')} · Cash{' '}
                                            {peso(day.cash)} · Cashless{' '}
                                            {peso(day.cashless)} · Expenses{' '}
                                            {peso(day.expenses)}
                                        </p>
                                    </li>
                                ))}
                            </ul>
                        </AnalyticsCard>
                    )}

                    <AnalyticsCard
                        title="Store Sessions"
                        hint={
                            report.sessions_listed.shown <
                            report.sessions_listed.total
                                ? `Showing the latest ${report.sessions_listed.shown} of ${report.sessions_listed.total} Store Sessions; every total above includes all of them.`
                                : 'Sessions that cross midnight stay under their opening date. Closed sessions show their close-time snapshot.'
                        }
                    >
                        <StoreSessionList
                            sessions={report.sessions}
                            onView={setDetailId}
                            emptyMessage={`No Store Session was opened on ${period.days > 1 ? 'these business dates' : 'this business date'} for ${scopeLabel}.`}
                        />
                    </AnalyticsCard>
                </div>
            </OwnerPage>

            <SessionDetail session={detail} onClose={() => setDetailId(null)} />
            {filtersOpen && (
                <FilterDialog
                    open
                    onOpenChange={setFiltersOpen}
                    analytics={analytics}
                    onApply={(next) => {
                        setFiltersOpen(false);
                        visit(next);
                    }}
                />
            )}
            <Dialog open={exportOpen} onOpenChange={setExportOpen}>
                <DialogContent className="owner-surface gap-4 rounded-[20px] p-5 sm:max-w-[440px]">
                    <DialogHeader className="text-left">
                        <DialogTitle className="text-base font-bold">
                            Export report
                        </DialogTitle>
                        <DialogDescription className="text-[12.5px] text-[#666]">
                            {period.label} · {scopeLabel}
                            {chips.length > 0 &&
                                ` · ${chips.map((chip) => chip.label).join(' · ')}`}
                        </DialogDescription>
                    </DialogHeader>
                    <div className="flex flex-col gap-2">
                        <a
                            href={exportReport.url({ query: currentQuery })}
                            onClick={() => setExportOpen(false)}
                            className="flex min-h-14 items-center gap-3 rounded-xl border border-[#e5e5e5] px-3.5 hover:border-[#111] focus-visible:ring-2 focus-visible:ring-[#111] focus-visible:outline-none"
                        >
                            <FileSpreadsheet
                                className="size-5 shrink-0"
                                aria-hidden="true"
                            />
                            <span className="flex flex-col">
                                <span className="text-[13.5px] font-semibold">
                                    CSV spreadsheet
                                </span>
                                <span className="text-[11.5px] text-[#767676]">
                                    Every figure and table of this filtered
                                    report
                                </span>
                            </span>
                        </a>
                        <button
                            type="button"
                            onClick={() => {
                                setExportOpen(false);
                                window.setTimeout(() => window.print(), 120);
                            }}
                            className="flex min-h-14 items-center gap-3 rounded-xl border border-[#e5e5e5] px-3.5 text-left hover:border-[#111] focus-visible:ring-2 focus-visible:ring-[#111] focus-visible:outline-none"
                        >
                            <FileText
                                className="size-5 shrink-0"
                                aria-hidden="true"
                            />
                            <span className="flex flex-col">
                                <span className="text-[13.5px] font-semibold">
                                    PDF
                                </span>
                                <span className="text-[11.5px] text-[#767676]">
                                    Opens the print view — choose “Save as PDF”
                                </span>
                            </span>
                        </button>
                    </div>
                </DialogContent>
            </Dialog>
        </>
    );
}

function CategoryDot({
    category,
    categories,
}: {
    category: string;
    categories: string[];
}) {
    const index = categories.indexOf(category);

    return (
        <span
            aria-hidden="true"
            className="mr-[7px] inline-block size-2 rounded-[3px] align-middle"
            style={{
                background:
                    index < 0
                        ? '#C9C9C9'
                        : SERIES_COLORS[index % SERIES_COLORS.length],
            }}
        />
    );
}


/** A small, clearable indication that the product views are narrowed to a category. */
function CategoryScopePill({
    label,
    onClear,
}: {
    label: string;
    onClear: () => void;
}) {
    return (
        <button
            type="button"
            onClick={onClear}
            aria-label={`Clear category filter ${label}`}
            className="inline-flex min-h-11 max-w-full items-center gap-[7px] rounded-full border border-[#111] bg-white px-[11px] text-[11.5px] font-semibold focus-visible:ring-2 focus-visible:ring-[#111] focus-visible:outline-none md:min-h-8"
        >
            <span className="truncate">Category: {label}</span>
            <X className="size-3.5 shrink-0 text-[#767676]" aria-hidden="true" />
        </button>
    );
}

type Chip = { key: keyof ReportOrderFilters; label: string };

function filterChips(analytics: Analytics): Chip[] {
    const name = <T extends string | number>(
        options: { value: T; label: string }[],
        value: T,
    ) =>
        options.find((option) => option.value === value)?.label ??
        'Not in this period';
    const chips: Chip[] = [];
    if (analytics.filters.categories.length > 0) {
        chips.push({
            key: 'categories',
            label: `Category: ${analytics.filters.categories.map((value) => name(analytics.filter_options.categories, value)).join(', ')}`,
        });
    }
    if (analytics.filters.order_types.length > 0) {
        chips.push({
            key: 'order_types',
            label: `Order type: ${analytics.filters.order_types.map((value) => name(analytics.filter_options.order_types, value)).join(', ')}`,
        });
    }
    if (analytics.filters.payment_methods.length > 0) {
        chips.push({
            key: 'payment_methods',
            label: `Payment: ${analytics.filters.payment_methods.map((value) => name(analytics.filter_options.payment_methods, value)).join(', ')}`,
        });
    }
    if (analytics.filters.cashiers.length > 0) {
        chips.push({
            key: 'cashiers',
            label: `Cashier: ${analytics.filters.cashiers.map((value) => name(analytics.filter_options.cashiers, value)).join(', ')}`,
        });
    }

    return chips;
}

type FilterDraft = {
    categories: string[];
    order_types: string[];
    payment_methods: string[];
    cashiers: number[];
};

/**
 * The standalone "Filter this report" dialog: a 560px dialog on tablet and desktop and a bottom sheet on phones. Every
 * option of an unfiltered group starts checked; applying a group with nothing or everything checked removes it.
 * Order type, payment method and cashier are server filters for the whole report; Category narrows the product views
 * only, because payments are recorded per order.
 */
function FilterDialog({
    open,
    onOpenChange,
    analytics,
    onApply,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    analytics: Analytics;
    onApply: (filters: ReportOrderFilters) => void;
}) {
    const options = analytics.filter_options;
    const everything = (): FilterDraft => ({
        categories: draftSelection([], options.categories),
        order_types: draftSelection([], options.order_types),
        payment_methods: draftSelection([], options.payment_methods),
        cashiers: draftSelection([], options.cashiers),
    });
    const [draft, setDraft] = useState<FilterDraft>(() => ({
        categories: draftSelection(
            analytics.filters.categories,
            options.categories,
        ),
        order_types: draftSelection(
            analytics.filters.order_types,
            options.order_types,
        ),
        payment_methods: draftSelection(
            analytics.filters.payment_methods,
            options.payment_methods,
        ),
        cashiers: draftSelection(analytics.filters.cashiers, options.cashiers),
    }));
    const groups: {
        key: keyof FilterDraft;
        title: string;
        scope?: string;
        options: { value: string | number; label: string }[];
        empty: string;
    }[] = [
        {
            key: 'categories',
            title: 'Category',
            scope: 'Product views only',
            options: options.categories,
            empty: 'No products sold in this period.',
        },
        {
            key: 'order_types',
            title: 'Order type',
            options: options.order_types,
            empty: 'No order types.',
        },
        {
            key: 'payment_methods',
            title: 'Payment method',
            options: options.payment_methods,
            empty: 'No payment methods.',
        },
        {
            key: 'cashiers',
            title: 'Cashier',
            options: options.cashiers,
            empty: 'No cashier has transactions in this period.',
        },
    ];

    function toggle(key: keyof FilterDraft, value: string | number) {
        setDraft((current) => ({
            ...current,
            [key]: toggleFilterValue(
                current[key] as (string | number)[],
                value,
            ),
        }));
    }

    function selectAll(key: keyof FilterDraft) {
        setDraft((current) => ({ ...current, [key]: everything()[key] }));
    }

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent
                showCloseButton={false}
                overlayClassName="bg-[rgba(17,17,17,0.44)]"
                className="owner-surface top-auto bottom-0 left-0 flex max-h-[92dvh] w-full max-w-none translate-x-0 translate-y-0 flex-col gap-0 overflow-hidden rounded-t-[20px] rounded-b-none border-0 bg-white p-0 text-[#111] shadow-[0_30px_70px_rgba(0,0,0,0.3)] sm:max-w-none md:top-[50%] md:bottom-auto md:left-[50%] md:max-h-[min(92dvh,940px)] md:max-w-[560px] md:translate-x-[-50%] md:translate-y-[-50%] md:rounded-[20px]"
            >
                <DialogHeader className="flex shrink-0 flex-row items-center justify-between gap-2.5 border-b border-[#e5e5e5] px-4 py-3.5 text-left">
                    <div className="flex min-w-0 flex-col gap-0.5">
                        <span className="text-[11px] font-semibold tracking-[0.09em] text-[#8a8a8a] uppercase">
                            Analytics filter
                        </span>
                        <DialogTitle className="text-base leading-tight font-bold tracking-[-0.01em]">
                            Filter this report
                        </DialogTitle>
                    </div>
                    <DialogClose
                        aria-label="Close"
                        className="inline-flex size-11 shrink-0 items-center justify-center rounded-[10px] text-[#767676] hover:bg-[#f2f2f2] hover:text-[#111] focus-visible:ring-2 focus-visible:ring-[#111] focus-visible:outline-none md:size-10"
                    >
                        <X className="size-[18px]" aria-hidden="true" />
                    </DialogClose>
                </DialogHeader>
                <div className="owner-scrollbar flex min-h-0 flex-1 flex-col gap-4 overflow-y-auto p-4">
                    <DialogDescription className="text-xs leading-[1.5] text-[#767676]">
                        Order type, payment method and cashier apply to every
                        KPI, chart and table (Store Session reconciliation
                        always covers the whole drawer). Category narrows Top
                        products and Product performance only.
                    </DialogDescription>
                    {groups.map((group) => {
                        const values = draft[group.key] as (string | number)[];
                        const titleId = `report-filter-${group.key}`;

                        return (
                            <div
                                key={group.key}
                                role="group"
                                aria-labelledby={titleId}
                                className="flex flex-col gap-2"
                            >
                                <div className="flex items-center justify-between gap-2.5">
                                    <span className="flex min-w-0 flex-wrap items-center gap-2">
                                        <span
                                            id={titleId}
                                            className="text-[11px] font-semibold tracking-[0.06em] text-[#767676] uppercase"
                                        >
                                            {group.title}
                                        </span>
                                        {group.scope && (
                                            <span className="rounded-full bg-[#f2f2f2] px-2 py-0.5 text-[10.5px] font-semibold text-[#666]">
                                                {group.scope}
                                            </span>
                                        )}
                                    </span>
                                    {group.options.length > 0 && (
                                        <button
                                            type="button"
                                            onClick={() => selectAll(group.key)}
                                            className="-my-2 inline-flex min-h-11 shrink-0 items-center rounded-lg focus-visible:ring-2 focus-visible:ring-[#111] focus-visible:outline-none md:my-0 md:min-h-7"
                                        >
                                            <span className="inline-flex h-7 items-center rounded-lg bg-[#f2f2f2] px-[9px] text-[11px] font-semibold text-[#666]">
                                                Select all
                                            </span>
                                        </button>
                                    )}
                                </div>
                                {group.options.length === 0 ? (
                                    <p className="text-[12px] text-[#767676]">
                                        {group.empty}
                                    </p>
                                ) : (
                                    <div className="grid gap-[7px] [grid-template-columns:repeat(auto-fit,minmax(150px,1fr))]">
                                        {group.options.map((option) => {
                                            const on = values.includes(
                                                option.value,
                                            );

                                            return (
                                                <button
                                                    key={option.value}
                                                    type="button"
                                                    role="checkbox"
                                                    aria-checked={on}
                                                    onClick={() =>
                                                        toggle(
                                                            group.key,
                                                            option.value,
                                                        )
                                                    }
                                                    className={`flex h-11 w-full min-w-0 items-center gap-[9px] rounded-[11px] border px-3 text-left text-[13px] font-semibold focus-visible:ring-2 focus-visible:ring-[#111] focus-visible:outline-none ${on ? 'border-[#111] bg-[#fafafa] text-[#111]' : 'border-[#e5e5e5] bg-white text-[#666]'}`}
                                                >
                                                    <span
                                                        aria-hidden="true"
                                                        className={`inline-flex size-5 shrink-0 items-center justify-center rounded-md border ${on ? 'border-[#111] bg-[#111] text-white' : 'border-[#c9c9c9] bg-white text-transparent'}`}
                                                    >
                                                        <Check className="size-[13px]" />
                                                    </span>
                                                    <span className="truncate">
                                                        {option.label}
                                                    </span>
                                                </button>
                                            );
                                        })}
                                    </div>
                                )}
                            </div>
                        );
                    })}
                </div>
                <div className="flex shrink-0 gap-[9px] border-t border-[#e5e5e5] bg-white px-4 pt-3 pb-[calc(14px+env(safe-area-inset-bottom,0px))]">
                    <button
                        type="button"
                        onClick={() => setDraft(everything())}
                        className="inline-flex h-[52px] flex-[0_1_auto] items-center justify-center rounded-xl border border-[#949494] bg-white px-[18px] text-sm font-semibold focus-visible:ring-2 focus-visible:ring-[#111] focus-visible:outline-none"
                    >
                        Reset
                    </button>
                    <button
                        type="button"
                        onClick={() =>
                            onApply({
                                categories: appliedSelection(
                                    draft.categories,
                                    options.categories,
                                ),
                                order_types: appliedSelection(
                                    draft.order_types,
                                    options.order_types,
                                ),
                                payment_methods: appliedSelection(
                                    draft.payment_methods,
                                    options.payment_methods,
                                ),
                                cashiers: appliedSelection(
                                    draft.cashiers,
                                    options.cashiers,
                                ),
                            })
                        }
                        className="inline-flex h-[52px] flex-[1_1_auto] items-center justify-center gap-2 rounded-xl border border-[#111] bg-[#111] text-[14.5px] font-semibold text-white hover:bg-neutral-800 focus-visible:ring-2 focus-visible:ring-[#111] focus-visible:ring-offset-2 focus-visible:outline-none"
                    >
                        <Check className="size-4" aria-hidden="true" />
                        Apply filters
                    </button>
                </div>
            </DialogContent>
        </Dialog>
    );
}
