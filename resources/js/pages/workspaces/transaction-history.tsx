import { Head, Link, http, router, usePage } from '@inertiajs/react';
import { CalendarDays, CreditCard, Eye, Grid2X2, List, Pencil, Printer, Search, ShieldBan } from 'lucide-react';
import { useMemo, useState } from 'react';
import { toast } from 'sonner';
import { PosPaymentPreview } from '@/components/pos-payment-preview';
import { PosProductDialog } from '@/components/pos-product-dialog';
import { TransactionInvoiceDialog } from '@/components/transaction-invoice-dialog';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogTitle } from '@/components/ui/dialog';
import { createClientUuid } from '@/lib/client-uuid';
import { pesos } from '@/lib/pos-money';
import { useBranchRealtimeRefresh } from '@/hooks/use-branch-realtime-refresh';
import { show, update } from '@/routes/pos/transactions';
import { store as settle } from '@/routes/pos/orders/settlements';
import { receiptShare } from '@/routes/pos/orders';
import { transactionHistory } from '@/routes/workspaces';
import type { BranchContext } from '@/types';
import type { CashierCatalog } from '@/types/catalog';
import type { BranchTable, CartLine, OrderSummary, PaymentInput } from '@/types/pos';

type Summary = {
    id: string; order_number: string; reference_number: string; customer_label: string | null;
    order_type: 'dine_in' | 'take_out'; table_name: string | null; kitchen_status: string;
    payment_status: 'paid' | 'unpaid' | 'partial'; payment_method: string | null; total: string;
    original_total: string | null; amount_paid: string; adjustment_total: string; outstanding: string;
    committed_at: string; edited_at: string | null; version: number; item_count: number; items_preview: string[]; can_edit: boolean;
};
type Detail = Summary & {
    can_edit: boolean; can_settle: boolean;
    items: { id: string; product_id: string; name: string; unit_price: string; quantity: number; line_total: string; notes: string | null; modifiers: { group_id: string; option_id: string; group_name: string; name: string; price_delta: string }[] }[];
    payment_groups: { id: string; method: string; context: string; amount: string; paid_at: string; cashier: string; payments: { id: string; method: 'cash' | 'cashless'; amount: string; amount_received: string | null; change_amount: string | null; invoice: { name: string; url: string } | null }[] }[];
    adjustments: { id: string; type: string; amount: string; reason: string | null; created_at: string; created_by: string }[];
};
type PageLink = { url: string | null; label: string; active: boolean };
type Props = {
    transactions: { data: Summary[]; from: number | null; to: number | null; total: number; links: PageLink[] };
    metrics: { total: number; paid: number; pending: number; balance: number; sales: string };
    filters: Record<string, string>;
    catalog: CashierCatalog;
    tables: BranchTable[];
};

