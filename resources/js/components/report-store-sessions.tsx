import { AlertTriangle, ChevronRight, Info } from 'lucide-react';
import type { ReactNode } from 'react';
import {
    OwnerStatusBadge,
    ownerSecondaryActionClass,
} from '@/components/owner-ui';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { SESSION_RESULTS, VARIANCE_LABELS } from '@/lib/reports';
import type { ReportSessionResult } from '@/lib/reports';
import { formatDecimalPeso } from '@/lib/store-close';

/*
 * Store Session summaries for the Owner Reports page: the canonical Phase 16A session rows, a responsive list and the
 * read-only reconciliation detail. CLOSED sessions show their persisted close-time snapshot; OPEN ones are live.
 */

type ChannelMoney = { cash: string; cashless: string };
type NullableChannelMoney = { cash: string | null; cashless: string | null };

export type SessionRow = {
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
    business_date_short: string;
    archived_qr_orders: number;
    reconciliation: {
        source: 'closing_snapshot' | 'closing_record' | 'live' | 'order_filtered';
        opening: ChannelMoney;
        expected: NullableChannelMoney;
        actual: NullableChannelMoney;
        variance: NullableChannelMoney;
        variance_status: NullableChannelMoney;
        closing_note: string | null;
    };
};

const peso = formatDecimalPeso;
export const isZero = (value: string) => /^-?0\.00$/.test(value);
export const plural = (count: number, word: string) =>
    `${count.toLocaleString('en-PH')} ${word}${count === 1 ? '' : 's'}`;
export const labelClass =
    'text-[10px] font-semibold tracking-[0.07em] text-[#767676] uppercase';
export const insetClass = 'rounded-xl border border-[#efefef] bg-[#fafafa]';
export function Notice({
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

export function ResultBadge({ result }: { result: ReportSessionResult }) {
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

export function SessionDetail({
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
                                {!live && (
                                    <DetailRow
                                        label="Unclaimed QR orders archived"
                                        value={session.archived_qr_orders.toLocaleString(
                                            'en-PH',
                                        )}
                                    />
                                )}
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


/** Store Sessions as a table on wide screens and cards on narrow ones; each opens the read-only detail. */
export function StoreSessionList({
    sessions,
    emptyMessage,
    onView,
}: {
    sessions: SessionRow[];
    emptyMessage: ReactNode;
    onView: (id: string) => void;
}) {
    return (
        <>
                        {sessions.length === 0 ? (
                            <p
                                className={`${insetClass} px-4 py-8 text-center text-[13px] text-[#666]`}
                            >
                                {emptyMessage}
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
                                            {sessions.map((session) => (
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
                                                                onView(
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
                                    {sessions.map((session) => (
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
                                                    onView(session.id)
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
        </>
    );
}
