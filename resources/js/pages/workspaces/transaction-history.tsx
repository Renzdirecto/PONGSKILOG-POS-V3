import { Head, Link, http, router, usePage } from '@inertiajs/react';
import {
    AlertTriangle,
    ArrowLeft,
    CalendarDays,
    Check,
    ChevronDown,
    ChevronLeft,
    ChevronRight,
    CreditCard,
    Grid2X2,
    ImageOff,
    List,
    Minus,
    Pencil,
    Plus,
    Printer,
    Search,
    ShieldBan,
    ShoppingBag,
    Trash2,
    UtensilsCrossed,
    X,
} from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';
import type { ReactNode } from 'react';
import { toast } from 'sonner';
import { CategoryIcon } from '@/components/category-icon';
import { PosPaid } from '@/components/pos-paid';
import { PosPaymentPreview } from '@/components/pos-payment-preview';
import {
    PosProductDialog,
    posDialogClass,
} from '@/components/pos-product-dialog';
import { PosProductMedia } from '@/components/pos-product-media';
import { TransactionInvoiceDialog } from '@/components/transaction-invoice-dialog';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import { useBranchRealtimeRefresh } from '@/hooks/use-branch-realtime-refresh';
import { createClientUuid } from '@/lib/client-uuid';
import { canTransitionKitchenStatus } from '@/lib/kitchen';
import { cents, lineCents, pesos } from '@/lib/pos-money';
import {
    correctionRefundBounds,
    normalizeMoneyInput,
    signedCents,
} from '@/lib/store-close';
import { stockAvailabilityLabel } from '@/lib/pos-order';
import { update as updateKitchenStatus } from '@/routes/orders/kitchen-status';
import { store as settle } from '@/routes/pos/orders/settlements';
import { show, update, voidMethod } from '@/routes/pos/transactions';
import { transactionHistory } from '@/routes/workspaces';
import type { Auth, BranchContext } from '@/types';
import type { CashierCatalog } from '@/types/catalog';
import type {
    BranchTable,
    CartLine,
    OrderSummary,
    PaymentInput,
    ReceiptSummary,
} from '@/types/pos';

type KitchenStatus = 'kitchen' | 'preparing' | 'ready' | 'done';
type SemanticRole = 'size' | 'instruction' | null;
type SummaryModifier = {
    group_name: string;
    semantic_role: SemanticRole;
    name: string;
};
type SummaryItem = {
    id: string;
    name: string;
    size_prefix: string | null;
    display_name: string;
    quantity: number;
    line_total: string;
    notes: string | null;
    modifiers: SummaryModifier[];
};
type DetailModifier = SummaryModifier & {
    group_id: string;
    option_id: string;
    price_delta: string;
};
type DetailItem = Omit<SummaryItem, 'modifiers'> & {
    product_id: string;
    unit_price: string;
    modifiers: DetailModifier[];
};
type Summary = {
    id: string;
    order_number: string;
    reference_number: string;
    customer_label: string | null;
    order_type: 'dine_in' | 'take_out';
    table_name: string | null;
    kitchen_status: KitchenStatus;
    commercial_status: 'active' | 'completed' | 'voided';
    payment_status: 'paid' | 'unpaid' | 'partial';
    payment_method: 'cash' | 'cashless' | 'split' | null;
    initial_cash: string | null;
    initial_cashless: string | null;
    cashier: string | null;
    total: string;
    original_total: string | null;
    amount_paid: string;
    adjustment_total: string;
    outstanding: string;
    committed_at: string;
    edited_at: string | null;
    voided_at: string | null;
    void: {
        reason_code: string;
        reason_label: string;
        reason_text: string | null;
        initiated_by: string | null;
        authorized_by: string | null;
        authorization_method: string;
        created_at: string | null;
    } | null;
    version: number;
    item_count: number;
    items_preview: SummaryItem[];
    can_edit: boolean;
    can_settle: boolean;
    can_void: boolean;
};
type Detail = Summary & {
    items: DetailItem[];
    refund_sources: { cash_available: string; cashless_available: string };
    payment_groups: {
        id: string;
        method: 'cash' | 'cashless' | 'split';
        context: string;
        amount: string;
        paid_at: string;
        cashier: string;
        payments: {
            id: string;
            method: 'cash' | 'cashless';
            amount: string;
            amount_received: string | null;
            change_amount: string | null;
            invoice: { name: string; url: string } | null;
        }[];
    }[];
    adjustments: {
        id: string;
        type: string;
        amount: string;
        cash_amount: string | null;
        cashless_amount: string | null;
        reason: string | null;
        created_at: string;
        created_by: string;
    }[];
    inventory_restorations: {
        product_name: string | null;
        quantity_restored: number;
    }[];
    receipt: ReceiptSummary;
};
type PageLink = { url: string | null; label: string; active: boolean };
type Props = {
    transactions: {
        data: Summary[];
        from: number | null;
        to: number | null;
        total: number;
        links: PageLink[];
    };
    history_total: number;
    metrics: {
        in_kitchen: number;
        preparing: number;
        done: number;
        paid: number;
        pending: number;
    };
    filters: Record<string, string>;
    catalog: CashierCatalog;
    tables: BranchTable[];
};

const DATE_OPTIONS: [string, string][] = [
    ['', 'All dates'],
    ['today', 'Today'],
    ['yesterday', 'Yesterday'],
    ['last_7_days', 'Last 7 days'],
    ['month', 'This month'],
    ['custom', 'Custom'],
];
const KITCHEN_OPTIONS: [string, string][] = [
    ['', 'Status'],
    ['kitchen', 'Kitchen'],
    ['preparing', 'Preparing'],
    ['ready', 'Ready'],
    ['done', 'Done'],
];
const KITCHEN_LABELS: Record<KitchenStatus, string> = {
    kitchen: 'Kitchen',
    preparing: 'Preparing',
    ready: 'Ready',
    done: 'Done',
};
const KITCHEN_STYLES: Record<KitchenStatus, string> = {
    kitchen: 'border-amber-300 bg-amber-50 text-amber-800',
    preparing: 'border-blue-200 bg-blue-50 text-blue-700',
    ready: 'border-green-200 bg-green-50 text-green-700',
    done: 'border-neutral-200 bg-neutral-100 text-neutral-500',
};

const HISTORY_REALTIME_EVENTS = [
    '.order.committed',
    '.order.updated',
    '.order.voided',
    '.kitchen.ticket_created',
    '.kitchen.status_changed',
] as const;

