import { http } from '@inertiajs/react';
import {
    ArrowLeft,
    Gift,
    Pencil,
    RotateCcw,
    Search,
    Undo2,
} from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { toast } from 'sonner';
import { PosProductDialog } from '@/components/pos-product-dialog';
import { Button } from '@/components/ui/button';
import { DialogDescription, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { createClientUuid } from '@/lib/client-uuid';
import { stockAvailabilityLabel } from '@/lib/pos-order';
import { recipeProductLabel } from '@/lib/recipe-availability';
import { isBlank, requiredGroupOutline } from '@/lib/required-field';
import {
    GIVEAWAY_REASONS,
    giveawayError,
    giveawaySelection,
} from '@/lib/store-giveaway';
import type { GiveawayReason } from '@/lib/store-giveaway';
import { isExpenseWriteOnline } from '@/lib/store-session-expense';
import { recipeCapacity } from '@/routes/pos';
import {
    catalog as giveawayCatalog,
    reverse,
    store,
} from '@/routes/store-session-giveaways';
import type { CurrentStoreSession, StoreSessionGiveaway } from '@/types';
import type { CashierCatalog } from '@/types/catalog';
import type { CartLine, PosProduct } from '@/types/pos';

const giveawayTime = new Intl.DateTimeFormat('en-PH', {
    timeZone: 'Asia/Manila',
    month: 'short',
    day: 'numeric',
    hour: 'numeric',
    minute: '2-digit',
});

function Header({
    kicker,
    title,
    onBack,
    disabled = false,
}: {
    kicker: string;
    title: string;
    onBack: () => void;
    disabled?: boolean;
}) {
    return (
        <header className="flex items-center gap-2 border-b border-neutral-200 pr-12 pb-3 pl-1">
            <button
                type="button"
                onClick={onBack}
                disabled={disabled}
                className="flex size-11 shrink-0 items-center justify-center rounded-xl hover:bg-neutral-100 focus-visible:ring-2 focus-visible:ring-neutral-950 focus-visible:outline-none disabled:opacity-50"
                aria-label="Back to the Store Session"
            >
                <ArrowLeft className="size-5" />
            </button>
            <div className="min-w-0">
                <p className="text-[10px] font-semibold tracking-wider text-neutral-500 uppercase">
                    {kicker}
                </p>
                <DialogTitle className="truncate text-base font-bold">
                    {title}
                </DialogTitle>
            </div>
        </header>
    );
}

/**
 * Record giveaway: a real Product given away free in this Store Session. Stock leaves the store (Recipe Ingredients
 * or Product stock, as the server decides) but revenue is ₱0: no sale, Payment or Store Expense is created.
 */
export function StoreGiveawayForm({
    session,
    onBack,
    onSaved,
}: {
    session: CurrentStoreSession;
    onBack: () => void;
    onSaved: () => Promise<void>;
}) {
    const [catalog, setCatalog] = useState<CashierCatalog | null>(null);
    const [loadError, setLoadError] = useState('');
    const [search, setSearch] = useState('');
    const [customizing, setCustomizing] = useState<PosProduct | null>(null);
    const [line, setLine] = useState<CartLine | null>(null);
    const [reason, setReason] = useState<GiveawayReason | null>(null);
    const [note, setNote] = useState('');
    const [confirming, setConfirming] = useState(false);
    const [attempt, setAttempt] = useState(createClientUuid);
    const [processing, setProcessing] = useState(false);
    const [error, setError] = useState('');

    useEffect(() => {
        let active = true;
        http.getClient()
            .request({
                ...giveawayCatalog(),
                headers: { Accept: 'application/json' },
            })
            .then((response) => {
                if (!active) return;
                const data =
                    typeof response.data === 'string'
                        ? (JSON.parse(response.data) as CashierCatalog)
                        : (response.data as CashierCatalog);
                setCatalog(data);
            })
            .catch(() => {
                if (active)
                    setLoadError(
                        'The product list could not be loaded. Reconnect and try again.',
                    );
            });

        return () => {
            active = false;
        };
    }, []);

    const products = useMemo(() => {
        const query = search.trim().toLowerCase();
        const all = catalog?.products ?? [];

        return query
            ? all.filter((product) =>
                  `${product.name} ${product.category_name}`
                      .toLowerCase()
                      .includes(query),
              )
            : all;
    }, [catalog, search]);
    const selection = line
        ? giveawaySelection(line.product.modifier_groups, line.modifiers)
        : null;
    const noteRequired = reason === 'other';
    const noteMissing = noteRequired && isBlank(note);
    const canSave = line !== null && reason !== null && !noteMissing;
    const reasonLabel =
        GIVEAWAY_REASONS.find((item) => item.value === reason)?.label ?? '';

    function reset<T>(setter: (value: T) => void) {
        return (value: T) => {
            setter(value);
            setConfirming(false);
            setError('');
            setAttempt(createClientUuid());
        };
    }

    async function save() {
        if (!canSave || !line || !reason) return;
        if (!isExpenseWriteOnline()) {
            setError('You are offline. Reconnect before recording a giveaway.');
            return;
        }
        setProcessing(true);
        setError('');
        try {
            await http.getClient().request({
                ...store(),
                data: {
                    idempotency_key: attempt,
                    product_id: line.product.id,
                    quantity: line.quantity,
                    modifiers: line.modifiers,
                    reason_code: reason,
                    note: note.trim() || null,
                },
                headers: { Accept: 'application/json' },
            });
            toast.success(
                'Giveaway recorded. Stock was updated; no sale, payment or expense was created.',
            );
            await onSaved();
        } catch (reasonError) {
            setError(giveawayError(reasonError));
            setConfirming(false);
        } finally {
            setProcessing(false);
        }
    }

    return (
        <div className="flex min-h-0 flex-1 flex-col">
            <Header
                kicker="Current Store Session"
                title="Record giveaway"
                onBack={onBack}
                disabled={processing}
            />
            <div className="min-h-0 flex-1 space-y-4 overflow-y-auto py-4 pr-1">
                <DialogDescription className="rounded-lg bg-neutral-100 px-3 py-2 text-xs leading-5 text-neutral-700">
                    A free item given to a customer or staff. Its stock leaves
                    the store, but it earns ₱0 and is never a sale, payment or
                    expense. {session.branch.code} only.
                </DialogDescription>

                <section
                    aria-labelledby="giveaway-product-heading"
                    className="space-y-1.5"
                >
                    <p
                        id="giveaway-product-heading"
                        className="text-sm font-medium"
                    >
                        Product
                    </p>
                    {line && selection ? (
                        <div className="flex items-start gap-3 rounded-xl border border-neutral-200 p-3">
                            <span className="flex size-9 shrink-0 items-center justify-center rounded-xl bg-rose-50 text-rose-700">
                                <Gift className="size-4" />
                            </span>
                            <div className="min-w-0 flex-1 text-sm">
                                <p className="font-bold wrap-anywhere">
                                    {selection.size ? `${selection.size} ` : ''}
                                    {line.product.name} × {line.quantity}
                                </p>
                                {selection.addOns.length > 0 && (
                                    <p className="mt-0.5 text-xs text-neutral-600">
                                        Add-ons: {selection.addOns.join(', ')}
                                    </p>
                                )}
                                {selection.instructions.length > 0 && (
                                    <p className="mt-0.5 text-xs text-neutral-600">
                                        Instructions:{' '}
                                        {selection.instructions.join(', ')}
                                    </p>
                                )}
                            </div>
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => setCustomizing(line.product)}
                                className="min-h-11 shrink-0 rounded-xl px-3"
                            >
                                <Pencil className="size-4" /> Change
                            </Button>
                        </div>
                    ) : (
                        <>
                            <div className="relative">
                                <Search className="absolute top-1/2 left-3 size-4 -translate-y-1/2 text-neutral-400" />
                                <Input
                                    value={search}
                                    onChange={(event) =>
                                        setSearch(event.target.value)
                                    }
                                    className="min-h-11 rounded-xl pl-9"
                                    placeholder="Search products"
                                    aria-label="Search products"
                                />
                            </div>
                            <div
                                role="group"
                                aria-label="Product given away"
                                aria-invalid
                                aria-describedby="giveaway-product-required"
                                className={`max-h-56 space-y-1 overflow-y-auto rounded-xl border p-1 ${requiredGroupOutline(true)}`}
                            >
                                {catalog === null && !loadError && (
                                    <p
                                        role="status"
                                        className="flex items-center justify-center gap-2 p-3 text-xs text-neutral-500"
                                    >
                                        <Spinner /> Loading products…
                                    </p>
                                )}
                                {loadError && (
                                    <p
                                        role="alert"
                                        className="p-3 text-center text-xs text-red-800"
                                    >
                                        {loadError}
                                    </p>
                                )}
                                {catalog !== null && products.length === 0 && (
                                    <p className="p-3 text-center text-xs text-neutral-500">
                                        No products found.
                                    </p>
                                )}
                                {products.map((product) => (
                                    <button
                                        key={product.id}
                                        type="button"
                                        disabled={!product.is_available}
                                        onClick={() => setCustomizing(product)}
                                        className="flex min-h-12 w-full items-center justify-between gap-3 rounded-lg px-3 text-left hover:bg-neutral-100 disabled:cursor-not-allowed disabled:opacity-50"
                                    >
                                        <span className="min-w-0">
                                            <span className="block truncate text-sm font-semibold">
                                                {product.name}
                                            </span>
                                            <span className="block truncate text-[11px] text-neutral-500">
                                                {product.category_name} ·{' '}
                                                {product.recipe
                                                    ? recipeProductLabel(
                                                          product.recipe,
                                                      )
                                                    : stockAvailabilityLabel(
                                                          product,
                                                      )}
                                            </span>
                                        </span>
                                    </button>
                                ))}
                            </div>
                            <p
                                id="giveaway-product-required"
                                className="text-xs text-red-700"
                            >
                                Required · choose the product, then its size,
                                add-ons and quantity
                            </p>
                        </>
                    )}
                </section>

                <fieldset
                    className="space-y-1.5"
                    aria-invalid={reason === null || undefined}
                    aria-describedby={
                        reason === null ? 'giveaway-reason-required' : undefined
                    }
                >
                    <legend className="text-sm font-medium">Reason</legend>
                    <div
                        className={`grid grid-cols-2 gap-2 rounded-xl border p-1 sm:grid-cols-3 ${requiredGroupOutline(reason === null)}`}
                    >
                        {GIVEAWAY_REASONS.map((item) => (
                            <button
                                key={item.value}
                                type="button"
                                aria-pressed={reason === item.value}
                                onClick={() => reset(setReason)(item.value)}
                                className={`min-h-11 rounded-xl border px-3 text-left text-xs font-semibold ${reason === item.value ? 'border-neutral-950 bg-neutral-950 text-white' : 'border-neutral-200 hover:bg-neutral-50'}`}
                            >
                                {item.label}
                            </button>
                        ))}
                    </div>
                    {reason === null && (
                        <p
                            id="giveaway-reason-required"
                            className="text-xs text-red-700"
                        >
                            Required · choose why it was given away
                        </p>
                    )}
                </fieldset>

                <div className="space-y-1.5">
                    <Label htmlFor="giveaway-note">
                        Note {noteRequired ? '(required)' : '(optional)'}
                    </Label>
                    <textarea
                        id="giveaway-note"
                        value={note}
                        onChange={(event) => reset(setNote)(event.target.value)}
                        maxLength={500}
                        rows={2}
                        required={noteRequired}
                        aria-invalid={noteMissing}
                        aria-describedby={
                            noteMissing ? 'giveaway-note-required' : undefined
                        }
                        className={`w-full resize-none rounded-xl border bg-transparent px-3 py-2 text-sm outline-none focus-visible:ring-2 focus-visible:ring-neutral-950 ${noteMissing ? 'border-red-700' : 'border-input'}`}
                        placeholder="e.g. Free drink for a delayed order."
                    />
                    {noteMissing && (
                        <p
                            id="giveaway-note-required"
                            className="text-xs text-red-700"
                        >
                            Required when the reason is Other
                        </p>
                    )}
                </div>

                {confirming && line && selection && (
                    <div
                        role="status"
                        className="space-y-1 rounded-xl border border-amber-200 bg-amber-50 p-3 text-xs leading-5 text-amber-950"
                    >
                        <p>
                            <span className="font-bold">
                                {selection.size ? `${selection.size} ` : ''}
                                {line.product.name} × {line.quantity}
                            </span>{' '}
                            will be recorded as a giveaway. Reason:{' '}
                            {reasonLabel}.
                        </p>
                        <p>
                            Its recipe ingredients or Product stock are deducted
                            now. Revenue ₱0 · no payment · no expense.
                        </p>
                    </div>
                )}
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
                {confirming ? (
                    <div className="grid grid-cols-[auto_minmax(0,1fr)] gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => setConfirming(false)}
                            disabled={processing}
                            className="min-h-12 rounded-xl"
                        >
                            Back
                        </Button>
                        <Button
                            type="button"
                            onClick={() => void save()}
                            disabled={processing || !canSave}
                            className="min-h-12 rounded-xl bg-neutral-950 text-white hover:bg-black"
                        >
                            {processing ? (
                                <Spinner />
                            ) : (
                                <Gift className="size-4" />
                            )}
                            {processing
                                ? 'Recording giveaway…'
                                : 'Confirm giveaway'}
                        </Button>
                    </div>
                ) : (
                    <Button
                        type="button"
                        onClick={() => setConfirming(true)}
                        disabled={!canSave}
                        className="min-h-12 w-full rounded-xl bg-neutral-950 text-white hover:bg-black"
                    >
                        <Gift className="size-4" />
                        Review giveaway
                    </Button>
                )}
            </footer>
            {customizing && (
                <PosProductDialog
                    product={customizing}
                    initial={
                        line?.product.id === customizing.id ? line : undefined
                    }
                    purpose="giveaway"
                    capacityUrl={recipeCapacity.url()}
                    onClose={() => setCustomizing(null)}
                    onRemove={() => {
                        reset(setLine)(null);
                        setCustomizing(null);
                    }}
                    onSave={(next) => {
                        reset(setLine)(next);
                        setCustomizing(null);
                    }}
                />
            )}
        </div>
    );
}

