import { http } from '@inertiajs/react';
import {
    ArrowLeft,
    Banknote,
    Camera,
    ChevronRight,
    FileImage,
    PackagePlus,
    Plus,
    ReceiptText,
    Search,
    Smartphone,
    Store,
} from 'lucide-react';
import { useMemo, useRef, useState } from 'react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useStoreExpenseRealtime } from '@/hooks/use-store-expense-realtime';
import { createClientUuid } from '@/lib/client-uuid';
import {
    isExpenseWriteOnline,
    storeExpenseError,
} from '@/lib/store-session-expense';
import {
    formatStoreSessionMoney,
    storeSessionDetailRows,
} from '@/lib/store-session';
import { store } from '@/routes/store-session-expenses';
import type {
    CurrentStoreSession,
    StoreSessionExpense,
} from '@/types';

type View = 'overview' | 'add' | 'detail';

const manilaTime = new Intl.DateTimeFormat('en-PH', {
    timeZone: 'Asia/Manila',
    month: 'short',
    day: 'numeric',
    hour: 'numeric',
    minute: '2-digit',
});

function SummaryCard({
    label,
    amount,
    emphasis = false,
}: {
    label: string;
    amount: string;
    emphasis?: boolean;
}) {
    return (
        <div
            className={`rounded-xl border p-3 ${emphasis ? 'border-neutral-950 bg-neutral-950 text-white' : 'border-neutral-200 bg-neutral-50'}`}
        >
            <p
                className={`text-[9px] font-semibold tracking-wider uppercase ${emphasis ? 'text-white/60' : 'text-neutral-500'}`}
            >
                {label}
            </p>
            <p className="mt-1 text-[clamp(0.72rem,3vw,1rem)] font-bold break-all tabular-nums">
                {formatStoreSessionMoney(amount)}
            </p>
        </div>
    );
}

function ExpenseDetail({
    expense,
    session,
    onBack,
}: {
    expense: StoreSessionExpense;
    session: CurrentStoreSession;
    onBack: () => void;
}) {
    const rows = [
        ['Amount', formatStoreSessionMoney(expense.amount)],
        ['Paid using', expense.payment_source === 'cash' ? 'Cash' : 'Cashless'],
        ['Recorded by', expense.created_by.name],
        ['Recorded', manilaTime.format(new Date(expense.created_at))],
        ['Branch', `${session.branch.code} · ${session.branch.name}`],
        ['Store Session', session.id],
    ];

    return (
        <div className="flex min-h-0 flex-1 flex-col">
            <header className="flex items-center gap-2 border-b border-neutral-200 px-1 pb-3">
                <button
                    type="button"
                    onClick={onBack}
                    className="flex size-11 shrink-0 items-center justify-center rounded-xl hover:bg-neutral-100 focus-visible:ring-2 focus-visible:ring-neutral-950 focus-visible:outline-none"
                    aria-label="Back to purchases and expenses"
                >
                    <ArrowLeft className="size-5" />
                </button>
                <div className="min-w-0">
                    <p className="text-[10px] font-semibold tracking-wider text-neutral-500 uppercase">
                        Expense detail
                    </p>
                    <h3 className="truncate text-base font-bold">
                        {expense.description}
                    </h3>
                </div>
            </header>
            <div className="min-h-0 flex-1 space-y-4 overflow-y-auto py-4">
                <dl className="overflow-hidden rounded-xl border border-neutral-200">
                    {rows.map(([label, value]) => (
                        <div
                            key={label}
                            className="flex items-start justify-between gap-4 border-b border-neutral-100 px-3.5 py-3 last:border-0"
                        >
                            <dt className="text-[10px] font-semibold tracking-wider text-neutral-500 uppercase">
                                {label}
                            </dt>
                            <dd className="max-w-[65%] text-right text-sm font-bold break-words">
                                {value}
                            </dd>
                        </div>
                    ))}
                </dl>
                {expense.note && (
                    <section className="rounded-xl border border-neutral-200 p-3.5">
                        <p className="text-[10px] font-semibold tracking-wider text-neutral-500 uppercase">
                            Note / reason
                        </p>
                        <p className="mt-2 text-sm leading-6 break-words">
                            {expense.note}
                        </p>
                    </section>
                )}
                {expense.item && (
                    <section className="rounded-xl border border-emerald-200 bg-emerald-50 p-3.5 text-emerald-950">
                        <div className="flex items-center gap-2 font-bold">
                            <PackagePlus className="size-4" /> Inventory restock
                        </div>
                        <p className="mt-2 text-sm">
                            {expense.item.product_name} × {expense.item.quantity}
                        </p>
                        {expense.item.movement_id && (
                            <p className="mt-1 text-[10px] break-all text-emerald-800">
                                Movement {expense.item.movement_id}
                            </p>
                        )}
                    </section>
                )}
                {expense.receipt && (
                    <a
                        href={expense.receipt.url}
                        target="_blank"
                        rel="noreferrer"
                        className="flex min-h-12 items-center justify-center gap-2 rounded-xl border border-neutral-300 bg-white px-4 text-sm font-semibold hover:bg-neutral-50 focus-visible:ring-2 focus-visible:ring-neutral-950 focus-visible:outline-none"
                    >
                        <FileImage className="size-4" /> View private receipt
                    </a>
                )}
            </div>
        </div>
    );
}