export default function TransactionHistory({
    transactions,
    history_total: historyTotal,
    metrics,
    filters,
    catalog,
    tables,
}: Props) {
    const { auth, branchContext } = usePage<{
        auth: Auth;
        branchContext: BranchContext;
    }>().props;
    const branch = branchContext.current;
    const [view, setView] = useState<'tiles' | 'list'>(() =>
        localStorage.getItem('transaction-history-view') === 'list'
            ? 'list'
            : 'tiles',
    );
    const [selected, setSelected] = useState<Detail | null>(null);
    const [loading, setLoading] = useState(false);
    const [editing, setEditing] = useState(false);
    const [settling, setSettling] = useState(false);
    const [voiding, setVoiding] = useState(false);
    const [receiptOpen, setReceiptOpen] = useState(false);
    const [resolution, setResolution] = useState<Detail | null>(null);
    const [invoicePayment, setInvoicePayment] = useState<
        Detail['payment_groups'][number]['payments'][number] | null
    >(null);
    const [searchText, setSearchText] = useState(filters.search ?? '');
    const canManageKitchen = auth.roles.includes('cashier_kitchen');
    const filtersActive = Object.values(filters).some((value) => value !== '');

    function apply(next: Record<string, string | undefined>) {
        const merged = { ...filters, ...next, page: undefined };
        const query = Object.fromEntries(
            Object.entries(merged).filter(
                ([, value]) => value !== undefined && value !== '',
            ),
        );
        router.get(transactionHistory(), query, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            only: ['transactions', 'history_total', 'metrics', 'filters'],
        });
    }

    useEffect(() => {
        if (searchText === (filters.search ?? '')) return;
        const timeout = window.setTimeout(
            () => apply({ search: searchText || undefined }),
            300,
        );
        return () => window.clearTimeout(timeout);
    }, [searchText, filters.search]);

    const refresh = () =>
        router.reload({ only: ['transactions', 'history_total', 'metrics'] });
    useBranchRealtimeRefresh({
        branchId: branch?.id ?? '',
        channel: 'pos',
        events: HISTORY_REALTIME_EVENTS,
        only: ['transactions', 'history_total', 'metrics'],
        debounceMs: 120,
        onEvent: (event) => {
            const orderId = event.order_id ?? event.entity_id;

            if (selected && orderId === selected.id) {
                void loadDetail(selected.id);
            }
        },
    });

    async function loadDetail(id: string): Promise<Detail | null> {
        setLoading(true);
        try {
            const response = await http.getClient().request({
                ...show(id),
                headers: { Accept: 'application/json' },
            });
            const detail = (
                JSON.parse(response.data) as { transaction: Detail }
            ).transaction;
            setSelected(detail);
            return detail;
        } catch {
            toast.error('Transaction details could not be loaded.');
            return null;
        } finally {
            setLoading(false);
        }
    }

    async function reloadDetail() {
        if (selected) await loadDetail(selected.id);
        refresh();
    }

    function clearFilters() {
        setSearchText('');
        router.get(
            transactionHistory(),
            {},
            {
                preserveState: true,
                preserveScroll: true,
                replace: true,
                only: ['transactions', 'history_total', 'metrics', 'filters'],
            },
        );
    }

    const metricCards = [
        {
            key: 'in-kitchen',
            label: 'In kitchen',
            value: metrics.in_kitchen,
            dot: 'bg-neutral-500',
            active: filters.kitchen_status === 'kitchen',
            pick: () =>
                apply({ kitchen_status: 'kitchen', payment_status: undefined }),
        },
        {
            key: 'preparing',
            label: 'Preparing',
            value: metrics.preparing,
            dot: 'bg-amber-600',
            active: filters.kitchen_status === 'preparing',
            pick: () =>
                apply({
                    kitchen_status: 'preparing',
                    payment_status: undefined,
                }),
        },
        {
            key: 'done',
            label: 'Done',
            value: metrics.done,
            dot: 'bg-green-700',
            active: filters.kitchen_status === 'done',
            pick: () =>
                apply({ kitchen_status: 'done', payment_status: undefined }),
        },
        {
            key: 'paid',
            label: 'Paid',
            value: metrics.paid,
            dot: 'bg-neutral-950',
            active: filters.payment_status === 'paid',
            pick: () =>
                apply({ payment_status: 'paid', kitchen_status: undefined }),
        },
        {
            key: 'pending',
            label: 'Pending',
            value: metrics.pending,
            dot: 'bg-red-700',
            active: filters.payment_status === 'pending',
            pick: () =>
                apply({ payment_status: 'pending', kitchen_status: undefined }),
        },
    ];

    return (
        <div className="pos-surface min-h-full bg-[#fafafa] p-3 text-[#111] md:p-4">
            <Head title="Transaction history" />
            <header className="mb-2.5 flex flex-wrap items-end gap-2.5">
                <div className="min-w-0 flex-1">
                    <h1 className="text-[17px] font-bold tracking-[-0.02em]">
                        Transaction history
                    </h1>
                    <p className="text-xs text-[#666]">
                        {transactions.total} of {historyTotal} transactions
                        {filtersActive ? ' (filtered)' : ''}
                    </p>
                </div>
                <div className="flex shrink-0 gap-0.5 rounded-[11px] bg-[#f2f2f2] p-0.5">
                    {(['tiles', 'list'] as const).map((mode) => (
                        <button
                            key={mode}
                            type="button"
                            onClick={() => {
                                setView(mode);
                                localStorage.setItem(
                                    'transaction-history-view',
                                    mode,
                                );
                            }}
                            className={`inline-flex h-10 items-center gap-1.5 rounded-[9px] px-3.5 text-[12.5px] font-semibold ${view === mode ? 'bg-[#111] text-white' : 'text-[#666]'}`}
                        >
                            {mode === 'tiles' ? (
                                <Grid2X2 className="size-4" />
                            ) : (
                                <List className="size-4" />
                            )}
                            {mode === 'tiles' ? 'Tiled' : 'List'}
                        </button>
                    ))}
                </div>
            </header>

            <section className="mb-2.5 grid grid-cols-2 gap-2 min-[680px]:grid-cols-5">
                {metricCards.map((metric) => (
                    <button
                        key={metric.key}
                        type="button"
                        aria-pressed={metric.active}
                        onClick={metric.pick}
                        className={`flex min-w-0 flex-col items-start gap-1 rounded-[13px] border bg-white px-3 py-2.5 text-left shadow-[0_1px_2px_rgba(17,17,17,.05),0_8px_20px_-12px_rgba(17,17,17,.18)] ${metric.active ? 'border-[#111] ring-1 ring-[#111]' : 'border-[#e5e5e5]'}`}
                    >
                        <span className="flex w-full items-center gap-1.5">
                            <span
                                className={`size-2 shrink-0 rounded-full ${metric.dot}`}
                            />
                            <span className="truncate text-[10.5px] font-semibold tracking-[.06em] text-[#767676] uppercase">
                                {metric.label}
                            </span>
                        </span>
                        <strong className="text-[19px] leading-6 font-bold tabular-nums">
                            {metric.value}
                        </strong>
                    </button>
                ))}
            </section>

            <section className="mb-2.5 flex items-center gap-1.5 overflow-x-auto overflow-y-visible pb-0.5">
                <label className="relative flex h-10 min-w-[190px] flex-1 items-center rounded-[10px] border border-[#e5e5e5] bg-white">
                    <Search className="ml-3 size-4 shrink-0 text-[#767676]" />
                    <span className="sr-only">Search transactions</span>
                    <input
                        value={searchText}
                        onChange={(event) => setSearchText(event.target.value)}
                        placeholder="Search order or customer"
                        className="h-full min-w-0 flex-1 border-0 bg-transparent px-2 text-base outline-none"
                    />
                    {searchText && (
                        <button
                            type="button"
                            aria-label="Clear search"
                            onClick={() => {
                                setSearchText('');
                                apply({ search: undefined });
                            }}
                            className="mr-2 inline-flex size-6 items-center justify-center rounded-full bg-[#f2f2f2] text-[#767676]"
                        >
                            <X className="size-3.5" />
                        </button>
                    )}
                </label>
                <DateFilter filters={filters} apply={apply} />
                <CompactSelect
                    value={filters.kitchen_status ?? ''}
                    label="Status"
                    options={KITCHEN_OPTIONS}
                    onChange={(value) =>
                        apply({ kitchen_status: value || undefined })
                    }
                />
                <CompactSelect
                    value={filters.payment_status ?? ''}
                    label="Payment"
                    options={[
                        ['', 'Payment'],
                        ['paid', 'Paid'],
                        ['pending', 'Pending'],
                        ['balance', 'Balance due'],
                    ]}
                    onChange={(value) =>
                        apply({ payment_status: value || undefined })
                    }
                />
                <CompactSelect
                    value={filters.order_type ?? ''}
                    label="Type"
                    options={[
                        ['', 'Type'],
                        ['dine_in', 'Dine in'],
                        ['take_out', 'Take out'],
                    ]}
                    onChange={(value) =>
                        apply({ order_type: value || undefined })
                    }
                />
                <CompactSelect
                    value={filters.payment_method ?? ''}
                    label="Method"
                    options={[
                        ['', 'Method'],
                        ['cash', 'Cash'],
                        ['cashless', 'Cashless'],
                        ['split', 'Split'],
                    ]}
                    onChange={(value) =>
                        apply({ payment_method: value || undefined })
                    }
                />
                {filtersActive && (
                    <button
                        type="button"
                        aria-label="Reset filters"
                        title="Reset filters"
                        onClick={clearFilters}
                        className="inline-flex size-10 shrink-0 items-center justify-center rounded-[10px] border border-red-200 bg-red-50 text-red-700"
                    >
                        <X className="size-4" />
                    </button>
                )}
            </section>

            {transactions.data.length === 0 ? (
                <div className="flex flex-col items-center gap-2.5 rounded-2xl border border-[#e5e5e5] bg-white px-5 py-14 text-center shadow-sm">
                    <CalendarDays className="size-9 text-neutral-400" />
                    <h2 className="text-[15px] font-semibold">
                        No transactions match
                    </h2>
                    <p className="max-w-sm text-[12.5px] leading-5 text-neutral-500">
                        Adjust the search or clear the filters to see the full
                        history for this terminal.
                    </p>
                    <button
                        type="button"
                        onClick={clearFilters}
                        className="mt-1 h-11 rounded-[11px] border border-neutral-400 px-4 text-[13px] font-semibold"
                    >
                        Clear filters
                    </button>
                </div>
            ) : (
                <div
                    className={
                        view === 'tiles'
                            ? 'grid items-start gap-3 min-[720px]:grid-cols-2 min-[1080px]:grid-cols-3'
                            : 'flex flex-col gap-2'
                    }
                >
                    {transactions.data.map((item) => (
                        <TransactionCard
                            key={item.id}
                            item={item}
                            compact={view === 'list'}
                            onDetails={() => void loadDetail(item.id)}
                            onEdit={() =>
                                void loadDetail(item.id).then((detail) =>
                                    detail ? setEditing(true) : undefined,
                                )
                            }
                            onPayment={() =>
                                void loadDetail(item.id).then((detail) =>
                                    detail ? setSettling(true) : undefined,
                                )
                            }
                            onVoid={() =>
                                void loadDetail(item.id).then((detail) =>
                                    detail ? setVoiding(true) : undefined,
                                )
                            }
                            onPrint={() =>
                                void loadDetail(item.id).then((detail) =>
                                    detail ? setReceiptOpen(true) : undefined,
                                )
                            }
                        />
                    ))}
                </div>
            )}

            <div className="mt-3 flex flex-wrap items-center justify-between gap-2.5">
                <span className="text-xs text-neutral-500">
                    Showing {transactions.from ?? 0}–{transactions.to ?? 0} of{' '}
                    {transactions.total}
                </span>
                <nav className="flex flex-wrap gap-1.5">
                    {transactions.links.map((link) =>
                        link.url ? (
                            <Link
                                key={link.label}
                                href={link.url}
                                preserveScroll
                                preserveState
                                only={[
                                    'transactions',
                                    'history_total',
                                    'metrics',
                                ]}
                                className={`inline-flex min-h-11 min-w-11 items-center justify-center rounded-[11px] border px-2.5 text-[13px] font-semibold ${link.active ? 'border-[#111] bg-[#111] text-white' : 'border-[#e5e5e5] bg-white'}`}
                                dangerouslySetInnerHTML={{
                                    __html: link.label,
                                }}
                            />
                        ) : (
                            <span
                                key={link.label}
                                className="inline-flex min-h-11 min-w-11 items-center justify-center rounded-[11px] border border-[#e5e5e5] bg-white px-2.5 text-[13px] text-neutral-300"
                                dangerouslySetInnerHTML={{
                                    __html: link.label,
                                }}
                            />
                        ),
                    )}
                </nav>
            </div>

            <TransactionDetailDialog
                detail={selected}
                loading={loading}
                open={
                    selected !== null &&
                    !editing &&
                    !settling &&
                    !voiding &&
                    !receiptOpen &&
                    resolution === null
                }
                onClose={() => setSelected(null)}
                onEdit={() => setEditing(true)}
                onSettle={() => setSettling(true)}
                onVoid={() => setVoiding(true)}
                onPrint={() => setReceiptOpen(true)}
                onInvoice={setInvoicePayment}
            />
            {selected && editing && (
                <EditDialog
                    detail={selected}
                    catalog={catalog}
                    tables={tables}
                    canManageKitchen={canManageKitchen}
                    onClose={() => {
                        setEditing(false);
                        setSelected(null);
                    }}
                    onSaved={(detail) => {
                        setSelected(detail);
                        setEditing(false);
                        refresh();
                        if (
                            Number(detail.outstanding) > 0 &&
                            Number(selected.amount_paid) > 0
                        ) {
                            setResolution(detail);
                        }
                    }}
                />
            )}
            {selected && settling && (
                <SettlementDialog
                    detail={selected}
                    tables={tables}
                    onClose={() => {
                        setSettling(false);
                        setSelected(null);
                    }}
                    onPaid={(detail) => {
                        setSelected(detail);
                        setSettling(false);
                        setResolution(null);
                        refresh();
                    }}
                />
            )}
            {selected && voiding && (
                <VoidDialog
                    detail={selected}
                    onClose={() => {
                        setVoiding(false);
                        setSelected(null);
                    }}
                    onVoided={() => {
                        setSelected(null);
                        setVoiding(false);
                        refresh();
                    }}
                />
            )}
            {resolution && (
                <BalanceResolutionDialog
                    detail={resolution}
                    onLater={() => {
                        setResolution(null);
                        setSelected(null);
                    }}
                    onNow={() => {
                        setSelected(resolution);
                        setResolution(null);
                        setSettling(true);
                    }}
                />
            )}
            {selected && receiptOpen && (
                <ReceiptDialog
                    detail={selected}
                    onClose={() => {
                        setReceiptOpen(false);
                        setSelected(null);
                    }}
                />
            )}
            {invoicePayment && selected && (
                <TransactionInvoiceDialog
                    paymentId={invoicePayment.id}
                    invoice={invoicePayment.invoice}
                    open
                    mutable={selected.can_edit}
                    onClose={() => setInvoicePayment(null)}
                    onChanged={() => void reloadDetail()}
                />
            )}
        </div>
    );
}

function CompactSelect({
    value,
    label,
    options,
    onChange,
}: {
    value: string;
    label: string;
    options: [string, string][];
    onChange: (value: string) => void;
}) {
    const active = value !== '';
    return (
        <label className="relative inline-flex shrink-0 items-center">
            <span className="sr-only">{label}</span>
            <select
                value={value}
                onChange={(event) => onChange(event.target.value)}
                className={`h-10 max-w-[168px] appearance-none rounded-[10px] border py-0 pr-8 pl-3 text-[12.5px] font-semibold outline-none ${active ? 'border-[#111] bg-[#111] text-white' : 'border-[#e5e5e5] bg-white text-[#111]'}`}
            >
                {options.map(([key, text]) => (
                    <option
                        key={key}
                        value={key}
                        className="bg-white text-[#111]"
                    >
                        {text}
                    </option>
                ))}
            </select>
            <ChevronDown
                className={`pointer-events-none absolute right-2.5 size-3.5 ${active ? 'text-white/70' : 'text-neutral-400'}`}
            />
        </label>
    );
}

