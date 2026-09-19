import { Pencil, ShoppingBag, Trash2 } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { lineCents, pesos, selectedOptions } from '@/lib/pos-money';
import type { CartLine } from '@/types/pos';

export function PosCart({
    lines,
    onEdit,
    onRemove,
    onReview,
}: {
    lines: CartLine[];
    onEdit: (line: CartLine) => void;
    onRemove: (key: string) => void;
    onReview: () => void;
}) {
    return (
        <div className="flex min-w-0 flex-col gap-4">
            <div className="flex items-center justify-between border-b pb-4">
                <h2 className="text-[15px] font-bold">Current order</h2>
                <span className="text-xs text-neutral-500">
                    {lines.reduce((sum, line) => sum + line.quantity, 0)} items
                </span>
            </div>
            {lines.length === 0 ? (
                <div className="flex flex-col items-center gap-3 py-12 text-center">
                    <ShoppingBag className="size-10 text-neutral-400" />
                    <h3 className="text-sm font-bold">Cart is empty</h3>
                    <p className="max-w-52 text-xs leading-5 text-neutral-500">
                        Choose your order type, then tap a product to customize
                        it.
                    </p>
                </div>
            ) : (
                <ul className="space-y-3">
                    {lines.map((line) => (
                        <li key={line.key} className="border-b pb-3">
                            <div className="flex items-start gap-2 text-sm font-semibold">
                                <span className="text-red-700">
                                    {line.quantity}×
                                </span>
                                <span className="min-w-0 flex-1 wrap-anywhere">
                                    {line.product.name}
                                </span>
                                <span className="shrink-0 text-red-700">
                                    {pesos(lineCents(line))}
                                </span>
                            </div>
                            {selectedOptions(line).map((option) => (
                                <p
                                    key={option.id}
                                    className="mt-1 text-xs text-neutral-500"
                                >
                                    {option.name} (+{pesos(option.price_delta)})
                                </p>
                            ))}
                            {line.notes && (
                                <p className="mt-2 rounded-md bg-orange-50 p-2 text-xs wrap-anywhere whitespace-pre-wrap text-orange-900">
                                    {line.notes}
                                </p>
                            )}
                            <div className="mt-1 flex justify-end gap-1">
                                <Button
                                    variant="ghost"
                                    className="min-h-11 px-3 text-xs"
                                    onClick={() => onEdit(line)}
                                    aria-label={`Edit ${line.product.name}`}
                                >
                                    <Pencil className="size-3.5" /> Edit
                                </Button>
                                <Button
                                    variant="ghost"
                                    className="min-h-11 px-3 text-xs text-red-700"
                                    onClick={() => onRemove(line.key)}
                                    aria-label={`Remove ${line.product.name}`}
                                >
                                    <Trash2 className="size-3.5" /> Remove
                                </Button>
                            </div>
                        </li>
                    ))}
                </ul>
            )}
            <div className="mt-auto space-y-3 border-t pt-4">
                <div className="flex justify-between text-sm">
                    <span>Subtotal</span>
                    <span>
                        {pesos(
                            lines.reduce(
                                (sum, line) => sum + lineCents(line),
                                0n,
                            ),
                        )}
                    </span>
                </div>
                <div className="flex justify-between font-bold">
                    <span>Total</span>
                    <span className="text-red-700">
                        {pesos(
                            lines.reduce(
                                (sum, line) => sum + lineCents(line),
                                0n,
                            ),
                        )}
                    </span>
                </div>
                <p className="text-xs leading-5 text-neutral-500">
                    Prices and availability are checked when you review the
                    order.
                </p>
                <Button
                    className="min-h-12 w-full bg-neutral-950 text-white hover:bg-neutral-800"
                    disabled={lines.length === 0}
                    onClick={onReview}
                >
                    Order information
                </Button>
            </div>
        </div>
    );
}