export default function TransactionHistory({ transactions, metrics, filters, catalog, tables }: Props) {
    const branch = usePage<{ branchContext: BranchContext }>().props.branchContext.current;
    const [view, setView] = useState<'tiles' | 'list'>(() => (localStorage.getItem('transaction-history-view') === 'list' ? 'list' : 'tiles'));
    const [selected, setSelected] = useState<Detail | null>(null);
    const [loading, setLoading] = useState(false);
    const [editing, setEditing] = useState(false);
    const [settling, setSettling] = useState(false);
    const [invoicePayment, setInvoicePayment] = useState<Detail['payment_groups'][number]['payments'][number] | null>(null);
    const [searchText, setSearchText] = useState(filters.search ?? '');
    const manilaToday = new Intl.DateTimeFormat('en-CA', {
        timeZone: 'Asia/Manila',
    }).format(new Date());

    const refresh = () => router.reload({ only: ['transactions', 'metrics'] });
    useBranchRealtimeRefresh({
        branchId: branch?.id ?? '', channel: 'pos', events: ['order.committed', 'order.updated'],
        only: ['transactions', 'metrics'], debounceMs: 120,
    });

    function apply(next: Record<string, string | undefined>) {
        router.get(transactionHistory(), { ...filters, ...next }, { preserveState: true, preserveScroll: true, replace: true, only: ['transactions', 'metrics', 'filters'] });
    }
    async function openDetail(id: string) {
        setLoading(true);
        try {
            const response = await http.getClient().request({ ...show(id), headers: { Accept: 'application/json' } });
            setSelected((JSON.parse(response.data) as { transaction: Detail }).transaction);
        } catch {
            toast.error('Transaction details could not be loaded.');
        } finally { setLoading(false); }
    }
    async function reloadDetail() {
        if (selected) await openDetail(selected.id);
        refresh();
    }

    return (
        <div className="pos-surface min-h-full bg-[#f5f5f3] p-3 text-[#111] md:p-4">
            <Head title="Transaction history" />
            <header className="mb-3 flex flex-wrap items-center gap-3">
                <div className="mr-auto"><h2 className="text-xl font-black">Transaction history</h2><p className="text-xs text-neutral-500">Committed orders for {branch?.name}</p></div>
                <div className="flex rounded-xl border bg-white p-1">
                    {(['tiles', 'list'] as const).map((mode) => <button key={mode} onClick={() => { setView(mode); localStorage.setItem('transaction-history-view', mode); }} className={`flex min-h-10 items-center gap-2 rounded-lg px-3 text-xs font-bold ${view === mode ? 'bg-[#111] text-white' : 'text-neutral-500'}`}>{mode === 'tiles' ? <Grid2X2 className="size-4" /> : <List className="size-4" />}{mode === 'tiles' ? 'Tiled' : 'List'}</button>)}
                </div>
            </header>
            <section className="mb-3 grid grid-cols-2 gap-2 lg:grid-cols-5">
                <Metric label="All transactions" value={metrics.total} onClick={() => apply({ payment_status: undefined })} />
                <Metric label="Paid" value={metrics.paid} onClick={() => apply({ payment_status: 'paid' })} />
                <Metric label="Pending" value={metrics.pending} onClick={() => apply({ payment_status: 'pending' })} />
                <Metric label="With balance" value={metrics.balance} onClick={() => apply({ payment_status: 'balance' })} />
                <Metric label="Sales" value={pesos(metrics.sales)} />
            </section>
            <section className="mb-3 grid gap-2 rounded-2xl border bg-white p-3 md:grid-cols-7">
                <form className="relative md:col-span-2" onSubmit={(event) => { event.preventDefault(); apply({ search: searchText || undefined }); }}><Search className="absolute top-3 left-3 size-4 text-neutral-400" /><input value={searchText} onChange={(event) => setSearchText(event.target.value)} placeholder="Order, reference, or customer" className="min-h-11 w-full rounded-xl border bg-neutral-50 pr-3 pl-9 text-sm" /></form>
                <Filter value={filters.date ?? ''} onChange={(value) => value === 'custom' ? apply({ date: 'custom', from: manilaToday, to: manilaToday }) : apply({ date: value || undefined, from: undefined, to: undefined })} label="Date" options={[['', 'All dates'], ['today', 'Today'], ['yesterday', 'Yesterday'], ['week', 'This week'], ['month', 'This month'], ['custom', 'Custom range']]} />
                <Filter value={filters.kitchen_status ?? ''} onChange={(value) => apply({ kitchen_status: value || undefined })} label="Kitchen" options={[['', 'All kitchen'], ['kitchen', 'Kitchen'], ['preparing', 'Preparing'], ['ready', 'Ready'], ['done', 'Done']]} />
                <Filter value={filters.payment_status ?? ''} onChange={(value) => apply({ payment_status: value || undefined })} label="Payment" options={[['', 'All payments'], ['paid', 'Paid'], ['pending', 'Pending'], ['balance', 'With balance']]} />
                <Filter value={filters.order_type ?? ''} onChange={(value) => apply({ order_type: value || undefined })} label="Order type" options={[['', 'All types'], ['dine_in', 'Dine in'], ['take_out', 'Take out']]} />
                <Filter value={filters.payment_method ?? ''} onChange={(value) => apply({ payment_method: value || undefined })} label="Method" options={[['', 'All methods'], ['cash', 'Cash'], ['cashless', 'Cashless'], ['split', 'Split']]} />
                {filters.date === 'custom' && <div className="grid gap-2 md:col-span-7 md:grid-cols-2"><label className="text-[10px] font-bold text-neutral-500 uppercase">From<input type="date" value={filters.from ?? ''} onChange={(event) => apply({ from: event.target.value || undefined, to: filters.to || event.target.value || undefined })} className="mt-1 min-h-10 w-full rounded-lg border px-3 text-sm text-neutral-900" /></label><label className="text-[10px] font-bold text-neutral-500 uppercase">To<input type="date" value={filters.to ?? ''} min={filters.from} onChange={(event) => apply({ to: event.target.value || undefined })} className="mt-1 min-h-10 w-full rounded-lg border px-3 text-sm text-neutral-900" /></label></div>}
            </section>
            <p className="mb-2 text-xs text-neutral-500">Showing {transactions.from ?? 0}–{transactions.to ?? 0} of {transactions.total}</p>
            {transactions.data.length === 0 ? <div className="rounded-2xl border bg-white p-14 text-center"><CalendarDays className="mx-auto mb-3 size-8 text-neutral-400" /><h3 className="font-black">No matching transactions</h3><p className="text-sm text-neutral-500">Try changing the search or filters.</p></div> : (
                <div className={view === 'tiles' ? 'grid gap-3 lg:grid-cols-2 2xl:grid-cols-3' : 'space-y-2'}>{transactions.data.map((item) => <TransactionCard key={item.id} item={item} compact={view === 'list'} onDetails={() => void openDetail(item.id)} onEdit={() => { void openDetail(item.id).then(() => setEditing(true)); }} />)}</div>
            )}
            <nav className="mt-4 flex flex-wrap justify-center gap-1">{transactions.links.map((link) => link.url ? <Link key={link.label} href={link.url} preserveScroll preserveState only={['transactions', 'metrics']} className={`min-w-10 rounded-lg border px-3 py-2 text-center text-xs ${link.active ? 'bg-[#111] text-white' : 'bg-white'}`} dangerouslySetInnerHTML={{ __html: link.label }} /> : <span key={link.label} className="min-w-10 rounded-lg border px-3 py-2 text-center text-xs text-neutral-300" dangerouslySetInnerHTML={{ __html: link.label }} />)}</nav>

            <TransactionDetailDialog detail={selected} loading={loading} open={selected !== null && !editing && !settling} onClose={() => setSelected(null)} onEdit={() => setEditing(true)} onSettle={() => setSettling(true)} onInvoice={setInvoicePayment} />
            {selected && editing && <EditDialog detail={selected} catalog={catalog} tables={tables} onClose={() => setEditing(false)} onSaved={(detail) => { setSelected(detail); setEditing(false); refresh(); }} />}
            {selected && settling && <SettlementDialog detail={selected} tables={tables} onClose={() => setSettling(false)} onPaid={(detail) => { setSelected(detail); setSettling(false); refresh(); }} />}
            {invoicePayment && selected && <TransactionInvoiceDialog paymentId={invoicePayment.id} invoice={invoicePayment.invoice} open mutable={selected.can_edit} onClose={() => setInvoicePayment(null)} onChanged={() => void reloadDetail()} />}
        </div>
    );
}

