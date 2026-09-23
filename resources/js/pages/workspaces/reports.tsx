import { Head, router, usePage } from '@inertiajs/react';
import {
    AlertTriangle,
    CalendarDays,
    ChevronRight,
    Info,
    RefreshCw,
} from 'lucide-react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import {
    OwnerPage,
    OwnerStatusBadge,
    ownerControlClass,
    ownerPanelClass,
    ownerPrimaryActionClass,
    ownerSecondaryActionClass,
} from '@/components/owner-ui';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    REPORT_PRESETS,
    SESSION_RESULTS,
    VARIANCE_LABELS,
    customRangeError,
    reportQuery,
} from '@/lib/reports';
import type {
    ReportFilters,
    ReportPreset,
    ReportSessionResult,
} from '@/lib/reports';
import { formatDecimalPeso } from '@/lib/store-close';
import { reports } from '@/routes/workspaces';

type ChannelMoney = { cash: string; cashless: string };
type NullableChannelMoney = { cash: string | null; cashless: string | null };

type SessionRow = {
    id: string;
    status: 'open' | 'closed';
    result: ReportSessionResult;
    branch: { id: string; name: string; code: string };
    business_date: string;
    business_date_label: string;
    time_range: string;
    opened_at: string;
    opened_at_label: string;
    closed_at: string | null;
    closed_at_label: string | null;
    opened_by: string;
    closed_by: string | null;
    orders: number;
    net_sales: string;
    collections: ChannelMoney & { total: string };
    split: ChannelMoney & { count: number; total: string };
    expenses: ChannelMoney & { count: number; total: string };
    corrections: ChannelMoney & {
        count: number;
        total: string;
        unallocated: string;
    };
    voids: ChannelMoney & { count: number; reversal: string };
    reconciliation: {
        source: 'closing_snapshot' | 'closing_record' | 'live';
        opening: ChannelMoney;
        expected: NullableChannelMoney;
        actual: NullableChannelMoney;
        variance: NullableChannelMoney;
        variance_status: NullableChannelMoney;
        closing_note: string | null;
    };
};

