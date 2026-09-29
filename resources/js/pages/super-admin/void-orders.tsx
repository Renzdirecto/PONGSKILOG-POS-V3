import { Form, Head, router } from '@inertiajs/react';
import {
    CalendarClock,
    CheckCircle2,
    ChevronLeft,
    ChevronRight,
    CreditCard,
    KeyRound,
    PackageCheck,
    ReceiptText,
    Search,
    ShieldBan,
    ShieldCheck,
    Store,
    UserRound,
} from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
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
    DialogTitle,
} from '@/components/ui/dialog';
import { useAuditRealtimeRefresh } from '@/hooks/use-audit-realtime-refresh';
import { pesos } from '@/lib/pos-money';
import { update as updateVoidPin } from '@/routes/workspaces/void-orders/pin';
import { voidOrders } from '@/routes/workspaces';

type Person = { id: number; name: string; email: string };
type VoidOrder = {
    id: string;
    order_number: string;
    reference_number: string;
    customer_label: string | null;
    table_name: string | null;
    order_type: 'dine_in' | 'take_out';
    payment_status: 'paid' | 'unpaid' | 'partial';
    payment_method: 'cash' | 'cashless' | 'split' | null;
    total: string;
    original_total: string | null;
    amount_paid: string;
    adjustment_total: string;
    outstanding: string;
    committed_at: string;
    voided_at: string | null;
    items: {
        id: string;
        display_name: string;
        quantity: number;
        unit_price: string;
        line_total: string;
        notes: string | null;
        modifiers: {
            group_name: string;
            name: string;
            semantic_role: string;
            price_delta: string;
        }[];
    }[];
    payment_groups: {
        id: string;
        method: 'cash' | 'cashless' | 'split';
        amount: string;
        paid_at: string;
        cashier: string | null;
    }[];
    inventory_restorations: {
        product_name: string | null;
        quantity_restored: number;
    }[];
};
type VoidRow = {
    id: string;
    created_at: string;
    branch: { id: string; name: string; code: string } | null;
    order: VoidOrder | null;
    initiated_by: Person | null;
    authorized_by: Person | null;
    reason_code: string;
    reason_label: string;
    reason_text: string | null;
    authorization_method: string;
    audit: { id: string; created_at: string } | null;
};
type PageLink = { url: string | null; label: string; active: boolean };
type PinStatus = { configured_at: string; configured_by: Person | null } | null;
type Props = {
    voids: { data: VoidRow[]; links: PageLink[]; total: number };
    filters: Record<string, string>;
    branches: { id: string; name: string; code: string }[];
    users: Person[];
    pinStatus: PinStatus;
};

const reasons: [string, string][] = [
    ['wrong_item', 'Wrong item'],
    ['customer_cancelled', 'Customer cancelled'],
    ['duplicate_transaction', 'Duplicate transaction'],
    ['price_or_quantity_error', 'Price / quantity error'],
    ['other', 'Other'],
];
const dateTime = new Intl.DateTimeFormat('en-PH', {
    dateStyle: 'medium',
    timeStyle: 'short',
    timeZone: 'Asia/Manila',
});

export default function VoidOrders({
    voids,
    filters,
    branches,
    users,
    pinStatus,
}: Props) {
    const [selected, setSelected] = useState<VoidRow | null>(null);
    const [search, setSearch] = useState(filters.search ?? '');
    const realtimeProps = useMemo(() => ['voids', 'pinStatus'], []);
    useAuditRealtimeRefresh(realtimeProps);
    const apply = (next: Record<string, string>) => {
        const query = Object.fromEntries(
            Object.entries({ ...filters, ...next }).filter(
                ([, value]) => value,
            ),
        );

        router.get(voidOrders(), query, {
            preserveScroll: true,
            preserveState: true,
            replace: true,
        });
    };

    useEffect(() => {
        if (search === (filters.search ?? '')) {
            return;
        }

        const timer = window.setTimeout(() => apply({ search }), 350);

        return () => window.clearTimeout(timer);
    }, [search, filters.search]);

    return (
        <>
            <Head title="Void orders" />
            <OwnerPage
                title="Void orders"
                description="Set the approval PIN and monitor every voided sale in one consistent Super Admin workspace."
            >
                <section className="grid gap-3 xl:grid-cols-[minmax(0,1.6fr)_minmax(340px,0.9fr)]">
                    <VoidRegister
                        voids={voids}
                        filters={filters}
                        branches={branches}
                        users={users}
                        search={search}
                        setSearch={setSearch}
                        apply={apply}
                        onSelect={setSelected}
                    />
                    <PinPanel pinStatus={pinStatus} />
                </section>
            </OwnerPage>
            {selected && (
                <VoidDetail
                    record={selected}
                    onClose={() => setSelected(null)}
                />
            )}
        </>
    );
}