function DateFilter({
    filters,
    apply,
}: {
    filters: Record<string, string>;
    apply: (next: Record<string, string | undefined>) => void;
}) {
    const button = useRef<HTMLButtonElement>(null);
    const [open, setOpen] = useState(false);
    const [calendar, setCalendar] = useState(false);
    const [from, setFrom] = useState(filters.from ?? '');
    const [to, setTo] = useState(filters.to ?? '');
    const [month, setMonth] = useState(() =>
        startOfMonthKey(filters.from || todayKey()),
    );
    const [position, setPosition] = useState({ top: 0, left: 12 });
    const active = Boolean(filters.date);
    const label =
        filters.date === 'custom'
            ? shortRange(filters.from, filters.to)
            : (DATE_OPTIONS.find(
                  ([key]) => key === (filters.date ?? ''),
              )?.[1] ?? 'All dates');

    function toggle() {
        if (!open && button.current) {
            const rect = button.current.getBoundingClientRect();
            const width = calendar ? 304 : 236;
            setPosition({
                top: Math.max(
                    12,
                    Math.min(rect.bottom + 6, window.innerHeight - 470),
                ),
                left: Math.max(
                    12,
                    Math.min(rect.left, window.innerWidth - width - 12),
                ),
            });
        }
        setOpen((current) => !current);
    }

    const days = calendarDays(month);
    return (
        <div className="shrink-0">
            <button
                ref={button}
                type="button"
                onClick={toggle}
                className={`inline-flex h-10 items-center gap-1.5 rounded-[10px] border px-3 text-[12.5px] font-semibold whitespace-nowrap ${active ? 'border-[#111] bg-[#111] text-white' : 'border-[#e5e5e5] bg-white text-[#111]'}`}
            >
                <CalendarDays className="size-4" />
                {label}
                <ChevronDown className="size-3.5 opacity-70" />
            </button>
            {open && (
                <div
                    style={position}
                    className={`fixed z-[90] max-h-[calc(100dvh-24px)] overflow-y-auto rounded-[14px] border border-[#e5e5e5] bg-white shadow-[0_18px_44px_rgba(0,0,0,.16)] ${calendar ? 'w-[304px] p-2.5' : 'w-[236px] p-1.5'}`}
                >
                    {!calendar ? (
                        DATE_OPTIONS.map(([key, text]) => {
                            const selected = (filters.date ?? '') === key;
                            return (
                                <button
                                    key={key || 'all'}
                                    type="button"
                                    onClick={() => {
                                        if (key === 'custom') {
                                            setFrom(filters.from ?? '');
                                            setTo(filters.to ?? '');
                                            setMonth(
                                                startOfMonthKey(
                                                    filters.from || todayKey(),
                                                ),
                                            );
                                            setCalendar(true);
                                            return;
                                        }
                                        apply({
                                            date: key || undefined,
                                            from: undefined,
                                            to: undefined,
                                        });
                                        setOpen(false);
                                    }}
                                    className={`flex h-[38px] w-full items-center justify-between rounded-[9px] px-2.5 text-left text-[13px] ${selected ? 'bg-[#f2f2f2] font-bold' : 'font-medium hover:bg-[#f7f7f7]'}`}
                                >
                                    {text}
                                    {selected && <Check className="size-3.5" />}
                                </button>
                            );
                        })
                    ) : (
                        <div className="space-y-2.5">
                            <p className="text-[9.5px] font-semibold tracking-[.09em] text-neutral-400 uppercase">
                                Date range picker
                            </p>
                            <div className="grid grid-cols-2 gap-1.5">
                                <CalendarField label="From" value={from} />
                                <CalendarField label="To" value={to} />
                            </div>
                            <div className="flex items-center justify-between">
                                <button
                                    type="button"
                                    aria-label="Previous month"
                                    onClick={() =>
                                        setMonth(shiftMonth(month, -1))
                                    }
                                    className="inline-flex size-[30px] items-center justify-center rounded-[9px] border border-neutral-200"
                                >
                                    <ChevronLeft className="size-4" />
                                </button>
                                <strong className="text-[13px]">
                                    {monthTitle(month)}
                                </strong>
                                <button
                                    type="button"
                                    aria-label="Next month"
                                    onClick={() =>
                                        setMonth(shiftMonth(month, 1))
                                    }
                                    className="inline-flex size-[30px] items-center justify-center rounded-[9px] border border-neutral-200"
                                >
                                    <ChevronRight className="size-4" />
                                </button>
                            </div>
                            <div className="grid grid-cols-7 gap-px">
                                {[
                                    'Mon',
                                    'Tue',
                                    'Wed',
                                    'Thu',
                                    'Fri',
                                    'Sat',
                                    'Sun',
                                ].map((weekday, index) => (
                                    <span
                                        key={weekday}
                                        className={`flex h-[22px] items-center justify-center text-[9.5px] font-bold tracking-wide uppercase ${index > 4 ? 'text-red-700' : 'text-neutral-400'}`}
                                    >
                                        {weekday}
                                    </span>
                                ))}
                                {days.map((day) => {
                                    const endpoint =
                                        day.key === from || day.key === to;
                                    const inRange =
                                        Boolean(from && to) &&
                                        day.key > from &&
                                        day.key < to;
                                    const today = day.key === todayKey();
                                    return (
                                        <button
                                            key={day.key}
                                            type="button"
                                            aria-label={day.key}
                                            onClick={() => {
                                                if (!from || to) {
                                                    setFrom(day.key);
                                                    setTo('');
                                                } else if (day.key < from) {
                                                    setTo(from);
                                                    setFrom(day.key);
                                                } else {
                                                    setTo(day.key);
                                                }
                                            }}
                                            className={`flex h-[34px] items-center justify-center text-[12.5px] tabular-nums ${endpoint ? 'rounded-[9px] bg-red-700 font-bold text-white' : inRange ? 'bg-red-50 font-semibold' : `rounded-[9px] ${day.current ? 'text-[#111]' : 'text-neutral-300'}`} ${today && !endpoint ? 'border border-red-700' : ''}`}
                                        >
                                            {day.day}
                                        </button>
                                    );
                                })}
                            </div>
                            <div className="grid grid-cols-2 gap-2">
                                <button
                                    type="button"
                                    onClick={() => {
                                        setCalendar(false);
                                        setOpen(false);
                                    }}
                                    className="h-10 rounded-[10px] border border-neutral-300 text-[13px] font-semibold"
                                >
                                    Cancel
                                </button>
                                <button
                                    type="button"
                                    disabled={!from}
                                    onClick={() => {
                                        apply({
                                            date: 'custom',
                                            from,
                                            to: to || from,
                                        });
                                        setCalendar(false);
                                        setOpen(false);
                                    }}
                                    className="h-10 rounded-[10px] bg-[#111] text-[13px] font-semibold text-white disabled:bg-neutral-500"
                                >
                                    Apply
                                </button>
                            </div>
                        </div>
                    )}
                </div>
            )}
        </div>
    );
}

function CalendarField({ label, value }: { label: string; value: string }) {
    return (
        <span className="flex min-w-0 flex-col rounded-[10px] border border-neutral-200 px-2.5 py-2">
            <span className="text-[9.5px] font-semibold tracking-wide text-neutral-400 uppercase">
                {label}
            </span>
            <strong className="truncate text-[12.5px]">
                {value ? formatShortDate(value) : 'Select date'}
            </strong>
        </span>
    );
}

function TransactionCard({
    item,
    compact,
    onDetails,
    onEdit,
    onPayment,
    onVoid,
    onPrint,
}: {
    item: Summary;
    compact: boolean;
    onDetails: () => void;
    onEdit: () => void;
    onPayment: () => void;
    onVoid: () => void;
    onPrint: () => void;
}) {
    const customer = truthfulCustomer(item);
    const date = formatManila(item.committed_at);
    const many = item.items_preview.length > 5;
    const isVoided = item.commercial_status === 'voided';
    return (
        <article
            className={`flex min-w-0 flex-col gap-2 rounded-2xl border border-[#e5e5e5] bg-white p-[11px] shadow-[0_1px_2px_rgba(17,17,17,.05),0_8px_20px_-12px_rgba(17,17,17,.18)] ${isVoided ? 'bg-red-50/40 opacity-75' : ''} ${compact ? 'sm:p-3' : ''}`}
        >
            <div className="flex min-w-0 items-center gap-2.5">
                <strong className="shrink-0 text-[17px] tracking-[-0.02em] tabular-nums">
                    #{item.order_number}
                </strong>
                {customer && (
                    <strong className="min-w-0 flex-1 truncate text-[14.5px] text-red-700">
                        {customer}
                    </strong>
                )}
                <span className="ml-auto flex shrink-0 flex-col items-end">
                    <strong className="text-[11.5px] font-semibold tabular-nums">
                        {date.date}
                    </strong>
                    <span className="text-[11px] text-neutral-500 tabular-nums">
                        {date.time}
                    </span>
                </span>
            </div>
            <div className="flex flex-wrap gap-1.5">
                <SemanticChip kind={item.order_type} />
                {isVoided ? (
                    <SemanticChip kind="voided" />
                ) : (
                    <SemanticChip kind={item.kitchen_status} />
                )}
                <SemanticChip
                    kind={
                        item.payment_status === 'unpaid'
                            ? 'unpaid'
                            : item.payment_status === 'partial'
                              ? 'balance'
                              : (item.payment_method ?? 'paid')
                    }
                />
                {item.edited_at && <SemanticChip kind="edited" />}
            </div>
            {item.payment_method === 'split' &&
                item.payment_status !== 'unpaid' && (
                    <p className="text-[11.5px] font-semibold text-[#444] tabular-nums">
                        Split · Cash {pesos(item.initial_cash ?? '0')} /
                        Cashless {pesos(item.initial_cashless ?? '0')}
                    </p>
                )}
            <div className="min-w-0 flex-1">
                <p className="mb-1 text-[9.5px] font-semibold tracking-[.09em] text-neutral-400 uppercase">
                    Order summary
                </p>
                <div
                    className={`flex flex-col pr-1 ${many ? 'max-h-[112px] overflow-y-auto' : ''}`}
                >
                    {item.items_preview.map((line) => (
                        <div
                            key={line.id}
                            className="flex items-start gap-2 py-0.5"
                        >
                            <strong className="shrink-0 text-xs text-red-700 tabular-nums">
                                {line.quantity}×
                            </strong>
                            <span className="min-w-0 flex-1 text-xs leading-[1.4] font-semibold">
                                {line.size_prefix && (
                                    <SizeBadge>{line.size_prefix}</SizeBadge>
                                )}
                                {line.name}
                            </span>
                            <strong className="shrink-0 text-[11.5px] font-semibold tabular-nums">
                                {pesos(line.line_total)}
                            </strong>
                        </div>
                    ))}
                </div>
                {many && (
                    <p className="mt-1 text-[10.5px] text-neutral-400 italic">
                        Scroll for {item.items_preview.length - 5} more item
                        {item.items_preview.length - 5 === 1 ? '' : 's'}
                    </p>
                )}
            </div>
            <div className="flex items-end justify-between gap-2 border-t border-neutral-100 pt-2">
                <span className="flex min-w-0 flex-col">
                    <span className="text-[9.5px] font-semibold tracking-[.09em] text-neutral-400 uppercase">
                        Total
                    </span>
                    <strong className="text-[17px] tracking-[-0.02em] tabular-nums">
                        {pesos(item.total)}
                    </strong>
                </span>
                <button
                    type="button"
                    onClick={onDetails}
                    className="h-9 shrink-0 rounded-full border border-neutral-200 px-3 text-xs font-semibold hover:border-neutral-950"
                >
                    Details
                </button>
            </div>
            {!isVoided && Number(item.outstanding) > 0 && (
                <button
                    type="button"
                    disabled={!item.can_settle}
                    onClick={onPayment}
                    title={
                        item.can_settle
                            ? undefined
                            : 'Earlier Store Sessions are read-only'
                    }
                    className="inline-flex h-[46px] w-full items-center justify-center gap-2 rounded-[11px] bg-green-700 text-[13.5px] font-semibold text-white hover:bg-green-800 disabled:bg-neutral-400"
                >
                    <CreditCard className="size-4" />
                    {item.payment_status === 'partial'
                        ? 'Settle balance'
                        : 'Take payment'}{' '}
                    {pesos(item.outstanding)}
                </button>
            )}
            <div className="grid grid-cols-3 gap-1.5">
                <button
                    type="button"
                    disabled={!item.can_void}
                    title={
                        item.can_void
                            ? 'Void transaction'
                            : 'This transaction cannot be voided'
                    }
                    onClick={onVoid}
                    className="inline-flex h-11 items-center justify-center gap-1.5 rounded-[11px] border border-red-200 text-[12.5px] font-semibold text-red-700 hover:border-red-700 hover:bg-red-50 disabled:opacity-40"
                >
                    <AlertTriangle className="size-4" /> Void
                </button>
                <button
                    type="button"
                    disabled={!item.can_edit || isVoided}
                    title={
                        item.can_edit
                            ? 'Edit transaction'
                            : 'Earlier Store Sessions are read-only'
                    }
                    onClick={onEdit}
                    className="inline-flex h-11 items-center justify-center gap-1.5 rounded-[11px] border border-neutral-200 text-[12.5px] font-semibold disabled:opacity-40"
                >
                    <Pencil className="size-4" /> Edit
                </button>
                <button
                    type="button"
                    onClick={onPrint}
                    className="inline-flex h-11 items-center justify-center gap-1.5 rounded-[11px] border border-neutral-200 text-[12.5px] font-semibold text-blue-700 hover:border-blue-700 hover:bg-blue-50"
                >
                    <Printer className="size-4" /> Print
                </button>
            </div>
        </article>
    );
}

