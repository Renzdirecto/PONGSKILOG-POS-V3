import { Link, router } from '@inertiajs/react';
import { ChevronDown, ReceiptText } from 'lucide-react';
import { useState } from 'react';
import {
    Chip,
    EmptyState,
    OperationsShell,
    Segmented,
    opsButtonClass,
    opsLabelClass,
} from '@/components/operations-ui';
import { formatPeso, formatQuantity } from '@/lib/operations';
import operationsRoutes from '@/routes/operations';
import type { OperationsContext, PurchaseRun } from '@/types/operations';

type Props = {
    operations: OperationsContext;
    scope: 'plan' | 'all';
    stats: {
        today_cents: number;
        today_runs: number;
        week_cents: number;
        week_runs: number;
        week_estimate_cents: number;
        week_estimate_complete: boolean;
    };
    purchases: {
        data: PurchaseRun[];
        current_page: number;
        last_page: number;
        total: number;
    };
};

const when = new Intl.DateTimeFormat('en-PH', {
    dateStyle: 'medium',
    timeStyle: 'short',
    timeZone: 'Asia/Manila',
});

export default function OperationsPurchases({
    operations,
    scope,
    stats,
    purchases,
}: Props) {
    const plan = operations.plans.find(
        (item) => item.id === operations.active_plan_id,
    );
    const [open, setOpen] = useState<Record<string, boolean>>({});
    const difference = stats.week_cents - stats.week_estimate_cents;
    const scopeQuery = (next: 'plan' | 'all', page?: number) => ({
        query: {
            ...(plan ? { plan: plan.id } : {}),
            ...(next === 'all' ? { scope: 'all' } : {}),
            ...(page && page > 1 ? { page } : {}),
        },
    });

    return (
        <OperationsShell
            operations={operations}
            title="Purchases"
            description="Confirmed pamamalengke runs. Each one is saved once as a Store Purchase in Expenses."
        >
            <p className="flex items-start gap-2 rounded-xl bg-[#f7f7f7] px-3 py-2.5 text-[12.5px] leading-5">
                <ReceiptText
                    className="mt-0.5 size-4 shrink-0"
                    aria-hidden="true"
                />
                <span>
                    Each confirmed run is saved once, as a Store Purchase in the
                    existing Store Session expenses. This page is a view of
                    those records; Pamamalengke keeps no ledger of its own.
                    {!operations.branch && ' Showing every Branch.'}
                </span>
            </p>
            {plan && (
                <div className="max-w-[460px]">
                    <Segmented
                        label="Purchase scope"
                        value={scope}
                        onChange={(next) => {
                            router.visit(
                                operationsRoutes.purchases(scopeQuery(next)),
                                { preserveScroll: true },
                            );
                        }}
                        options={[
                            { value: 'plan', label: `${plan.name} plan` },
                            { value: 'all', label: 'All plans' },
                        ]}
                    />
                </div>
            )}
            <div className="grid grid-cols-2 gap-2 min-[560px]:grid-cols-3">
                <SummaryBox
                    label="Purchased today"
                    value={formatPeso(stats.today_cents, true)}
                    note={`${stats.today_runs} run${stats.today_runs === 1 ? '' : 's'}`}
                />
                <SummaryBox
                    label="Last 7 days"
                    value={formatPeso(stats.week_cents, true)}
                    note={`${stats.week_runs} runs`}
                />
                <SummaryBox
                    label="Actual vs estimate"
                    value={
                        stats.week_runs === 0
                            ? '—'
                            : `${difference >= 0 ? '+' : '−'}${formatPeso(Math.abs(difference), true)}`
                    }
                    note={
                        stats.week_estimate_complete
                            ? `Estimated ${formatPeso(stats.week_estimate_cents, true)}`
                            : 'Some estimates were incomplete'
                    }
                />
            </div>

            {purchases.data.length === 0 ? (
                <EmptyState
                    title={`No pamamalengke runs recorded${scope === 'plan' && plan ? ' for this plan' : ''} yet.`}
                    body="Confirm a run from the Shopping checklist to record it here."
                />
            ) : (
                <ul className="flex flex-col gap-2">
                    {purchases.data.map((run) => {
                        const expanded = open[run.id] ?? false;
                        const restocked = run.items.some(
                            (item) => item.type === 'ingredient',
                        );

                        return (
                            <li
                                key={run.id}
                                className="flex flex-col gap-2.5 rounded-[14px] border border-[#e5e5e5] bg-white p-3 md:p-3.5"
                            >
                                <div className="flex items-start justify-between gap-2.5">
                                    <span className="flex min-w-0 flex-col gap-0.5">
                                        <span className="text-[13.5px] font-bold">
                                            {run.created_at
                                                ? when.format(
                                                      new Date(run.created_at),
                                                  )
                                                : '—'}{' '}
                                            ·{' '}
                                            {run.plan
                                                ? `${run.plan.name} plan`
                                                : 'No plan'}
                                        </span>
                                        <span className="text-xs text-[#767676]">
                                            Pamamalengke run
                                            {run.branch && !operations.branch
                                                ? ` · ${run.branch.code}`
                                                : ''}{' '}
                                            · paid from{' '}
                                            {run.payment_source === 'cashless'
                                                ? 'Cashless'
                                                : 'Cash'}
                                        </span>
                                    </span>
                                    <Chip
                                        tone={restocked ? 'green' : 'outline'}
                                    >
                                        {restocked
                                            ? 'Stock + expense'
                                            : 'Expense only'}
                                    </Chip>
                                </div>
                                <dl className="grid grid-cols-2 gap-x-3 gap-y-2 min-[560px]:grid-cols-4">
                                    <Value
                                        label="Estimated"
                                        value={
                                            run.estimate_cents === null
                                                ? '—'
                                                : `${formatPeso(run.estimate_cents, true)}${run.estimate_complete ? '' : ' +'}`
                                        }
                                    />
                                    <Value
                                        label="Actual"
                                        value={formatPeso(run.actual_cents)}
                                    />
                                    <Value
                                        label="Items"
                                        value={String(run.items.length)}
                                    />
                                    <Value
                                        label="Bought by"
                                        value={run.bought_by ?? '—'}
                                    />
                                </dl>
                                <button
                                    type="button"
                                    aria-expanded={expanded}
                                    onClick={() =>
                                        setOpen((current) => ({
                                            ...current,
                                            [run.id]: !expanded,
                                        }))
                                    }
                                    className="flex min-h-11 items-center justify-between gap-2 border-t border-[#f2f2f2] pt-2 text-[12.5px] font-semibold"
                                >
                                    {expanded ? 'Hide details' : 'View details'}
                                    <ChevronDown
                                        className={`size-4 text-[#767676] transition ${expanded ? 'rotate-180' : ''}`}
                                    />
                                </button>
                                {expanded && (
                                    <div className="flex flex-col">
                                        {run.items.map((item, index) => (
                                            <div
                                                key={`${run.id}-${index}`}
                                                className="flex flex-wrap items-center gap-x-3 gap-y-1 border-b border-[#f2f2f2] py-2"
                                            >
                                                <span className="min-w-0 flex-[1_1_140px] text-[13px] font-medium wrap-anywhere">
                                                    {item.name}
                                                </span>
                                                <span className="text-xs text-[#666] tabular-nums">
                                                    {item.recommended !==
                                                        null &&
                                                    item.recommended !==
                                                        item.quantity
                                                        ? `Recommended ${formatQuantity(item.recommended, item.unit)} · `
                                                        : ''}
                                                    {formatQuantity(
                                                        item.quantity,
                                                        item.unit,
                                                    )}{' '}
                                                    ×{' '}
                                                    {formatPeso(
                                                        item.unit_cost_cents,
                                                    )}
                                                </span>
                                                <Chip
                                                    tone={
                                                        item.type ===
                                                        'ingredient'
                                                            ? 'green'
                                                            : 'outline'
                                                    }
                                                >
                                                    {item.type ===
                                                        'ingredient' &&
                                                    item.base_quantity &&
                                                    item.base_unit
                                                        ? `+${formatQuantity(item.base_quantity, item.base_unit)} stock`
                                                        : 'Not stock-tracked'}
                                                </Chip>
                                                <span className="w-20 text-right text-[13px] font-semibold tabular-nums">
                                                    {formatPeso(
                                                        item.total_cents,
                                                    )}
                                                </span>
                                            </div>
                                        ))}
                                        <p className="pt-2 text-[11px] leading-5 text-[#767676]">
                                            Saved once as Store Purchase{' '}
                                            {run.expense_reference}
                                            {run.note ? ` · ${run.note}` : ''}.
                                            Audited with{' '}
                                            {run.bought_by ?? 'the buyer'} and
                                            the time of confirmation.
                                        </p>
                                    </div>
                                )}
                            </li>
                        );
                    })}
                </ul>
            )}
            {purchases.last_page > 1 && (
                <nav
                    aria-label="Purchases pagination"
                    className="flex items-center justify-between gap-2"
                >
                    {purchases.current_page > 1 ? (
                        <Link
                            href={operationsRoutes.purchases(
                                scopeQuery(scope, purchases.current_page - 1),
                            )}
                            className={opsButtonClass}
                        >
                            Newer
                        </Link>
                    ) : (
                        <span />
                    )}
                    <span className="text-xs text-[#767676]">
                        Page {purchases.current_page} of {purchases.last_page}
                    </span>
                    {purchases.current_page < purchases.last_page ? (
                        <Link
                            href={operationsRoutes.purchases(
                                scopeQuery(scope, purchases.current_page + 1),
                            )}
                            className={opsButtonClass}
                        >
                            Older
                        </Link>
                    ) : (
                        <span />
                    )}
                </nav>
            )}
        </OperationsShell>
    );
}

function SummaryBox({
    label,
    value,
    note,
}: {
    label: string;
    value: string;
    note: string;
}) {
    return (
        <div className="flex min-w-0 flex-col gap-1 rounded-[13px] border border-[#e5e5e5] bg-white p-3">
            <span className={opsLabelClass}>{label}</span>
            <span className="text-xl font-bold tracking-[-0.02em] tabular-nums">
                {value}
            </span>
            <span className="text-[11px] text-[#8a8a8a]">{note}</span>
        </div>
    );
}

function Value({ label, value }: { label: string; value: string }) {
    return (
        <div className="flex min-w-0 flex-col gap-px">
            <dt className={opsLabelClass}>{label}</dt>
            <dd className="truncate text-sm font-bold tabular-nums">{value}</dd>
        </div>
    );
}