function ExpenseForm({
    session,
    connectionStatus,
    onBack,
    onSaved,
}: {
    session: CurrentStoreSession;
    connectionStatus: string;
    onBack: () => void;
    onSaved: () => Promise<void>;
}) {
    const cameraInput = useRef<HTMLInputElement>(null);
    const fileInput = useRef<HTMLInputElement>(null);
    const [description, setDescription] = useState('');
    const [amount, setAmount] = useState('');
    const [paymentSource, setPaymentSource] = useState<'cash' | 'cashless'>('cash');
    const [note, setNote] = useState('');
    const [restock, setRestock] = useState(false);
    const [productId, setProductId] = useState('');
    const [quantity, setQuantity] = useState('');
    const [search, setSearch] = useState('');
    const [receipt, setReceipt] = useState<File | null>(null);
    const [attempt, setAttempt] = useState(createClientUuid);
    const [processing, setProcessing] = useState(false);
    const [error, setError] = useState('');
    const products = useMemo(() => {
        const query = search.trim().toLowerCase();
        return query
            ? session.restock_products.filter((product) =>
                  product.name.toLowerCase().includes(query),
              )
            : session.restock_products;
    }, [search, session.restock_products]);

    async function submit(event: React.FormEvent) {
        event.preventDefault();
        if (!isExpenseWriteOnline(connectionStatus)) {
            setError('You are offline. Reconnect before recording an expense.');
            return;
        }
        setProcessing(true);
        setError('');
        const data = new FormData();
        data.append('idempotency_key', attempt);
        data.append('description', description);
        data.append('amount', amount);
        data.append('payment_source', paymentSource);
        data.append('note', note);
        data.append('restock', restock ? '1' : '0');
        if (restock) {
            data.append('product_id', productId);
            data.append('quantity', quantity);
        }
        if (receipt) data.append('receipt', receipt);

        try {
            await http.getClient().request({
                ...store(),
                data,
                headers: { Accept: 'application/json' },
            });
            toast.success('Expense recorded for this Store Session.');
            setAttempt(createClientUuid());
            await onSaved();
        } catch (reason) {
            setError(storeExpenseError(reason));
        } finally {
            setProcessing(false);
        }
    }

    return (
        <form onSubmit={submit} className="flex min-h-0 flex-1 flex-col">
            <header className="flex items-center gap-2 border-b border-neutral-200 px-1 pb-3">
                <button
                    type="button"
                    onClick={onBack}
                    disabled={processing}
                    className="flex size-11 shrink-0 items-center justify-center rounded-xl hover:bg-neutral-100 focus-visible:ring-2 focus-visible:ring-neutral-950 focus-visible:outline-none disabled:opacity-50"
                    aria-label="Back to purchases and expenses"
                >
                    <ArrowLeft className="size-5" />
                </button>
                <div>
                    <p className="text-[10px] font-semibold tracking-wider text-neutral-500 uppercase">
                        Current Store Session
                    </p>
                    <h3 className="text-base font-bold">Add expense / purchase</h3>
                </div>
            </header>
            <div className="min-h-0 flex-1 space-y-4 overflow-y-auto py-4 pr-1">
                <div className="space-y-1.5">
                    <Label htmlFor="expense-description">Description / item</Label>
                    <Input
                        id="expense-description"
                        value={description}
                        onChange={(event) => setDescription(event.target.value)}
                        maxLength={150}
                        required
                        className="min-h-12 rounded-xl"
                        placeholder="e.g. Ice"
                    />
                </div>
                <div className="space-y-1.5">
                    <Label htmlFor="expense-amount">Amount</Label>
                    <div className="relative">
                        <span className="absolute top-1/2 left-3 -translate-y-1/2 text-sm font-bold text-neutral-500">
                            ₱
                        </span>
                        <Input
                            id="expense-amount"
                            value={amount}
                            onChange={(event) => setAmount(event.target.value)}
                            inputMode="decimal"
                            required
                            className="min-h-12 rounded-xl pl-8 text-base font-bold tabular-nums"
                            placeholder="0.00"
                        />
                    </div>
                </div>
                <fieldset className="space-y-1.5">
                    <legend className="text-sm font-medium">Paid using</legend>
                    <div className="grid grid-cols-2 gap-2 rounded-xl bg-neutral-100 p-1">
                        {(['cash', 'cashless'] as const).map((source) => (
                            <button
                                key={source}
                                type="button"
                                onClick={() => setPaymentSource(source)}
                                className={`min-h-11 rounded-[10px] text-sm font-semibold capitalize ${paymentSource === source ? 'bg-neutral-950 text-white shadow-sm' : 'text-neutral-600 hover:bg-white'}`}
                            >
                                {source}
                            </button>
                        ))}
                    </div>
                </fieldset>
                <div className="space-y-1.5">
                    <Label htmlFor="expense-note">Note / reason (optional)</Label>
                    <textarea
                        id="expense-note"
                        value={note}
                        onChange={(event) => setNote(event.target.value)}
                        maxLength={2000}
                        rows={3}
                        className="w-full resize-none rounded-xl border border-input bg-transparent px-3 py-2 text-sm outline-none focus-visible:ring-2 focus-visible:ring-neutral-950"
                        placeholder="Why was this purchase needed?"
                    />
                </div>
                <section className="rounded-xl border border-neutral-200 p-3.5">
                    <div className="flex items-center justify-between gap-3">
                        <div>
                            <p className="text-sm font-bold">Restock inventory</p>
                            <p className="mt-0.5 text-xs leading-5 text-neutral-500">
                                Link this purchase to one tracked product.
                            </p>
                        </div>
                        <button
                            type="button"
                            role="switch"
                            aria-checked={restock}
                            onClick={() => {
                                setRestock((value) => !value);
                                setProductId('');
                                setQuantity('');
                            }}
                            className={`relative h-7 w-12 shrink-0 rounded-full transition ${restock ? 'bg-emerald-600' : 'bg-neutral-300'}`}
                        >
                            <span
                                className={`absolute top-1 size-5 rounded-full bg-white shadow transition ${restock ? 'left-6' : 'left-1'}`}
                            />
                        </button>
                    </div>
                    {restock && (
                        <div className="mt-4 space-y-3 border-t border-neutral-200 pt-4">
                            <p className="rounded-lg bg-emerald-50 p-2.5 text-xs font-medium text-emerald-900">
                                Stock will increase when this expense is saved.
                            </p>
                            <div className="relative">
                                <Search className="absolute top-1/2 left-3 size-4 -translate-y-1/2 text-neutral-400" />
                                <Input
                                    value={search}
                                    onChange={(event) => setSearch(event.target.value)}
                                    className="min-h-11 rounded-xl pl-9"
                                    placeholder="Search tracked products"
                                    aria-label="Search tracked products"
                                />
                            </div>
                            <div className="max-h-40 space-y-1 overflow-y-auto rounded-xl border border-neutral-200 p-1">
                                {products.length === 0 ? (
                                    <p className="p-3 text-center text-xs text-neutral-500">
                                        No tracked products found.
                                    </p>
                                ) : (
                                    products.map((product) => (
                                        <button
                                            key={product.id}
                                            type="button"
                                            onClick={() => setProductId(product.id)}
                                            className={`flex min-h-11 w-full items-center justify-between gap-3 rounded-lg px-3 text-left text-sm ${productId === product.id ? 'bg-neutral-950 text-white' : 'hover:bg-neutral-100'}`}
                                        >
                                            <span className="font-semibold">
                                                {product.name}
                                            </span>
                                            <span className="text-xs opacity-70">
                                                Stock {product.on_hand}
                                            </span>
                                        </button>
                                    ))
                                )}
                            </div>
                            <div className="space-y-1.5">
                                <Label htmlFor="expense-quantity">Quantity</Label>
                                <Input
                                    id="expense-quantity"
                                    value={quantity}
                                    onChange={(event) => setQuantity(event.target.value)}
                                    inputMode="numeric"
                                    min="1"
                                    step="1"
                                    type="number"
                                    required={restock}
                                    className="min-h-12 rounded-xl"
                                    placeholder="0"
                                />
                            </div>
                        </div>
                    )}
                </section>
                <section className="space-y-2 rounded-xl border border-neutral-200 p-3.5">
                    <div>
                        <p className="text-sm font-bold">Receipt (optional)</p>
                        <p className="mt-0.5 text-xs text-neutral-500">
                            JPG, PNG, or WebP · private evidence
                        </p>
                    </div>
                    <input
                        ref={cameraInput}
                        type="file"
                        accept="image/jpeg,image/png,image/webp"
                        capture="environment"
                        className="hidden"
                        onChange={(event) => setReceipt(event.target.files?.[0] ?? null)}
                    />
                    <input
                        ref={fileInput}
                        type="file"
                        accept="image/jpeg,image/png,image/webp"
                        className="hidden"
                        onChange={(event) => setReceipt(event.target.files?.[0] ?? null)}
                    />
                    <div className="grid grid-cols-2 gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            className="min-h-11 rounded-xl"
                            onClick={() => cameraInput.current?.click()}
                        >
                            <Camera className="size-4" /> Take photo
                        </Button>
                        <Button
                            type="button"
                            variant="outline"
                            className="min-h-11 rounded-xl"
                            onClick={() => fileInput.current?.click()}
                        >
                            <FileImage className="size-4" /> Choose file
                        </Button>
                    </div>
                    {receipt && (
                        <p className="truncate rounded-lg bg-neutral-100 px-3 py-2 text-xs font-medium">
                            {receipt.name}
                        </p>
                    )}
                </section>
                {error && (
                    <div
                        role="alert"
                        className="rounded-xl border border-red-200 bg-red-50 p-3 text-sm leading-5 text-red-800"
                    >
                        {error}
                    </div>
                )}
            </div>
            <footer className="border-t border-neutral-200 pt-3 pb-[max(0px,env(safe-area-inset-bottom))]">
                <Button
                    type="submit"
                    disabled={processing}
                    className="min-h-12 w-full rounded-xl bg-neutral-950 text-white hover:bg-black"
                >
                    {processing ? <Spinner /> : <Plus className="size-4" />}
                    {processing ? 'Saving expense…' : 'Save expense / purchase'}
                </Button>
            </footer>
        </form>
    );
}