function SemanticChip({ kind }: { kind: string }) {
    const labels: Record<string, string> = {
        dine_in: 'Dine in',
        take_out: 'Take out',
        kitchen: 'Kitchen',
        preparing: 'Preparing',
        ready: 'Ready',
        done: 'Done',
        voided: 'Voided',
        cash: 'Cash',
        cashless: 'Cashless',
        split: 'Split',
        paid: 'Paid',
        unpaid: 'Unpaid',
        balance: 'Balance due',
        edited: 'Edited',
    };
    const styles: Record<string, string> = {
        dine_in: 'border-green-200 bg-green-50 text-green-700',
        take_out: 'border-blue-200 bg-blue-50 text-blue-700',
        kitchen: KITCHEN_STYLES.kitchen,
        preparing: KITCHEN_STYLES.preparing,
        ready: KITCHEN_STYLES.ready,
        done: KITCHEN_STYLES.done,
        voided: 'border-red-200 bg-red-50 text-red-700',
        cash: 'border-green-200 bg-green-50 text-green-700',
        cashless: 'border-blue-200 bg-blue-50 text-blue-700',
        split: 'border-blue-100 bg-[#f5f9ff] text-blue-700',
        paid: 'border-neutral-950 bg-neutral-950 text-white',
        unpaid: 'border-red-200 bg-red-50 text-red-700',
        balance: 'border-amber-200 bg-amber-50 text-amber-800',
        edited: 'border-neutral-200 bg-neutral-100 text-neutral-500',
    };
    return (
        <span
            className={`inline-flex h-5 items-center rounded-full border px-2 text-[9.5px] font-bold tracking-[.04em] uppercase ${styles[kind] ?? styles.edited}`}
        >
            {labels[kind] ?? kind}
        </span>
    );
}

function SizeBadge({ children }: { children: ReactNode }) {
    return (
        <span className="mr-1 inline-flex rounded border border-amber-200 bg-amber-100 px-1.5 py-px text-[9.5px] font-bold tracking-wide text-amber-800 uppercase">
            {children}
        </span>
    );
}

function TransactionDetailDialog({
    detail,
    loading,
    open,
    onClose,
    onEdit,
    onSettle,
    onVoid,
    onPrint,
    onInvoice,
}: {
    detail: Detail | null;
    loading: boolean;
    open: boolean;
    onClose: () => void;
    onEdit: () => void;
    onSettle: () => void;
    onVoid: () => void;
    onPrint: () => void;
    onInvoice: (
        payment: Detail['payment_groups'][number]['payments'][number],
    ) => void;
}) {
    if (!detail && !loading) return null;
    const split = detail?.payment_groups[0]?.payments ?? [];
    const date = detail ? formatManila(detail.committed_at) : null;
    return (
        <Dialog open={open} onOpenChange={(value) => !value && onClose()}>
            <DialogContent className="pos-surface flex max-h-[92dvh] max-w-xl flex-col gap-0 overflow-hidden p-0 max-sm:h-dvh max-sm:max-h-dvh max-sm:max-w-full max-sm:rounded-none">
                <header className="flex items-center justify-between border-b border-neutral-200 px-3.5 py-3">
                    <DialogTitle className="text-[15px]">
                        Transaction details{' '}
                        {detail ? `#${detail.order_number}` : ''}
                    </DialogTitle>
                    <DialogDescription className="sr-only">
                        Transaction items, payment metadata, totals, and
                        actions.
                    </DialogDescription>
                </header>
                {loading || !detail ? (
                    <p className="p-6 text-sm text-neutral-500">Loading…</p>
                ) : (
                    <div className="min-h-0 flex-1 space-y-3 overflow-y-auto p-3.5">
                        <div className="flex items-start justify-between gap-3">
                            <div className="min-w-0">
                                <p className="text-2xl font-bold text-red-700 tabular-nums">
                                    #{detail.order_number}
                                </p>
                                {truthfulCustomer(detail) && (
                                    <p className="text-[13px] font-semibold">
                                        {truthfulCustomer(detail)}
                                    </p>
                                )}
                            </div>
                            <div className="flex flex-wrap justify-end gap-1.5">
                                <SemanticChip kind={detail.order_type} />
                                <SemanticChip
                                    kind={
                                        detail.commercial_status === 'voided'
                                            ? 'voided'
                                            : detail.kitchen_status
                                    }
                                />
                                <SemanticChip
                                    kind={
                                        detail.payment_status === 'unpaid'
                                            ? 'unpaid'
                                            : detail.payment_status ===
                                                'partial'
                                              ? 'balance'
                                              : (detail.payment_method ??
                                                'paid')
                                    }
                                />
                            </div>
                        </div>
                        <MetadataRows
                            rows={[
                                [
                                    'Order type',
                                    detail.order_type === 'dine_in'
                                        ? 'Dine in'
                                        : 'Take out',
                                ],
                                [
                                    'Order status',
                                    KITCHEN_LABELS[detail.kitchen_status],
                                ],
                                [
                                    'Payment status',
                                    detail.payment_status === 'partial'
                                        ? 'Balance due'
                                        : detail.payment_status === 'unpaid'
                                          ? 'Pending'
                                          : 'Paid',
                                ],
                                [
                                    'Payment method',
                                    detail.payment_method
                                        ? titleCase(detail.payment_method)
                                        : 'Unpaid',
                                ],
                                ...split.map(
                                    (payment) =>
                                        [
                                            titleCase(payment.method),
                                            pesos(payment.amount),
                                        ] as [string, string],
                                ),
                                ['Recorded', `${date?.date} · ${date?.time}`],
                                ['Cashier', detail.cashier ?? '—'],
                                ...(truthfulCustomer(detail)
                                    ? [
                                          [
                                              'Customer / table',
                                              truthfulCustomer(detail) ?? '',
                                          ] as [string, string],
                                      ]
                                    : []),
                                ['REF', detail.reference_number],
                                ...(detail.void
                                    ? ([
                                          [
                                              'Void reason',
                                              detail.void.reason_label,
                                          ],
                                          [
                                              'Initiated by',
                                              detail.void.initiated_by ?? 'â€”',
                                          ],
                                          [
                                              'Authorized by',
                                              detail.void.authorized_by ??
                                                  'â€”',
                                          ],
                                          [
                                              'Voided',
                                              detail.void.created_at
                                                  ? `${formatManila(detail.void.created_at).date} Â· ${formatManila(detail.void.created_at).time}`
                                                  : 'â€”',
                                          ],
                                      ] as [string, string][])
                                    : []),
                            ]}
                        />
                        <section>
                            <p className="mb-1.5 text-[9.5px] font-semibold tracking-[.09em] text-neutral-400 uppercase">
                                Items
                            </p>
                            <div className="overflow-hidden rounded-xl border border-neutral-200">
                                {detail.items.map((item) => {
                                    const standard = item.modifiers.filter(
                                        (modifier) =>
                                            modifier.semantic_role !==
                                                'instruction' &&
                                            modifier.semantic_role !== 'size',
                                    );
                                    const instructions = item.modifiers.filter(
                                        (modifier) =>
                                            modifier.semantic_role ===
                                            'instruction',
                                    );
                                    return (
                                        <div
                                            key={item.id}
                                            className="flex items-start gap-2 border-b border-neutral-100 p-2.5 last:border-b-0"
                                        >
                                            <strong className="shrink-0 text-[12.5px] text-neutral-500">
                                                {item.quantity}×
                                            </strong>
                                            <div className="min-w-0 flex-1 space-y-1">
                                                <p className="text-[13px] font-semibold">
                                                    {item.size_prefix && (
                                                        <SizeBadge>
                                                            {item.size_prefix}
                                                        </SizeBadge>
                                                    )}
                                                    {item.name}
                                                </p>
                                                {standard.length > 0 && (
                                                    <p className="text-[11px] leading-4 text-neutral-500">
                                                        {standard
                                                            .map(
                                                                (modifier) =>
                                                                    modifier.name,
                                                            )
                                                            .join(' · ')}
                                                    </p>
                                                )}
                                                {instructions.length > 0 && (
                                                    <p className="inline-flex rounded-[7px] border border-amber-200 bg-amber-50 px-2 py-1 text-[10.5px] leading-4 text-amber-800">
                                                        Instructions:{' '}
                                                        {instructions
                                                            .map(
                                                                (modifier) =>
                                                                    modifier.name,
                                                            )
                                                            .join(', ')}
                                                    </p>
                                                )}
                                                {item.notes && (
                                                    <p className="w-fit rounded-[7px] border border-orange-200 bg-orange-50 px-2 py-1 text-[10.5px] leading-4 text-orange-800">
                                                        Note: {item.notes}
                                                    </p>
                                                )}
                                            </div>
                                            <strong className="shrink-0 text-[13px] tabular-nums">
                                                {pesos(item.line_total)}
                                            </strong>
                                        </div>
                                    );
                                })}
                            </div>
                        </section>
                        <MoneyPanel detail={detail} />
                        {detail.inventory_restorations.length > 0 && (
                            <section>
                                <p className="mb-1.5 text-[9.5px] font-semibold tracking-[.09em] text-neutral-400 uppercase">
                                    Inventory restored
                                </p>
                                <MetadataRows
                                    rows={detail.inventory_restorations.map(
                                        (restoration) => [
                                            restoration.product_name ??
                                                'Product',
                                            `${restoration.quantity_restored} restored`,
                                        ],
                                    )}
                                />
                            </section>
                        )}
                        {detail.payment_groups.some((group) =>
                            group.payments.some(
                                (payment) => payment.method === 'cashless',
                            ),
                        ) && (
                            <section className="space-y-2">
                                <p className="text-[9.5px] font-semibold tracking-[.09em] text-neutral-400 uppercase">
                                    Cashless invoice
                                </p>
                                {detail.payment_groups.flatMap((group) =>
                                    group.payments
                                        .filter(
                                            (payment) =>
                                                payment.method === 'cashless',
                                        )
                                        .map((payment) => (
                                            <button
                                                key={payment.id}
                                                type="button"
                                                onClick={() =>
                                                    onInvoice(payment)
                                                }
                                                className="flex w-full items-center gap-3 rounded-xl border border-neutral-200 p-3 text-left"
                                            >
                                                <CreditCard className="size-4 text-blue-700" />
                                                <span className="min-w-0 flex-1">
                                                    <strong className="block text-[12.5px]">
                                                        {payment.invoice
                                                            ? 'Invoice attached'
                                                            : 'Capture invoice'}
                                                    </strong>
                                                    <span className="block truncate text-[11px] text-neutral-500">
                                                        {payment.invoice
                                                            ?.name ??
                                                            'No proof attached'}
                                                    </span>
                                                </span>
                                                <ChevronRight className="size-4 text-neutral-400" />
                                            </button>
                                        )),
                                )}
                            </section>
                        )}
                    </div>
                )}
                {detail && !loading && (
                    <footer className="grid shrink-0 grid-cols-[auto_auto_1fr] gap-2 border-t border-neutral-200 bg-white p-3.5">
                        <button
                            type="button"
                            disabled={!detail.can_void}
                            title={
                                detail.can_void
                                    ? 'Void transaction'
                                    : 'This transaction cannot be voided'
                            }
                            onClick={onVoid}
                            className="inline-flex h-12 items-center justify-center gap-1.5 rounded-xl border border-red-200 px-3 text-[13px] font-semibold text-red-700 hover:border-red-700 hover:bg-red-50 disabled:opacity-40"
                        >
                            <ShieldBan className="size-4" /> Void
                        </button>
                        <button
                            type="button"
                            disabled={
                                !detail.can_edit ||
                                detail.commercial_status === 'voided'
                            }
                            onClick={onEdit}
                            className="inline-flex h-12 items-center justify-center gap-1.5 rounded-xl border border-neutral-200 px-3 text-[13px] font-semibold disabled:opacity-40"
                        >
                            <Pencil className="size-4" /> Edit
                        </button>
                        <button
                            type="button"
                            onClick={onPrint}
                            className="inline-flex h-12 items-center justify-center gap-2 rounded-xl bg-[#111] text-[13.5px] font-semibold text-white"
                        >
                            <Printer className="size-4" /> Receipt
                        </button>
                        {detail.can_settle && (
                            <button
                                type="button"
                                onClick={onSettle}
                                className="col-span-3 inline-flex h-12 items-center justify-center gap-2 rounded-xl bg-green-700 text-[13.5px] font-semibold text-white hover:bg-green-800"
                            >
                                <CreditCard className="size-4" />
                                {detail.payment_status === 'partial'
                                    ? 'Settle balance'
                                    : 'Take payment'}{' '}
                                {pesos(detail.outstanding)}
                            </button>
                        )}
                    </footer>
                )}
            </DialogContent>
        </Dialog>
    );
}

