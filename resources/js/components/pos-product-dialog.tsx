import { ArrowLeft, Minus, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { PosProductMedia } from '@/components/pos-product-media';
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
import { stockAvailabilityLabel } from '@/lib/pos-order';
import type { CartLine, PosProduct } from '@/types/pos';

export const posDialogClass =
    'pos-surface flex max-h-[92dvh] flex-col gap-0 overflow-hidden rounded-[20px] border-neutral-200 bg-white p-0 text-neutral-950 max-md:h-dvh max-md:max-h-dvh max-md:max-w-full max-md:rounded-none [&>button:last-child]:top-2.5 [&>button:last-child]:right-2.5 [&>button:last-child]:flex [&>button:last-child]:size-11 [&>button:last-child]:items-center [&>button:last-child]:justify-center';

export function PosProductDialog({
    product,
    initial,
    onClose,
    onSave,
    onRemove,
}: {
    product: PosProduct;
    initial?: CartLine;
    onClose: () => void;
    onSave: (line: CartLine) => void;
    onRemove: () => void;
}) {
    const [quantity, setQuantity] = useState(String(initial?.quantity ?? 1));
    const [notes, setNotes] = useState(initial?.notes ?? '');
    const [modifiers, setModifiers] = useState(initial?.modifiers ?? []);
    const validQuantity =
        /^\d+$/.test(quantity) &&
        Number(quantity) >= 1 &&
        Number(quantity) <= 999;
    const groups = product.modifier_groups ?? [];
    const stockLabel = stockAvailabilityLabel(product);
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
            <DialogContent className={`${posDialogClass} pos-product-dialog`}>
                <header className="flex shrink-0 items-center gap-2 border-b border-neutral-200 px-3 py-2.5 pr-14">
                    <button
                        aria-label="Back"
                        onClick={onClose}
                        className="flex size-11 shrink-0 items-center justify-center rounded-xl hover:bg-neutral-100"
                    >
                        <ArrowLeft className="size-5" />
                    </button>
                    <DialogTitle className="text-[15px] font-bold">
                        {initial ? 'Edit item' : 'Customize item'}
                    </DialogTitle>
                    <DialogDescription className="sr-only">
                        Choose options, quantity and special instructions for{' '}
                        {product.name}.
                    </DialogDescription>
                </header>
                <div className="grid min-h-0 flex-1 content-start overflow-y-auto min-[900px]:grid-cols-[.95fr_1.05fr] min-[900px]:content-stretch">
                    <div className="flex min-w-0 flex-col gap-3.5 border-b border-neutral-200 p-4 min-[900px]:border-r min-[900px]:border-b-0">
                        <div className="flex aspect-[16/10] w-full shrink-0 items-center justify-center overflow-hidden rounded-[14px] bg-[#f7f7f7] min-[900px]:aspect-square">
                            <PosProductMedia product={product} detail />
                        </div>
                        <h2 className="text-[22px] leading-tight font-bold tracking-tight wrap-anywhere">
                            {product.name}
                        </h2>
                        <div className="flex flex-wrap items-center gap-2.5">
                            <span className="text-xl font-bold text-red-700">
                                {pesos(product.effective_price)}
                            </span>
                            <span
                                className={`rounded-full px-2.5 py-1 text-[11px] font-semibold ${product.on_hand === 0 ? 'bg-neutral-100 text-neutral-600' : product.stock_status === 'low_stock' ? 'bg-amber-50 text-amber-800' : 'bg-green-50 text-green-700'}`}
                            >
                                {stockLabel}
                            </span>
                        </div>
                        <p className="text-[12.5px] leading-[1.6] text-neutral-500">
                            {product.description || 'No description available.'}
                        </p>
                    </div>
                    <div className="flex min-w-0 flex-col gap-[18px] p-4">
                        <div className="space-y-2">
                            <Label
                                htmlFor="pos-quantity"
                                className="text-[10px] tracking-wider text-neutral-500 uppercase"
                            >
                                Select quantity
                            </Label>
                            <div className="flex w-fit overflow-hidden rounded-xl border border-neutral-300">
                                <button
                                    className="flex size-[52px] items-center justify-center bg-neutral-50 disabled:opacity-40"
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
                                    <Minus className="size-5" />
                                </button>
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
                                    className="h-[52px] w-[58px] rounded-none border-0 px-0 text-center text-lg font-bold shadow-none"
                                    aria-invalid={!validQuantity}
                                />
                                <button
                                    className="flex size-[52px] items-center justify-center bg-neutral-50 disabled:opacity-40"
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
                                    <Plus className="size-5" />
                                </button>
                            </div>
                            {!validQuantity && (
                                <p
                                    role="alert"
                                    className="text-xs text-red-700"
                                >
                                    Enter a whole quantity from 1 to 999.
                                </p>
                            )}
                        </div>
                        {groups.map((group) => (
                            <fieldset key={group.id} className="space-y-2">
                                <legend className="mb-2 text-[10px] font-semibold tracking-wider text-neutral-500 uppercase">
                                    {group.name}{' '}
                                    {group.min_select > 0 && (
                                        <span className="ml-1 rounded-full bg-neutral-950 px-2 py-1 text-[9px] text-white">
                                            Required
                                        </span>
                                    )}
                                </legend>
                                <p className="text-[10px] text-neutral-500">
                                    Choose {group.min_select}–{group.max_select}
                                </p>
                                <div
                                    className={
                                        group.selection_type === 'single'
                                            ? 'flex flex-wrap gap-2'
                                            : 'space-y-2'
                                    }
                                >
                                    {group.options.map((option) => {
                                        const checked = modifiers.some(
                                            (selection) =>
                                                selection.option_id ===
                                                option.id,
                                        );
                                        return (
                                            <label
                                                key={option.id}
                                                className={`flex min-h-11 cursor-pointer items-center gap-2 rounded-xl border px-3 py-2 text-xs font-semibold ${checked ? 'border-neutral-950 bg-neutral-100' : 'border-neutral-200'} ${group.selection_type === 'multiple' ? 'w-full' : ''}`}
                                            >
                                                <input
                                                    type={
                                                        group.selection_type ===
                                                            'single' &&
                                                        group.min_select > 0
                                                            ? 'radio'
                                                            : 'checkbox'
                                                    }
                                                    name={`modifier-${group.id}`}
                                                    checked={checked}
                                                    onChange={() =>
                                                        setModifiers(
                                                            (current) =>
                                                                checked
                                                                    ? current.filter(
                                                                          (
                                                                              selection,
                                                                          ) =>
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
                                                <span className="text-[11px] text-red-700">
                                                    +{pesos(option.price_delta)}
                                                </span>
                                            </label>
                                        );
                                    })}
                                </div>
                                {group.options.length === 0 && (
                                    <p className="text-xs text-red-700">
                                        No options are currently available.
                                    </p>
                                )}
                            </fieldset>
                        ))}
                        <div className="space-y-2">
                            <Label
                                htmlFor="pos-notes"
                                className="text-[10px] tracking-wider text-neutral-500 uppercase"
                            >
                                Special instructions
                            </Label>
                            <textarea
                                id="pos-notes"
                                maxLength={1000}
                                value={notes}
                                onChange={(event) =>
                                    setNotes(event.target.value)
                                }
                                placeholder="e.g. Less oil, no onions, extra sauce on the side"
                                className="min-h-22 w-full rounded-xl border border-neutral-300 p-3 text-base sm:text-[13px]"
                            />
                        </div>
                        {!validModifiers && (
                            <p className="text-xs text-red-700">
                                Complete the required options within each
                                selection limit.
                            </p>
                        )}
                    </div>
                </div>
                <footer className="flex shrink-0 gap-2.5 border-t border-neutral-200 bg-white px-3.5 py-3 pb-[max(12px,env(safe-area-inset-bottom))]">
                    {initial && (
                        <Button
                            variant="outline"
                            className="min-h-12 rounded-xl px-3 text-red-700"
                            onClick={onRemove}
                        >
                            <Trash2 className="size-4" />
                            <span className="sr-only sm:not-sr-only">
                                Remove
                            </span>
                        </Button>
                    )}
                    <Button
                        className="min-h-12 min-w-0 flex-1 gap-2 rounded-xl bg-neutral-950 px-3 text-[13px] text-white hover:bg-black"
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
                        <span className="text-white/50">|</span>
                        {pesos(lineCents(line))}
                    </Button>
                </footer>
            </DialogContent>
        </Dialog>
    );
}