function Metric({ label, value, onClick }: { label: string; value: string | number; onClick?: () => void }) {
    return <button type="button" onClick={onClick} disabled={!onClick} className="rounded-2xl border bg-white p-3 text-left disabled:cursor-default"><span className="text-[10px] font-bold tracking-wide text-neutral-500 uppercase">{label}</span><strong className="mt-1 block text-xl font-black">{value}</strong></button>;
}
function Filter({ value, onChange, label, options }: { value: string; onChange: (value: string) => void; label: string; options: [string, string][] }) {
    return <label className="text-[10px] font-bold tracking-wide text-neutral-500 uppercase">{label}<select value={value} onChange={(event) => onChange(event.target.value)} className="mt-1 min-h-9 w-full rounded-lg border bg-white px-2 text-xs font-semibold text-neutral-900 normal-case">{options.map(([key, text]) => <option key={key} value={key}>{text}</option>)}</select></label>;
}
function TransactionCard({ item, compact, onDetails, onEdit }: { item: Summary; compact: boolean; onDetails: () => void; onEdit: () => void }) {
    return <article className={`rounded-2xl border bg-white ${compact ? 'flex flex-wrap items-center gap-3 p-3' : 'p-4'}`}>
        <div className="min-w-0 flex-1"><div className="flex flex-wrap items-center gap-2"><strong className="text-base">#{item.order_number}</strong><Chip>{item.order_type === 'dine_in' ? 'Dine in' : 'Take out'}</Chip><Chip>{item.payment_status === 'partial' ? 'Balance' : item.payment_status}</Chip>{item.edited_at && <Chip>Edited</Chip>}</div><p className="mt-1 truncate text-xs text-neutral-500">{item.customer_label ?? item.table_name ?? 'No customer label'} · {new Date(item.committed_at).toLocaleString()}</p>{!compact && <p className="mt-3 text-sm text-neutral-700">{item.items_preview.join(', ')}</p>}</div>
        <div className={compact ? 'text-right' : 'mt-4 flex items-end justify-between'}><div><strong className="block text-lg text-red-700">{pesos(item.total)}</strong>{Number(item.outstanding) > 0 && <span className="text-xs font-bold text-amber-700">Balance {pesos(item.outstanding)}</span>}</div><div className="flex gap-1"><Button size="sm" variant="outline" onClick={onDetails}><Eye /> Details</Button><Button size="sm" variant="outline" disabled={!item.can_edit} title={item.can_edit ? 'Edit order' : 'Earlier Store Sessions are read-only'} onClick={onEdit}><Pencil /> Edit</Button><Button size="sm" disabled title="Available in Phase 13"><ShieldBan /> Void · Phase 13</Button></div></div>
    </article>;
}
function Chip({ children }: { children: React.ReactNode }) { return <span className="rounded-full bg-neutral-100 px-2 py-1 text-[10px] font-bold capitalize text-neutral-600">{children}</span>; }