function MetadataRows({ rows }: { rows: [string, string][] }) {
    return (
        <dl className="overflow-hidden rounded-xl border border-neutral-200">
            {rows.map(([label, value]) => (
                <div
                    key={`${label}-${value}`}
                    className="flex items-center justify-between gap-3 border-b border-neutral-100 px-3 py-2 last:border-b-0"
                >
                    <dt className="shrink-0 text-[11.5px] text-neutral-500">
                        {label}
                    </dt>
                    <dd className="min-w-0 text-right text-[12.5px] font-semibold wrap-anywhere tabular-nums">
                        {value}
                    </dd>
                </div>
            ))}
        </dl>
    );
}

function MoneyPanel({ detail }: { detail: Detail }) {
    const corrected = Number(detail.adjustment_total);
    return (
        <dl className="overflow-hidden rounded-xl border border-neutral-200">
            {detail.edited_at && detail.original_total && (
                <MoneyRow
                    label="Original total"
                    value={pesos(detail.original_total)}
                    muted
                />
            )}
            <MoneyRow
                label={detail.edited_at ? 'Updated total' : 'Total'}
                value={pesos(detail.total)}
                strong
            />
            {Number(detail.amount_paid) > 0 && (
                <MoneyRow
                    label="Amount paid"
                    value={pesos(detail.amount_paid)}
                />
            )}
            {Number(detail.outstanding) > 0 && (
                <MoneyRow
                    label="Outstanding balance"
                    value={pesos(detail.outstanding)}
                    tone="amber"
                    strong
                />
            )}
            {corrected > 0 && (
                <MoneyRow
                    label="Amount to return / correction"
                    value={pesos(detail.adjustment_total)}
                    tone="red"
                    strong
                />
            )}
        </dl>
    );
}

function MoneyRow({
    label,
    value,
    muted,
    strong,
    tone,
}: {
    label: string;
    value: string;
    muted?: boolean;
    strong?: boolean;
    tone?: 'amber' | 'red';
}) {
    return (
        <div
            className={`flex items-center justify-between gap-3 border-b border-neutral-100 px-3 py-2.5 last:border-b-0 ${tone === 'amber' ? 'bg-amber-50 text-amber-800' : tone === 'red' ? 'bg-red-50 text-red-700' : ''}`}
        >
            <dt className={`text-[11.5px] ${strong ? 'font-bold' : ''}`}>
                {label}
            </dt>
            <dd
                className={`${strong ? 'text-[17px] font-bold' : 'text-[13px] font-semibold'} tabular-nums ${muted ? 'text-neutral-400 line-through' : ''}`}
            >
                {value}
            </dd>
        </div>
    );
}

const VOID_REASONS: { value: string; label: string }[] = [
    { value: 'wrong_item', label: 'Wrong item' },
    { value: 'customer_cancelled', label: 'Customer cancelled' },
    { value: 'duplicate_transaction', label: 'Duplicate transaction' },
    { value: 'price_or_quantity_error', label: 'Price or quantity error' },
    { value: 'other', label: 'Other' },
];

function VoidDialog({
    detail,
    onClose,
    onVoided,
}: {
    detail: Detail;
    onClose: () => void;
    onVoided: (detail: Detail) => void;
}) {
    const [reasonCode, setReasonCode] = useState('');
    const [reasonText, setReasonText] = useState('');
    const [authorizationPin, setAuthorizationPin] = useState('');
    const [processing, setProcessing] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [idempotencyKey] = useState(createClientUuid);

    async function submit() {
        if (!navigator.onLine) {
            setError('Void authorization requires an internet connection.');

            return;
        }

        if (!reasonCode) {
            setError('Select a Void reason.');

            return;
        }

        if (reasonCode === 'other' && !reasonText.trim()) {
            setError('Describe the Void reason.');

            return;
        }

        if (!/^\d{4}$/.test(authorizationPin)) {
            setError('Enter the 4-digit Void PIN.');

            return;
        }

        setProcessing(true);
        setError(null);

        try {
            const response = await http.getClient().request({
                ...voidMethod(detail.id),
                data: {
                    reason_code: reasonCode,
                    reason_text: reasonText.trim() || null,
                    authorization_pin: authorizationPin,
                    idempotency_key: idempotencyKey,
                    expected_version: detail.version,
                },
                headers: { Accept: 'application/json' },
            });
            const updated = (
                JSON.parse(response.data) as { transaction: Detail }
            ).transaction;

            toast.success(`Transaction #${detail.order_number} was voided.`);
            onVoided(updated);
        } catch (requestError) {
            const response = requestError as {
                response?: { data?: string; status?: number };
            };

            if (response.response?.status === 409) {
                setError(
                    'Transaction changed. Review the latest details before voiding.',
                );

                return;
            }

            try {
                const payload = JSON.parse(response.response?.data ?? '{}') as {
                    message?: string;
                    errors?: Record<string, string[]>;
                };
                setError(
                    payload.errors?.authorization_pin?.[0] ??
                        payload.errors?.authorization?.[0] ??
                        payload.message ??
                        'Void could not be authorized.',
                );
            } catch {
                setError('Void could not be authorized.');
            }
        } finally {
            setProcessing(false);
        }
    }

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="pos-surface max-w-md p-5">
                <span className="inline-flex size-11 items-center justify-center rounded-xl bg-red-50 text-red-700">
                    <ShieldBan className="size-5" />
                </span>
                <DialogTitle>
                    Void transaction #{detail.order_number}
                </DialogTitle>
                <DialogDescription className="text-[12.5px] leading-5">
                    This permanently marks the transaction as voided. The
                    original order and payment history are retained for audit;
                    stock is restored when applicable.
                </DialogDescription>
                <MetadataRows
                    rows={[
                        [
                            'Customer / table',
                            truthfulCustomer(detail) ?? 'Walk-in',
                        ],
                        [
                            'Order type',
                            detail.order_type === 'dine_in'
                                ? 'Dine in'
                                : 'Take out',
                        ],
                        ['Total', pesos(detail.total)],
                        ['Reference', detail.reference_number],
                    ]}
                />
                {error && (
                    <p
                        role="alert"
                        className="rounded-xl bg-red-50 p-3 text-sm text-red-800"
                    >
                        {error}
                    </p>
                )}
                <fieldset className="space-y-2">
                    <legend className="text-[10px] font-semibold tracking-[.09em] text-neutral-500 uppercase">
                        Void reason
                    </legend>
                    <div className="grid grid-cols-1 gap-2 sm:grid-cols-2">
                        {VOID_REASONS.map((reason) => (
                            <button
                                key={reason.value}
                                type="button"
                                onClick={() => setReasonCode(reason.value)}
                                className={`min-h-11 rounded-xl border px-3 text-left text-[12px] font-semibold ${reasonCode === reason.value ? 'border-red-700 bg-red-50 text-red-800' : 'border-neutral-200'}`}
                            >
                                {reason.label}
                            </button>
                        ))}
                    </div>
                </fieldset>
                {reasonCode === 'other' && (
                    <label className="block space-y-1.5">
                        <span className="text-[10px] font-semibold tracking-[.09em] text-neutral-500 uppercase">
                            Reason details
                        </span>
                        <textarea
                            value={reasonText}
                            onChange={(event) =>
                                setReasonText(event.target.value)
                            }
                            maxLength={1000}
                            rows={3}
                            className="w-full rounded-xl border border-neutral-300 px-3 py-2 text-sm"
                        />
                    </label>
                )}
                <section className="space-y-2 rounded-xl border border-amber-200 bg-amber-50 p-3">
                    <p className="text-[12px] font-semibold text-amber-900">
                        Enter the 4-digit Void PIN set in Super Admin.
                    </p>
                    <label className="block space-y-1">
                        <span className="text-[10px] font-semibold tracking-[.09em] text-amber-800 uppercase">
                            Void PIN
                        </span>
                        <input
                            type="password"
                            inputMode="numeric"
                            autoComplete="one-time-code"
                            maxLength={4}
                            value={authorizationPin}
                            onChange={(event) =>
                                setAuthorizationPin(
                                    event.target.value
                                        .replace(/\D/g, '')
                                        .slice(0, 4),
                                )
                            }
                            className="h-11 w-full rounded-xl border border-amber-200 bg-white px-3 text-center text-lg tracking-[0.5em]"
                        />
                    </label>
                </section>
                <div className="grid grid-cols-2 gap-2">
                    <button
                        type="button"
                        disabled={processing}
                        onClick={onClose}
                        className="h-12 rounded-xl border border-neutral-300 text-sm font-semibold disabled:opacity-50"
                    >
                        Cancel
                    </button>
                    <button
                        type="button"
                        disabled={
                            processing ||
                            !reasonCode ||
                            !/^\d{4}$/.test(authorizationPin)
                        }
                        onClick={() => void submit()}
                        className="h-12 rounded-xl bg-red-700 text-sm font-semibold text-white hover:bg-red-800 disabled:bg-red-300"
                    >
                        {processing ? 'Authorizing…' : 'Confirm Void'}
                    </button>
                </div>
            </DialogContent>
        </Dialog>
    );
}

type EditLine = CartLine & {
    existingOrderItemId?: string;
    committedUnitPrice?: string;
    committedModifierKey?: string;
};