function VoidRegister({
    voids,
    filters,
    branches,
    users,
    search,
    setSearch,
    apply,
    onSelect,
}: {
    voids: Props['voids'];
    filters: Record<string, string>;
    branches: Props['branches'];
    users: Person[];
    search: string;
    setSearch: (value: string) => void;
    apply: (next: Record<string, string>) => void;
    onSelect: (record: VoidRow) => void;
}) {
    const clear = () => {
        setSearch('');
        router.get(voidOrders(), {}, { preserveScroll: true, replace: true });
    };
    return (
        <div className="flex min-w-0 flex-col gap-3">
            <section className={`${ownerPanelClass} p-3 md:p-4`}>
                <div className="flex flex-col gap-3">
                    <div className="flex flex-col gap-2 sm:flex-row">
                        <label className="relative min-w-0 flex-1">
                            <Search className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-neutral-400" />
                            <input
                                value={search}
                                onChange={(event) =>
                                    setSearch(event.target.value)
                                }
                                placeholder="Search order number, reference, or staff"
                                className={`${ownerControlClass} w-full pl-10`}
                            />
                        </label>
                        <button
                            type="button"
                            onClick={clear}
                            className={ownerSecondaryActionClass}
                        >
                            Clear
                        </button>
                    </div>
                    <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                        <Select
                            label="Branch"
                            value={filters.branch_id ?? ''}
                            options={branches.map((branch) => [
                                branch.id,
                                `${branch.name} · ${branch.code}`,
                            ])}
                            onChange={(branch_id) => apply({ branch_id })}
                        />
                        <Select
                            label="Initiated by"
                            value={filters.initiated_by_user_id ?? ''}
                            options={users.map((user) => [
                                String(user.id),
                                user.name,
                            ])}
                            onChange={(initiated_by_user_id) =>
                                apply({ initiated_by_user_id })
                            }
                        />
                        <Select
                            label="PIN configured by"
                            value={filters.authorized_by_user_id ?? ''}
                            options={users.map((user) => [
                                String(user.id),
                                user.name,
                            ])}
                            onChange={(authorized_by_user_id) =>
                                apply({ authorized_by_user_id })
                            }
                        />
                        <Select
                            label="Reason"
                            value={filters.reason_code ?? ''}
                            options={reasons}
                            onChange={(reason_code) => apply({ reason_code })}
                        />
                        <label className="grid gap-1 text-[11px] font-semibold text-neutral-600">
                            Date
                            <input
                                type="date"
                                value={filters.date ?? ''}
                                onChange={(event) =>
                                    apply({ date: event.target.value })
                                }
                                className={ownerControlClass}
                            />
                        </label>
                    </div>
                    <p className="text-[11px] text-neutral-500">
                        Search and filters update automatically. New voids
                        appear live without reloading the page.
                    </p>
                </div>
            </section>
            <section className={`${ownerPanelClass} overflow-hidden`}>
                <div className="flex items-center justify-between gap-3 border-b border-neutral-100 px-4 py-3">
                    <div>
                        <p className="text-sm font-semibold">Void register</p>
                        <p className="text-xs text-neutral-500">
                            {voids.total} recorded voids · live updates enabled
                        </p>
                    </div>
                    <OwnerStatusBadge tone="red">Voided</OwnerStatusBadge>
                </div>
                {voids.data.length === 0 ? (
                    <div className="px-5 py-16 text-center text-sm text-neutral-500">
                        No Void records match these filters.
                    </div>
                ) : (
                    <div className="divide-y divide-neutral-100">
                        {voids.data.map((record) => (
                            <button
                                type="button"
                                key={record.id}
                                onClick={() => onSelect(record)}
                                className="grid w-full gap-2 px-4 py-3.5 text-left transition hover:bg-red-50/40 md:grid-cols-[145px_1fr_1fr_auto] md:items-center"
                            >
                                <time className="text-xs text-neutral-500 tabular-nums">
                                    {dateTime.format(
                                        new Date(record.created_at),
                                    )}
                                </time>
                                <span>
                                    <strong className="block text-sm">
                                        #
                                        {record.order?.order_number ??
                                            'Deleted order'}
                                    </strong>
                                    <span className="block text-xs text-neutral-500">
                                        {record.branch?.name ?? 'No branch'} ·{' '}
                                        {record.reason_label}
                                    </span>
                                </span>
                                <span className="text-xs">
                                    <strong className="block truncate">
                                        Voided by:{' '}
                                        {record.initiated_by?.name ?? 'Unknown'}
                                    </strong>
                                    <span className="block truncate text-neutral-500">
                                        PIN set by:{' '}
                                        {record.authorized_by?.name ??
                                            'Unknown'}
                                    </span>
                                </span>
                                <OwnerStatusBadge tone="red">
                                    View
                                </OwnerStatusBadge>
                            </button>
                        ))}
                    </div>
                )}
                <Pagination links={voids.links} />
            </section>
        </div>
    );
}