function TransactionDetailDialog({ detail, loading, open, onClose, onEdit, onSettle, onInvoice }: { detail: Detail | null; loading: boolean; open: boolean; onClose: () => void; onEdit: () => void; onSettle: () => void; onInvoice: (payment: Detail['payment_groups'][number]['payments'][number]) => void }) {
    async function share() {
        if (!detail) return;
        try {
            const response = await http.getClient().request({ ...receiptShare(detail.id), headers: { Accept: 'application/json' } });
            const url = new URL((JSON.parse(response.data) as { url: string }).url, window.location.origin).toString();
            if (navigator.share) await navigator.share({ title: `Receipt #${detail.order_number}`, url });
            else { await navigator.clipboard.writeText(url); toast.success('Receipt link copied.'); }
        } catch { toast.error('A receipt link is not available for this transaction.'); }
    }
    return <Dialog open={open} onOpenChange={(value) => !value && onClose()}><DialogContent className="max-h-[92dvh] max-w-3xl overflow-y-auto"><DialogTitle>Transaction details {detail && `#${detail.order_number}`}</DialogTitle><DialogDescription>Fresh authoritative order, payment, adjustment, and kitchen history.</DialogDescription>{loading || !detail ? <p>Loading…</p> : <div className="space-y-5">
        <section className="grid grid-cols-2 gap-2 sm:grid-cols-4"><Fact label="Total" value={pesos(detail.total)} /><Fact label="Paid" value={pesos(detail.amount_paid)} /><Fact label="Adjustments" value={pesos(detail.adjustment_total)} /><Fact label="Outstanding" value={pesos(detail.outstanding)} /></section>
        <section><h3 className="mb-2 text-xs font-black uppercase">Items</h3><ul className="divide-y rounded-xl border">{detail.items.map((item) => <li key={item.id} className="flex gap-3 p-3 text-sm"><strong>{item.quantity}×</strong><span className="min-w-0 flex-1">{item.name}<small className="block text-neutral-500">{item.modifiers.map((mod) => mod.name).join(', ')}{item.notes ? ` · ${item.notes}` : ''}</small></span><strong>{pesos(item.line_total)}</strong></li>)}</ul></section>
        <section><h3 className="mb-2 text-xs font-black uppercase">Payment history</h3><div className="space-y-2">{detail.payment_groups.length === 0 ? <p className="rounded-xl bg-neutral-50 p-3 text-sm">No payments yet.</p> : detail.payment_groups.map((group) => <div key={group.id} className="rounded-xl border p-3"><div className="flex justify-between text-sm font-bold"><span className="capitalize">{group.method} · {group.context.replaceAll('_', ' ')}</span><span>{pesos(group.amount)}</span></div><p className="text-xs text-neutral-500">{new Date(group.paid_at).toLocaleString()} · {group.cashier}</p>{group.payments.filter((payment) => payment.method === 'cashless').map((payment) => <Button key={payment.id} size="sm" variant="outline" className="mt-2" onClick={() => onInvoice(payment)}><CreditCard /> {payment.invoice ? 'View / replace invoice' : 'Add invoice proof'}</Button>)}</div>)}</div></section>
        {detail.adjustments.length > 0 && <section><h3 className="mb-2 text-xs font-black uppercase">Order adjustments</h3>{detail.adjustments.map((adjustment) => <p key={adjustment.id} className="rounded-xl border border-amber-200 bg-amber-50 p-3 text-sm">{pesos(adjustment.amount)} lower-total correction · {adjustment.created_by}{adjustment.reason ? ` · ${adjustment.reason}` : ''}</p>)}</section>}
        <footer className="flex flex-wrap gap-2"><Button onClick={onEdit} disabled={!detail.can_edit}><Pencil /> Edit order</Button><Button onClick={onSettle} disabled={!detail.can_settle}><CreditCard /> Pay balance</Button><Button variant="outline" onClick={() => window.print()}><Printer /> Print</Button><Button variant="outline" disabled={detail.payment_status !== 'paid'} onClick={() => void share()}>Share receipt</Button><Button disabled title="Available in Phase 13"><ShieldBan /> Void · Phase 13</Button></footer>
    </div>}</DialogContent></Dialog>;
}
function Fact({ label, value }: { label: string; value: string }) { return <div className="rounded-xl bg-neutral-100 p-3"><span className="text-[10px] font-bold text-neutral-500 uppercase">{label}</span><strong className="block">{value}</strong></div>; }