type Report = {
    period: {
        preset: ReportPreset;
        from: string;
        to: string;
        label: string;
        days: number;
    };
    scope: { id: string; name: string; code: string } | null;
    session_filter: {
        selected: string | null;
        ignored: boolean;
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
};

type Props = { report: Report; filters: ReportFilters };

const peso = formatDecimalPeso;
const isZero = (value: string) => /^-?0\.00$/.test(value);
const plural = (count: number, word: string) =>
    `${count.toLocaleString('en-PH')} ${word}${count === 1 ? '' : 's'}`;
const labelClass =
    'text-[10px] font-semibold tracking-[0.07em] text-[#767676] uppercase';
const insetClass = 'rounded-xl border border-[#efefef] bg-[#fafafa]';

function Kpi({
    label,
    value,
    hint,
}: {
    label: string;
    value: string;
    hint: ReactNode;
}) {
    return (
        <div className={`${ownerPanelClass} flex min-w-0 flex-col gap-2 p-4`}>
            <span className={`${labelClass} truncate`}>{label}</span>
            <span className="text-[21px] leading-[1.1] font-bold tracking-[-0.025em] [overflow-wrap:anywhere] tabular-nums sm:text-[25px]">
                {value}
            </span>
            <span className="text-[11px] leading-4 text-[#767676]">{hint}</span>
        </div>
    );
}

function Panel({
    title,
    hint,
    children,
    action,
}: {
    title: string;
    hint?: ReactNode;
    children: ReactNode;
    action?: ReactNode;
}) {
    const id = `report-${title.toLowerCase().replace(/[^a-z]+/g, '-')}`;

    return (
        <section
            aria-labelledby={id}
            className={`${ownerPanelClass} flex min-w-0 flex-col gap-3.5 p-4 sm:p-[18px]`}
        >
            <div className="flex flex-wrap items-start justify-between gap-2.5">
                <div className="flex min-w-0 flex-col gap-0.5">
                    <h2
                        id={id}
                        className="text-[15px] font-bold tracking-[-0.01em]"
                    >
                        {title}
                    </h2>
                    {hint && (
                        <p className="text-xs leading-5 text-[#767676]">
                            {hint}
                        </p>
                    )}
                </div>
                {action}
            </div>
            {children}
        </section>
    );
}

function Notice({
    tone,
    children,
}: {
    tone: 'amber' | 'neutral';
    children: ReactNode;
}) {
    const Icon = tone === 'amber' ? AlertTriangle : Info;

    return (
        <p
            role={tone === 'amber' ? 'status' : undefined}
            className={`flex items-start gap-2.5 rounded-xl border px-3.5 py-3 text-[12.5px] leading-5 ${tone === 'amber' ? 'border-amber-200 bg-amber-50 text-amber-900' : 'border-[#ececec] bg-white text-[#555]'}`}
        >
            <Icon className="mt-0.5 size-4 shrink-0" aria-hidden="true" />
            <span>{children}</span>
        </p>
    );
}

function ResultBadge({ result }: { result: ReportSessionResult }) {
    const { label, tone } = SESSION_RESULTS[result];

    return <OwnerStatusBadge tone={tone}>{label}</OwnerStatusBadge>;
}

function DetailRow({
    label,
    value,
    strong = false,
}: {
    label: string;
    value: ReactNode;
    strong?: boolean;
}) {
    return (
        <div className="flex items-baseline justify-between gap-3 py-2">
            <dt className="text-[12.5px] text-[#666]">{label}</dt>
            <dd
                className={`text-right text-[13px] [overflow-wrap:anywhere] tabular-nums ${strong ? 'font-bold' : 'font-semibold'}`}
            >
                {value}
            </dd>
        </div>
    );
}

function DetailSection({
    title,
    children,
}: {
    title: string;
    children: ReactNode;
}) {
    return (
        <section className="flex flex-col gap-1">
            <h3 className={labelClass}>{title}</h3>
            <dl className="divide-y divide-[#f0f0f0]">{children}</dl>
        </section>
    );
}

const notAvailable = (
    <span className="font-medium text-[#8a8a8a]">Not available</span>
);

function nullablePeso(value: string | null): ReactNode {
    return value === null ? notAvailable : peso(value);
}

function varianceText(value: string | null, status: string | null): ReactNode {
    if (value === null || status === null) {
        return notAvailable;
    }
    const tone =
        status === 'shortage'
            ? 'text-red-700'
            : status === 'overage'
              ? 'text-amber-800'
              : 'text-emerald-700';

    return (
        <span className={tone}>
            {peso(value)} · {VARIANCE_LABELS[status] ?? status}
        </span>
    );
}

function SessionDetail({
    session,
    onClose,
}: {
    session: SessionRow | null;
    onClose: () => void;
}) {
    const live = session?.status === 'open';
    const reconciliation = session?.reconciliation;

    return (
        <Dialog
            open={session !== null}
            onOpenChange={(open) => !open && onClose()}
        >
            <DialogContent className="owner-surface top-auto bottom-0 left-0 max-h-[90dvh] w-full max-w-none translate-x-0 translate-y-0 gap-0 overflow-hidden rounded-t-[20px] rounded-b-none border-0 p-0 sm:top-[50%] sm:left-[50%] sm:max-h-[86dvh] sm:max-w-[560px] sm:translate-x-[-50%] sm:translate-y-[-50%] sm:rounded-[20px] [&>button]:top-3 [&>button]:right-3 [&>button]:flex [&>button]:size-11 [&>button]:items-center [&>button]:justify-center [&>button]:rounded-xl [&>button]:focus-visible:ring-2 [&>button]:focus-visible:ring-[#111]">
                {session && reconciliation && (
                    <div className="flex max-h-[inherit] min-h-0 flex-col">
                        <DialogHeader className="border-b border-[#ececec] px-5 pt-5 pr-16 pb-4 text-left">
                            <div className="flex flex-wrap items-center gap-2">
                                <DialogTitle className="text-base font-bold">
                                    Store Session
                                </DialogTitle>
                                <ResultBadge result={session.result} />
                            </div>
                            <DialogDescription className="text-[12.5px] text-[#666]">
                                {session.branch.code} · {session.branch.name} ·{' '}
                                {session.time_range}
                            </DialogDescription>
                        </DialogHeader>
                        <div className="owner-scrollbar flex min-h-0 flex-col gap-5 overflow-y-auto px-5 pt-4 pb-[max(20px,env(safe-area-inset-bottom))]">
                            {live && (
                                <Notice tone="amber">
                                    LIVE · figures are provisional until this
                                    Store Session is closed.
                                </Notice>
                            )}
                            <DetailSection title="Store Session">
                                <DetailRow
                                    label="Branch"
                                    value={`${session.branch.code} · ${session.branch.name}`}
                                />
                                <DetailRow
                                    label="Business date"
                                    value={session.business_date_label}
                                />
                                <DetailRow
                                    label="Opened"
                                    value={session.opened_at_label}
                                />
                                <DetailRow
                                    label="Opened by"
                                    value={session.opened_by}
                                />
                                <DetailRow
                                    label="Closed"
                                    value={session.closed_at_label ?? 'LIVE'}
                                />
                                <DetailRow
                                    label="Closed by"
                                    value={
                                        session.closed_by ??
                                        (live ? '—' : notAvailable)
                                    }
                                />
                            </DetailSection>
                            <DetailSection title="Sales">
                                <DetailRow
                                    label="Orders"
                                    value={session.orders.toLocaleString(
                                        'en-PH',
                                    )}
                                />
                                <DetailRow
                                    label="Net Sales"
                                    value={peso(session.net_sales)}
                                    strong
                                />
                            </DetailSection>
                            <DetailSection title="Collections">
                                <DetailRow
                                    label="Cash"
                                    value={peso(session.collections.cash)}
                                />
                                <DetailRow
                                    label="Cashless"
                                    value={peso(session.collections.cashless)}
                                />
                                <DetailRow
                                    label={`Split · ${plural(session.split.count, 'order')}`}
                                    value={`${peso(session.split.total)} (included above)`}
                                />
                            </DetailSection>
                            <DetailSection title="Outflows / effects">
                                <DetailRow
                                    label={`Expenses · Cash ${peso(session.expenses.cash)} · Cashless ${peso(session.expenses.cashless)}`}
                                    value={peso(session.expenses.total)}
                                />
                                <DetailRow
                                    label={`Corrections · Cash ${peso(session.corrections.cash)} · Cashless ${peso(session.corrections.cashless)}`}
                                    value={peso(session.corrections.total)}
                                />
                                <DetailRow
                                    label={`Void reversals · ${plural(session.voids.count, 'voided order')}`}
                                    value={peso(session.voids.reversal)}
                                />
                            </DetailSection>
                            {!isZero(session.corrections.unallocated) && (
                                <Notice tone="amber">
                                    Payment correction allocation is pending:{' '}
                                    {peso(session.corrections.unallocated)} is
                                    not yet deducted from Cash or Cashless.
                                    Resolve it from Close Store.
                                </Notice>
                            )}
                            <DetailSection title="Reconciliation">
                                <DetailRow
                                    label="Opening Cash"
                                    value={peso(reconciliation.opening.cash)}
                                />
                                <DetailRow
                                    label="Opening Cashless"
                                    value={peso(
                                        reconciliation.opening.cashless,
                                    )}
                                />
                                <DetailRow
                                    label={
                                        live
                                            ? 'Expected Cash (provisional)'
                                            : 'Expected Cash'
                                    }
                                    value={nullablePeso(
                                        reconciliation.expected.cash,
                                    )}
                                />
                                <DetailRow
                                    label={
                                        live
                                            ? 'Expected Cashless (provisional)'
                                            : 'Expected Cashless'
                                    }
                                    value={nullablePeso(
                                        reconciliation.expected.cashless,
                                    )}
                                />
                                {!live && (
                                    <>
                                        <DetailRow
                                            label="Actual Closing Cash"
                                            value={nullablePeso(
                                                reconciliation.actual.cash,
                                            )}
                                        />
                                        <DetailRow
                                            label="Actual Closing Cashless"
                                            value={nullablePeso(
                                                reconciliation.actual.cashless,
                                            )}
                                        />
                                        <DetailRow
                                            label="Cash Variance"
                                            value={varianceText(
                                                reconciliation.variance.cash,
                                                reconciliation.variance_status
                                                    .cash,
                                            )}
                                        />
                                        <DetailRow
                                            label="Cashless Variance"
                                            value={varianceText(
                                                reconciliation.variance
                                                    .cashless,
                                                reconciliation.variance_status
                                                    .cashless,
                                            )}
                                        />
                                    </>
                                )}
                            </DetailSection>
                            {[
                                reconciliation.expected.cash,
                                reconciliation.expected.cashless,
                            ].some(
                                (value) =>
                                    value !== null && value.startsWith('-'),
                            ) && (
                                <Notice tone="amber">
                                    An expected balance is negative. It is shown
                                    exactly as recorded.
                                </Notice>
                            )}
                            {reconciliation.closing_note && (
                                <section className="flex flex-col gap-1.5">
                                    <h3 className={labelClass}>Closing note</h3>
                                    <p
                                        className={`${insetClass} px-3.5 py-3 text-[13px] leading-5 [overflow-wrap:anywhere] whitespace-pre-line`}
                                    >
                                        {reconciliation.closing_note}
                                    </p>
                                </section>
                            )}
                            {reconciliation.source === 'closing_record' && (
                                <Notice tone="neutral">
                                    This Store Session closed before close-time
                                    snapshots were recorded. Its figures come
                                    from its payment, expense and correction
                                    records.
                                </Notice>
                            )}
                        </div>
                    </div>
                )}
            </DialogContent>
        </Dialog>
    );
}

export default function Reports({ report, filters }: Props) {
    const { errors } = usePage<{ errors: Record<string, string> }>().props;
    const [customOpen, setCustomOpen] = useState(
        report.period.preset === 'custom',
    );
    const [from, setFrom] = useState(filters.from ?? report.period.from);
    const [to, setTo] = useState(filters.to ?? report.period.to);
    const [customError, setCustomError] = useState<string | null>(null);
    const [loading, setLoading] = useState(false);
    const [detailId, setDetailId] = useState<string | null>(null);
    const detail =
        report.sessions.find((session) => session.id === detailId) ?? null;
    const { summary, period } = report;
    const activePreset = customOpen ? 'custom' : period.preset;
    const scopeLabel = report.scope
        ? `${report.scope.code} · ${report.scope.name}`
        : 'All Branches';
    const pendingAllocation = !isZero(summary.corrections.unallocated);

    function visit(next: ReportFilters) {
        router.get(reports(), reportQuery(filters, next), {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            only: ['report', 'filters', 'errors'],
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

    return (
        <>
            <Head title="Reports" />
            <OwnerPage
                title="Reports"
                description="Sales & Store Sessions. Business date is based on when the Store Session opened."
                action={
                    <button
                        type="button"
                        onClick={() =>
                            router.reload({
                                only: ['report'],
                                onStart: () => setLoading(true),
                                onFinish: () => setLoading(false),
                            })
                        }
                        disabled={loading}
                        className={`${ownerSecondaryActionClass} inline-flex items-center justify-center gap-2 disabled:opacity-60`}
                    >
                        <RefreshCw
                            className={`size-4 ${loading ? 'animate-spin' : ''}`}
                            aria-hidden="true"
                        />
                        Refresh
                    </button>
                }
            >
                <h1 className="sr-only md:hidden">
                    Reports · Sales & Store Sessions
                </h1>
                <section
                    aria-label="Report filters"
                    className={`${ownerPanelClass} flex flex-col gap-3 p-3 min-[1100px]:flex-row min-[1100px]:items-center sm:p-4`}
                >
                    <div
                        role="group"
                        aria-label="Business date"
                        className="owner-hide-scrollbar flex max-w-full gap-[3px] overflow-x-auto rounded-[11px] bg-[#f2f2f2] p-[3px] min-[1100px]:shrink-0"
                    >
                        {REPORT_PRESETS.map(([key, label]) => (
                            <button
                                key={key}
                                type="button"
                                aria-pressed={activePreset === key}
                                onClick={() => choosePreset(key)}
                                className={`inline-flex min-h-11 shrink-0 items-center rounded-[9px] px-3.5 text-[12.5px] font-semibold whitespace-nowrap transition focus-visible:ring-2 focus-visible:ring-[#111] focus-visible:outline-none ${activePreset === key ? 'bg-[#111] text-white' : 'text-[#666] hover:text-[#111]'}`}
                            >
                                {label}
                            </button>
                        ))}
                    </div>
                    <div className="flex min-w-0 flex-1 flex-col gap-px min-[1100px]:items-center min-[1100px]:text-center">
                        <span className={labelClass}>Business date</span>
                        <span className="truncate text-[15.5px] font-bold tabular-nums">
                            {period.label}
                        </span>
                        <span className="text-[11px] text-[#8a8a8a] tabular-nums">
                            {plural(period.days, 'day')} ·{' '}
                            {plural(
                                report.session_filter.options.length,
                                'Store Session',
                            )}{' '}
                            · {scopeLabel}
                        </span>
                    </div>
                    <label className="flex min-w-0 flex-col gap-1 min-[1100px]:w-[300px] min-[1100px]:shrink-0">
                        <span className={labelClass}>Store Session</span>
                        <select
                            value={report.session_filter.selected ?? ''}
                            onChange={(event) =>
                                visit({
                                    session: event.target.value || undefined,
                                })
                            }
                            disabled={
                                report.session_filter.options.length === 0
                            }
                            className={`${ownerControlClass} min-h-11 w-full font-semibold disabled:opacity-60`}
                        >
                            <option value="">All Sessions</option>
                            {report.session_filter.options.map((option) => (
                                <option key={option.id} value={option.id}>
                                    {option.label}
                                </option>
                            ))}
                        </select>
                    </label>
                </section>

                {customOpen && (
                    <section
                        aria-label="Custom business-date range"
                        className={`${ownerPanelClass} flex flex-col gap-3 p-3 sm:flex-row sm:flex-wrap sm:items-end sm:p-4`}
                    >
                        <label className="flex min-w-0 flex-col gap-1 sm:w-[180px]">
                            <span className={labelClass}>From</span>
                            <input
                                type="date"
                                value={from}
                                max={to || undefined}
                                onChange={(event) =>
                                    setFrom(event.target.value)
                                }
                                className={`${ownerControlClass} min-h-11`}
                            />
                        </label>
                        <label className="flex min-w-0 flex-col gap-1 sm:w-[180px]">
                            <span className={labelClass}>To</span>
                            <input
                                type="date"
                                value={to}
                                min={from || undefined}
                                onChange={(event) => setTo(event.target.value)}
                                className={`${ownerControlClass} min-h-11`}
                            />
                        </label>
                        <button
                            type="button"
                            onClick={applyCustom}
                            disabled={loading}
                            className={`${ownerPrimaryActionClass} inline-flex items-center justify-center gap-2 disabled:opacity-60`}
                        >
                            <CalendarDays
                                className="size-4"
                                aria-hidden="true"
                            />
                            Apply range
                        </button>
                        <p className="text-[11.5px] leading-5 text-[#767676] sm:basis-full">
                            Up to 31 business dates, in Philippine time.
                        </p>
                        {(customError ?? errors.from ?? errors.to) && (
                            <p
                                role="alert"
                                className="text-[12.5px] font-semibold text-red-700 sm:basis-full"
                            >
                                {customError ?? errors.from ?? errors.to}
                            </p>
                        )}
                    </section>
                )}

                {report.session_filter.ignored && (
                    <Notice tone="amber">
                        The selected Store Session is not in this Branch scope
                        or business date, so all Store Sessions are shown.
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
                    <section
                        aria-label="Summary"
                        className="grid grid-cols-2 gap-3 min-[900px]:grid-cols-3 min-[1250px]:grid-cols-6"
                    >
                        <Kpi
                            label="Net Sales"
                            value={peso(summary.net_sales)}
                            hint="Committed orders, Pay Later included"
                        />
                        <Kpi
                            label="Orders"
                            value={summary.orders.toLocaleString('en-PH')}
                            hint="Committed, not voided"
                        />
                        <Kpi
                            label="Cash Collected"
                            value={peso(summary.cash)}
                            hint="Net of corrections and voids"
                        />
                        <Kpi
                            label="Cashless Collected"
                            value={peso(summary.cashless)}
                            hint="Net of corrections and voids"
                        />
                        <Kpi
                            label="Expenses"
                            value={peso(summary.expenses.total)}
                            hint={`Cash ${peso(summary.expenses.cash)} · Cashless ${peso(summary.expenses.cashless)}`}
                        />
                        <Kpi
                            label="Store Sessions"
                            value={summary.sessions.count.toLocaleString(
                                'en-PH',
                            )}
                            hint={
                                summary.sessions.open > 0
                                    ? `${summary.sessions.open} live · figures provisional`
                                    : 'All closed'
                            }
                        />
                    </section>

                    <Panel
                        title="Financial effects"
                        hint={`Collected ${peso(summary.collected)} across Cash and Cashless. These values explain the totals above and are not added again.`}
                    >
                        <div className="grid gap-2.5 md:grid-cols-3">
                            <div className={`${insetClass} p-3.5`}>
                                <p className={labelClass}>Split payments</p>
                                <p className="mt-1.5 text-[18px] font-bold tabular-nums">
                                    {peso(summary.split.total)}
                                </p>
                                <p className="mt-1 text-[11.5px] leading-4 text-[#767676]">
                                    {plural(summary.split.count, 'order')} ·
                                    Cash {peso(summary.split.cash)} · Cashless{' '}
                                    {peso(summary.split.cashless)} · already in
                                    Cash and Cashless
                                </p>
                            </div>
                            <div className={`${insetClass} p-3.5`}>
                                <p className={labelClass}>Corrections</p>
                                <p className="mt-1.5 text-[18px] font-bold tabular-nums">
                                    {peso(summary.corrections.total)}
                                </p>
                                <p className="mt-1 text-[11.5px] leading-4 text-[#767676]">
                                    Cash {peso(summary.corrections.cash)} ·
                                    Cashless{' '}
                                    {peso(summary.corrections.cashless)}
                                    {pendingAllocation &&
                                        ` · ${peso(summary.corrections.unallocated)} pending allocation`}
                                </p>
                            </div>
                            <div className={`${insetClass} p-3.5`}>
                                <p className={labelClass}>Void reversals</p>
                                <p className="mt-1.5 text-[18px] font-bold tabular-nums">
                                    {peso(summary.voids.reversal)}
                                </p>
                                <p className="mt-1 text-[11.5px] leading-4 text-[#767676]">
                                    {plural(
                                        summary.voids.count,
                                        'voided order',
                                    )}{' '}
                                    · payments reversed, excluded from Net Sales
                                </p>
                            </div>
                        </div>
                    </Panel>

                    {period.days > 1 && report.days.length > 0 && (
                        <Panel
                            title="Daily summary"
                            hint="Each business date includes the Store Sessions opened on it."
                        >
                            <div className="hidden overflow-hidden rounded-[13px] border border-[#efefef] md:block">
                                <table className="w-full text-left text-[13px] tabular-nums">
                                    <thead className="bg-[#fafafa]">
                                        <tr className={labelClass}>
                                            <th
                                                scope="col"
                                                className="px-3.5 py-2.5 font-semibold"
                                            >
                                                Business date
                                            </th>
                                            <th
                                                scope="col"
                                                className="px-3.5 py-2.5 text-right font-semibold"
                                            >
                                                Sessions
                                            </th>
                                            <th
                                                scope="col"
                                                className="px-3.5 py-2.5 text-right font-semibold"
                                            >
                                                Orders
                                            </th>
                                            <th
                                                scope="col"
                                                className="px-3.5 py-2.5 text-right font-semibold"
                                            >
                                                Net sales
                                            </th>
                                            <th
                                                scope="col"
                                                className="px-3.5 py-2.5 text-right font-semibold"
                                            >
                                                Cash
                                            </th>
                                            <th
                                                scope="col"
                                                className="px-3.5 py-2.5 text-right font-semibold"
                                            >
                                                Cashless
                                            </th>
                                            <th
                                                scope="col"
                                                className="px-3.5 py-2.5 text-right font-semibold"
                                            >
                                                Expenses
                                            </th>
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
                        </Panel>
                    )}

                    <Panel
                        title="Store Sessions"
                        hint="Sessions that cross midnight stay under their opening date."
                    >
                        {report.sessions.length === 0 ? (
                            <p
                                className={`${insetClass} px-4 py-8 text-center text-[13px] text-[#666]`}
                            >
                                No Store Session was opened on{' '}
                                {period.days > 1
                                    ? 'these business dates'
                                    : 'this business date'}{' '}
                                for {scopeLabel}.
                            </p>
                        ) : (
                            <>
                                <div className="hidden overflow-x-auto rounded-[13px] border border-[#efefef] min-[1280px]:block">
                                    <table className="w-full min-w-[920px] text-left text-[13px] tabular-nums">
                                        <thead className="bg-[#fafafa]">
                                            <tr className={labelClass}>
                                                <th
                                                    scope="col"
                                                    className="px-3 py-2.5 font-semibold"
                                                >
                                                    Store Session
                                                </th>
                                                <th
                                                    scope="col"
                                                    className="px-3 py-2.5 font-semibold"
                                                >
                                                    Staff
                                                </th>
                                                <th
                                                    scope="col"
                                                    className="px-3 py-2.5 text-right font-semibold"
                                                >
                                                    Orders
                                                </th>
                                                <th
                                                    scope="col"
                                                    className="px-3 py-2.5 text-right font-semibold"
                                                >
                                                    Net sales
                                                </th>
                                                <th
                                                    scope="col"
                                                    className="px-3 py-2.5 text-right font-semibold"
                                                >
                                                    Cash
                                                </th>
                                                <th
                                                    scope="col"
                                                    className="px-3 py-2.5 text-right font-semibold"
                                                >
                                                    Cashless
                                                </th>
                                                <th
                                                    scope="col"
                                                    className="px-3 py-2.5 text-right font-semibold"
                                                >
                                                    Expenses
                                                </th>
                                                <th
                                                    scope="col"
                                                    className="px-3 py-2.5 font-semibold"
                                                >
                                                    Status
                                                </th>
                                                <th
                                                    scope="col"
                                                    className="px-3 py-2.5 font-semibold"
                                                >
                                                    <span className="sr-only">
                                                        Actions
                                                    </span>
                                                </th>
                                            </tr>
                                        </thead>
                                        <tbody className="divide-y divide-[#f3f3f3]">
                                            {report.sessions.map((session) => (
                                                <tr key={session.id}>
                                                    <th
                                                        scope="row"
                                                        className="px-3 py-2.5 text-left font-normal"
                                                    >
                                                        <span className="block font-semibold">
                                                            {
                                                                session.branch
                                                                    .code
                                                            }{' '}
                                                            ·{' '}
                                                            {
                                                                session.business_date_label
                                                            }
                                                        </span>
                                                        <span className="block text-[11.5px] text-[#767676]">
                                                            {session.time_range}
                                                        </span>
                                                    </th>
                                                    <td className="px-3 py-2.5 text-[12px] text-[#555]">
                                                        <span className="block">
                                                            Opened ·{' '}
                                                            {session.opened_by}
                                                        </span>
                                                        {session.closed_by && (
                                                            <span className="block">
                                                                Closed ·{' '}
                                                                {
                                                                    session.closed_by
                                                                }
                                                            </span>
                                                        )}
                                                    </td>
                                                    <td className="px-3 py-2.5 text-right">
                                                        {session.orders}
                                                    </td>
                                                    <td className="px-3 py-2.5 text-right font-bold">
                                                        {peso(
                                                            session.net_sales,
                                                        )}
                                                    </td>
                                                    <td className="px-3 py-2.5 text-right">
                                                        {peso(
                                                            session.collections
                                                                .cash,
                                                        )}
                                                    </td>
                                                    <td className="px-3 py-2.5 text-right">
                                                        {peso(
                                                            session.collections
                                                                .cashless,
                                                        )}
                                                    </td>
                                                    <td className="px-3 py-2.5 text-right">
                                                        {peso(
                                                            session.expenses
                                                                .total,
                                                        )}
                                                    </td>
                                                    <td className="px-3 py-2.5">
                                                        <ResultBadge
                                                            result={
                                                                session.result
                                                            }
                                                        />
                                                    </td>
                                                    <td className="px-3 py-2 text-right">
                                                        <button
                                                            type="button"
                                                            onClick={() =>
                                                                setDetailId(
                                                                    session.id,
                                                                )
                                                            }
                                                            className={`${ownerSecondaryActionClass} inline-flex items-center gap-1 whitespace-nowrap`}
                                                        >
                                                            View session
                                                            <ChevronRight
                                                                className="size-4"
                                                                aria-hidden="true"
                                                            />
                                                        </button>
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                                <ul className="grid gap-2.5 min-[1280px]:hidden md:grid-cols-2">
                                    {report.sessions.map((session) => (
                                        <li
                                            key={session.id}
                                            className={`${insetClass} flex flex-col gap-2.5 p-3.5`}
                                        >
                                            <div className="flex items-start justify-between gap-3">
                                                <div className="min-w-0">
                                                    <p className="text-[13.5px] font-bold">
                                                        {session.branch.code} ·{' '}
                                                        {
                                                            session.business_date_label
                                                        }
                                                    </p>
                                                    <p className="text-[11.5px] text-[#767676]">
                                                        {session.time_range}
                                                    </p>
                                                </div>
                                                <ResultBadge
                                                    result={session.result}
                                                />
                                            </div>
                                            <dl className="grid grid-cols-2 gap-x-3 gap-y-2 text-[12.5px] tabular-nums sm:grid-cols-4">
                                                {[
                                                    [
                                                        'Net sales',
                                                        peso(session.net_sales),
                                                    ],
                                                    [
                                                        'Orders',
                                                        String(session.orders),
                                                    ],
                                                    [
                                                        'Cash',
                                                        peso(
                                                            session.collections
                                                                .cash,
                                                        ),
                                                    ],
                                                    [
                                                        'Cashless',
                                                        peso(
                                                            session.collections
                                                                .cashless,
                                                        ),
                                                    ],
                                                    [
                                                        'Expenses',
                                                        peso(
                                                            session.expenses
                                                                .total,
                                                        ),
                                                    ],
                                                ].map(([label, value]) => (
                                                    <div
                                                        key={label}
                                                        className="min-w-0"
                                                    >
                                                        <dt className="text-[10px] tracking-[0.06em] text-[#8a8a8a] uppercase">
                                                            {label}
                                                        </dt>
                                                        <dd className="font-semibold [overflow-wrap:anywhere]">
                                                            {value}
                                                        </dd>
                                                    </div>
                                                ))}
                                            </dl>
                                            <p className="text-[11.5px] text-[#666]">
                                                Opened by {session.opened_by}
                                                {session.closed_by &&
                                                    ` · Closed by ${session.closed_by}`}
                                            </p>
                                            <button
                                                type="button"
                                                onClick={() =>
                                                    setDetailId(session.id)
                                                }
                                                className={`${ownerSecondaryActionClass} inline-flex w-full items-center justify-center gap-1`}
                                            >
                                                View session
                                                <ChevronRight
                                                    className="size-4"
                                                    aria-hidden="true"
                                                />
                                            </button>
                                        </li>
                                    ))}
                                </ul>
                            </>
                        )}
                    </Panel>
                </div>
            </OwnerPage>

            <SessionDetail session={detail} onClose={() => setDetailId(null)} />
        </>
    );
}
