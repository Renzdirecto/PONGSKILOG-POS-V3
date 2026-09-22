import { useState } from 'react';
import { ArrowLeft, Minus, Plus } from 'lucide-react';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import { qrLineCents } from '@/lib/qr-order';
import { createClientUuid } from '@/lib/client-uuid';
import { pesos } from '@/lib/pos-money';
import type { QrLine, QrProduct } from '@/types/qr';

export const qrButton =
    'inline-flex min-h-11 items-center justify-center gap-2 rounded-xl border border-neutral-200 px-4 text-[13px] font-semibold disabled:cursor-not-allowed disabled:opacity-45';
export const qrPrimary = `${qrButton} border-neutral-950 bg-neutral-950 text-white`;
export const qrPanel = 'rounded-[14px] border border-neutral-200 bg-white p-3';
export function QrImage({
    product,
    className = '',
}: {
    product: QrProduct;
    className?: string;
}) {
    const [failed, setFailed] = useState(false);
    return (
        <div
            className={`flex shrink-0 items-center justify-center overflow-hidden bg-neutral-100 ${className}`}
        >
            {product.image_url && !failed ? (
                <img
                    src={product.image_url}
                    onError={() => setFailed(true)}
                    loading="lazy"
                    alt={product.name}
                    className="size-full object-cover"
                />
            ) : (
                <span className="text-2xl font-bold text-neutral-400">
                    {product.name.slice(0, 2).toUpperCase()}
                </span>
            )}
        </div>
    );
}
export function CustomerQrProduct({
    product,
    initial,
    locked,
    onClose,
    onSave,
    onTrack,
}: {
    product: QrProduct;
    initial?: QrLine;
    locked: boolean;
    onClose: () => void;
    onSave: (line: QrLine) => void;
    onTrack: () => void;
}) {
    const [quantity, setQuantity] = useState(initial?.quantity ?? 1);
    const defaultChoices = () =>
        (product.modifier_groups ?? []).flatMap((group) =>
            group.options
                .slice(0, group.min_select)
                .map((option) => ({
                    group_id: group.id,
                    option_id: option.id,
                })),
        );
    const [modifiers, setModifiers] = useState(
        initial?.modifiers ?? defaultChoices(),
    );
    const [notes, setNotes] = useState(initial?.notes ?? '');
    const groups = product.modifier_groups ?? [];
    const staleChoices = modifiers.some(
        (mod) =>
            !groups.some(
                (group) =>
                    group.id === mod.group_id &&
                    group.options.some((option) => option.id === mod.option_id),
            ),
    );
    const valid =
        !staleChoices &&
        groups.every((group) => {
            const selected = modifiers.filter(
                (mod) => mod.group_id === group.id,
            );
            return (
                selected.length >= group.min_select &&
                selected.length <= group.max_select &&
                selected.every((mod) =>
                    group.options.some((option) => option.id === mod.option_id),
                )
            );
        });
    const line = {
        key: initial?.key ?? '',
        product,
        quantity,
        modifiers,
        notes,
    };
    return (
        <Dialog
            open
            onOpenChange={(open) => {
                if (!open) onClose();
            }}
        >
            <DialogContent className="pos-surface flex max-h-[94dvh] flex-col gap-0 overflow-hidden rounded-2xl bg-white p-0 text-neutral-950 max-sm:top-auto max-sm:bottom-0 max-sm:h-dvh max-sm:max-h-dvh max-sm:max-w-full max-sm:translate-y-0 max-sm:rounded-none sm:max-w-[560px]">
                <header className="flex items-center gap-2 border-b p-2 pr-12">
                    <button
                        className={qrButton}
                        onClick={onClose}
                        aria-label="Back"
                    >
                        <ArrowLeft size={18} />
                    </button>
                    <DialogTitle className="text-[15px] font-bold">
                        {product.name}
                    </DialogTitle>
                    <DialogDescription className="sr-only">
                        Customize your item before adding it to your cart.
                    </DialogDescription>
                </header>
                <div className="flex min-h-0 flex-1 flex-col gap-4 overflow-y-auto p-4">
                    <QrImage
                        product={product}
                        className="aspect-[16/9] w-full rounded-xl"
                    />
                    <div className="flex justify-between gap-3">
                        <h2 className="text-xl font-bold">{product.name}</h2>
                        <strong className="text-red-700">
                            {pesos(product.effective_price)}
                        </strong>
                    </div>
                    <p className="text-xs text-neutral-500">
                        {product.description}
                    </p>
                    {staleChoices && !locked && (
                        <div
                            role="alert"
                            className="rounded-xl bg-amber-50 p-3 text-xs"
                        >
                            <p>
                                Some selected options are no longer available.
                                Review the current choices before adding this
                                item.
                            </p>
                            <button
                                className={`${qrButton} mt-2`}
                                onClick={() => setModifiers(defaultChoices())}
                            >
                                Reset choices
                            </button>
                        </div>
                    )}
                    {!locked && product.is_available ? (
                        <>
                            <div className="flex items-center justify-between">
                                <strong className="text-sm">Quantity</strong>
                                <div className="flex items-center gap-3">
                                    <button
                                        className={qrButton}
                                        disabled={quantity <= 1}
                                        onClick={() =>
                                            setQuantity(quantity - 1)
                                        }
                                        aria-label="Decrease quantity"
                                    >
                                        <Minus size={16} />
                                    </button>
                                    <span>{quantity}</span>
                                    <button
                                        className={qrButton}
                                        disabled={quantity >= 999}
                                        onClick={() =>
                                            setQuantity(quantity + 1)
                                        }
                                        aria-label="Increase quantity"
                                    >
                                        <Plus size={16} />
                                    </button>
                                </div>
                            </div>
                            {groups.map((group) => (
                                <fieldset
                                    key={group.id}
                                    className="flex flex-col gap-2"
                                >
                                    <legend className="mb-2 flex w-full justify-between text-sm font-semibold">
                                        {group.name}
                                        <span className="text-[11px] font-normal text-neutral-500">
                                            {group.min_select
                                                ? `Required · choose ${group.min_select}`
                                                : 'Optional'}
                                            {group.max_select > 1
                                                ? ` · up to ${group.max_select}`
                                                : ''}
                                        </span>
                                    </legend>
                                    <div
                                        className={
                                            group.semantic_role ===
                                            'instruction'
                                                ? 'flex flex-wrap gap-2'
                                                : 'grid gap-2'
                                        }
                                    >
                                        {group.options.map((option) => {
                                            const selected = modifiers.some(
                                                (mod) =>
                                                    mod.option_id ===
                                                        option.id &&
                                                    mod.group_id === group.id,
                                            );
                                            return (
                                                <button
                                                    key={option.id}
                                                    aria-pressed={selected}
                                                    disabled={
                                                        !selected &&
                                                        group.selection_type ===
                                                            'multiple' &&
                                                        modifiers.filter(
                                                            (mod) =>
                                                                mod.group_id ===
                                                                group.id,
                                                        ).length >=
                                                            group.max_select
                                                    }
                                                    className={`${qrButton} justify-between ${selected ? 'border-neutral-950 bg-neutral-950 text-white' : ''}`}
                                                    onClick={() =>
                                                        setModifiers(
                                                            (current) =>
                                                                selected
                                                                    ? current.filter(
                                                                          (
                                                                              mod,
                                                                          ) =>
                                                                              mod.option_id !==
                                                                              option.id,
                                                                      )
                                                                    : [
                                                                          ...current.filter(
                                                                              (
                                                                                  mod,
                                                                              ) =>
                                                                                  group.selection_type !==
                                                                                      'single' ||
                                                                                  mod.group_id !==
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
                                                >
                                                    <span>{option.name}</span>
                                                    {group.semantic_role !==
                                                        'instruction' &&
                                                        option.price_delta !==
                                                            '0.00' && (
                                                            <span>
                                                                +
                                                                {pesos(
                                                                    option.price_delta,
                                                                )}
                                                            </span>
                                                        )}
                                                </button>
                                            );
                                        })}
                                    </div>
                                </fieldset>
                            ))}
                            <label className="flex flex-col gap-2 text-sm font-semibold">
                                Special instructions
                                <span className="text-xs font-normal text-neutral-500">
                                    Add a note for the kitchen. Optional.
                                </span>
                                <textarea
                                    className="rounded-xl border border-neutral-200 p-3 text-base font-normal"
                                    rows={2}
                                    maxLength={120}
                                    placeholder="e.g. No onions"
                                    value={notes}
                                    onChange={(event) =>
                                        setNotes(event.target.value)
                                    }
                                />
                            </label>
                        </>
                    ) : (
                        <div className="rounded-xl bg-amber-50 p-3 text-sm">
                            <strong>
                                {locked
                                    ? 'May current order ka pa'
                                    : 'This item is unavailable'}
                            </strong>
                            <p className="mt-1 text-xs">
                                {locked
                                    ? 'Browse-only ang menu habang may active order. May additional order? Please approach the cashier.'
                                    : 'Your selections have been kept. You can remove this item from the cart.'}
                            </p>
                        </div>
                    )}
                </div>
                <footer className="border-t p-3">
                    {locked ? (
                        <button
                            className={`${qrPrimary} w-full`}
                            onClick={onTrack}
                        >
                            View current order
                        </button>
                    ) : (
                        <button
                            className={`${qrPrimary} w-full justify-between`}
                            disabled={!product.is_available || !valid}
                            onClick={() =>
                                onSave({
                                    ...line,
                                    key: line.key || createClientUuid(),
                                })
                            }
                        >
                            <span>
                                {initial ? 'Update item' : 'Add to cart'}
                            </span>
                            <span>{pesos(qrLineCents(line))}</span>
                        </button>
                    )}
                </footer>
            </DialogContent>
        </Dialog>
    );
}
