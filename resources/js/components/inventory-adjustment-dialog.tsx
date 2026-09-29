import { useForm } from '@inertiajs/react';
import { Check } from 'lucide-react';
import { useRef, useState } from 'react';
import { toast } from 'sonner';
import {
    actionClass,
    controlClass,
    Field,
    FormErrors,
} from '@/components/catalog-ui';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { store } from '@/routes/inventory/adjustments';
import type { BranchSummary } from '@/types';
import type { InventoryProduct } from '@/types/inventory';

type AdjustmentMode = 'add' | 'remove' | 'set';

export function InventoryAdjustmentDialog({
    product,
    branch,
    onClose,
}: {
    product: InventoryProduct;
    branch: BranchSummary;
    onClose: () => void;
}) {
    const form = useForm({ quantity_delta: '', reason: '' });
    const submitting = useRef(false);
    const [mode, setMode] = useState<AdjustmentMode>('add');
    const quantity = /^\d+$/.test(form.data.quantity_delta.trim())
        ? Number(form.data.quantity_delta)
        : null;
    const currentOnHand = product.on_hand ?? 0;
    const quantityDelta =
        quantity === null
            ? null
            : mode === 'add'
              ? quantity
              : mode === 'remove'
                ? -quantity
                : quantity - currentOnHand;
    const projectedOnHand =
        quantityDelta !== null &&
        Number.isSafeInteger(quantityDelta) &&
        Number.isSafeInteger(currentOnHand + quantityDelta)
            ? currentOnHand + quantityDelta
            : null;

    return (
        <Dialog
            open
            onOpenChange={(open) => {
                if (!open && !submitting.current) onClose();
            }}
        >
            <DialogContent className="owner-surface top-auto bottom-0 flex max-h-[92dvh] w-full max-w-none translate-y-0 flex-col gap-0 overflow-hidden rounded-t-[20px] rounded-b-none border-[#e5e5e5] bg-white p-0 text-neutral-950 sm:top-1/2 sm:bottom-auto sm:max-w-lg sm:-translate-y-1/2 sm:rounded-[18px] [&>button]:top-2 [&>button]:right-2 [&>button]:flex [&>button]:min-h-11 [&>button]:min-w-11 [&>button]:items-center [&>button]:justify-center">
                <DialogHeader className="shrink-0 gap-0.5 border-b border-neutral-200 px-4 py-3 pr-12 text-left">
                    <DialogDescription className="order-first text-[10px] font-semibold tracking-[0.09em] text-neutral-500 uppercase">
                        Adjust stock
                    </DialogDescription>
                    <DialogTitle className="text-[16px] font-bold">
                        {product.name}
                    </DialogTitle>
                    <p className="text-[11px] text-neutral-500">
                        {branch.name} · {branch.code}
                    </p>
                </DialogHeader>

                <form
                    className="flex min-h-0 flex-1 flex-col"
                    aria-busy={form.processing}
                    onSubmit={(event) => {
                        event.preventDefault();
                        if (submitting.current) return;
                        form.clearErrors();

                        if (
                            quantity === null ||
                            !Number.isSafeInteger(quantity) ||
                            (mode !== 'set' && quantity <= 0)
                        ) {
                            form.setError(
                                'quantity_delta',
                                mode === 'set'
                                    ? 'Enter a non-negative whole-number stock count.'
                                    : 'Enter a whole-number quantity greater than zero.',
                            );
                            return;
                        }
                        if (quantityDelta === 0) {
                            form.setError(
                                'quantity_delta',
                                'The resulting stock must be different from the current stock.',
                            );
                            return;
                        }
                        if (projectedOnHand === null || projectedOnHand < 0) {
                            form.setError(
                                'quantity_delta',
                                'This adjustment would produce a negative stock count.',
                            );
                            return;
                        }
                        if (!form.data.reason.trim()) {
                            form.setError(
                                'reason',
                                'Enter a reason for this adjustment.',
                            );
                            return;
                        }

                        form.transform((data) => ({
                            ...data,
                            quantity_delta: quantityDelta,
                            reason: data.reason.trim(),
                        }));
                        submitting.current = true;
                        form.submit(
                            store({ branch: branch.id, product: product.id }),
                            {
                                preserveScroll: true,
                                onSuccess: () => {
                                    toast.success('Stock adjusted');
                                    onClose();
                                },
                                onFinish: () => {
                                    submitting.current = false;
                                },
                            },
                        );
                    }}
                >
                    <div className="min-h-0 flex-1 space-y-4 overflow-y-auto p-4">
                        <div className="flex items-center justify-between gap-3 rounded-xl border border-neutral-200 bg-neutral-50 p-3.5">
                            <span className="text-xs text-neutral-500">
                                Current stock
                            </span>
                            <span className="text-xl font-bold tabular-nums">
                                {currentOnHand.toLocaleString()}
                            </span>
                        </div>

                        <fieldset
                            disabled={form.processing}
                            className="space-y-4"
                        >
                            <div className="space-y-1.5">
                                <p className="text-[11px] font-semibold tracking-[0.06em] text-neutral-500 uppercase">
                                    Adjustment type
                                </p>
                                <div
                                    role="tablist"
                                    aria-label="Adjustment type"
                                    className="grid grid-cols-3 gap-[3px] rounded-xl bg-neutral-100 p-[3px]"
                                >
                                    {(['add', 'remove', 'set'] as const).map(
                                        (option) => (
                                            <button
                                                key={option}
                                                type="button"
                                                role="tab"
                                                aria-selected={mode === option}
                                                className={`min-h-11 rounded-[9px] text-[13.5px] font-semibold capitalize transition ${mode === option ? 'bg-neutral-950 text-white' : 'text-neutral-600 hover:bg-white'}`}
                                                onClick={() => {
                                                    setMode(option);
                                                    form.clearErrors(
                                                        'quantity_delta',
                                                    );
                                                }}
                                            >
                                                {option}
                                            </button>
                                        ),
                                    )}
                                </div>
                            </div>

                            <Field
                                id="quantity_delta"
                                label={
                                    mode === 'set'
                                        ? 'New stock count'
                                        : 'Quantity'
                                }
                                error={form.errors.quantity_delta}
                            >
                                <input
                                    id="quantity_delta"
                                    name="quantity_delta"
                                    type="number"
                                    min={0}
                                    step={1}
                                    required
                                    value={form.data.quantity_delta}
                                    placeholder="0"
                                    className={`${controlClass} h-[52px] text-[17px] font-semibold tabular-nums`}
                                    aria-invalid={!!form.errors.quantity_delta}
                                    onChange={(event) =>
                                        form.setData(
                                            'quantity_delta',
                                            event.target.value,
                                        )
                                    }
                                />
                            </Field>

                            <Field
                                id="reason"
                                label="Reason"
                                error={form.errors.reason}
                            >
                                <textarea
                                    id="reason"
                                    name="reason"
                                    required
                                    rows={3}
                                    maxLength={1000}
                                    value={form.data.reason}
                                    placeholder="Stock delivery, damaged stock, recount, or another reason"
                                    className={`${controlClass} h-auto min-h-24 py-3`}
                                    aria-invalid={!!form.errors.reason}
                                    onChange={(event) =>
                                        form.setData(
                                            'reason',
                                            event.target.value,
                                        )
                                    }
                                />
                                <p className="text-[11.5px] leading-5 text-neutral-500">
                                    Every adjustment is recorded with its
                                    free-text reason. Stock is never overwritten
                                    silently.
                                </p>
                            </Field>
                        </fieldset>

                        <div
                            className={`flex items-center justify-between gap-3 rounded-xl border p-3.5 ${projectedOnHand !== null && projectedOnHand < 0 ? 'border-red-300 bg-red-50 text-red-800' : 'border-neutral-950 bg-white'}`}
                            aria-live="polite"
                        >
                            <span className="text-[12.5px] font-semibold">
                                Resulting stock
                            </span>
                            <span className="text-[22px] font-bold tabular-nums">
                                {projectedOnHand?.toLocaleString() ?? '—'}
                            </span>
                        </div>
                    </div>

                    <div className="shrink-0 border-t border-neutral-200 bg-white px-4 pt-3 pb-[calc(14px+env(safe-area-inset-bottom,0px))]">
                        <FormErrors
                            errors={form.errors}
                            inline={['quantity_delta', 'reason']}
                        />
                        <div className="mt-2 grid grid-cols-[auto_minmax(0,1fr)] gap-2">
                            <Button
                                type="button"
                                variant="outline"
                                className={`${actionClass} min-h-[52px] px-5`}
                                disabled={form.processing}
                                onClick={onClose}
                            >
                                Cancel
                            </Button>
                            <Button
                                type="submit"
                                className="min-h-[52px] rounded-xl bg-neutral-950 text-[14.5px] font-semibold text-white hover:bg-neutral-800"
                                disabled={form.processing}
                            >
                                <Check className="size-4" />
                                {form.processing
                                    ? 'Saving…'
                                    : 'Confirm adjustment'}
                            </Button>
                        </div>
                    </div>
                </form>
            </DialogContent>
        </Dialog>
    );
}