function EditDialog({
    detail,
    catalog,
    tables,
    canManageKitchen,
    onClose,
    onSaved,
}: {
    detail: Detail;
    catalog: CashierCatalog;
    tables: BranchTable[];
    canManageKitchen: boolean;
    onClose: () => void;
    onSaved: (detail: Detail) => void;
}) {
    const [orderType, setOrderType] = useState(detail.order_type);
    const [customer, setCustomer] = useState(detail.customer_label ?? '');
    const [tableId, setTableId] = useState(
        tables.find((table) => table.name === detail.table_name)?.id ?? '',
    );
    const [reason, setReason] = useState('');
    const [processing, setProcessing] = useState(false);
    const [active, setActive] = useState<EditLine | null>(null);
    const [addingProduct, setAddingProduct] = useState(false);
    const [screen, setScreen] = useState<'edit' | 'add'>('edit');
    const [category, setCategory] = useState('all');
    const [productSearch, setProductSearch] = useState('');
    const [kitchenStatus, setKitchenStatus] = useState(detail.kitchen_status);
    const [confirmLower, setConfirmLower] = useState(false);
    const [refundCash, setRefundCash] = useState('');
    const [lines, setLines] = useState<EditLine[]>(() =>
        detail.items.flatMap((item) => {
            const product = catalog.products.find(
                (candidate) => candidate.id === item.product_id,
            );
            const modifiers = item.modifiers.map((modifier) => ({
                group_id: modifier.group_id,
                option_id: modifier.option_id,
            }));
            return product
                ? [
                      {
                          key: item.id,
                          existingOrderItemId: item.id,
                          product,
                          quantity: item.quantity,
                          notes: item.notes ?? '',
                          modifiers,
                          committedUnitPrice: item.unit_price,
                          committedModifierKey: modifierKey(modifiers),
                      },
                  ]
                : [];
        }),
    );
    const totalCents = lines.reduce(
        (total, line) => total + editLineCents(line),
        0n,
    );
    const total = centsDecimal(totalCents);
    const settled = Math.max(
        0,
        Number(detail.amount_paid) - Number(detail.adjustment_total),
    );
    const difference = Math.round((Number(total) - settled) * 100) / 100;
    /** A refund funded by both methods needs the Cashier's Cash/Cashless answer; it is never guessed. */
    const settledCents = cents(detail.amount_paid) - cents(detail.adjustment_total);
    const refundCents =
        settledCents > totalCents ? settledCents - totalCents : 0n;
    const refundBounds =
        refundCents > 0n
            ? correctionRefundBounds(
                  refundCents,
                  detail.refund_sources.cash_available,
                  detail.refund_sources.cashless_available,
              )
            : null;
    const refundChoiceRequired =
        refundBounds !== null && refundBounds.min < refundBounds.max;
    const refundCashValue = normalizeMoneyInput(refundCash);
    const refundCashCents =
        refundCashValue === null ? null : signedCents(refundCashValue);
    const refundChoiceValid =
        !refundChoiceRequired ||
        (refundCashCents !== null &&
            refundCashCents >= refundBounds.min &&
            refundCashCents <= refundBounds.max);
    const filteredProducts = catalog.products.filter(
        (product) =>
            (category === 'all' || product.category_id === category) &&
            product.name
                .toLocaleLowerCase()
                .includes(productSearch.trim().toLocaleLowerCase()),
    );

    async function persist() {
        setProcessing(true);
        try {
            const response = await http.getClient().request({
                ...update(detail.id),
                data: {
                    idempotency_key: createClientUuid(),
                    expected_version: detail.version,
                    order_type: orderType,
                    customer_label: customer || null,
                    branch_table_id: tableId || null,
                    reason: reason || null,
                    refund_cash_amount: refundChoiceRequired
                        ? refundCashValue
                        : null,
                    items: lines.map((line) => ({
                        existing_order_item_id: line.existingOrderItemId,
                        product_id: line.product.id,
                        quantity: line.quantity,
                        notes: line.notes,
                        modifiers: line.modifiers,
                    })),
                },
                headers: { Accept: 'application/json' },
            });
            const updated = (
                JSON.parse(response.data) as { transaction: Detail }
            ).transaction;

            if (kitchenStatus !== updated.kitchen_status) {
                try {
                    const kitchenResponse = await http.getClient().request({
                        ...updateKitchenStatus(detail.id),
                        data: { status: kitchenStatus },
                        headers: { Accept: 'application/json' },
                    });
                    const transition = (
                        JSON.parse(kitchenResponse.data) as {
                            kitchenTransition: {
                                to: KitchenStatus;
                                version: number;
                            };
                        }
                    ).kitchenTransition;
                    updated.kitchen_status = transition.to;
                    updated.version = transition.version;
                } catch {
                    toast.error(
                        'Order changes were saved, but the kitchen status could not be changed.',
                    );
                    onSaved(updated);

                    return;
                }
            }

            toast.success('Committed order updated.');
            onSaved(updated);
        } catch (error) {
            const data = (error as { response?: { data?: string } }).response
                ?.data;
            toast.error(
                data
                    ? ((JSON.parse(data) as { message?: string }).message ??
                          'Order could not be updated.')
                    : 'Order could not be updated.',
            );
        } finally {
            setProcessing(false);
        }
    }

    function stageKitchenStatus(status: KitchenStatus) {
        if (!canManageKitchen || status === kitchenStatus) return;
        if (
            status !== detail.kitchen_status &&
            !canTransitionKitchenStatus(detail.kitchen_status, status)
        )
            return;

        setKitchenStatus(status);
    }

    if (confirmLower) {
        return (
            <Dialog
                open
                onOpenChange={(value) => !value && setConfirmLower(false)}
            >
                <DialogContent className="pos-surface max-w-md p-5">
                    <span className="inline-flex size-11 items-center justify-center rounded-xl bg-red-50 text-red-700">
                        <AlertTriangle className="size-5" />
                    </span>
                    <DialogTitle>Adjustment to return</DialogTitle>
                    <DialogDescription className="text-[12.5px] leading-5">
                        The updated total is lower than the settled amount. The
                        append-only correction is recorded only after you
                        confirm.
                    </DialogDescription>
                    <div className="overflow-hidden rounded-xl border border-neutral-200">
                        <MoneyRow
                            label="Originally paid"
                            value={pesos(settled.toFixed(2))}
                        />
                        <MoneyRow label="Updated total" value={pesos(total)} />
                        <MoneyRow
                            label="Amount to return"
                            value={pesos(Math.abs(difference).toFixed(2))}
                            tone="red"
                            strong
                        />
                    </div>
                    {refundBounds && !refundChoiceRequired && (
                        <p className="rounded-xl bg-neutral-100 px-3 py-2 text-[12px] font-semibold">
                            Returned from Cash {pesos(refundBounds.min)} ·
                            Cashless {pesos(refundCents - refundBounds.min)}
                        </p>
                    )}
                    {refundBounds && refundChoiceRequired && (
                        <div className="space-y-1.5 rounded-xl border border-amber-200 bg-amber-50 p-3">
                            <label
                                htmlFor="refund-cash-amount"
                                className="text-[12px] font-bold text-amber-950"
                            >
                                Returned in Cash
                            </label>
                            <input
                                id="refund-cash-amount"
                                value={refundCash}
                                onChange={(event) =>
                                    setRefundCash(event.target.value)
                                }
                                inputMode="decimal"
                                placeholder="0.00"
                                className="h-11 w-full rounded-xl border border-amber-300 bg-white px-3 text-sm font-bold tabular-nums outline-none focus-visible:ring-2 focus-visible:ring-neutral-950"
                            />
                            <p className="text-[11px] leading-4 text-amber-900">
                                This order was paid with Cash and Cashless.
                                Enter {pesos(refundBounds.min)} –{' '}
                                {pesos(refundBounds.max)}; the rest is returned
                                as Cashless.
                                {refundChoiceValid && refundCashCents !== null
                                    ? ` Cashless ${pesos(refundCents - refundCashCents)}.`
                                    : ''}
                            </p>
                        </div>
                    )}
                    <div className="grid grid-cols-2 gap-2">
                        <button
                            type="button"
                            onClick={() => setConfirmLower(false)}
                            className="h-12 rounded-xl border border-neutral-300 text-sm font-semibold"
                        >
                            Back
                        </button>
                        <button
                            type="button"
                            disabled={processing || !refundChoiceValid}
                            onClick={() => void persist()}
                            className="h-12 rounded-xl bg-[#111] text-sm font-semibold text-white disabled:bg-neutral-500"
                        >
                            {processing ? 'Saving…' : 'Confirm adjustment'}
                        </button>
                    </div>
                </DialogContent>
            </Dialog>
        );
    }

    return (
        <>
            <Dialog open onOpenChange={(value) => !value && onClose()}>
                <DialogContent className="pos-surface flex max-h-[94dvh] max-w-[calc(100%-2rem)] flex-col gap-0 overflow-hidden p-0 max-md:h-dvh max-md:max-h-dvh max-md:max-w-full max-md:rounded-none sm:max-w-[760px]">
                    {screen === 'edit' ? (
                        <>
                            <header className="flex items-center justify-between border-b border-neutral-200 px-3.5 py-3">
                                <DialogTitle className="text-[15px]">
                                    Edit transaction #{detail.order_number}
                                </DialogTitle>
                                <DialogDescription className="sr-only">
                                    Edit transaction items and order
                                    information.
                                </DialogDescription>
                            </header>
                            <div className="min-h-0 flex-1 space-y-4 overflow-y-auto p-4">
                                <label className="block space-y-1.5">
                                    <span className="text-[10px] font-semibold tracking-[.09em] text-neutral-500 uppercase">
                                        Customer name / table label
                                    </span>
                                    <input
                                        value={customer}
                                        onChange={(event) =>
                                            setCustomer(event.target.value)
                                        }
                                        className="h-[50px] w-full rounded-xl border border-neutral-300 px-3.5 text-base outline-none focus:border-neutral-600"
                                    />
                                </label>
                                <section className="space-y-2">
                                    <p className="text-[10px] font-semibold tracking-[.09em] text-neutral-500 uppercase">
                                        Order type
                                    </p>
                                    <div className="flex max-w-xs gap-1 rounded-[11px] bg-neutral-100 p-1">
                                        <button
                                            type="button"
                                            onClick={() =>
                                                setOrderType('dine_in')
                                            }
                                            className={`inline-flex h-[42px] flex-1 items-center justify-center gap-2 rounded-[9px] text-[13px] font-semibold ${orderType === 'dine_in' ? 'border border-green-300 bg-green-50 text-green-700' : 'text-neutral-500'}`}
                                        >
                                            <UtensilsCrossed className="size-4" />
                                            Dine in
                                        </button>
                                        <button
                                            type="button"
                                            onClick={() =>
                                                setOrderType('take_out')
                                            }
                                            className={`inline-flex h-[42px] flex-1 items-center justify-center gap-2 rounded-[9px] text-[13px] font-semibold ${orderType === 'take_out' ? 'border border-blue-300 bg-blue-50 text-blue-700' : 'text-neutral-500'}`}
                                        >
                                            <ShoppingBag className="size-4" />
                                            Take out
                                        </button>
                                    </div>
                                </section>
                                <section className="space-y-2">
                                    <p className="text-[10px] font-semibold tracking-[.09em] text-neutral-500 uppercase">
                                        Table (optional for both order types)
                                    </p>
                                    <div className="flex gap-2 overflow-x-auto pb-1">
                                        {tables.map((table) => (
                                            <button
                                                key={table.id}
                                                type="button"
                                                onClick={() =>
                                                    setTableId((current) =>
                                                        current === table.id
                                                            ? ''
                                                            : table.id,
                                                    )
                                                }
                                                className={`h-10 shrink-0 rounded-[10px] border px-3 text-xs font-semibold ${tableId === table.id ? 'border-[#111] bg-[#111] text-white' : 'border-neutral-200 bg-white'}`}
                                            >
                                                {table.name}
                                            </button>
                                        ))}
                                    </div>
                                </section>
                                <section className="space-y-2">
                                    <div className="flex items-center justify-between gap-2">
                                        <p className="text-[10px] font-semibold tracking-[.09em] text-neutral-500 uppercase">
                                            Kitchen status
                                        </p>
                                        {!canManageKitchen && (
                                            <span className="text-[10.5px] text-neutral-400">
                                                Kitchen role required to change
                                            </span>
                                        )}
                                    </div>
                                    <div className="flex flex-wrap gap-2">
                                        {(
                                            Object.keys(
                                                KITCHEN_LABELS,
                                            ) as KitchenStatus[]
                                        ).map((status) => {
                                            const current =
                                                status === kitchenStatus;
                                            const allowed =
                                                canManageKitchen &&
                                                (status ===
                                                    detail.kitchen_status ||
                                                    canTransitionKitchenStatus(
                                                        detail.kitchen_status,
                                                        status,
                                                    ));
                                            return (
                                                <button
                                                    key={status}
                                                    type="button"
                                                    disabled={
                                                        !current && !allowed
                                                    }
                                                    onClick={() =>
                                                        stageKitchenStatus(
                                                            status,
                                                        )
                                                    }
                                                    className={`h-10 rounded-full border px-3.5 text-[12px] font-semibold ${current ? KITCHEN_STYLES[status] : allowed ? 'border-neutral-300 bg-white' : 'border-neutral-100 bg-white text-neutral-300'}`}
                                                >
                                                    {KITCHEN_LABELS[status]}
                                                </button>
                                            );
                                        })}
                                    </div>
                                </section>
                                <section className="space-y-2">
                                    <div className="flex items-center justify-between gap-2">
                                        <p className="text-[10px] font-semibold tracking-[.09em] text-neutral-500 uppercase">
                                            Items
                                        </p>
                                        <button
                                            type="button"
                                            onClick={() => setScreen('add')}
                                            className="inline-flex h-10 items-center gap-1.5 rounded-[10px] border border-[#111] px-3.5 text-[12.5px] font-semibold hover:bg-[#111] hover:text-white"
                                        >
                                            <Plus className="size-4" /> Add item
                                        </button>
                                    </div>
                                    <div className="overflow-hidden rounded-[13px] border border-neutral-200">
                                        {lines.map((line) => (
                                            <EditLineRow
                                                key={line.key}
                                                line={line}
                                                onChange={(changed) =>
                                                    setLines((current) =>
                                                        current.map(
                                                            (candidate) =>
                                                                candidate.key ===
                                                                changed.key
                                                                    ? changed
                                                                    : candidate,
                                                        ),
                                                    )
                                                }
                                                onEdit={() => {
                                                    setAddingProduct(false);
                                                    setActive(line);
                                                }}
                                                onRemove={() =>
                                                    setLines((current) =>
                                                        current.filter(
                                                            (candidate) =>
                                                                candidate.key !==
                                                                line.key,
                                                        ),
                                                    )
                                                }
                                            />
                                        ))}
                                    </div>
                                    {lines.length === 0 && (
                                        <p className="text-xs text-red-700">
                                            Add at least one item before saving.
                                        </p>
                                    )}
                                </section>
                                <label className="block space-y-1.5">
                                    <span className="text-[10px] font-semibold tracking-[.09em] text-neutral-500 uppercase">
                                        Edit reason (optional)
                                    </span>
                                    <textarea
                                        value={reason}
                                        onChange={(event) =>
                                            setReason(event.target.value)
                                        }
                                        rows={2}
                                        className="min-h-16 w-full rounded-[11px] border border-neutral-300 p-3 text-[13px] outline-none focus:border-neutral-600"
                                        placeholder="Reason recorded in the audit trail"
                                    />
                                </label>
                                <div className="flex items-end justify-between gap-3 rounded-xl bg-neutral-50 p-3">
                                    <div>
                                        <p className="text-[11.5px] text-neutral-500">
                                            Recalculated total
                                        </p>
                                        <p className="text-[11px] text-neutral-400">
                                            was {pesos(detail.total)}
                                        </p>
                                    </div>
                                    <strong className="text-2xl tracking-[-0.025em] tabular-nums">
                                        {pesos(total)}
                                    </strong>
                                </div>
                                {settled > 0 && (
                                    <div
                                        className={`rounded-[11px] border p-3 text-xs leading-5 font-semibold ${difference > 0 ? 'border-amber-200 bg-amber-50 text-amber-800' : difference < 0 ? 'border-red-200 bg-red-50 text-red-700' : 'border-neutral-200 bg-neutral-50 text-neutral-600'}`}
                                    >
                                        Already paid {pesos(settled.toFixed(2))}
                                        .{' '}
                                        {difference > 0
                                            ? `Additional balance of ${pesos(difference.toFixed(2))} will be due after saving.`
                                            : difference < 0
                                              ? `${pesos(Math.abs(difference).toFixed(2))} to return to the customer.`
                                              : 'Total unchanged — no payment adjustment needed.'}
                                    </div>
                                )}
                            </div>
                            <footer className="grid shrink-0 grid-cols-[auto_1fr] gap-2 border-t border-neutral-200 bg-white p-3.5">
                                <button
                                    type="button"
                                    onClick={onClose}
                                    className="h-12 rounded-xl border border-neutral-400 px-4 text-sm font-semibold"
                                >
                                    Discard
                                </button>
                                <button
                                    type="button"
                                    disabled={processing || lines.length === 0}
                                    onClick={() => {
                                        if (settled > 0 && difference < 0) {
                                            setConfirmLower(true);
                                        } else {
                                            void persist();
                                        }
                                    }}
                                    className="h-12 rounded-xl bg-[#111] px-4 text-[14.5px] font-semibold text-white disabled:bg-neutral-500"
                                >
                                    {processing ? 'Saving…' : 'Save changes'}
                                </button>
                            </footer>
                        </>
                    ) : (
                        <>
                            <header className="flex items-center justify-between border-b border-neutral-200 px-3.5 py-2.5">
                                <button
                                    type="button"
                                    onClick={() => setScreen('edit')}
                                    className="inline-flex h-10 items-center gap-2 rounded-[10px] px-2 text-[13px] font-semibold hover:bg-neutral-100"
                                >
                                    <ArrowLeft className="size-4" /> Back
                                </button>
                                <DialogTitle className="text-sm">
                                    Add item to #{detail.order_number}
                                </DialogTitle>
                                <DialogDescription className="sr-only">
                                    Search the live catalog and customize an
                                    item.
                                </DialogDescription>
                                <span className="w-14" />
                            </header>
                            <div className="flex shrink-0 flex-wrap items-center gap-2 border-b border-neutral-200 px-3.5 py-2.5">
                                <div className="order-2 flex min-w-0 basis-full gap-1.5 overflow-x-auto sm:order-none sm:flex-1 sm:basis-auto">
                                    <button
                                        type="button"
                                        onClick={() => setCategory('all')}
                                        className={`inline-flex h-[42px] shrink-0 items-center gap-1.5 rounded-[10px] border px-3 text-[12.5px] font-semibold ${category === 'all' ? 'border-[#111] bg-[#111] text-white' : 'border-neutral-200 bg-white'}`}
                                    >
                                        <Grid2X2 className="size-4" /> All
                                    </button>
                                    {catalog.categories.map((item) => (
                                        <button
                                            key={item.id}
                                            type="button"
                                            onClick={() => setCategory(item.id)}
                                            className={`inline-flex h-[42px] shrink-0 items-center gap-1.5 rounded-[10px] border px-3 text-[12.5px] font-semibold ${category === item.id ? 'border-[#111] bg-[#111] text-white' : 'border-neutral-200 bg-white'}`}
                                        >
                                            <CategoryIcon
                                                iconKey={item.icon_key}
                                            />
                                            {item.name}
                                        </button>
                                    ))}
                                </div>
                                <label className="relative min-w-[190px] flex-1 sm:max-w-[240px]">
                                    <Search className="absolute top-1/2 left-3 size-4 -translate-y-1/2 text-neutral-400" />
                                    <input
                                        value={productSearch}
                                        onChange={(event) =>
                                            setProductSearch(event.target.value)
                                        }
                                        placeholder="Search products"
                                        className="h-11 w-full rounded-[11px] border border-neutral-200 bg-neutral-50 pr-3 pl-9 text-base outline-none"
                                    />
                                </label>
                            </div>
                            <div className="min-h-0 flex-1 overflow-y-auto bg-neutral-50 p-3.5">
                                {filteredProducts.length ? (
                                    <div className="grid grid-cols-2 gap-2.5 sm:grid-cols-3 lg:grid-cols-4">
                                        {filteredProducts.map((product) => {
                                            const unavailable =
                                                !product.is_available;
                                            return (
                                                <button
                                                    key={product.id}
                                                    type="button"
                                                    disabled={unavailable}
                                                    onClick={() => {
                                                        setAddingProduct(true);
                                                        setActive({
                                                            key: createClientUuid(),
                                                            product,
                                                            quantity: 1,
                                                            notes: '',
                                                            modifiers: [],
                                                        });
                                                    }}
                                                    className="flex min-w-0 flex-col items-start gap-2 rounded-[14px] border border-neutral-200 bg-white p-2.5 text-left shadow-sm transition hover:border-neutral-950 disabled:cursor-not-allowed disabled:opacity-55"
                                                >
                                                    <span className="flex aspect-3/2 w-full items-center justify-center overflow-hidden rounded-[10px] bg-neutral-100">
                                                        {product.image_url ? (
                                                            <PosProductMedia
                                                                product={
                                                                    product
                                                                }
                                                            />
                                                        ) : (
                                                            <ImageOff className="size-7 text-neutral-300" />
                                                        )}
                                                    </span>
                                                    <strong className="line-clamp-2 text-sm leading-[1.3]">
                                                        {product.name}
                                                    </strong>
                                                    <span className="text-sm font-bold text-red-700 tabular-nums">
                                                        {pesos(
                                                            product.effective_price,
                                                        )}
                                                    </span>
                                                    {(unavailable ||
                                                        product.stock_status ===
                                                            'low_stock') && (
                                                        <span
                                                            className={`rounded-full px-2 py-1 text-[10px] font-bold ${unavailable ? 'bg-neutral-100 text-neutral-500' : 'bg-amber-50 text-amber-800'}`}
                                                        >
                                                            {stockAvailabilityLabel(
                                                                product,
                                                            )}
                                                        </span>
                                                    )}
                                                </button>
                                            );
                                        })}
                                    </div>
                                ) : (
                                    <div className="flex flex-col items-center gap-2 py-12 text-center">
                                        <Search className="size-8 text-neutral-300" />
                                        <strong>No products match</strong>
                                        <p className="text-xs text-neutral-500">
                                            Try another category or clear the
                                            search.
                                        </p>
                                    </div>
                                )}
                            </div>
                            <footer className="flex shrink-0 items-center justify-between gap-3 border-t border-neutral-200 bg-white p-3.5">
                                <span>
                                    <span className="block text-[11px] text-neutral-500">
                                        {lines.reduce(
                                            (count, line) =>
                                                count + line.quantity,
                                            0,
                                        )}{' '}
                                        items on this transaction
                                    </span>
                                    <strong className="text-[15px] tabular-nums">
                                        {pesos(total)}
                                    </strong>
                                </span>
                                <button
                                    type="button"
                                    onClick={() => setScreen('edit')}
                                    className="inline-flex h-[52px] items-center gap-2 rounded-xl bg-[#111] px-5 text-[14.5px] font-semibold text-white"
                                >
                                    <Check className="size-4" /> Done
                                </button>
                            </footer>
                        </>
                    )}
                </DialogContent>
            </Dialog>
            {active && (
                <PosProductDialog
                    product={active.product}
                    initial={active}
                    onClose={() => setActive(null)}
                    onRemove={() => {
                        setLines((current) =>
                            current.filter((line) => line.key !== active.key),
                        );
                        setActive(null);
                        setScreen('edit');
                    }}
                    onSave={(saved) => {
                        setLines((current) =>
                            current.some((line) => line.key === saved.key)
                                ? current.map((line) =>
                                      line.key === saved.key
                                          ? {
                                                ...saved,
                                                existingOrderItemId:
                                                    line.existingOrderItemId,
                                                committedUnitPrice:
                                                    line.committedUnitPrice,
                                                committedModifierKey:
                                                    line.committedModifierKey,
                                            }
                                          : line,
                                  )
                                : [...current, saved],
                        );
                        setActive(null);
                        if (addingProduct) setScreen('edit');
                    }}
                />
            )}
        </>
    );
}

