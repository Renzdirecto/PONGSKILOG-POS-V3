import { Minus, Plus } from 'lucide-react';
import { useState } from 'react';
import { ProductImage } from '@/components/product-forms';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { lineCents, pesos } from '@/lib/pos-money';
import type { CartLine, PosProduct } from '@/types/pos';

export const posDialogClass =
    'pos-surface flex max-h-[100dvh] max-w-full flex-col overflow-y-auto rounded-none bg-white text-neutral-950 max-sm:h-[100dvh] sm:max-h-[90dvh] sm:rounded-xl [&>button:last-child]:size-11 [&>button:last-child]:top-2 [&>button:last-child]:right-2 [&>button:last-child]:flex [&>button:last-child]:items-center [&>button:last-child]:justify-center';

export function PosProductDialog({
    product,
    initial,
    onClose,
    onSave,
}: {
    product: PosProduct;
    initial?: CartLine;
    onClose: () => void;
    onSave: (line: CartLine) => void;
}) {
    const [quantity, setQuantity] = useState(String(initial?.quantity ?? 1));
    const [notes, setNotes] = useState(initial?.notes ?? '');
    const [modifiers, setModifiers] = useState(initial?.modifiers ?? []);
    const validQuantity =
        /^\d+$/.test(quantity) &&
        Number(quantity) >= 1 &&
        Number(quantity) <= 999;
    const groups = product.modifier_groups ?? [];
    const validModifiers = groups.every((group) => {
        const count = modifiers.filter(
            (selection) => selection.group_id === group.id,
        ).length;
        return (
            count >= group.min_select &&
            count <= group.max_select &&
            (group.selection_type !== 'single' || count <= 1)
        );
    });
    const line: CartLine = {
        key: initial?.key ?? '',
        product,
        quantity: validQuantity ? Number(quantity) : 1,
        notes,
        modifiers,
    };

    return (
        <Dialog
            open
            onOpenChange={(open) => {
                if (!open) onClose();
            }}
        >
            <DialogContent className={`${posDialogClass} sm:max-w-3xl`}>
                <DialogTitle className="pr-10 text-base">
                    {initial ? 'Edit item' : 'Customize item'}
                </DialogTitle>
                <DialogDescription className="sr-only">
                    Choose options, quantity and notes for {product.name}.
                </DialogDescription>
                <div className="grid gap-5 sm:grid-cols-2">
                    <div className="min-w-0 space-y-3">
                        <div className="overflow-hidden rounded-xl">
                            <ProductImage product={product} />
                        </div>
                        <h2 className="text-lg font-bold wrap-anywhere">
                            {product.name}
                        </h2>
                        <p className="font-semibold text-red-700">
                            {pesos(product.effective_price)}{' '}
                            <span className="text-xs font-normal text-neutral-500">
                                base price
                            </span>
                        </p>
                    </div>
                    <div className="min-w-0 space-y-5">
                        {groups.map((group) => (
                            <fieldset key={group.id} className="space-y-2">
                                <legend className="text-sm font-bold">
                                    {group.name}{' '}
                                    <span className="font-normal text-neutral-500">
                                        {group.min_select > 0
                                            ? 'Required'
                                            : 'Optional'}{' '}
                                        · choose {group.min_select}–
                                        {group.max_select}
                                    </span>
                                </legend>
                                {group.options.map((option) => {
                                    const checked = modifiers.some(
                                        (selection) =>
                                            selection.option_id === option.id,
                                    );
                                    return (
                                        <label
                                            key={option.id}
                                            className={`flex min-h-11 cursor-pointer items-center gap-3 rounded-lg border p-3 text-sm ${checked ? 'border-neutral-950 bg-neutral-50' : 'border-neutral-200'}`}
                                        >
                                            <input
                                                type="checkbox"
                                                checked={checked}
                                                onChange={() =>
                                                    setModifiers((current) =>
                                                        checked
                                                            ? current.filter(
                                                                  (selection) =>
                                                                      selection.option_id !==
                                                                      option.id,
                                                              )
                                                            : [
                                                                  ...current.filter(
                                                                      (
                                                                          selection,
                                                                      ) =>
                                                                          group.selection_type !==
                                                                              'single' ||
                                                                          selection.group_id !==
                                                                              group.id,
                                                                  ),
                                                                  {
                                                                      group_id:
                                                                          group.id,
                                                                      option_id:
                                                                          option.id,
                                                                  },
                                                              ],
                                                    )
                                                }
                                                className="size-4 accent-neutral-950"
                                            />
                                            <span className="min-w-0 flex-1 wrap-anywhere">
                                                {option.name}
                                            </span>
                                            <span className="text-xs text-red-700">
                                                +{pesos(option.price_delta)}
                                            </span>
                                        </label>
                                    );
                                })}
                                {group.options.length === 0 && (
                                    <p className="text-sm text-red-700">
                                        No options are currently available.
                                    </p>
                                )}
                            </fieldset>
                        ))}
                        <div className="space-y-2">
                            <Label htmlFor="pos-quantity">Quantity</Label>
                            <div className="flex gap-2">
                                <Button
                                    type="button"
                                    variant="outline"
                                    className="size-11"
                                    aria-label="Decrease quantity"
                                    disabled={
                                        !validQuantity || Number(quantity) <= 1
                                    }
                                    onClick={() =>
                                        setQuantity(
                                            String(Number(quantity) - 1),
                                        )
                                    }
                                >
                                    <Minus />
                                </Button>
                                <Input
                                    id="pos-quantity"
                                    type="number"
                                    inputMode="numeric"
                                    min={1}
                                    max={999}
                                    step={1}
                                    value={quantity}
                                    onChange={(event) =>
                                        setQuantity(event.target.value)
                                    }
                                    className="h-11 w-20 text-center text-base"
                                    aria-invalid={!validQuantity}
                                />
                                <Button
                                    type="button"
                                    variant="outline"
                                    className="size-11"
                                    aria-label="Increase quantity"
                                    disabled={
                                        !validQuantity ||
                                        Number(quantity) >= 999
                                    }
                                    onClick={() =>
                                        setQuantity(
                                            String(Number(quantity) + 1),
                                        )
                                    }
                                >
                                    <Plus />
                                </Button>
                            </div>
                            {!validQuantity && (
                                <p
                                    role="alert"
                                    className="text-sm text-red-700"
                                >
                                    Enter a whole quantity from 1 to 999.
                                </p>
                            )}
                        </div>
                        <div className="space-y-2">
                            <Label htmlFor="pos-notes">
                                Notes{' '}
                                <span className="text-neutral-500">
                                    (optional)
                                </span>
                            </Label>
                            <textarea
                                id="pos-notes"
                                maxLength={1000}
                                value={notes}
                                onChange={(event) =>
                                    setNotes(event.target.value)
                                }
                                placeholder="e.g. Less rice, no onions"
                                className="min-h-22 w-full rounded-lg border border-neutral-300 p-3 text-base focus-visible:outline-2"
                            />
                        </div>
                    </div>
                </div>
                <div className="sticky bottom-0 mt-auto flex items-center justify-between gap-3 border-t bg-white pt-4">
                    <span className="text-lg font-bold text-red-700">
                        {pesos(lineCents(line))}
                    </span>
                    <Button
                        className="min-h-12 bg-neutral-950 px-6 text-white hover:bg-neutral-800"
                        disabled={
                            !validQuantity ||
                            !validModifiers ||
                            !product.is_available
                        }
                        onClick={() =>
                            onSave({
                                ...line,
                                key: initial?.key ?? crypto.randomUUID(),
                            })
                        }
                    >
                        {initial ? 'Update cart item' : 'Add to cart'}
                    </Button>
                </div>
                {!validModifiers && (
                    <p className="text-xs text-red-700">
                        Complete the required options within each selection
                        limit.
                    </p>
                )}
            </DialogContent>
        </Dialog>
    );
}