/** Stock-only history row of a Giveaway: ₱0, with its reversal state. */
export function GiveawayRow({
    giveaway,
    compact = false,
}: {
    giveaway: StoreSessionGiveaway;
    compact?: boolean;
}) {
    const name = `${giveaway.size_name ? `${giveaway.size_name} ` : ''}${giveaway.product_name}`;

    return (
        <span
            className={`flex w-full items-center gap-3 px-3 text-left ${compact ? 'py-2.5' : 'min-h-16 py-2.5'}`}
        >
            <span
                className={`flex shrink-0 items-center justify-center bg-rose-50 text-rose-700 ${compact ? 'size-8 rounded-lg' : 'size-9 rounded-xl'}`}
            >
                <Gift className="size-4" />
            </span>
            <span className="min-w-0 flex-1">
                <span
                    className={`block truncate font-bold ${compact ? 'text-xs' : 'text-sm'} ${giveaway.reversal ? 'text-neutral-500 line-through' : ''}`}
                >
                    {name} × {giveaway.quantity}
                </span>
                <span className="mt-0.5 block truncate text-[10px] text-neutral-500">
                    {giveawayTime.format(new Date(giveaway.created_at))} ·{' '}
                    {giveaway.created_by.name}
                </span>
                <span className="mt-1 flex flex-wrap gap-1">
                    <span className="rounded bg-rose-50 px-1.5 py-0.5 text-[9px] font-bold text-rose-800 uppercase">
                        Giveaway · {giveaway.reason_label}
                    </span>
                    {giveaway.reversal && (
                        <span className="rounded bg-neutral-100 px-1.5 py-0.5 text-[9px] font-bold text-neutral-700 uppercase">
                            Reversed
                        </span>
                    )}
                </span>
            </span>
            <span className="shrink-0 text-right">
                <span
                    className={`block font-bold tabular-nums ${compact ? 'text-xs' : 'text-sm'}`}
                >
                    ₱0.00
                </span>
                <span className="text-[9px] font-semibold tracking-wide text-neutral-500 uppercase">
                    Stock only
                </span>
            </span>
        </span>
    );
}