function EditLineRow({
    line,
    onChange,
    onEdit,
    onRemove,
}: {
    line: EditLine;
    onChange: (line: EditLine) => void;
    onEdit: () => void;
    onRemove: () => void;
}) {
    const chosen = selectedModifierDetails(line);
    return (
        <div className="grid grid-cols-[minmax(0,1fr)_44px] items-center gap-3 border-b border-neutral-100 p-3 last:border-b-0 sm:grid-cols-[minmax(0,1fr)_128px_72px_44px]">
            <button
                type="button"
                onClick={onEdit}
                className="min-w-0 space-y-1 text-left"
                aria-label={`Edit ${line.product.name}`}
            >
                <p className="text-[13px] font-semibold">
                    {chosen.size ? `${chosen.size} ` : ''}
                    {line.product.name}
                </p>
                {chosen.standard.length > 0 && (
                    <p className="text-[11px] text-neutral-500">
                        {chosen.standard.join(' · ')}
                    </p>
                )}
                {chosen.instructions.length > 0 && (
                    <p className="w-fit rounded-md bg-amber-50 px-2 py-1 text-[10.5px] text-amber-800">
                        Instructions: {chosen.instructions.join(', ')}
                    </p>
                )}
                {line.notes && (
                    <p className="w-fit rounded-md bg-orange-50 px-2 py-1 text-[10.5px] text-orange-800">
                        Note: {line.notes}
                    </p>
                )}
            </button>
            <div className="col-span-2 row-start-2 flex w-32 shrink-0 overflow-hidden rounded-[10px] border border-neutral-300 sm:col-span-1 sm:row-auto">
                <button
                    type="button"
                    aria-label="Decrease quantity"
                    disabled={line.quantity <= 1}
                    onClick={() =>
                        onChange({ ...line, quantity: line.quantity - 1 })
                    }
                    className="inline-flex h-11 flex-1 items-center justify-center disabled:opacity-35"
                >
                    <Minus className="size-4" />
                </button>
                <strong className="inline-flex h-11 w-9 shrink-0 items-center justify-center border-x border-neutral-200 text-sm tabular-nums">
                    {line.quantity}
                </strong>
                <button
                    type="button"
                    aria-label="Increase quantity"
                    onClick={() =>
                        onChange({ ...line, quantity: line.quantity + 1 })
                    }
                    className="inline-flex h-11 flex-1 items-center justify-center"
                >
                    <Plus className="size-4" />
                </button>
            </div>
            <strong className="col-start-1 row-start-3 text-left text-[13px] tabular-nums sm:col-start-auto sm:row-auto sm:text-right">
                {pesos(editLineCents(line))}
            </strong>
            <button
                type="button"
                aria-label="Remove item"
                onClick={onRemove}
                className="col-start-2 row-start-1 inline-flex size-11 items-center justify-center rounded-[10px] border border-neutral-200 text-red-700 sm:col-start-auto sm:row-auto"
            >
                <Trash2 className="size-4" />
            </button>
        </div>
    );
}

