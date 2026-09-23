import { http } from '@inertiajs/react';
import { ArrowLeft, PackageMinus, Search } from 'lucide-react';
import { useMemo, useState } from 'react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';
import { DialogDescription, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { createClientUuid } from '@/lib/client-uuid';
import {
    INVENTORY_ADJUSTMENT_REASONS,
    inventoryAdjustmentError,
    inventoryAdjustmentPreview,
    type InventoryAdjustmentReason,
} from '@/lib/store-inventory-adjustment';
import { isExpenseWriteOnline } from '@/lib/store-session-expense';
import { store } from '@/routes/store-session-inventory-adjustments';
import type {
    CurrentStoreSession,
    StoreSessionInventoryAdjustment,
} from '@/types';

/** Stock used outside a normal sale: inventory-only, never a Store Expense or Cash/Cashless change. */
export function StoreInventoryAdjustmentForm({
    session,
    onBack,
    onSaved,
}: {
    session: CurrentStoreSession;
    onBack: () => void;
    onSaved: () => Promise<void>;
}) {
    const [reason, setReason] = useState<InventoryAdjustmentReason | null>(
        null,
    );
    const [productId, setProductId] = useState('');
    const [quantity, setQuantity] = useState('1');
    const [note, setNote] = useState('');
    const [search, setSearch] = useState('');
    const [confirming, setConfirming] = useState(false);
    const [attempt, setAttempt] = useState(createClientUuid);
    const [processing, setProcessing] = useState(false);
    const [error, setError] = useState('');
    const product =
        session.restock_products.find((item) => item.id === productId) ?? null;
    const products = useMemo(() => {
        const query = search.trim().toLowerCase();
        return query
            ? session.restock_products.filter((item) =>
                  item.name.toLowerCase().includes(query),
              )
            : session.restock_products;
    }, [search, session.restock_products]);
    const preview = inventoryAdjustmentPreview(
        product?.on_hand ?? null,
        quantity,
    );
    const noteRequired = reason === 'other';
    const reasonLabel =
        INVENTORY_ADJUSTMENT_REASONS.find((item) => item.value === reason)
            ?.label ?? '';
    const canSave =
        reason !== null &&
        product !== null &&
        preview.valid &&
        (!noteRequired || note.trim() !== '');

    function change<T>(setter: (value: T) => void) {
        return (value: T) => {
            setter(value);
            setConfirming(false);
            setError('');
            setAttempt(createClientUuid());
        };
    }

    async function save() {
        if (!canSave || !product || !reason) return;
        if (!isExpenseWriteOnline()) {
            setError('You are offline. Reconnect before adjusting inventory.');
            return;
        }
        setProcessing(true);
        setError('');
        try {
            await http.getClient().request({
                ...store(),
                data: {
                    idempotency_key: attempt,
                    reason_code: reason,
                    product_id: product.id,
                    quantity: preview.quantity,
                    note: note.trim() || null,
                },
                headers: { Accept: 'application/json' },
            });
            toast.success('Inventory adjustment recorded.');
            await onSaved();
        } catch (reasonError) {
            setError(inventoryAdjustmentError(reasonError));
            setConfirming(false);
        } finally {
            setProcessing(false);
        }
    }

    return (
        <div className="flex min-h-0 flex-1 flex-col">
            <header className="flex items-center gap-2 border-b border-neutral-200 pr-12 pb-3 pl-1">
                <button
                    type="button"
                    onClick={onBack}
                    disabled={processing}
                    className="flex size-11 shrink-0 items-center justify-center rounded-xl hover:bg-neutral-100 focus-visible:ring-2 focus-visible:ring-neutral-950 focus-visible:outline-none disabled:opacity-50"
                    aria-label="Back to purchases and expenses"
                >
                    <ArrowLeft className="size-5" />
                </button>
                <div className="min-w-0">
                    <p className="text-[10px] font-semibold tracking-wider text-neutral-500 uppercase">
                        Current Store Session
                    </p>
                    <DialogTitle className="text-base font-bold">
                        Adjust inventory
                    </DialogTitle>
                </div>
            </header>
            <div className="min-h-0 flex-1 space-y-4 overflow-y-auto py-4 pr-1">
                <DialogDescription className="rounded-lg bg-neutral-100 px-3 py-2 text-xs leading-5 text-neutral-700">
                    Record stock used outside a normal sale. This does not
                    affect Cash or Cashless totals.
                </DialogDescription>

                <fieldset className="space-y-1.5">
                    <legend className="text-sm font-medium">Reason</legend>
                    <div className="grid grid-cols-2 gap-2 sm:grid-cols-3">
                        {INVENTORY_ADJUSTMENT_REASONS.map((item) => (
                            <button
                                key={item.value}
                                type="button"
                                aria-pressed={reason === item.value}
                                onClick={() => change(setReason)(item.value)}
                                className={`min-h-11 rounded-xl border px-3 text-left text-xs font-semibold ${reason === item.value ? 'border-neutral-950 bg-neutral-950 text-white' : 'border-neutral-200 hover:bg-neutral-50'}`}
                            >
                                {item.label}
                            </button>
                        ))}
                    </div>
                </fieldset>

                <div className="space-y-1.5">
                    <Label htmlFor="adjust-product-search">Product</Label>
                    <div className="relative">
                        <Search className="absolute top-1/2 left-3 size-4 -translate-y-1/2 text-neutral-400" />
                        <Input
                            id="adjust-product-search"
                            value={search}
                            onChange={(event) => setSearch(event.target.value)}
                            className="min-h-11 rounded-xl pl-9"
                            placeholder="Search tracked products"
                        />
                    </div>
                    <div className="max-h-44 space-y-1 overflow-y-auto rounded-xl border border-neutral-200 p-1">
                        {products.length === 0 ? (
                            <p className="p-3 text-center text-xs text-neutral-500">
                                No tracked products found.
                            </p>
                        ) : (
                            products.map((item) => (
                                <button
                                    key={item.id}
                                    type="button"
                                    aria-pressed={productId === item.id}
                                    disabled={item.on_hand < 1}
                                    onClick={() =>
                                        change(setProductId)(item.id)
                                    }
                                    className={`flex min-h-12 w-full items-center justify-between gap-3 rounded-lg px-3 text-left disabled:opacity-50 ${productId === item.id ? 'bg-neutral-950 text-white' : 'hover:bg-neutral-100'}`}
                                >
                                    <span className="min-w-0">
                                        <span className="block truncate text-sm font-semibold">
                                            {item.name}
                                        </span>
                                        <span className="block text-[11px] opacity-70">
                                            Current stock: {item.on_hand}
                                        </span>
                                    </span>
                                </button>
                            ))
                        )}
                    </div>
                </div>

                <div className="space-y-1.5">
                    <Label htmlFor="adjust-quantity">Quantity</Label>
                    <Input
                        id="adjust-quantity"
                        value={quantity}
                        onChange={(event) =>
                            change(setQuantity)(event.target.value)
                        }
                        inputMode="numeric"
                        type="number"
                        min="1"
                        max={product?.on_hand ?? undefined}
                        step="1"
                        aria-invalid={quantity !== '' && !preview.valid}
                        className="min-h-12 rounded-xl text-base font-bold tabular-nums"
                    />
                    {preview.message && (
                        <p role="alert" className="text-xs text-red-800">
                            {preview.message}
                        </p>
                    )}
                </div>

                <div className="space-y-1.5">
                    <Label htmlFor="adjust-note">
                        Note / explanation{' '}
                        {noteRequired ? '(required)' : '(optional)'}
                    </Label>
                    <textarea
                        id="adjust-note"
                        value={note}
                        onChange={(event) =>
                            change(setNote)(event.target.value)
                        }
                        maxLength={500}
                        rows={2}
                        className="border-input w-full resize-none rounded-xl border bg-transparent px-3 py-2 text-sm outline-none focus-visible:ring-2 focus-visible:ring-neutral-950"
                        placeholder="e.g. Free drink given due to delayed order."
                    />
                </div>

                {product && (
                    <dl
                        aria-label="Stock preview"
                        className="overflow-hidden rounded-xl border border-neutral-200 text-sm tabular-nums"
                    >
                        {[
                            ['Current stock', String(product.on_hand)],
                            [
                                'Adjustment',
                                preview.valid ? `−${preview.quantity}` : '—',
                            ],
                            [
                                'Stock after',
                                preview.valid ? String(preview.after) : '—',
                            ],
                        ].map(([label, value], index) => (
                            <div
                                key={label}
                                className={`flex items-center justify-between gap-4 px-3.5 py-2.5 ${index === 2 ? 'bg-neutral-950 font-bold text-white' : 'border-b border-neutral-100'}`}
                            >
                                <dt className="text-xs">{label}</dt>
                                <dd
                                    className={`font-bold ${index === 1 && preview.valid ? 'text-red-700' : ''}`}
                                >
                                    {value}
                                </dd>
                            </div>
                        ))}
                    </dl>
                )}

                {confirming && product && preview.valid && (
                    <p
                        role="status"
                        className="rounded-xl border border-amber-200 bg-amber-50 p-3 text-xs leading-5 text-amber-950"
                    >
                        <span className="font-bold">{product.name}</span> will
                        decrease from {product.on_hand} to {preview.after}.
                        Reason: {reasonLabel}.
                    </p>
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
                                <PackageMinus className="size-4" />
                            )}
                            {processing
                                ? 'Saving adjustment…'
                                : 'Confirm adjustment'}
                        </Button>
                    </div>
                ) : (
                    <Button
                        type="button"
                        onClick={() => setConfirming(true)}
                        disabled={!canSave}
                        className="min-h-12 w-full rounded-xl bg-neutral-950 text-white hover:bg-black"
                    >
                        <PackageMinus className="size-4" />
                        Save inventory adjustment
                    </Button>
                )}
            </footer>
        </div>
    );
}