export function StoreSessionDetailsDialog({
    open,
    onOpenChange,
    session,
    branchId,
    loading,
    unavailable,
    refreshSession,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    session: CurrentStoreSession | null;
    branchId: string;
    loading: boolean;
    unavailable: boolean;
    refreshSession: () => Promise<void>;
}) {
    const [view, setView] = useState<View>('overview');
    const [selected, setSelected] = useState<StoreSessionExpense | null>(null);
    const connectionStatus = useStoreExpenseRealtime(
        branchId,
        refreshSession,
    );

    function changeOpen(next: boolean) {
        if (!next) {
            setView('overview');
            setSelected(null);
        }
        onOpenChange(next);
    }

    return (
        <Dialog open={open} onOpenChange={changeOpen}>
            <DialogContent className="flex h-[min(92svh,780px)] w-[calc(100%-16px)] max-w-[760px] flex-col overflow-hidden bg-white p-3 text-neutral-950 sm:p-5 [&>button]:top-1 [&>button]:right-1 [&>button]:flex [&>button]:min-h-11 [&>button]:min-w-11 [&>button]:items-center [&>button]:justify-center">
                {view === 'overview' && (
                    <>
                        <DialogHeader className="shrink-0 pr-9 text-left">
                            <div className="flex items-center gap-3">
                                <span className="flex size-10 shrink-0 items-center justify-center rounded-xl bg-green-50 text-green-700">
                                    <Store className="size-5" />
                                </span>
                                <div className="min-w-0">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <DialogTitle className="text-[17px] font-bold">
                                            Current Store Session
                                        </DialogTitle>
                                        <span className="inline-flex items-center gap-1.5 rounded-full border border-green-200 bg-green-50 px-2 py-1 text-[9px] font-bold tracking-wide text-green-700">
                                            <span className="size-1.5 rounded-full bg-green-700" />
                                            LIVE · STORE OPEN
                                        </span>
                                    </div>
                                    <DialogDescription className="mt-0.5 truncate text-xs text-neutral-500">
                                        {session
                                            ? `${session.branch.code} · ${session.branch.name}`
                                            : 'Loading current branch session'}
                                    </DialogDescription>
                                </div>
                            </div>
                        </DialogHeader>

                        {loading && !session && (
                            <div className="flex min-h-52 flex-1 items-center justify-center gap-2 text-sm text-neutral-500" role="status">
                                <Spinner /> Loading Store Session…
                            </div>
                        )}
                        {!loading && unavailable && !session && (
                            <div role="alert" className="my-4 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm leading-6 text-amber-900">
                                This Store Session is no longer available. The store state may have changed; close this window and refresh the POS.
                            </div>
                        )}
                        {session && (
                            <div className="mt-3 min-h-0 flex-1 space-y-4 overflow-y-auto pr-1">
                                <dl className="grid gap-2 rounded-xl border border-neutral-200 p-3 sm:grid-cols-3">
                                    {storeSessionDetailRows(session).map((row) => (
                                        <div key={row.label} className="min-w-0">
                                            <dt className="text-[9px] font-semibold tracking-wider text-neutral-500 uppercase">{row.label}</dt>
                                            <dd className="mt-1 text-xs font-bold break-words tabular-nums">{row.value}</dd>
                                        </div>
                                    ))}
                                </dl>
                                <section>
                                    <div className="mb-2 flex items-center justify-between gap-3">
                                        <div>
                                            <p className="text-[10px] font-bold tracking-[0.14em] text-neutral-500 uppercase">Purchases & expenses</p>
                                            <p className="mt-0.5 text-xs text-neutral-500">Current Store Session only</p>
                                        </div>
                                        <Button type="button" onClick={() => setView('add')} disabled={!isExpenseWriteOnline(connectionStatus)} className="min-h-11 rounded-xl bg-neutral-950 px-3 text-white hover:bg-black">
                                            <Plus className="size-4" /> Add expense / purchase
                                        </Button>
                                    </div>
                                    {connectionStatus !== 'connected' && (
                                        <p role="status" className="mb-2 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-900">
                                            You are offline. Reconnect before recording an expense. Confirmed history remains visible.
                                        </p>
                                    )}
                                    <div className="grid grid-cols-3 gap-2">
                                        <SummaryCard label="Cash" amount={session.expense_totals.cash} />
                                        <SummaryCard label="Cashless" amount={session.expense_totals.cashless} />
                                        <SummaryCard label="Total" amount={session.expense_totals.total} emphasis />
                                    </div>
                                </section>
                                <section className="overflow-hidden rounded-xl border border-neutral-200">
                                    {session.expenses.length === 0 ? (
                                        <div className="flex min-h-36 flex-col items-center justify-center gap-2 p-6 text-center text-neutral-500">
                                            <ReceiptText className="size-6" />
                                            <p className="text-sm font-semibold text-neutral-700">No purchases or expenses recorded for this Store Session.</p>
                                        </div>
                                    ) : (
                                        <ul className="divide-y divide-neutral-100">
                                            {session.expenses.map((expense) => (
                                                <li key={expense.id}>
                                                    <button type="button" onClick={() => { setSelected(expense); setView('detail'); }} className="flex min-h-16 w-full items-center gap-3 px-3 py-2.5 text-left hover:bg-neutral-50 focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-neutral-950 focus-visible:outline-none">
                                                        <span className={`flex size-9 shrink-0 items-center justify-center rounded-xl ${expense.payment_source === 'cash' ? 'bg-amber-50 text-amber-800' : 'bg-sky-50 text-sky-700'}`}>
                                                            {expense.payment_source === 'cash' ? <Banknote className="size-4" /> : <Smartphone className="size-4" />}
                                                        </span>
                                                        <span className="min-w-0 flex-1">
                                                            <span className="block truncate text-sm font-bold">{expense.description}</span>
                                                            <span className="mt-0.5 block text-[10px] text-neutral-500">
                                                                {manilaTime.format(new Date(expense.created_at))} · {expense.created_by.name}
                                                            </span>
                                                            <span className="mt-1 flex flex-wrap gap-1">
                                                                {expense.item && <span className="rounded bg-emerald-50 px-1.5 py-0.5 text-[9px] font-bold text-emerald-800">RESTOCK · {expense.item.product_name} × {expense.item.quantity}</span>}
                                                                {expense.receipt && <span className="rounded bg-neutral-100 px-1.5 py-0.5 text-[9px] font-bold text-neutral-600">RECEIPT</span>}
                                                            </span>
                                                        </span>
                                                        <span className="shrink-0 text-right">
                                                            <span className="block text-sm font-bold tabular-nums">{formatStoreSessionMoney(expense.amount)}</span>
                                                            <span className="text-[9px] font-semibold tracking-wide text-neutral-500 uppercase">{expense.payment_source}</span>
                                                        </span>
                                                        <ChevronRight className="size-4 shrink-0 text-neutral-400" />
                                                    </button>
                                                </li>
                                            ))}
                                        </ul>
                                    )}
                                    {session.expenses_truncated && (
                                        <p className="border-t border-neutral-100 px-3 py-2 text-center text-[10px] text-neutral-500">
                                            Showing the newest 50 of {session.expense_count} records.
                                        </p>
                                    )}
                                </section>
                            </div>
                        )}
                    </>
                )}
                {view === 'add' && session && (
                    <ExpenseForm
                        session={session}
                        connectionStatus={connectionStatus}
                        onBack={() => setView('overview')}
                        onSaved={async () => {
                            await refreshSession();
                            setView('overview');
                        }}
                    />
                )}
                {view === 'detail' && session && selected && (
                    <ExpenseDetail expense={selected} session={session} onBack={() => setView('overview')} />
                )}
            </DialogContent>
        </Dialog>
    );
}
