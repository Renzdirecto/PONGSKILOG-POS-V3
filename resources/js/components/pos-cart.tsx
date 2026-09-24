import {
    Minus,
    Plus,
    Pencil,
    ShoppingBag,
    Trash2,
    UtensilsCrossed,
} from 'lucide-react';
import { Button } from '@/components/ui/button';
import { PosProductMedia } from '@/components/pos-product-media';
import { OperationalItemName } from '@/components/operational-item-name';
import { PosModifierDetails } from '@/components/pos-modifier-details';
import {
    editableLines,
    lineCents,
    orderItemCount,
    orderTotalCents,
    pesos,
    selectedOptions,
} from '@/lib/pos-money';
import { cartItemName, savedItemName } from '@/lib/pos-item-name';
import { orderNumberLabel } from '@/lib/pos-order';
import type { CartLine, OrderSummary, OrderType } from '@/types/pos';

export function PosCart({
    lines,
    orderType,
    saved,
    orderNumber,
    customer,
    onTypeChange,
    onEdit,
    onRemove,
    onQuantityChange,
    onCheckout,
    onClear,
}: {
    lines: CartLine[];
    orderType: OrderType | null;
    saved: OrderSummary | null;
    orderNumber: string | null;
    customer: string;
    onTypeChange: (type: OrderType) => void;
    onEdit: (line: CartLine) => void;
    onRemove: (key: string) => void;
    onQuantityChange: (key: string, quantity: number) => void;
    onCheckout: (flow: 'information' | 'payment') => void;
    onClear: () => void;
}) {
    /** A loaded QR order keeps its submitted items read-only; items the Cashier adds stay editable below them. */
    const extra = editableLines(saved, lines);
    const total = pesos(orderTotalCents(saved, lines));
    const count = orderItemCount(saved, lines);
    return (
        <div className="flex h-full min-h-0 min-w-0 flex-col bg-white">
            <div className="flex shrink-0 items-start justify-between gap-2 border-b border-neutral-200 p-3.5">
                <div className="min-w-0">
                    <h2 className="text-[10px] font-semibold tracking-[.09em] text-neutral-500 uppercase">
                        Current order
                    </h2>
                    <p className="text-[16px] font-bold tracking-tight wrap-anywhere">
                        {orderNumberLabel(orderNumber)}
                    </p>
                    {customer && (
                        <p className="text-sm font-bold wrap-anywhere text-red-700">
                            {customer}
                        </p>
                    )}
                    {saved && (
                        <p className="text-[10px] text-neutral-500">
                            Draft · not sent to kitchen
                        </p>
                    )}
                </div>
                {orderType && (
                    <Button
                        variant="outline"
                        className="size-11 shrink-0 rounded-xl text-neutral-500"
                        aria-label={
                            saved ? 'Start another order' : 'Clear order'
                        }
                        onClick={onClear}
                    >
                        <Trash2 className="size-4" />
                    </Button>
                )}
            </div>
            <div className="min-h-0 flex-1 overflow-y-auto">
                {count === 0 ? (
                    <div className="flex h-full min-h-48 flex-col items-center justify-center gap-3 px-6 py-10 text-center">
                        <span className="flex size-14 items-center justify-center rounded-2xl bg-neutral-100 text-neutral-400">
                            <ShoppingBag className="size-7" strokeWidth={1.5} />
                        </span>
                        <h3 className="text-[15px] font-semibold">
                            Your cart is empty
                        </h3>
                        <p className="max-w-56 text-xs leading-5 text-neutral-500">
                            {orderType
                                ? 'Tap a product to add it to your order.'
                                : 'Select Dine in or Take out to begin.'}
                        </p>
                    </div>
                ) : (
                    <ul>
                        {[
                            ...(saved?.items ?? []).map((item) => ({
                                key: item.id,
                                itemName: savedItemName(item),
                                quantity: item.quantity,
                                amount: pesos(item.line_total),
                                notes: item.notes,
                                modifiers: item.modifiers,
                                product: null,
                                line: null,
                            })),
                            ...extra.map((line) => ({
                                key: line.key,
                                itemName: cartItemName(line),
                                quantity: line.quantity,
                                amount: pesos(lineCents(line)),
                                notes: line.notes,
                                modifiers: selectedOptions(line),
                                product: line.product,
                                line,
                            })),
                        ].map((row) => (
                            <li
                                key={row.key}
                                className="flex flex-col gap-[9px] border-b border-neutral-100 px-[13px] py-[11px]"
                            >
                                <div className="flex items-start gap-2">
                                    <span className="flex size-[42px] shrink-0 items-center justify-center overflow-hidden rounded-[10px] border border-neutral-100 bg-[#f7f7f7]">
                                        {row.product ? (
                                            <PosProductMedia
                                                product={row.product}
                                            />
                                        ) : (
                                            <ShoppingBag className="size-5 text-neutral-300" />
                                        )}
                                    </span>
                                    <div className="min-w-0 flex-1">
                                        <div className="flex gap-1.5 text-sm font-semibold">
                                            <span className="shrink-0 text-[13px] font-bold text-red-700">
                                                {row.quantity}&times;
                                            </span>
                                            <OperationalItemName
                                                value={row.itemName}
                                            />
                                        </div>
                                        <PosModifierDetails
                                            modifiers={row.modifiers}
                                            notes={row.notes}
                                            standardClassName="mt-1 text-[11.5px] leading-4 text-amber-800"
                                            instructionClassName="mt-1 rounded-md bg-amber-50 p-1.5 text-[11px] leading-4 text-amber-900"
                                        />
                                    </div>
                                    <span className="shrink-0 text-sm font-bold text-red-700">
                                        {row.amount}
                                    </span>
                                </div>
                                {row.line && (
                                    <div className="flex items-center justify-between gap-2">
                                        <div className="flex shrink-0 overflow-hidden rounded-[10px] border border-neutral-300">
                                            <button
                                                className="flex size-[46px] items-center justify-center hover:bg-neutral-100 disabled:opacity-40"
                                                aria-label={`Decrease ${row.itemName.displayName} quantity`}
                                                disabled={row.quantity <= 1}
                                                onClick={() =>
                                                    onQuantityChange(
                                                        row.key,
                                                        row.quantity - 1,
                                                    )
                                                }
                                            >
                                                <Minus className="size-4" />
                                            </button>
                                            <span className="flex h-[46px] w-[42px] items-center justify-center border-x border-neutral-200 text-[15px] font-bold">
                                                {row.quantity}
                                            </span>
                                            <button
                                                className="flex size-[46px] items-center justify-center hover:bg-neutral-100 disabled:opacity-40"
                                                aria-label={`Increase ${row.itemName.displayName} quantity`}
                                                disabled={row.quantity >= 999}
                                                onClick={() =>
                                                    onQuantityChange(
                                                        row.key,
                                                        row.quantity + 1,
                                                    )
                                                }
                                            >
                                                <Plus className="size-4" />
                                            </button>
                                        </div>
                                        <div className="flex gap-1.5">
                                            <button
                                                className="flex h-[46px] items-center gap-1 rounded-[10px] border border-neutral-200 px-3 text-[12.5px] font-semibold"
                                                onClick={() => {
                                                    if (row.line)
                                                        onEdit(row.line);
                                                }}
                                                aria-label={`Edit ${row.itemName.displayName}`}
                                            >
                                                <Pencil className="size-3.5" />
                                                Edit
                                            </button>
                                            <button
                                                className="flex size-[46px] items-center justify-center rounded-[10px] border border-neutral-200 text-red-700"
                                                onClick={() =>
                                                    onRemove(row.key)
                                                }
                                                aria-label={`Remove ${row.itemName.displayName}`}
                                            >
                                                <Trash2 className="size-4" />
                                            </button>
                                        </div>
                                    </div>
                                )}
                            </li>
                        ))}
                    </ul>
                )}
            </div>
            <footer className="flex shrink-0 flex-col gap-3 border-t border-neutral-200 p-3.5">
                <div
                    aria-label="Order type"
                    className="flex gap-[3px] rounded-[11px] bg-[#f2f2f2] p-[3px]"
                >
                    {(['dine_in', 'take_out'] as const).map((type) => (
                        <button
                            key={type}
                            aria-pressed={orderType === type}
                            disabled={!!saved && lines.length === 0}
                            title={
                                saved && lines.length === 0
                                    ? 'This recovered snapshot is read-only. Clear it to start another order.'
                                    : undefined
                            }
                            onClick={() => onTypeChange(type)}
                            className={`flex h-[42px] min-w-0 flex-1 items-center justify-center gap-2 rounded-[9px] text-[13px] font-semibold ${orderType === type ? (type === 'dine_in' ? 'bg-green-700 text-white shadow-sm' : 'bg-sky-700 text-white shadow-sm') : 'text-neutral-500'}`}
                        >
                            {type === 'dine_in' ? (
                                <UtensilsCrossed className="size-4" />
                            ) : (
                                <ShoppingBag className="size-4" />
                            )}
                            {type === 'dine_in' ? 'Dine in' : 'Take out'}
                        </button>
                    ))}
                </div>
                <div className="flex justify-between text-[12.5px] text-neutral-500">
                    <span>
                        {count} {count === 1 ? 'item' : 'items'}
                    </span>
                    <span>
                        Subtotal{' '}
                        {saved && extra.length === 0
                            ? pesos(saved.subtotal)
                            : total}
                    </span>
                </div>
                <div className="flex items-baseline justify-between">
                    <span className="text-[15px] font-semibold">Total</span>
                    <span className="text-[27px] font-bold tracking-tight text-red-700">
                        {total}
                    </span>
                </div>
                <div className="flex gap-2">
                    <button
                        disabled={!orderType || count === 0 || !orderNumber}
                        onClick={() => onCheckout('information')}
                        className="h-12 shrink-0 rounded-xl border border-amber-400 bg-amber-50 px-3.5 text-[13.5px] font-semibold text-amber-800 disabled:opacity-50"
                    >
                        Save &middot; pay later
                    </button>
                    <button
                        disabled={!orderType || count === 0 || !orderNumber}
                        onClick={() => onCheckout('payment')}
                        className="h-12 min-w-0 flex-1 rounded-xl bg-green-700 px-3 text-[15.5px] font-semibold text-white disabled:opacity-50"
                    >
                        Pay now
                    </button>
                </div>
            </footer>
        </div>
    );
}