function BalanceResolutionDialog({
    detail,
    onLater,
    onNow,
}: {
    detail: Detail;
    onLater: () => void;
    onNow: () => void;
}) {
    const paid = Number(detail.amount_paid) - Number(detail.adjustment_total);
    return (
        <Dialog open onOpenChange={(value) => !value && onLater()}>
            <DialogContent className="pos-surface max-w-md p-5">
                <span className="inline-flex size-11 items-center justify-center rounded-xl bg-amber-50 text-amber-700">
                    <AlertTriangle className="size-5" />
                </span>
                <DialogTitle>Additional payment required</DialogTitle>
                <DialogDescription className="text-[12.5px] leading-5">
                    #{detail.order_number} was edited after payment. Only the
                    difference is due; the amount already paid is not charged
                    again.
                </DialogDescription>
                <div className="overflow-hidden rounded-xl border border-neutral-200">
                    <MoneyRow
                        label="Originally paid"
                        value={pesos(paid.toFixed(2))}
                    />
                    <MoneyRow
                        label="Updated total"
                        value={pesos(detail.total)}
                    />
                    <MoneyRow
                        label="Additional balance"
                        value={pesos(detail.outstanding)}
                        tone="amber"
                        strong
                    />
                </div>
                <div className="grid grid-cols-2 gap-2">
                    <button
                        type="button"
                        onClick={onLater}
                        className="h-12 rounded-xl border border-neutral-400 text-sm font-semibold"
                    >
                        Pay later
                    </button>
                    <button
                        type="button"
                        onClick={onNow}
                        className="inline-flex h-12 items-center justify-center gap-2 rounded-xl bg-[#111] text-sm font-semibold text-white"
                    >
                        <CreditCard className="size-4" /> Pay now
                    </button>
                </div>
            </DialogContent>
        </Dialog>
    );
}

function SettlementDialog({
    detail,
    tables,
    onClose,
    onPaid,
}: {
    detail: Detail;
    tables: BranchTable[];
    onClose: () => void;
    onPaid: (detail: Detail) => void;
}) {
    const [processing, setProcessing] = useState(false);
    const [error, setError] = useState('');
    const idempotencyKey = useMemo(() => createClientUuid(), [detail.id]);
    const saved: OrderSummary = {
        id: detail.id,
        order_number: detail.order_number,
        reference_number: detail.reference_number,
        order_type: detail.order_type,
        table_name: detail.table_name,
        customer_label: detail.customer_label,
        subtotal: detail.outstanding,
        total: detail.outstanding,
        items: detail.items.map((item) => ({
            id: item.id,
            name: item.name,
            size_prefix: item.size_prefix,
            display_name: item.display_name,
            unit_price: item.unit_price,
            quantity: item.quantity,
            line_total: item.line_total,
            notes: item.notes,
            modifiers: item.modifiers.map((modifier, index) => ({
                id: `${item.id}-${index}`,
                group_name: modifier.group_name,
                semantic_role: modifier.semantic_role,
                name: modifier.name,
                price_delta: modifier.price_delta,
                quantity: 1,
            })),
        })),
    };
    async function pay(payment: PaymentInput) {
        setProcessing(true);
        setError('');
        try {
            await http.getClient().request({
                ...settle(detail.id),
                data: { ...payment, idempotency_key: idempotencyKey },
                headers: { Accept: 'application/json' },
            });
            const response = await http.getClient().request({
                ...show(detail.id),
                headers: { Accept: 'application/json' },
            });
            toast.success('Balance paid.');
            onPaid(
                (JSON.parse(response.data) as { transaction: Detail })
                    .transaction,
            );
        } catch {
            setError(
                'Payment could not be completed. Confirm the amount and try again.',
            );
        } finally {
            setProcessing(false);
        }
    }
    return (
        <Dialog open onOpenChange={(value) => !value && onClose()}>
            <DialogContent className={`${posDialogClass} pos-payment-dialog`}>
                <div className="shrink-0 border-b border-neutral-200 px-4 py-3.5 pr-14">
                    <DialogTitle className="text-[15px] font-bold">
                        {detail.payment_status === 'unpaid'
                            ? 'Take payment'
                            : 'Settle balance'}{' '}
                        #{detail.order_number}
                    </DialogTitle>
                    <DialogDescription className="mt-1 text-xs text-neutral-500">
                        Balance due {pesos(detail.outstanding)}. This payment
                        collects the exact delta only.
                    </DialogDescription>
                </div>
                <PosPaymentPreview
                    orderType={detail.order_type}
                    lines={[]}
                    saved={saved}
                    orderNumber={detail.order_number}
                    tables={tables}
                    customerLabel={detail.customer_label ?? ''}
                    tableId=""
                    onCustomerChange={() => undefined}
                    onTableChange={() => undefined}
                    onConfirm={(payment) => void pay(payment)}
                    processing={processing}
                    error={error}
                    attempt={null}
                />
            </DialogContent>
        </Dialog>
    );
}

function ReceiptDialog({
    detail,
    onClose,
}: {
    detail: Detail;
    onClose: () => void;
}) {
    return (
        <Dialog open onOpenChange={(value) => !value && onClose()}>
            <DialogContent className="pos-surface flex h-[92dvh] max-w-lg flex-col gap-0 overflow-hidden p-0 max-sm:h-dvh max-sm:max-h-dvh max-sm:max-w-full max-sm:rounded-none">
                <DialogTitle className="sr-only">
                    Receipt #{detail.order_number}
                </DialogTitle>
                <DialogDescription className="sr-only">
                    Canonical receipt print and QR view.
                </DialogDescription>
                <PosPaid
                    receipt={detail.receipt}
                    showReceipt
                    onReceipt={() => undefined}
                    onBack={onClose}
                    onNewOrder={onClose}
                    showNewOrderAction={false}
                />
            </DialogContent>
        </Dialog>
    );
}

function truthfulCustomer(
    item: Pick<Summary, 'customer_label' | 'table_name'>,
) {
    return item.customer_label || item.table_name || null;
}

function formatManila(value: string) {
    const date = new Date(value);
    return {
        date: new Intl.DateTimeFormat('en-US', {
            timeZone: 'Asia/Manila',
            month: 'short',
            day: '2-digit',
            year: 'numeric',
        }).format(date),
        time: new Intl.DateTimeFormat('en-US', {
            timeZone: 'Asia/Manila',
            hour: 'numeric',
            minute: '2-digit',
        }).format(date),
    };
}

function titleCase(value: string) {
    return value
        .replaceAll('_', ' ')
        .replace(/\b\w/g, (letter) => letter.toUpperCase());
}

function todayKey() {
    return new Intl.DateTimeFormat('en-CA', {
        timeZone: 'Asia/Manila',
    }).format(new Date());
}

function parseDateKey(value: string) {
    const [year, month, day] = value.split('-').map(Number);
    return new Date(year, month - 1, day);
}

function dateKey(date: Date) {
    return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
}

function startOfMonthKey(value: string) {
    const date = parseDateKey(value);
    return dateKey(new Date(date.getFullYear(), date.getMonth(), 1));
}

function shiftMonth(value: string, amount: number) {
    const date = parseDateKey(value);
    return dateKey(new Date(date.getFullYear(), date.getMonth() + amount, 1));
}

function monthTitle(value: string) {
    return new Intl.DateTimeFormat('en-US', {
        month: 'long',
        year: 'numeric',
    }).format(parseDateKey(value));
}

function calendarDays(value: string) {
    const base = parseDateKey(value);
    const first = new Date(base.getFullYear(), base.getMonth(), 1);
    const offset = (first.getDay() + 6) % 7;
    return Array.from({ length: 42 }, (_, index) => {
        const date = new Date(
            base.getFullYear(),
            base.getMonth(),
            1 - offset + index,
        );
        return {
            key: dateKey(date),
            day: date.getDate(),
            current: date.getMonth() === base.getMonth(),
        };
    });
}

function formatShortDate(value: string) {
    return new Intl.DateTimeFormat('en-US', {
        weekday: 'short',
        day: '2-digit',
        month: 'short',
    }).format(parseDateKey(value));
}

function shortRange(from?: string, to?: string) {
    if (!from) return 'Custom';
    const first = parseDateKey(from);
    const second = to ? parseDateKey(to) : first;
    const month = new Intl.DateTimeFormat('en-US', { month: 'short' });
    if (from === to || !to) return `${first.getDate()} ${month.format(first)}`;
    if (first.getMonth() === second.getMonth()) {
        return `${first.getDate()}–${second.getDate()} ${month.format(first)}`;
    }
    return `${first.getDate()} ${month.format(first)}–${second.getDate()} ${month.format(second)}`;
}

function modifierKey(modifiers: CartLine['modifiers']) {
    return modifiers
        .map((modifier) => modifier.option_id)
        .sort()
        .join('|');
}

function editLineCents(line: EditLine): bigint {
    if (
        line.committedUnitPrice &&
        line.committedModifierKey === modifierKey(line.modifiers)
    ) {
        return (
            BigInt(Math.round(Number(line.committedUnitPrice) * 100)) *
            BigInt(line.quantity)
        );
    }
    return lineCents(line);
}

function centsDecimal(value: bigint): string {
    return `${value / 100n}.${String(value % 100n).padStart(2, '0')}`;
}

function selectedModifierDetails(line: EditLine) {
    const groups = line.product.modifier_groups ?? [];
    const selected = groups.flatMap((group) =>
        group.options
            .filter((option) =>
                line.modifiers.some(
                    (modifier) => modifier.option_id === option.id,
                ),
            )
            .map((option) => ({
                ...option,
                role: group.semantic_role,
                groupName: group.name,
            })),
    );
    return {
        size: selected.find((option) => option.role === 'size')?.name ?? null,
        instructions: selected
            .filter((option) => option.role === 'instruction')
            .map((option) => option.name),
        standard: selected
            .filter(
                (option) =>
                    option.role !== 'size' && option.role !== 'instruction',
            )
            .map((option) => `${option.groupName}: ${option.name}`),
    };
}
