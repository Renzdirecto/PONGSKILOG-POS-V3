import { http, router } from '@inertiajs/react';
import {
    AlertTriangle,
    ArrowDown,
    ArrowLeft,
    ArrowUp,
    Banknote,
    Check,
    CheckCircle2,
    ChefHat,
    ChevronDown,
    CircleAlert,
    CreditCard,
    Info,
    LockKeyhole,
    PackagePlus,
    Pencil,
    ReceiptText,
    RefreshCw,
    Smartphone,
    WifiOff,
} from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';
import { DialogDescription, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { InventoryAdjustmentRow } from '@/components/store-inventory-adjustment-form';
import { useStoreCloseRealtime } from '@/hooks/use-store-close-realtime';
import { sessionActivity } from '@/lib/store-inventory-adjustment';
import { createClientUuid } from '@/lib/client-uuid';
import {
    assessClose,
    closeAttemptSignature,
    formatDecimalPeso,
    formatPeso,
    isBrowserOnline,
    MINIMUM_OVERAGE_NOTE_LENGTH,
    normalizeMoneyInput,
    shortageMessage,
    signedCents,
    storeCloseError,
    type ChannelAssessment,
} from '@/lib/store-close';
import { store as allocateCorrection } from '@/routes/pos/order-adjustments/allocation';
import {
    show as closePreview,
    store as closeStore,
} from '@/routes/store-sessions/close';
import { kitchen, transactionHistory } from '@/routes/workspaces';
import type { CurrentStoreSession } from '@/types';
import type {
    StoreCloseCorrection,
    StoreClosePreview,
    StoreCloseResult,
} from '@/types/store-close';

type Stage = 'review' | 'confirm' | 'closed';

const manilaTime = new Intl.DateTimeFormat('en-PH', {
    timeZone: 'Asia/Manila',
    month: 'short',
    day: 'numeric',
    hour: 'numeric',
    minute: '2-digit',
});

const kitchenLabels = {
    kitchen: 'In Kitchen',
    preparing: 'Preparing',
    ready: 'Ready',
} as const;

function useBrowserOnline(): boolean {
    const [online, setOnline] = useState(isBrowserOnline);

    useEffect(() => {
        const update = () => setOnline(isBrowserOnline());
        window.addEventListener('online', update);
        window.addEventListener('offline', update);
        return () => {
            window.removeEventListener('online', update);
            window.removeEventListener('offline', update);
        };
    }, []);

    return online;
}

function orderLabel(order: {
    order_number: string;
    customer_label: string | null;
    table_name?: string | null;
}): string {
    return [`#${order.order_number}`, order.table_name, order.customer_label]
        .filter(Boolean)
        .join(' · ');
}

function CheckCard({
    tone,
    title,
    detail,
    children,
}: {
    tone: 'ok' | 'blocked' | 'info';
    title: string;
    detail?: string;
    children?: React.ReactNode;
}) {
    const styles = {
        ok: 'border-green-200 bg-green-50 text-green-900',
        blocked: 'border-red-200 bg-red-50 text-red-900',
        info: 'border-neutral-200 bg-neutral-50 text-neutral-800',
    }[tone];
    const Icon = { ok: CheckCircle2, blocked: CircleAlert, info: Info }[tone];

    return (
        <section className={`rounded-xl border p-3 ${styles}`}>
            <div className="flex items-start gap-2.5">
                <Icon className="mt-0.5 size-4 shrink-0" aria-hidden />
                <div className="min-w-0 flex-1">
                    <p className="text-[13px] font-bold">
                        <span className="sr-only">
                            {tone === 'blocked'
                                ? 'Blocker: '
                                : tone === 'ok'
                                  ? 'Passed: '
                                  : 'Information: '}
                        </span>
                        {title}
                    </p>
                    {detail && (
                        <p className="mt-0.5 text-xs leading-5 opacity-80">
                            {detail}
                        </p>
                    )}
                </div>
            </div>
            {children && <div className="mt-2.5">{children}</div>}
        </section>
    );
}

function BlockerRows({
    rows,
}: {
    rows: { key: string; label: string; value: string }[];
}) {
    return (
        <ul className="divide-y divide-red-100 overflow-hidden rounded-lg border border-red-100 bg-white text-neutral-950">
            {rows.map((row) => (
                <li
                    key={row.key}
                    className="flex items-center justify-between gap-3 px-3 py-2 text-xs"
                >
                    <span className="min-w-0 truncate font-semibold">
                        {row.label}
                    </span>
                    <span className="shrink-0 font-bold text-red-800 tabular-nums">
                        {row.value}
                    </span>
                </li>
            ))}
        </ul>
    );
}

function CorrectionAllocation({
    item,
    online,
    onSaved,
}: {
    item: StoreCloseCorrection;
    online: boolean;
    onSaved: () => Promise<void>;
}) {
    const [cash, setCash] = useState('');
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState('');
    const normalized = normalizeMoneyInput(cash);
    const refund = signedCents(item.amount);
    const cashCents = normalized === null ? null : signedCents(normalized);
    const inRange =
        cashCents !== null &&
        cashCents >= signedCents(item.min_cash) &&
        cashCents <= signedCents(item.max_cash);

    async function save() {
        if (!online || normalized === null) return;
        setSaving(true);
        setError('');
        try {
            await http.getClient().request({
                ...allocateCorrection(item.id),
                data: { cash_amount: normalized },
                headers: { Accept: 'application/json' },
            });
            toast.success(`Correction for #${item.order_number} allocated.`);
            await onSaved();
        } catch (reason) {
            setError(storeCloseError(reason).message);
        } finally {
            setSaving(false);
        }
    }

    return (
        <div className="rounded-lg border border-red-100 bg-white p-3 text-neutral-950">
            <p className="text-xs font-bold">
                {orderLabel(item)} · {formatDecimalPeso(item.amount)} returned
            </p>
            <p className="mt-0.5 text-[11px] leading-4 text-neutral-500">
                How much of this refund left the Cash drawer? Allowed{' '}
                {formatDecimalPeso(item.min_cash)} –{' '}
                {formatDecimalPeso(item.max_cash)}.
            </p>
            <div className="mt-2 grid gap-2 sm:grid-cols-[1fr_auto]">
                <div className="relative">
                    <span className="absolute top-1/2 left-3 -translate-y-1/2 text-sm font-bold text-neutral-500">
                        ₱
                    </span>
                    <Input
                        value={cash}
                        onChange={(event) => setCash(event.target.value)}
                        inputMode="decimal"
                        aria-label={`Cash portion of the correction for order ${item.order_number}`}
                        className="min-h-11 rounded-xl pl-8 font-bold tabular-nums"
                        placeholder="Cash portion"
                    />
                </div>
                <Button
                    type="button"
                    onClick={() => void save()}
                    disabled={!online || !inRange || saving}
                    className="min-h-11 rounded-xl bg-neutral-950 px-4 text-white hover:bg-black"
                >
                    {saving ? <Spinner /> : null}
                    Save allocation
                </Button>
            </div>
            {inRange && cashCents !== null && (
                <p className="mt-1.5 text-[11px] text-neutral-600 tabular-nums">
                    Cash {formatPeso(cashCents)} · Cashless{' '}
                    {formatPeso(refund - cashCents)}
                </p>
            )}
            {error && (
                <p role="alert" className="mt-1.5 text-[11px] text-red-800">
                    {error}
                </p>
            )}
        </div>
    );
}

function MoneyLine({
    label,
    hint,
    cash,
    cashless,
    sign = '',
    strong = false,
}: {
    label: string;
    hint?: string;
    cash: string;
    cashless: string;
    sign?: '' | '+' | '−';
    strong?: boolean;
}) {
    const value = (amount: string) =>
        sign === '−' && signedCents(amount) === 0n
            ? formatDecimalPeso(amount)
            : `${sign}${sign ? ' ' : ''}${formatDecimalPeso(amount)}`;

    return (
        <div
            className={`grid grid-cols-[minmax(0,1fr)_auto_auto] items-baseline gap-x-3 px-3 py-2.5 sm:grid-cols-[minmax(0,1fr)_8.5rem_8.5rem] ${strong ? 'bg-neutral-950 text-white' : ''}`}
        >
            <div className="min-w-0">
                <p
                    className={`text-xs ${strong ? 'font-bold' : 'font-semibold'}`}
                >
                    {label}
                </p>
                {hint && (
                    <p
                        className={`text-[10px] leading-4 ${strong ? 'text-white/60' : 'text-neutral-500'}`}
                    >
                        {hint}
                    </p>
                )}
            </div>
            <p className="text-right text-xs font-bold break-all tabular-nums">
                {value(cash)}
            </p>
            <p className="text-right text-xs font-bold break-all tabular-nums">
                {value(cashless)}
            </p>
        </div>
    );
}

function VarianceCard({
    label,
    icon: Icon,
    expected,
    assessment,
}: {
    label: 'Cash' | 'Cashless';
    icon: typeof Banknote;
    expected: string;
    assessment: ChannelAssessment;
}) {
    const state = assessment.state;
    const variance = assessment.variance ?? 0n;
    const tone =
        state === 'shortage'
            ? 'border-red-200 bg-red-50 text-red-900'
            : state === 'overage'
              ? 'border-amber-200 bg-amber-50 text-amber-900'
              : state === 'exact'
                ? 'border-green-200 bg-green-50 text-green-900'
                : 'border-neutral-200 bg-neutral-50 text-neutral-700';
    const status =
        state === 'shortage'
            ? `Shortage ${formatPeso(-variance)}`
            : state === 'overage'
              ? `Overage ${formatPeso(variance)}`
              : state === 'exact'
                ? 'Exact'
                : state === 'invalid'
                  ? 'Invalid amount'
                  : 'Waiting for count';

    return (
        <div className={`rounded-xl border p-3 ${tone}`}>
            <div className="flex flex-wrap items-center justify-between gap-x-2 gap-y-1">
                <p className="flex items-center gap-1.5 text-[10px] font-bold tracking-wider uppercase">
                    <Icon className="size-3.5" aria-hidden /> {label}
                </p>
                <p className="text-right text-[11px] font-bold break-all">
                    {state === 'shortage'
                        ? '! '
                        : state === 'exact'
                          ? '✓ '
                          : state === 'overage'
                            ? '▲ '
                            : ''}
                    {status}
                </p>
            </div>
            <dl className="mt-2 grid grid-cols-3 gap-2 text-neutral-950">
                {[
                    ['Expected', formatDecimalPeso(expected)],
                    [
                        'Actual',
                        assessment.actual === null
                            ? '—'
                            : formatPeso(assessment.actual),
                    ],
                    [
                        'Difference',
                        assessment.variance === null
                            ? '—'
                            : `${variance > 0n ? '+' : ''}${formatPeso(variance)}`,
                    ],
                ].map(([term, value]) => (
                    <div key={term} className="min-w-0">
                        <dt className="text-[9px] font-semibold tracking-wider text-neutral-500 uppercase">
                            {term}
                        </dt>
                        <dd className="mt-0.5 text-[clamp(0.7rem,2.6vw,0.85rem)] font-bold break-all tabular-nums">
                            {value}
                        </dd>
                    </div>
                ))}
            </dl>
        </div>
    );
}

/** Final-confirmation summary for one independent reconciliation channel. */
function ChannelSummary({
    label,
    description,
    icon: Icon,
    tone,
    expected,
    actual,
    assessment,
}: {
    label: 'Cash' | 'Cashless';
    description: string;
    icon: typeof Banknote;
    tone: 'green' | 'blue';
    expected: string;
    actual: string;
    assessment: ChannelAssessment;
}) {
    const variance = assessment.variance ?? 0n;
    const state = assessment.state;
    const varianceStyle =
        state === 'exact'
            ? 'bg-green-50 text-green-800'
            : 'bg-red-50 text-red-700';
    const VarianceIcon =
        state === 'exact' ? Check : state === 'overage' ? ArrowUp : ArrowDown;

    return (
        <section className="overflow-hidden rounded-xl border border-neutral-200">
            <header className="flex items-center gap-3 px-3.5 pt-3.5 pb-2.5">
                <span
                    className={`flex size-10 shrink-0 items-center justify-center rounded-xl ${tone === 'green' ? 'bg-green-100 text-green-700' : 'bg-sky-100 text-sky-700'}`}
                >
                    <Icon className="size-5" aria-hidden />
                </span>
                <div className="min-w-0">
                    <p className="text-sm font-bold">{label}</p>
                    <p className="truncate text-[11px] text-neutral-500">
                        {description}
                    </p>
                </div>
            </header>
            <dl>
                {[
                    [`Expected ${label}`, formatDecimalPeso(expected)],
                    [`Actual ${label}`, formatDecimalPeso(actual)],
                ].map(([term, value]) => (
                    <div
                        key={term}
                        className="flex items-center justify-between gap-4 border-t border-neutral-100 px-3.5 py-2.5"
                    >
                        <dt className="text-xs text-neutral-600">{term}</dt>
                        <dd className="text-right text-sm font-semibold break-all tabular-nums">
                            {value}
                        </dd>
                    </div>
                ))}
                <div
                    className={`flex items-center justify-between gap-4 px-3.5 py-2.5 ${varianceStyle}`}
                >
                    <dt className="text-xs font-bold">{label} Variance</dt>
                    <dd className="flex items-center gap-2 text-right text-sm font-bold break-all tabular-nums">
                        {formatPeso(variance < 0n ? -variance : variance)}
                        <span
                            className={`flex size-5 shrink-0 items-center justify-center rounded-full ${state === 'exact' ? 'bg-green-600 text-white' : 'bg-red-100 text-red-700'}`}
                        >
                            <VarianceIcon className="size-3.5" aria-hidden />
                        </span>
                        <span className="sr-only">
                            {state === 'exact'
                                ? 'Exact'
                                : state === 'overage'
                                  ? 'Overage'
                                  : 'Shortage'}
                        </span>
                    </dd>
                </div>
            </dl>
        </section>
    );
}

/** Read-only view of the current session's purchases so the count can be checked against them. */
function SessionPurchases({
    session,
}: {
    session: CurrentStoreSession | null;
}) {
    const [open, setOpen] = useState(false);

    if (!session) {
        return null;
    }

    return (
        <section className="overflow-hidden rounded-xl border border-neutral-200">
            <button
                type="button"
                aria-expanded={open}
                aria-controls="session-purchases-list"
                onClick={() => setOpen((value) => !value)}
                className="flex min-h-14 w-full items-center justify-between gap-3 px-3.5 py-3 text-left hover:bg-neutral-50 focus-visible:ring-2 focus-visible:ring-neutral-950 focus-visible:outline-none focus-visible:ring-inset"
            >
                <span className="min-w-0">
                    <span className="block text-sm font-bold">
                        Purchases &amp; expenses ({session.expense_count})
                    </span>
                    <span className="mt-0.5 block text-xs text-neutral-500 tabular-nums">
                        Cash {formatDecimalPeso(session.expense_totals.cash)} ·
                        Cashless{' '}
                        {formatDecimalPeso(session.expense_totals.cashless)}
                        {session.inventory_adjustment_count > 0 &&
                            ` · ${session.inventory_adjustment_count} stock ${session.inventory_adjustment_count === 1 ? 'adjustment' : 'adjustments'}`}
                    </span>
                </span>
                <ChevronDown
                    aria-hidden
                    className={`size-5 shrink-0 text-neutral-500 transition-transform ${open ? 'rotate-180' : ''}`}
                />
            </button>
            {open && (
                <div
                    id="session-purchases-list"
                    className="border-t border-neutral-200 p-3.5"
                >
                    {session.expenses.length === 0 &&
                    (session.inventory_adjustments ?? []).length === 0 ? (
                        <p className="py-3 text-center text-xs text-neutral-500">
                            No purchases, expenses or stock adjustments recorded
                            for this Store Session.
                        </p>
                    ) : (
                        <ul className="divide-y divide-neutral-100 overflow-hidden rounded-lg border border-neutral-100">
                            {sessionActivity(
                                session.expenses,
                                session.inventory_adjustments,
                            ).map((entry) =>
                                entry.kind === 'adjustment' ? (
                                    <li key={`adjustment-${entry.item.id}`}>
                                        <InventoryAdjustmentRow
                                            adjustment={entry.item}
                                            compact
                                        />
                                    </li>
                                ) : (
                                    ((expense) => (
                                        <li
                                            key={expense.id}
                                            className="flex items-center gap-3 px-3 py-2.5"
                                        >
                                            <span
                                                className={`flex size-8 shrink-0 items-center justify-center rounded-lg ${expense.payment_source === 'cash' ? 'bg-amber-50 text-amber-800' : 'bg-sky-50 text-sky-700'}`}
                                            >
                                                {expense.payment_source ===
                                                'cash' ? (
                                                    <Banknote className="size-4" />
                                                ) : (
                                                    <Smartphone className="size-4" />
                                                )}
                                            </span>
                                            <span className="min-w-0 flex-1">
                                                <span className="block truncate text-xs font-bold">
                                                    {expense.description}
                                                </span>
                                                <span className="mt-0.5 block truncate text-[10px] text-neutral-500">
                                                    {manilaTime.format(
                                                        new Date(
                                                            expense.created_at,
                                                        ),
                                                    )}{' '}
                                                    · {expense.created_by.name}
                                                </span>
                                                {expense.item && (
                                                    <span className="mt-1 inline-flex items-center gap-1 rounded bg-emerald-50 px-1.5 py-0.5 text-[9px] font-bold text-emerald-800">
                                                        <PackagePlus className="size-3" />{' '}
                                                        RESTOCK ·{' '}
                                                        {
                                                            expense.item
                                                                .product_name
                                                        }{' '}
                                                        ×{' '}
                                                        {expense.item.quantity}
                                                    </span>
                                                )}
                                            </span>
                                            <span className="shrink-0 text-right">
                                                <span className="block text-xs font-bold tabular-nums">
                                                    {formatDecimalPeso(
                                                        expense.amount,
                                                    )}
                                                </span>
                                                <span className="text-[9px] font-semibold tracking-wide text-neutral-500 uppercase">
                                                    {expense.payment_source}
                                                </span>
                                            </span>
                                        </li>
                                    ))(entry.item)
                                ),
                            )}
                        </ul>
                    )}
                    {session.expenses_truncated && (
                        <p className="pt-2 text-center text-[10px] text-neutral-500">
                            Showing the newest 50 of {session.expense_count}{' '}
                            records. Totals include all records.
                        </p>
                    )}
                </div>
            )}
        </section>
    );
}

export function StoreCloseFlow({
    session,
    branchId,
    canOpenKitchen,
    canOpenHistory,
    onBack,
    onBusyChange,
    onClosed,
    onDone,
    onNavigate,
}: {
    session: CurrentStoreSession | null;
    branchId: string;
    canOpenKitchen: boolean;
    canOpenHistory: boolean;
    onBack: () => void;
    onBusyChange: (busy: boolean) => void;
    onClosed: (result: StoreCloseResult) => void;
    onDone: () => void;
    onNavigate: () => void;
}) {
    const online = useBrowserOnline();
    const [stage, setStage] = useState<Stage>('review');
    const [preview, setPreview] = useState<StoreClosePreview | null>(null);
    const [refreshing, setRefreshing] = useState(true);
    const [loadError, setLoadError] = useState('');
    const [cashInput, setCashInput] = useState('');
    const [cashlessInput, setCashlessInput] = useState('');
    const [note, setNote] = useState('');
    const [attempt, setAttempt] = useState<{
        key: string;
        signature: string;
    } | null>(null);
    const [submitting, setSubmitting] = useState(false);
    const [submitError, setSubmitError] = useState('');
    const [result, setResult] = useState<StoreCloseResult | null>(null);

    const loadPreview = useCallback(async () => {
        setRefreshing(true);
        try {
            const response = await http.getClient().request({
                ...closePreview(),
                headers: { Accept: 'application/json' },
            });
            setPreview(JSON.parse(response.data) as StoreClosePreview);
            setLoadError('');
        } catch (reason) {
            const status = (reason as { response?: { status?: number } })
                .response?.status;
            setLoadError(
                status === 404
                    ? 'The Store is already closed. This summary is no longer active.'
                    : status === 403
                      ? 'You do not have permission to close this Store.'
                      : 'The closing summary could not be loaded. Check your connection and Recheck.',
            );
        } finally {
            setRefreshing(false);
        }
    }, []);
    const connectionStatus = useStoreCloseRealtime(branchId, loadPreview);

    useEffect(() => {
        void loadPreview();
    }, [loadPreview]);

    useEffect(() => {
        onBusyChange(submitting);
    }, [submitting, onBusyChange]);

    const reconciliation = preview?.ready ? preview.reconciliation : null;
    const assessment = reconciliation
        ? assessClose(reconciliation.expected, cashInput, cashlessInput, note)
        : null;
    const cashValue = normalizeMoneyInput(cashInput);
    const cashlessValue = normalizeMoneyInput(cashlessInput);
    const trimmedNote = assessment?.needsNote ? note.trim() : null;

    function continueToConfirm() {
        if (
            !preview ||
            !assessment?.canContinue ||
            cashValue === null ||
            cashlessValue === null
        ) {
            return;
        }
        const signature = closeAttemptSignature(
            preview.store_session.id,
            cashValue,
            cashlessValue,
            trimmedNote,
        );
        if (attempt?.signature !== signature) {
            setAttempt({ key: createClientUuid(), signature });
        }
        setSubmitError('');
        setStage('confirm');
    }

    async function submit() {
        if (
            !preview ||
            !attempt ||
            cashValue === null ||
            cashlessValue === null
        ) {
            return;
        }
        if (!online) {
            setSubmitError(
                'You are offline. Reconnect before closing the Store.',
            );
            return;
        }
        setSubmitting(true);
        setSubmitError('');
        try {
            const response = await http.getClient().request({
                ...closeStore(),
                data: {
                    idempotency_key: attempt.key,
                    store_session_id: preview.store_session.id,
                    closing_cash_amount: cashValue,
                    closing_cashless_amount: cashlessValue,
                    closing_note: trimmedNote,
                },
                headers: { Accept: 'application/json' },
            });
            const closed = JSON.parse(response.data) as StoreCloseResult;
            setResult(closed);
            setStage('closed');
            onClosed(closed);
            router.reload();
        } catch (reason) {
            const failure = storeCloseError(reason);
            setSubmitError(failure.message);
            /** An ambiguous network result keeps the same key so a retry recovers instead of closing twice. */
            if (failure.kind !== 'network') {
                setAttempt(null);
            }
            if (
                failure.kind === 'blocker' ||
                failure.kind === 'conflict' ||
                failure.kind === 'validation'
            ) {
                setStage('review');
                void loadPreview();
            }
            if (failure.kind === 'closed') {
                router.reload();
            }
        } finally {
            setSubmitting(false);
        }
    }

    const branchLabel = preview
        ? `${preview.store_session.branch.code} · ${preview.store_session.branch.name}`
        : 'Current Store Session';

    if (stage === 'closed' && result) {
        const closed = result.store_session;
        const rows: [string, string][] = [
            ['Closed at', manilaTime.format(new Date(closed.closed_at))],
            ['Closed by', closed.closed_by.name ?? '—'],
            ['Closing Cash', formatDecimalPeso(closed.closing_cash_amount)],
            [
                'Closing Cashless',
                formatDecimalPeso(closed.closing_cashless_amount),
            ],
            ['Cash variance', formatDecimalPeso(closed.cash_variance)],
            ['Cashless variance', formatDecimalPeso(closed.cashless_variance)],
            ['QR orders archived', String(closed.qr_archived_count)],
        ];

        return (
            <div className="flex min-h-0 flex-1 flex-col">
                <div className="min-h-0 flex-1 overflow-y-auto py-2">
                    <div className="flex flex-col items-center gap-2 px-2 py-4 text-center">
                        <span className="flex size-14 items-center justify-center rounded-2xl bg-neutral-950 text-white">
                            <LockKeyhole className="size-6" />
                        </span>
                        <DialogTitle className="text-xl font-bold">
                            Store Closed
                        </DialogTitle>
                        <DialogDescription className="text-xs text-neutral-500">
                            {closed.branch.code} · {closed.branch.name}. QR
                            ordering is now unavailable until a new Store
                            Session is opened.
                        </DialogDescription>
                    </div>
                    <dl className="overflow-hidden rounded-xl border border-neutral-200">
                        {rows.map(([label, value]) => (
                            <div
                                key={label}
                                className="flex items-center justify-between gap-4 border-b border-neutral-100 px-3.5 py-2.5 last:border-0"
                            >
                                <dt className="text-[10px] font-semibold tracking-wider text-neutral-500 uppercase">
                                    {label}
                                </dt>
                                <dd className="text-right text-sm font-bold break-all tabular-nums">
                                    {value}
                                </dd>
                            </div>
                        ))}
                    </dl>
                    {closed.closing_note && (
                        <p className="mt-3 rounded-xl border border-amber-200 bg-amber-50 p-3 text-xs leading-5 break-words text-amber-900">
                            <span className="font-bold">
                                Overage explanation:{' '}
                            </span>
                            {closed.closing_note}
                        </p>
                    )}
                </div>
                <footer className="border-t border-neutral-200 pt-3 pb-[max(0px,env(safe-area-inset-bottom))]">
                    <Button
                        type="button"
                        onClick={onDone}
                        className="min-h-12 w-full rounded-xl bg-neutral-950 text-white hover:bg-black"
                    >
                        Done
                    </Button>
                </footer>
            </div>
        );
    }

    if (
        stage === 'confirm' &&
        preview &&
        reconciliation &&
        assessment &&
        cashValue !== null &&
        cashlessValue !== null
    ) {
        return (
            <div className="flex min-h-0 flex-1 flex-col">
                <header className="flex items-center gap-2 border-b border-neutral-200 pr-12 pb-3 pl-1">
                    <button
                        type="button"
                        onClick={() => setStage('review')}
                        disabled={submitting}
                        className="flex size-11 shrink-0 items-center justify-center rounded-xl hover:bg-neutral-100 focus-visible:ring-2 focus-visible:ring-neutral-950 focus-visible:outline-none disabled:opacity-50"
                        aria-label="Back to closing summary"
                    >
                        <ArrowLeft className="size-5" />
                    </button>
                    <div className="min-w-0">
                        <p className="text-[10px] font-semibold tracking-wider text-red-700 uppercase">
                            Final confirmation
                        </p>
                        <DialogTitle className="truncate text-base font-bold">
                            Close {preview.store_session.branch.name}?
                        </DialogTitle>
                        <DialogDescription className="truncate text-[11px] text-neutral-500">
                            Please review the session summary before closing.
                        </DialogDescription>
                    </div>
                </header>
                <div className="min-h-0 flex-1 space-y-3 overflow-y-auto py-4">
                    <ChannelSummary
                        label="Cash"
                        description="Physical cash payments"
                        icon={Banknote}
                        tone="green"
                        expected={reconciliation.expected.cash}
                        actual={cashValue}
                        assessment={assessment.cash}
                    />
                    <ChannelSummary
                        label="Cashless"
                        description="Card, e-wallet, and other cashless payments"
                        icon={CreditCard}
                        tone="blue"
                        expected={reconciliation.expected.cashless}
                        actual={cashlessValue}
                        assessment={assessment.cashless}
                    />
                    {trimmedNote && (
                        <section className="flex items-start gap-3 rounded-xl border border-amber-200 bg-amber-50 p-3.5 text-amber-950">
                            <span className="flex size-7 shrink-0 items-center justify-center rounded-full bg-amber-600 text-white">
                                <CircleAlert className="size-4" aria-hidden />
                            </span>
                            <div className="min-w-0 flex-1">
                                <p className="text-sm font-bold">
                                    Overage explanation
                                </p>
                                <p className="mt-0.5 text-xs leading-5 break-words">
                                    {trimmedNote}
                                </p>
                            </div>
                            <button
                                type="button"
                                onClick={() => setStage('review')}
                                disabled={submitting}
                                aria-label="Edit overage explanation"
                                className="flex size-11 shrink-0 items-center justify-center rounded-xl text-amber-900 hover:bg-amber-100 focus-visible:ring-2 focus-visible:ring-neutral-950 focus-visible:outline-none disabled:opacity-50"
                            >
                                <Pencil className="size-4" />
                            </button>
                        </section>
                    )}
                    <section className="flex items-start gap-3 rounded-xl border border-red-200 bg-red-50 p-3.5 text-red-900">
                        <AlertTriangle
                            className="mt-0.5 size-5 shrink-0 text-red-700"
                            aria-hidden
                        />
                        <div className="min-w-0">
                            <p className="text-sm font-bold text-red-700">
                                This will close the current store session
                            </p>
                            <p className="mt-0.5 text-xs leading-5">
                                This action closes the current Store Session and
                                disables operational ordering until a new Store
                                Session is opened.
                                {preview.qr.unclaimed_count > 0 &&
                                    ` ${preview.qr.unclaimed_count} unclaimed QR ${preview.qr.unclaimed_count === 1 ? 'order' : 'orders'} will be archived.`}{' '}
                                Any unsent POS cart on this device will be
                                cleared.
                            </p>
                        </div>
                    </section>
                    {!online && (
                        <p
                            role="alert"
                            className="flex items-center gap-2 rounded-xl border border-red-200 bg-red-50 p-3 text-xs text-red-900"
                        >
                            <WifiOff className="size-4 shrink-0" /> You are
                            offline. Reconnect before closing the Store.
                        </p>
                    )}
                    {submitError && (
                        <p
                            role="alert"
                            className="rounded-xl border border-red-200 bg-red-50 p-3 text-sm leading-5 text-red-800"
                        >
                            {submitError}
                        </p>
                    )}
                </div>
                <footer className="grid grid-cols-[auto_minmax(0,1fr)] gap-2 border-t border-neutral-200 pt-3 pb-[max(0px,env(safe-area-inset-bottom))]">
                    <Button
                        type="button"
                        variant="outline"
                        onClick={() => setStage('review')}
                        disabled={submitting}
                        className="min-h-12 rounded-xl"
                    >
                        Cancel
                    </Button>
                    <Button
                        type="button"
                        onClick={() => void submit()}
                        disabled={
                            submitting || !online || !assessment.canContinue
                        }
                        className="min-h-12 rounded-xl bg-red-700 text-white hover:bg-red-800"
                    >
                        {submitting ? (
                            <Spinner />
                        ) : (
                            <LockKeyhole className="size-4" />
                        )}
                        {submitting ? 'Closing Store…' : 'Confirm Close Store'}
                    </Button>
                </footer>
            </div>
        );
    }

    const blockers = preview?.blockers;

    return (
        <div className="flex min-h-0 flex-1 flex-col">
            <header className="flex items-center gap-2 border-b border-neutral-200 pr-12 pb-3 pl-1">
                <button
                    type="button"
                    onClick={onBack}
                    className="flex size-11 shrink-0 items-center justify-center rounded-xl hover:bg-neutral-100 focus-visible:ring-2 focus-visible:ring-neutral-950 focus-visible:outline-none"
                    aria-label="Back to Current Store Session"
                >
                    <ArrowLeft className="size-5" />
                </button>
                <div className="min-w-0 flex-1">
                    <p className="text-[10px] font-semibold tracking-wider text-red-700 uppercase">
                        Close Store
                    </p>
                    <DialogTitle className="truncate text-base font-bold">
                        Review &amp; reconcile
                    </DialogTitle>
                    <DialogDescription className="truncate text-[11px] text-neutral-500">
                        {branchLabel}
                    </DialogDescription>
                </div>
                <Button
                    type="button"
                    variant="outline"
                    onClick={() => void loadPreview()}
                    disabled={refreshing}
                    className="min-h-11 shrink-0 rounded-xl px-3"
                >
                    <RefreshCw
                        className={`size-4 ${refreshing ? 'animate-spin' : ''}`}
                    />
                    Recheck
                </Button>
            </header>

            <div className="min-h-0 flex-1 space-y-4 overflow-y-auto py-4 pr-1">
                {!online ? (
                    <p
                        role="status"
                        className="flex items-center gap-2 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-xs text-red-900"
                    >
                        <WifiOff className="size-4 shrink-0" /> You are offline.
                        Reconnect before closing the Store.
                    </p>
                ) : connectionStatus !== 'connected' ? (
                    <p
                        role="status"
                        className="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-900"
                    >
                        Live updates unavailable. Use Recheck after changes;
                        closing still verifies everything on the server.
                    </p>
                ) : null}

                {loadError && (
                    <div
                        role="alert"
                        className="rounded-xl border border-amber-200 bg-amber-50 p-3 text-sm leading-5 text-amber-900"
                    >
                        {loadError}
                    </div>
                )}

                {!preview && refreshing && (
                    <div
                        className="space-y-2"
                        role="status"
                        aria-label="Loading closing checks"
                    >
                        {[0, 1, 2].map((row) => (
                            <div
                                key={row}
                                className="h-16 animate-pulse rounded-xl bg-neutral-100"
                            />
                        ))}
                    </div>
                )}

                {/* Pre-close checks appear only while something blocks closing; the QR archive count stays in the confirmation. */}
                {preview && blockers && !preview.ready && (
                    <section
                        className="space-y-2"
                        aria-labelledby="pre-close-checks"
                    >
                        <div className="flex items-center justify-between gap-3">
                            <p
                                id="pre-close-checks"
                                className="text-[10px] font-bold tracking-[0.14em] text-neutral-500 uppercase"
                            >
                                Pre-close checks
                            </p>
                            <p className="text-[10px] text-neutral-500">
                                Checked{' '}
                                {manilaTime.format(
                                    new Date(preview.generated_at),
                                )}
                            </p>
                        </div>

                        {blockers.outstanding.count === 0 ? (
                            <CheckCard
                                tone="ok"
                                title="No outstanding balances"
                            />
                        ) : (
                            <CheckCard
                                tone="blocked"
                                title={`${blockers.outstanding.count} ${blockers.outstanding.count === 1 ? 'order requires' : 'orders require'} payment before the Store can close`}
                                detail={`${formatDecimalPeso(blockers.outstanding.total)} outstanding. Settle each balance, or void it when legitimate, through Transaction History.`}
                            >
                                <BlockerRows
                                    rows={blockers.outstanding.orders.map(
                                        (order) => ({
                                            key: order.id,
                                            label: `${orderLabel(order)} · ${order.payment_status === 'partial' ? 'Balance due' : order.payment_term === 'pay_later' ? 'Pay Later' : 'Unpaid'}`,
                                            value: formatDecimalPeso(
                                                order.outstanding,
                                            ),
                                        }),
                                    )}
                                />
                                {canOpenHistory && (
                                    <Button
                                        type="button"
                                        variant="outline"
                                        onClick={() => {
                                            onNavigate();
                                            router.visit(
                                                transactionHistory().url,
                                            );
                                        }}
                                        className="mt-2 min-h-11 rounded-xl border-red-200 bg-white text-red-800 hover:bg-red-100"
                                    >
                                        <ReceiptText className="size-4" />{' '}
                                        Review transactions
                                    </Button>
                                )}
                            </CheckCard>
                        )}

                        {blockers.kitchen.count === 0 ? (
                            <CheckCard
                                tone="ok"
                                title="Kitchen complete"
                                detail="Every committed Kitchen order is Done."
                            />
                        ) : (
                            <CheckCard
                                tone="blocked"
                                title={`${blockers.kitchen.count} Kitchen ${blockers.kitchen.count === 1 ? 'order is' : 'orders are'} still active`}
                                detail="All committed Kitchen orders must be Done before closing."
                            >
                                <BlockerRows
                                    rows={blockers.kitchen.orders.map(
                                        (order) => ({
                                            key: order.id,
                                            label: orderLabel(order),
                                            value: kitchenLabels[
                                                order.kitchen_status
                                            ],
                                        }),
                                    )}
                                />
                                {canOpenKitchen && (
                                    <Button
                                        type="button"
                                        variant="outline"
                                        onClick={() => {
                                            onNavigate();
                                            router.visit(kitchen().url);
                                        }}
                                        className="mt-2 min-h-11 rounded-xl border-red-200 bg-white text-red-800 hover:bg-red-100"
                                    >
                                        <ChefHat className="size-4" /> Open
                                        Kitchen
                                    </Button>
                                )}
                            </CheckCard>
                        )}

                        {blockers.loaded_qr.count > 0 && (
                            <CheckCard
                                tone="blocked"
                                title={`${blockers.loaded_qr.count} loaded QR ${blockers.loaded_qr.count === 1 ? 'order is' : 'orders are'} still in progress`}
                                detail="Complete payment or cancel LOAD at the POS that loaded it."
                            >
                                <BlockerRows
                                    rows={blockers.loaded_qr.orders.map(
                                        (order) => ({
                                            key: order.id,
                                            label: [
                                                order.qr_number,
                                                order.customer_label,
                                            ]
                                                .filter(Boolean)
                                                .join(' · '),
                                            value: order.loaded_by
                                                ? `Loaded by ${order.loaded_by}`
                                                : 'Loaded',
                                        }),
                                    )}
                                />
                            </CheckCard>
                        )}

                        {blockers.corrections.count > 0 && (
                            <CheckCard
                                tone="blocked"
                                title="Payment correction requires Cash/Cashless allocation"
                                detail="These corrections were recorded before refund sources were captured and were paid through both methods. Enter the Cash portion that was actually returned; the rest is Cashless."
                            >
                                <div className="space-y-2">
                                    {blockers.corrections.items.map((item) => (
                                        <CorrectionAllocation
                                            key={item.id}
                                            item={item}
                                            online={online}
                                            onSaved={loadPreview}
                                        />
                                    ))}
                                </div>
                            </CheckCard>
                        )}

                        <CheckCard
                            tone="info"
                            title={
                                preview.qr.unclaimed_count === 0
                                    ? 'No unclaimed QR orders'
                                    : `${preview.qr.unclaimed_count} unclaimed QR ${preview.qr.unclaimed_count === 1 ? 'order' : 'orders'} will be archived when the Store closes`
                            }
                            detail="Unclaimed QR orders do not block closing and have no payment, stock or Kitchen effect."
                        />
                    </section>
                )}

                {reconciliation && assessment && (
                    <>
                        <section aria-labelledby="session-money">
                            <p
                                id="session-money"
                                className="mb-2 text-[10px] font-bold tracking-[0.14em] text-neutral-500 uppercase"
                            >
                                Session money
                            </p>
                            <div className="overflow-hidden rounded-xl border border-neutral-200">
                                <div className="grid grid-cols-[minmax(0,1fr)_auto_auto] gap-x-3 border-b border-neutral-200 bg-neutral-50 px-3 py-2 text-[9px] font-bold tracking-wider text-neutral-500 uppercase sm:grid-cols-[minmax(0,1fr)_8.5rem_8.5rem]">
                                    <span />
                                    <span className="text-right">Cash</span>
                                    <span className="text-right">Cashless</span>
                                </div>
                                <div className="divide-y divide-neutral-100">
                                    <MoneyLine
                                        label="Opening"
                                        cash={reconciliation.opening.cash}
                                        cashless={
                                            reconciliation.opening.cashless
                                        }
                                    />
                                    <MoneyLine
                                        label="Sales"
                                        hint={`${reconciliation.sales.count} payment ${reconciliation.sales.count === 1 ? 'row' : 'rows'}`}
                                        cash={reconciliation.sales.cash}
                                        cashless={reconciliation.sales.cashless}
                                        sign="+"
                                    />
                                    {reconciliation.split.count > 0 && (
                                        <div className="bg-sky-50/60 px-3 py-2 text-[11px] leading-4 text-sky-900">
                                            <span className="font-bold">
                                                Split included:
                                            </span>{' '}
                                            Cash leg{' '}
                                            {formatDecimalPeso(
                                                reconciliation.split.cash,
                                            )}{' '}
                                            · Cashless leg{' '}
                                            {formatDecimalPeso(
                                                reconciliation.split.cashless,
                                            )}{' '}
                                            across {reconciliation.split.count}{' '}
                                            {reconciliation.split.count === 1
                                                ? 'payment'
                                                : 'payments'}
                                            . Already counted in Sales above.
                                        </div>
                                    )}
                                    <MoneyLine
                                        label="Purchases & expenses"
                                        hint={`${reconciliation.expenses.count} recorded`}
                                        cash={reconciliation.expenses.cash}
                                        cashless={
                                            reconciliation.expenses.cashless
                                        }
                                        sign="−"
                                    />
                                    {reconciliation.corrections.count > 0 && (
                                        <MoneyLine
                                            label="Payment corrections"
                                            hint="Lower-total refunds"
                                            cash={
                                                reconciliation.corrections.cash
                                            }
                                            cashless={
                                                reconciliation.corrections
                                                    .cashless
                                            }
                                            sign="−"
                                        />
                                    )}
                                    {reconciliation.voids.count > 0 && (
                                        <MoneyLine
                                            label="Void effects"
                                            hint={`Reverses all payments of ${reconciliation.voids.count} voided ${reconciliation.voids.count === 1 ? 'order' : 'orders'}, including earlier corrections`}
                                            cash={reconciliation.voids.cash}
                                            cashless={
                                                reconciliation.voids.cashless
                                            }
                                            sign="−"
                                        />
                                    )}
                                    <MoneyLine
                                        label="Expected closing"
                                        cash={reconciliation.expected.cash}
                                        cashless={
                                            reconciliation.expected.cashless
                                        }
                                        strong
                                    />
                                </div>
                            </div>
                            {(signedCents(reconciliation.expected.cash) < 0n ||
                                signedCents(reconciliation.expected.cashless) <
                                    0n) && (
                                <p className="mt-2 flex gap-2 rounded-xl border border-amber-200 bg-amber-50 p-3 text-xs leading-5 text-amber-900">
                                    <AlertTriangle className="mt-0.5 size-4 shrink-0" />
                                    An expected balance is negative: recorded
                                    expenses or refunds exceed that channel.
                                    Review them before counting; any positive
                                    count will need an overage explanation.
                                </p>
                            )}
                        </section>

                        <section
                            aria-labelledby="actual-closing"
                            className="space-y-3"
                        >
                            <div>
                                <p
                                    id="actual-closing"
                                    className="text-[10px] font-bold tracking-[0.14em] text-neutral-500 uppercase"
                                >
                                    Actual closing count
                                </p>
                                <p className="mt-0.5 text-xs text-neutral-500">
                                    Count the drawer and confirm the Cashless
                                    balance. Values are not prefilled.
                                </p>
                            </div>
                            <div className="grid gap-3 sm:grid-cols-2">
                                {(
                                    [
                                        [
                                            'closing-cash',
                                            'Closing Cash',
                                            cashInput,
                                            setCashInput,
                                        ],
                                        [
                                            'closing-cashless',
                                            'Closing Cashless',
                                            cashlessInput,
                                            setCashlessInput,
                                        ],
                                    ] as const
                                ).map(([id, label, value, setValue]) => (
                                    <div key={id} className="space-y-1.5">
                                        <Label htmlFor={id}>{label}</Label>
                                        <div className="relative">
                                            <span className="absolute top-1/2 left-3 -translate-y-1/2 text-sm font-bold text-neutral-500">
                                                ₱
                                            </span>
                                            <Input
                                                id={id}
                                                value={value}
                                                onChange={(event) =>
                                                    setValue(event.target.value)
                                                }
                                                inputMode="decimal"
                                                autoComplete="off"
                                                className="min-h-12 rounded-xl pl-8 text-base font-bold tabular-nums"
                                                placeholder="0.00"
                                            />
                                        </div>
                                    </div>
                                ))}
                            </div>
                            <div className="grid gap-2 sm:grid-cols-2">
                                <VarianceCard
                                    label="Cash"
                                    icon={Banknote}
                                    expected={reconciliation.expected.cash}
                                    assessment={assessment.cash}
                                />
                                <VarianceCard
                                    label="Cashless"
                                    icon={Smartphone}
                                    expected={reconciliation.expected.cashless}
                                    assessment={assessment.cashless}
                                />
                            </div>
                            {assessment.hasShortage && (
                                <div
                                    role="alert"
                                    className="space-y-1 rounded-xl border border-red-200 bg-red-50 p-3 text-xs leading-5 text-red-900"
                                >
                                    {assessment.cash.state === 'shortage' && (
                                        <p>
                                            {shortageMessage(
                                                'Cash',
                                                assessment.cash.variance ?? 0n,
                                            )}
                                        </p>
                                    )}
                                    {assessment.cashless.state ===
                                        'shortage' && (
                                        <p>
                                            {shortageMessage(
                                                'Cashless',
                                                assessment.cashless.variance ??
                                                    0n,
                                            )}
                                        </p>
                                    )}
                                    <p className="font-semibold">
                                        A shortage blocks closing. Check the
                                        count, missing expenses, and unresolved
                                        transactions, then recount.
                                    </p>
                                </div>
                            )}
                            {(assessment.cash.state === 'invalid' ||
                                assessment.cashless.state === 'invalid') && (
                                <p
                                    role="alert"
                                    className="text-xs text-red-800"
                                >
                                    Enter amounts as non-negative numbers with
                                    up to 2 decimal places.
                                </p>
                            )}
                            {assessment.needsNote && (
                                <div className="space-y-1.5">
                                    <Label htmlFor="closing-note">
                                        Overage explanation (required)
                                    </Label>
                                    <textarea
                                        id="closing-note"
                                        value={note}
                                        onChange={(event) =>
                                            setNote(event.target.value)
                                        }
                                        maxLength={1000}
                                        rows={3}
                                        aria-invalid={!assessment.noteValid}
                                        className="w-full resize-none rounded-xl border border-amber-300 bg-transparent px-3 py-2 text-sm outline-none focus-visible:ring-2 focus-visible:ring-neutral-950"
                                        placeholder="Why is there more money than expected?"
                                    />
                                    <p className="text-[11px] text-neutral-500">
                                        {assessment.noteValid
                                            ? `${note.trim().length}/1000`
                                            : `Add an explanation for the overage before closing (at least ${MINIMUM_OVERAGE_NOTE_LENGTH} characters).`}
                                    </p>
                                </div>
                            )}
                        </section>
                        <SessionPurchases session={session} />
                    </>
                )}

                {submitError && (
                    <p
                        role="alert"
                        className="rounded-xl border border-red-200 bg-red-50 p-3 text-sm leading-5 text-red-800"
                    >
                        {submitError}
                    </p>
                )}
            </div>

            <footer className="border-t border-neutral-200 pt-3 pb-[max(0px,env(safe-area-inset-bottom))]">
                <Button
                    type="button"
                    onClick={continueToConfirm}
                    disabled={!online || !assessment?.canContinue || refreshing}
                    className="min-h-12 w-full rounded-xl bg-red-700 text-white hover:bg-red-800"
                >
                    <LockKeyhole className="size-4" />
                    {!preview?.ready
                        ? 'Resolve blockers to continue'
                        : 'Continue to final confirmation'}
                </Button>
            </footer>
        </div>
    );
}
