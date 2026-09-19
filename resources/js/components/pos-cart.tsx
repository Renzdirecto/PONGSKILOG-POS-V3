import { Pencil, ShoppingBag, Trash2, UtensilsCrossed } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { PosProductMedia } from '@/components/pos-product-media';
import { lineCents, pesos, selectedOptions } from '@/lib/pos-money';
import type { CartLine, OrderSummary, OrderType } from '@/types/pos';

export function PosCart({
    lines,
    orderType,
    saved,
    customer,
    onTypeChange,
    onEdit,
    onRemove,
    onReview,
    onClear,
}: {
    lines: CartLine[];
    orderType: OrderType | null;
    saved: OrderSummary | null;
    customer: string;
    onTypeChange: (type: OrderType) => void;
    onEdit: (line: CartLine) => void;
    onRemove: (key: string) => void;
    onReview: () => void;
    onClear: () => void;
}) {
    const total = saved
        ? pesos(saved.total)
        : pesos(lines.reduce((sum, line) => sum + lineCents(line), 0n));
    const count = (saved?.items ?? lines).reduce(
        (sum, line) => sum + line.quantity,
        0,
    );
    return (
        <div className="flex h-full min-h-0 min-w-0 flex-col bg-white">
            <div className="flex shrink-0 items-start justify-between gap-2 border-b border-neutral-200 p-3.5">
                <div className="min-w-0">
                    <h2 className="text-[10px] font-semibold tracking-[.09em] text-neutral-500 uppercase">
                        Current order
                    </h2>
                    <p
                        className={`${saved ? 'text-lg' : 'text-[26px]'} font-bold tracking-tight wrap-anywhere`}
                    >
                        {saved ? `#${saved.order_number}` : 'New order'}
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
                {count > 0 && (
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
                                : 'Start a new order and select Dine in or Take out.'}
                        </p>
                    </div>
                ) : (
                    <ul>
                        {(saved
                            ? saved.items.map((item) => ({
                                  key: item.id,
                                  name: item.name,
                                  quantity: item.quantity,
                                  amount: pesos(item.line_total),
                                  notes: item.notes,
                                  modifiers: item.modifiers,
                                  product: null,
                                  line: null,
                              }))
                            : lines.map((line) => ({
                                  key: line.key,
                                  name: line.product.name,
                                  quantity: line.quantity,
                                  amount: pesos(lineCents(line)),
                                  notes: line.notes,
                                  modifiers: selectedOptions(line),
                                  product: line.product,
                                  line,
                              }))
                        ).map((row) => (
                            <li
                                key={row.key}
                                className="border-b border-neutral-100 px-3 py-3"
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
                                            <span className="text-red-700">
                                                {row.quantity}&times;
                                            </span>
                                            <span className="wrap-anywhere">
                                                {row.name}
                                            </span>
                                        </div>
                                        {row.modifiers.map((option) => (
                                            <p
                                                key={option.id}
                                                className="mt-1 text-[11px] leading-4 text-amber-800"
                                            >
                                                {option.name} (+
                                                {pesos(option.price_delta)})
                                            </p>
                                        ))}
                                        {row.notes && (
                                            <p className="mt-1 rounded-md bg-orange-50 p-1.5 text-[11px] wrap-anywhere whitespace-pre-wrap text-amber-800">
                                                {row.notes}
                                            </p>
                                        )}
                                    </div>
                                    <span className="shrink-0 text-sm font-bold text-red-700">
                                        {row.amount}
                                    </span>
                                </div>
                                {row.line && (
                                    <div className="flex justify-end gap-1">
                                        <button
                                            className="flex min-h-11 items-center gap-1 px-3 text-xs text-neutral-500"
                                            onClick={() => {
                                                if (row.line) onEdit(row.line);
                                            }}
                                            aria-label={`Edit ${row.name}`}
                                        >
                                            <Pencil className="size-3.5" />
                                            Edit
                                        </button>
                                        <button
                                            className="flex size-11 items-center justify-center text-red-700"
                                            onClick={() => onRemove(row.key)}
                                            aria-label={`Remove ${row.name}`}
                                        >
                                            <Trash2 className="size-4" />
                                        </button>
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
                    className="flex rounded-xl bg-neutral-100 p-1"
                >
                    {(['dine_in', 'take_out'] as const).map((type) => (
                        <button
                            key={type}
                            aria-pressed={orderType === type}
                            disabled={!!saved}
                            onClick={() => onTypeChange(type)}
                            className={`flex min-h-11 min-w-0 flex-1 items-center justify-center gap-2 rounded-[9px] text-xs font-semibold ${orderType === type ? (type === 'dine_in' ? 'bg-green-700 text-white shadow-sm' : 'bg-sky-700 text-white shadow-sm') : 'text-neutral-500'}`}
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
                <div className="flex justify-between text-[11px] text-neutral-500">
                    <span>
                        {count} {count === 1 ? 'item' : 'items'}
                    </span>
                    <span>
                        Subtotal {saved ? pesos(saved.subtotal) : total}
                    </span>
                </div>
                <div className="flex items-baseline justify-between">
                    <span className="text-sm font-semibold">Total</span>
                    <span className="text-[28px] font-bold tracking-tight text-red-700">
                        {total}
                    </span>
                </div>
                <Button
                    variant="outline"
                    className="min-h-11 rounded-xl text-xs"
                    disabled={count === 0}
                    onClick={onReview}
                >
                    {saved ? 'View order information' : 'Order information'}
                </Button>
                <div className="flex gap-2">
                    <button
                        disabled
                        aria-describedby="pos-payment-hint"
                        className="min-h-12 shrink-0 rounded-xl border border-amber-300 bg-amber-50 px-3 text-xs font-semibold text-amber-800 opacity-50"
                    >
                        Save · pay later
                    </button>
                    <button
                        disabled
                        aria-describedby="pos-payment-hint"
                        className="min-h-12 min-w-0 flex-1 rounded-xl bg-green-700 px-3 text-sm font-semibold text-white opacity-50"
                    >
                        Pay now
                    </button>
                </div>
                <p
                    id="pos-payment-hint"
                    className="text-center text-[10px] leading-4 text-neutral-500"
                >
                    Payment and Pay Later are not available yet.{' '}
                    {saved
                        ? 'Start a new order to make changes.'
                        : 'Order information saves a draft only.'}
                </p>
            </footer>
        </div>
    );
}