function PinPanel({ pinStatus }: { pinStatus: PinStatus }) {
    return (
        <section className={`${ownerPanelClass} h-fit p-4`}>
            <div className="flex items-start gap-3">
                <span className="flex size-10 shrink-0 items-center justify-center rounded-xl bg-neutral-950 text-white">
                    <KeyRound className="size-5" />
                </span>
                <div>
                    <h2 className="text-base font-bold">Void approval PIN</h2>
                    <p className="mt-1 text-xs leading-5 text-neutral-500">
                        Super Admin sets the 4-digit PIN here. Cashiers use it
                        only when confirming a Void.
                    </p>
                </div>
            </div>
            <div className="my-4 rounded-xl border border-neutral-200 bg-neutral-50 p-3 text-xs">
                <p className="font-semibold">
                    {pinStatus ? 'PIN is active' : 'PIN is not configured'}
                </p>
                <p className="mt-1 text-neutral-500">
                    {pinStatus
                        ? `Last set by ${pinStatus.configured_by?.name ?? 'a removed user'} · ${dateTime.format(new Date(pinStatus.configured_at))}`
                        : 'Set a PIN before staff can Void an order.'}
                </p>
            </div>
            <Form
                {...updateVoidPin.form()}
                resetOnSuccess={['pin', 'pin_confirmation']}
                className="space-y-3"
            >
                {({ errors, processing }) => (
                    <>
                        <label className="grid gap-1.5 text-xs font-semibold">
                            New 4-digit PIN
                            <input
                                name="pin"
                                type="password"
                                inputMode="numeric"
                                autoComplete="new-password"
                                pattern="[0-9]{4}"
                                maxLength={4}
                                required
                                className={`${ownerControlClass} text-center text-lg tracking-[0.5em]`}
                            />
                        </label>
                        <label className="grid gap-1.5 text-xs font-semibold">
                            Confirm PIN
                            <input
                                name="pin_confirmation"
                                type="password"
                                inputMode="numeric"
                                autoComplete="new-password"
                                pattern="[0-9]{4}"
                                maxLength={4}
                                required
                                className={`${ownerControlClass} text-center text-lg tracking-[0.5em]`}
                            />
                        </label>
                        {(errors.pin || errors.pin_confirmation) && (
                            <p
                                role="alert"
                                className="rounded-lg bg-red-50 p-2 text-xs text-red-800"
                            >
                                {errors.pin ?? errors.pin_confirmation}
                            </p>
                        )}
                        <button
                            type="submit"
                            disabled={processing}
                            className={`${ownerPrimaryActionClass} w-full disabled:opacity-50`}
                        >
                            {processing
                                ? 'Saving…'
                                : pinStatus
                                  ? 'Change Void PIN'
                                  : 'Set Void PIN'}
                        </button>
                    </>
                )}
            </Form>
            <p className="mt-3 flex gap-1.5 text-[11px] leading-4 text-neutral-500">
                <CheckCircle2 className="mt-0.5 size-3.5 shrink-0 text-emerald-600" />
                The PIN is stored securely and never shown in the audit trail.
            </p>
        </section>
    );
}
function Select({
    label,
    value,
    options,
    onChange,
}: {
    label: string;
    value: string;
    options: [string, string][];
    onChange: (value: string) => void;
}) {
    return (
        <label className="grid gap-1 text-[11px] font-semibold text-neutral-600">
            {label}
            <select
                value={value}
                onChange={(event) => onChange(event.target.value)}
                className={ownerControlClass}
            >
                <option value="">All</option>
                {options.map(([optionValue, optionLabel]) => (
                    <option key={optionValue} value={optionValue}>
                        {optionLabel}
                    </option>
                ))}
            </select>
        </label>
    );
}
function VoidDetail({
    record,
    onClose,
}: {
    record: VoidRow;
    onClose: () => void;
}) {
    const order = record.order;

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="max-h-[82dvh] w-[calc(100vw-2rem)] gap-0 overflow-hidden border-0 p-0 shadow-2xl sm:max-w-5xl xl:max-w-6xl">
                <div className="border-b border-red-100 bg-gradient-to-br from-red-50 via-white to-white px-5 py-5 sm:px-6">
                    <div className="flex items-start gap-3">
                        <span className="flex size-11 shrink-0 items-center justify-center rounded-2xl bg-red-600 text-white shadow-sm">
                            <ShieldBan className="size-5" />
                        </span>
                        <div className="min-w-0 flex-1">
                            <div className="flex flex-wrap items-center gap-2">
                                <DialogTitle className="text-xl font-bold tracking-tight">
                                    Void transaction #
                                    {order?.order_number ?? 'record'}
                                </DialogTitle>
                                <OwnerStatusBadge tone="red">
                                    Voided
                                </OwnerStatusBadge>
                            </div>
                            <DialogDescription className="mt-1 text-xs leading-5 text-neutral-600">
                                This sale is retained only as an immutable Super
                                Admin record. Receipt access is disabled.
                            </DialogDescription>
                        </div>
                    </div>
                </div>
                <div className="max-h-[calc(82dvh-112px)] space-y-4 overflow-y-auto bg-neutral-50/70 p-4 sm:p-6">
                    {order ? (
                        <>
                            <section className="grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
                                <DetailStat
                                    icon={ReceiptText}
                                    label="Reference"
                                    value={order.reference_number}
                                />
                                <DetailStat
                                    icon={Store}
                                    label="Branch"
                                    value={
                                        record.branch
                                            ? `${record.branch.name} · ${record.branch.code}`
                                            : 'Unknown'
                                    }
                                />
                                <DetailStat
                                    icon={UserRound}
                                    label="Customer / table"
                                    value={
                                        order.customer_label ||
                                        order.table_name ||
                                        'Walk-in'
                                    }
                                />
                                <DetailStat
                                    icon={CalendarClock}
                                    label="Voided"
                                    value={dateTime.format(
                                        new Date(record.created_at),
                                    )}
                                />
                            </section>

                            <section
                                className={`${ownerPanelClass} overflow-hidden`}
                            >
                                <div className="flex items-center justify-between border-b border-neutral-100 px-4 py-3">
                                    <div>
                                        <h3 className="text-sm font-bold">
                                            Order items
                                        </h3>
                                        <p className="text-xs text-neutral-500">
                                            {order.order_type === 'dine_in'
                                                ? 'Dine in'
                                                : 'Take out'}{' '}
                                            ·{' '}
                                            {order.items.reduce(
                                                (total, item) =>
                                                    total + item.quantity,
                                                0,
                                            )}{' '}
                                            item(s)
                                        </p>
                                    </div>
                                    <ReceiptText className="size-4 text-neutral-400" />
                                </div>
                                <div className="divide-y divide-neutral-100">
                                    {order.items.map((item) => (
                                        <div
                                            key={item.id}
                                            className="flex gap-3 px-4 py-3"
                                        >
                                            <span className="flex size-8 shrink-0 items-center justify-center rounded-lg bg-neutral-100 text-xs font-bold">
                                                {item.quantity}×
                                            </span>
                                            <div className="min-w-0 flex-1">
                                                <p className="text-sm font-semibold">
                                                    {item.display_name}
                                                </p>
                                                {item.modifiers.filter(
                                                    (modifier) =>
                                                        modifier.semantic_role !==
                                                        'size',
                                                ).length > 0 && (
                                                    <p className="mt-0.5 text-xs text-neutral-500">
                                                        {item.modifiers
                                                            .filter(
                                                                (modifier) =>
                                                                    modifier.semantic_role !==
                                                                    'size',
                                                            )
                                                            .map(
                                                                (modifier) =>
                                                                    modifier.name,
                                                            )
                                                            .join(' · ')}
                                                    </p>
                                                )}
                                                {item.notes && (
                                                    <p className="mt-1 text-xs text-neutral-500 italic">
                                                        Note: {item.notes}
                                                    </p>
                                                )}
                                                <p className="mt-1 text-[11px] text-neutral-400">
                                                    {pesos(item.unit_price)}{' '}
                                                    each
                                                </p>
                                            </div>
                                            <strong className="text-sm tabular-nums">
                                                {pesos(item.line_total)}
                                            </strong>
                                        </div>
                                    ))}
                                </div>
                                <div className="border-t border-neutral-200 bg-neutral-50 px-4 py-3">
                                    <MoneyRow
                                        label="Original total"
                                        value={pesos(
                                            order.original_total ?? order.total,
                                        )}
                                    />
                                    <MoneyRow
                                        label="Amount paid"
                                        value={pesos(order.amount_paid)}
                                    />
                                    {Number(order.adjustment_total) !== 0 && (
                                        <MoneyRow
                                            label="Adjustments"
                                            value={pesos(
                                                order.adjustment_total,
                                            )}
                                        />
                                    )}
                                    <div className="mt-2 flex items-center justify-between border-t border-neutral-200 pt-2 text-base font-bold">
                                        <span>Voided total</span>
                                        <span className="text-red-700 tabular-nums">
                                            {pesos(order.total)}
                                        </span>
                                    </div>
                                </div>
                            </section>

                            <div className="grid gap-4 lg:grid-cols-2">
                                <section className={`${ownerPanelClass} p-4`}>
                                    <div className="mb-3 flex items-center gap-2">
                                        <CreditCard className="size-4 text-neutral-500" />
                                        <h3 className="text-sm font-bold">
                                            Payment history
                                        </h3>
                                    </div>
                                    {order.payment_groups.length === 0 ? (
                                        <p className="text-xs text-neutral-500">
                                            No payment recorded.
                                        </p>
                                    ) : (
                                        <div className="space-y-2">
                                            {order.payment_groups.map(
                                                (payment) => (
                                                    <div
                                                        key={payment.id}
                                                        className="flex items-center justify-between rounded-xl border border-neutral-100 bg-neutral-50 px-3 py-2.5"
                                                    >
                                                        <div>
                                                            <p className="text-xs font-semibold capitalize">
                                                                {payment.method.replace(
                                                                    '_',
                                                                    ' ',
                                                                )}
                                                            </p>
                                                            <p className="text-[11px] text-neutral-500">
                                                                {payment.cashier ??
                                                                    'Unknown cashier'}{' '}
                                                                ·{' '}
                                                                {dateTime.format(
                                                                    new Date(
                                                                        payment.paid_at,
                                                                    ),
                                                                )}
                                                            </p>
                                                        </div>
                                                        <strong className="text-sm tabular-nums">
                                                            {pesos(
                                                                payment.amount,
                                                            )}
                                                        </strong>
                                                    </div>
                                                ),
                                            )}
                                        </div>
                                    )}
                                </section>
                                <section className={`${ownerPanelClass} p-4`}>
                                    <div className="mb-3 flex items-center gap-2">
                                        <PackageCheck className="size-4 text-neutral-500" />
                                        <h3 className="text-sm font-bold">
                                            Stock restoration
                                        </h3>
                                    </div>
                                    {order.inventory_restorations.length ===
                                    0 ? (
                                        <p className="text-xs text-neutral-500">
                                            No tracked stock was restored.
                                        </p>
                                    ) : (
                                        <div className="space-y-2">
                                            {order.inventory_restorations.map(
                                                (restoration, index) => (
                                                    <div
                                                        key={`${restoration.product_name}-${index}`}
                                                        className="flex justify-between rounded-xl bg-emerald-50 px-3 py-2 text-xs text-emerald-800"
                                                    >
                                                        <span>
                                                            {restoration.product_name ??
                                                                'Product'}
                                                        </span>
                                                        <strong>
                                                            +
                                                            {
                                                                restoration.quantity_restored
                                                            }
                                                        </strong>
                                                    </div>
                                                ),
                                            )}
                                        </div>
                                    )}
                                </section>
                            </div>
                        </>
                    ) : (
                        <div
                            className={`${ownerPanelClass} p-8 text-center text-sm text-neutral-500`}
                        >
                            The original order is no longer available.
                        </div>
                    )}

                    <section className="overflow-hidden rounded-[20px] border border-red-100 bg-white shadow-sm">
                        <div className="border-b border-red-100 bg-red-50/70 px-4 py-3">
                            <h3 className="text-sm font-bold text-red-900">
                                Void authorization
                            </h3>
                            <p className="mt-0.5 text-xs text-red-700">
                                Two-person control identities and reason
                            </p>
                        </div>
                        <dl className="divide-y divide-neutral-100 text-sm">
                            <Row label="Reason" value={record.reason_label} />
                            <Row
                                label="Details"
                                value={record.reason_text ?? '—'}
                            />
                            <Row
                                label="Initiated by"
                                value={
                                    record.initiated_by
                                        ? `${record.initiated_by.name} · ${record.initiated_by.email}`
                                        : 'Unknown'
                                }
                            />
                            <Row
                                label="Authorized by"
                                value={
                                    record.authorized_by
                                        ? `${record.authorized_by.name} · ${record.authorized_by.email}`
                                        : 'Unknown'
                                }
                            />
                            <Row
                                label="Authorization"
                                value="Super Admin Void PIN"
                            />
                            {record.audit && (
                                <Row
                                    label="Audit entry"
                                    value={`${record.audit.id.slice(0, 8).toUpperCase()} · ${dateTime.format(new Date(record.audit.created_at))}`}
                                />
                            )}
                        </dl>
                        <div className="flex gap-2 border-t border-neutral-100 bg-neutral-50 px-4 py-3 text-[11px] leading-4 text-neutral-500">
                            <ShieldCheck className="mt-0.5 size-4 shrink-0 text-emerald-600" />
                            The order, payment history, and both identities
                            remain preserved for audit. No receipt can be opened
                            or shared after a Void.
                        </div>
                    </section>
                </div>
            </DialogContent>
        </Dialog>
    );
}
function DetailStat({
    icon: Icon,
    label,
    value,
}: {
    icon: typeof ReceiptText;
    label: string;
    value: string;
}) {
    return (
        <div className="rounded-2xl border border-neutral-200 bg-white p-3 shadow-sm">
            <div className="mb-2 flex items-center gap-1.5 text-[10px] font-semibold tracking-wide text-neutral-500 uppercase">
                <Icon className="size-3.5" />
                {label}
            </div>
            <p className="truncate text-xs font-bold" title={value}>
                {value}
            </p>
        </div>
    );
}
function MoneyRow({ label, value }: { label: string; value: string }) {
    return (
        <div className="flex items-center justify-between py-1 text-xs">
            <span className="text-neutral-500">{label}</span>
            <span className="font-semibold tabular-nums">{value}</span>
        </div>
    );
}
function Row({ label, value }: { label: string; value: string }) {
    return (
        <div className="grid gap-1 px-4 py-3 sm:grid-cols-[150px_1fr] sm:gap-4">
            <dt className="text-xs text-neutral-500">{label}</dt>
            <dd className="text-xs font-semibold break-words sm:text-right">
                {value}
            </dd>
        </div>
    );
}
function Pagination({ links }: { links: PageLink[] }) {
    const previousUrl = links[0]?.url;
    const nextUrl = links.at(-1)?.url;
    return (
        <div className="flex justify-between border-t border-neutral-100 p-3">
            <button
                type="button"
                disabled={!previousUrl}
                onClick={() => previousUrl && router.get(previousUrl)}
                className={ownerSecondaryActionClass}
            >
                <ChevronLeft className="mr-1 inline size-4" /> Previous
            </button>
            <button
                type="button"
                disabled={!nextUrl}
                onClick={() => nextUrl && router.get(nextUrl)}
                className={ownerSecondaryActionClass}
            >
                Next <ChevronRight className="ml-1 inline size-4" />
            </button>
        </div>
    );
}