/** Detail of one Giveaway with the one-time audited reversal while its Store Session is open. */
export function GiveawayDetail({
    giveaway,
    session,
    onBack,
    onReversed,
}: {
    giveaway: StoreSessionGiveaway;
    session: CurrentStoreSession;
    onBack: () => void;
    onReversed: () => Promise<void>;
}) {
    const [reversing, setReversing] = useState(false);
    const [reason, setReason] = useState('');
    const [attempt, setAttempt] = useState(createClientUuid);
    const [processing, setProcessing] = useState(false);
    const [error, setError] = useState('');
    const reasonMissing = isBlank(reason);
    const rows: [string, string][] = [
        ['Quantity', String(giveaway.quantity)],
        ['Size', giveaway.size_name ?? 'Regular'],
        [
            'Add-ons',
            giveaway.add_ons.length ? giveaway.add_ons.join(', ') : 'None',
        ],
        [
            'Instructions',
            giveaway.instructions.length
                ? giveaway.instructions.join(', ')
                : 'None',
        ],
        ['Reason', giveaway.reason_label],
        ['Revenue', '₱0.00 · no payment · no expense'],
        ['Recorded by', giveaway.created_by.name],
        ['Recorded', giveawayTime.format(new Date(giveaway.created_at))],
        ['Branch', `${session.branch.code} · ${session.branch.name}`],
    ];

    async function confirmReversal() {
        if (reasonMissing) return;
        if (!isExpenseWriteOnline()) {
            setError('You are offline. Reconnect before reversing a giveaway.');
            return;
        }
        setProcessing(true);
        setError('');
        try {
            await http.getClient().request({
                ...reverse(giveaway.id),
                data: { idempotency_key: attempt, reason: reason.trim() },
                headers: { Accept: 'application/json' },
            });
            toast.success('Giveaway reversed. Its stock was restored.');
            await onReversed();
        } catch (reasonError) {
            setError(giveawayError(reasonError, 'reversal'));
        } finally {
            setProcessing(false);
        }
    }

    return (
        <div className="flex min-h-0 flex-1 flex-col">
            <Header
                kicker="Giveaway detail"
                title={`${giveaway.size_name ? `${giveaway.size_name} ` : ''}${giveaway.product_name}`}
                onBack={onBack}
                disabled={processing}
            />
            <div className="min-h-0 flex-1 space-y-4 overflow-y-auto py-4 pr-1">
                <DialogDescription className="sr-only">
                    Giveaway record and its stock effect.
                </DialogDescription>
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
                {giveaway.note && (
                    <section className="rounded-xl border border-neutral-200 p-3.5">
                        <p className="text-[10px] font-semibold tracking-wider text-neutral-500 uppercase">
                            Note
                        </p>
                        <p className="mt-2 text-sm leading-6 break-words">
                            {giveaway.note}
                        </p>
                    </section>
                )}
                <section className="rounded-xl border border-neutral-200 p-3.5">
                    <p className="text-[10px] font-semibold tracking-wider text-neutral-500 uppercase">
                        Stock that left the store
                    </p>
                    {giveaway.stock_effects.length === 0 ? (
                        <p className="mt-2 text-sm text-neutral-600">
                            This product tracks no stock, so nothing moved. The
                            giveaway is kept for history.
                        </p>
                    ) : (
                        <ul className="mt-2 space-y-1 text-sm">
                            {giveaway.stock_effects.map((effect) => (
                                <li
                                    key={effect.name}
                                    className="flex justify-between gap-3"
                                >
                                    <span className="wrap-anywhere">
                                        {effect.name}
                                    </span>
                                    <span className="shrink-0 font-bold tabular-nums">
                                        −{effect.quantity} {effect.unit}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    )}
                </section>
                {giveaway.reversal ? (
                    <section className="rounded-xl border border-neutral-300 bg-neutral-50 p-3.5 text-sm">
                        <p className="flex items-center gap-2 font-bold">
                            <RotateCcw className="size-4" /> Reversed
                        </p>
                        <p className="mt-1 text-xs leading-5 text-neutral-600">
                            {giveaway.reversal.created_by.name} ·{' '}
                            {giveawayTime.format(
                                new Date(giveaway.reversal.created_at),
                            )}{' '}
                            · {giveaway.reversal.reason}. The recorded stock was
                            restored once.
                        </p>
                    </section>
                ) : reversing ? (
                    <section className="space-y-2 rounded-xl border border-amber-200 bg-amber-50 p-3.5">
                        <Label htmlFor="giveaway-reversal-reason">
                            Why is this giveaway being reversed?
                        </Label>
                        <textarea
                            id="giveaway-reversal-reason"
                            value={reason}
                            onChange={(event) => {
                                setReason(event.target.value);
                                setAttempt(createClientUuid());
                                setError('');
                            }}
                            maxLength={500}
                            rows={2}
                            required
                            aria-invalid={reasonMissing}
                            aria-describedby={
                                reasonMissing
                                    ? 'giveaway-reversal-required'
                                    : undefined
                            }
                            className={`w-full resize-none rounded-xl border bg-white px-3 py-2 text-sm outline-none focus-visible:ring-2 focus-visible:ring-neutral-950 ${reasonMissing ? 'border-red-700' : 'border-input'}`}
                            placeholder="e.g. Recorded the wrong drink."
                        />
                        {reasonMissing && (
                            <p
                                id="giveaway-reversal-required"
                                className="text-xs text-red-700"
                            >
                                Required
                            </p>
                        )}
                        <p className="text-xs leading-5 text-amber-950">
                            Exactly the stock recorded above is restored, once.
                            The giveaway stays in the history as reversed.
                        </p>
                    </section>
                ) : null}
                {error && (
                    <div
                        role="alert"
                        className="rounded-xl border border-red-200 bg-red-50 p-3 text-sm leading-5 text-red-800"
                    >
                        {error}
                    </div>
                )}
            </div>
            {!giveaway.reversal && (
                <footer className="border-t border-neutral-200 pt-3 pb-[max(0px,env(safe-area-inset-bottom))]">
                    {reversing ? (
                        <div className="grid grid-cols-[auto_minmax(0,1fr)] gap-2">
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => setReversing(false)}
                                disabled={processing}
                                className="min-h-12 rounded-xl"
                            >
                                Cancel
                            </Button>
                            <Button
                                type="button"
                                onClick={() => void confirmReversal()}
                                disabled={processing || reasonMissing}
                                className="min-h-12 rounded-xl bg-neutral-950 text-white hover:bg-black"
                            >
                                {processing ? (
                                    <Spinner />
                                ) : (
                                    <Undo2 className="size-4" />
                                )}
                                {processing ? 'Reversing…' : 'Confirm reversal'}
                            </Button>
                        </div>
                    ) : (
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => setReversing(true)}
                            className="min-h-12 w-full rounded-xl"
                        >
                            <Undo2 className="size-4" /> Reverse giveaway
                        </Button>
                    )}
                </footer>
            )}
        </div>
    );
}