type EditLine = CartLine & { existingOrderItemId?: string };
function EditDialog({ detail, catalog, tables, onClose, onSaved }: { detail: Detail; catalog: CashierCatalog; tables: BranchTable[]; onClose: () => void; onSaved: (detail: Detail) => void }) {
    const [orderType, setOrderType] = useState(detail.order_type);
    const [customer, setCustomer] = useState(detail.customer_label ?? '');
    const [tableId, setTableId] = useState(tables.find((table) => table.name === detail.table_name)?.id ?? '');
    const [reason, setReason] = useState('');
    const [processing, setProcessing] = useState(false);
    const [active, setActive] = useState<EditLine | null>(null);
    const [lines, setLines] = useState<EditLine[]>(() => detail.items.flatMap((item) => {
        const product = catalog.products.find((candidate) => candidate.id === item.product_id);
        return product ? [{ key: item.id, existingOrderItemId: item.id, product, quantity: item.quantity, notes: item.notes ?? '', modifiers: item.modifiers.map((modifier) => ({ group_id: modifier.group_id, option_id: modifier.option_id })) }] : [];
    }));
    async function save() {
        setProcessing(true);
        try {
            const response = await http.getClient().request({ ...update(detail.id), data: { idempotency_key: createClientUuid(), expected_version: detail.version, order_type: orderType, customer_label: customer || null, branch_table_id: orderType === 'dine_in' ? tableId || null : null, reason: reason || null, items: lines.map((line) => ({ existing_order_item_id: line.existingOrderItemId, product_id: line.product.id, quantity: line.quantity, notes: line.notes, modifiers: line.modifiers })) }, headers: { Accept: 'application/json' } });
            toast.success('Committed order updated.'); onSaved((JSON.parse(response.data) as { transaction: Detail }).transaction);
        } catch (error) { const data = (error as { response?: { data?: string } }).response?.data; toast.error(data ? (JSON.parse(data) as { message?: string }).message ?? 'Order could not be updated.' : 'Order could not be updated.'); } finally { setProcessing(false); }
    }
    const activeProduct = active?.product ?? null;
    return <><Dialog open onOpenChange={(value) => !value && onClose()}><DialogContent className="max-h-[94dvh] max-w-4xl overflow-y-auto"><DialogTitle>Edit committed order #{detail.order_number}</DialogTitle><DialogDescription>Retained configurations keep their committed price. New or reconfigured items use the current catalog price.</DialogDescription>
        <div className="grid gap-3 sm:grid-cols-3"><Filter value={orderType} onChange={(value) => setOrderType(value as Detail['order_type'])} label="Order type" options={[["dine_in", "Dine in"], ["take_out", "Take out"]]} /><label className="text-xs font-bold">Customer<input value={customer} onChange={(event) => setCustomer(event.target.value)} className="mt-1 min-h-10 w-full rounded-lg border px-3 font-normal" /></label>{orderType === 'dine_in' && <label className="text-xs font-bold">Table<select value={tableId} onChange={(event) => setTableId(event.target.value)} className="mt-1 min-h-10 w-full rounded-lg border px-2 font-normal"><option value="">No table</option>{tables.map((table) => <option key={table.id} value={table.id}>{table.name}</option>)}</select></label>}</div>
        <div className="space-y-2">{lines.map((line) => <button type="button" key={line.key} onClick={() => setActive(line)} className="flex w-full items-center gap-3 rounded-xl border p-3 text-left"><strong>{line.quantity}×</strong><span className="flex-1 font-semibold">{line.product.name}</span><span className="text-xs text-neutral-500">Edit</span></button>)}</div>
        <div><h3 className="mb-2 text-xs font-black uppercase">Add item</h3><div className="grid max-h-48 grid-cols-2 gap-2 overflow-y-auto sm:grid-cols-4">{catalog.products.filter((product) => product.is_available).map((product) => <button type="button" key={product.id} onClick={() => setActive({ key: createClientUuid(), product, quantity: 1, notes: '', modifiers: [] })} className="rounded-xl border p-2 text-left text-xs font-bold">{product.name}<span className="block text-red-700">{pesos(product.effective_price)}</span></button>)}</div></div>
        <label className="text-xs font-bold">Edit reason (optional)<textarea value={reason} onChange={(event) => setReason(event.target.value)} className="mt-1 min-h-20 w-full rounded-lg border p-3 font-normal" /></label>
        <footer className="flex justify-end gap-2"><Button variant="outline" onClick={onClose}>Cancel</Button><Button disabled={processing || lines.length === 0} onClick={() => void save()}>{processing ? 'Saving…' : 'Save changes'}</Button></footer>
    </DialogContent></Dialog>{activeProduct && <PosProductDialog product={activeProduct} initial={active ?? undefined} onClose={() => setActive(null)} onRemove={() => { setLines((current) => current.filter((line) => line.key !== active?.key)); setActive(null); }} onSave={(saved) => { setLines((current) => current.some((line) => line.key === saved.key) ? current.map((line) => line.key === saved.key ? { ...saved, existingOrderItemId: line.existingOrderItemId } : line) : [...current, saved]); setActive(null); }} />}</>;
}