const adjustmentTime = new Intl.DateTimeFormat('en-PH', {
    timeZone: 'Asia/Manila',
    month: 'short',
    day: 'numeric',
    hour: 'numeric',
    minute: '2-digit',
});

/** Stock-only history row; it deliberately shows no peso value. */
export function InventoryAdjustmentRow({
    adjustment,
    compact = false,
}: {
    adjustment: StoreSessionInventoryAdjustment;
    compact?: boolean;
}) {
    return (
        <div
            className={`flex items-center gap-3 px-3 ${compact ? 'py-2.5' : 'min-h-16 py-2.5'}`}
        >
            <span
                className={`flex shrink-0 items-center justify-center bg-violet-50 text-violet-700 ${compact ? 'size-8 rounded-lg' : 'size-9 rounded-xl'}`}
            >
                <PackageMinus className="size-4" />
            </span>
            <span className="min-w-0 flex-1">
                <span
                    className={`block truncate font-bold ${compact ? 'text-xs' : 'text-sm'}`}
                >
                    {adjustment.product_name} × {adjustment.quantity}
                </span>
                <span className="mt-0.5 block truncate text-[10px] text-neutral-500">
                    {adjustmentTime.format(new Date(adjustment.created_at))} ·{' '}
                    {adjustment.created_by.name}
                </span>
                <span className="mt-1 flex flex-wrap gap-1">
                    <span className="rounded bg-violet-50 px-1.5 py-0.5 text-[9px] font-bold text-violet-800 uppercase">
                        Stock adjustment · {adjustment.reason_label}
                    </span>
                </span>
                {adjustment.note && (
                    <span className="mt-1 block truncate text-[10px] text-neutral-500 italic">
                        {adjustment.note}
                    </span>
                )}
            </span>
            <span className="shrink-0 text-right">
                <span
                    className={`block font-bold text-violet-700 tabular-nums ${compact ? 'text-xs' : 'text-sm'}`}
                >
                    −{adjustment.quantity}
                </span>
                <span className="text-[9px] font-semibold tracking-wide text-neutral-500 uppercase">
                    Stock only
                </span>
            </span>
        </div>
    );
}