function SettlementDialog({ detail, tables, onClose, onPaid }: { detail: Detail; tables: BranchTable[]; onClose: () => void; onPaid: (detail: Detail) => void }) {
    const [processing, setProcessing] = useState(false); const [error, setError] = useState(''); const attempt = useMemo(() => ({ idempotency_key: createClientUuid(), payment_method: 'cash' as const, cash_received: null, cashless_amount: null }), [detail.id]);
    const saved: OrderSummary = { id: detail.id, order_number: detail.order_number, reference_number: detail.reference_number, order_type: detail.order_type, table_name: detail.table_name, customer_label: detail.customer_label, subtotal: detail.outstanding, total: detail.outstanding, items: detail.items.map((item) => ({ id: item.id, name: item.name, unit_price: item.unit_price, quantity: item.quantity, line_total: item.line_total, notes: item.notes, modifiers: item.modifiers.map((modifier, index) => ({ id: `${item.id}-${index}`, group_name: modifier.group_name, name: modifier.name, price_delta: modifier.price_delta, quantity: 1 })) })) };
    async function pay(payment: PaymentInput) { setProcessing(true); setError(''); try { await http.getClient().request({ ...settle(detail.id), data: { ...payment, idempotency_key: attempt.idempotency_key }, headers: { Accept: 'application/json' } }); const response = await http.getClient().request({ ...show(detail.id), headers: { Accept: 'application/json' } }); toast.success('Balance paid.'); onPaid((JSON.parse(response.data) as { transaction: Detail }).transaction); } catch { setError('Payment could not be completed. Confirm the amount and try again.'); } finally { setProcessing(false); } }
    return <Dialog open onOpenChange={(value) => !value && onClose()}><DialogContent className="max-h-[94dvh] max-w-3xl overflow-y-auto"><DialogTitle>Pay outstanding balance</DialogTitle><DialogDescription>The exact authoritative balance is {pesos(detail.outstanding)}.</DialogDescription><PosPaymentPreview orderType={detail.order_type} lines={[]} saved={saved} orderNumber={detail.order_number} tables={tables} customerLabel={detail.customer_label ?? ''} tableId="" onCustomerChange={() => undefined} onTableChange={() => undefined} onConfirm={(payment) => void pay(payment)} processing={processing} error={error} attempt={attempt} /></DialogContent></Dialog>;
}
