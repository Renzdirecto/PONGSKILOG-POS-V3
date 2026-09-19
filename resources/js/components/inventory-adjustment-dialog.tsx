import { useForm } from '@inertiajs/react';
import { useRef } from 'react';
import { toast } from 'sonner';
import {
    actionClass,
    controlClass,
    Field,
    FormErrors,
    primaryActionClass,
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
    const adjustment = form.data.quantity_delta.trim();
    const quantityDelta = /^[+-]?\d+$/.test(adjustment)
        ? Number(adjustment)
        : null;
    const currentOnHand = product.on_hand ?? 0;
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
            <DialogContent className="max-h-[90svh] overflow-y-auto bg-white p-4 text-neutral-950 sm:max-w-lg sm:p-6 [&>button]:top-1 [&>button]:right-1 [&>button]:flex [&>button]:min-h-11 [&>button]:min-w-11 [&>button]:items-center [&>button]:justify-center">
                <DialogHeader className="pr-6 text-left">
                    <DialogTitle className="text-[17px] font-bold">
                        Adjust stock
                    </DialogTitle>
                    <DialogDescription className="break-words text-neutral-600">
                        {product.name} · {branch.name} ({branch.code})
                    </DialogDescription>
                </DialogHeader>
                <form
                    className="flex flex-col gap-4"
                    aria-busy={form.processing}
                    onSubmit={(event) => {
                        event.preventDefault();
                        if (submitting.current) return;
                        form.clearErrors();
                        if (
                            quantityDelta === null ||
                            !Number.isSafeInteger(quantityDelta) ||
                            quantityDelta === 0
                        ) {
                            form.setError(
                                'quantity_delta',
                                'Enter a non-zero whole number, such as +10 or -3.',
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
                    <div className="rounded-xl bg-neutral-50 p-3">
                        <p className="text-xs text-neutral-600">
                            Current on hand
                        </p>
                        <p className="text-lg font-bold tabular-nums">
                            {currentOnHand.toLocaleString()}
                        </p>
                    </div>
                    <fieldset
                        disabled={form.processing}
                        className="flex min-w-0 flex-col gap-4"
                    >
                        <Field
                            id="quantity_delta"
                            label="Adjustment"
                            error={form.errors.quantity_delta}
                        >
                            <input
                                id="quantity_delta"
                                name="quantity_delta"
                                type="text"
                                required
                                maxLength={16}
                                value={form.data.quantity_delta}
                                placeholder="+10 or -3"
                                className={controlClass}
                                aria-invalid={!!form.errors.quantity_delta}
                                aria-describedby={`adjustment-help${form.errors.quantity_delta ? ' quantity_delta-error' : ''}`}
                                onChange={(event) =>
                                    form.setData(
                                        'quantity_delta',
                                        event.target.value,
                                    )
                                }
                            />
                            <p
                                id="adjustment-help"
                                className="text-xs text-neutral-600"
                            >
                                Use a positive number to add stock or a negative
                                number to remove stock.
                            </p>
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
                                placeholder="Opening count, damaged item, or physical count correction"
                                className={`${controlClass} h-auto min-h-24 py-2`}
                                aria-invalid={!!form.errors.reason}
                                aria-describedby={
                                    form.errors.reason
                                        ? 'reason-error'
                                        : undefined
                                }
                                onChange={(event) =>
                                    form.setData('reason', event.target.value)
                                }
                            />
                        </Field>
                    </fieldset>
                    <div
                        className={`rounded-xl border p-3 ${projectedOnHand !== null && projectedOnHand < 0 ? 'border-red-200 bg-red-50 text-red-800' : 'border-neutral-200 text-neutral-700'}`}
                    >
                        <p className="text-sm font-semibold" aria-live="polite">
                            Projected on hand:{' '}
                            {projectedOnHand?.toLocaleString() ?? '—'}
                        </p>
                        <p className="mt-1 text-xs">
                            {projectedOnHand !== null && projectedOnHand < 0
                                ? 'This would exceed the displayed stock. Negative stock is not allowed.'
                                : 'Preview only. Current stock is checked when you save.'}
                        </p>
                    </div>
                    <FormErrors errors={form.errors} />
                    <div className="grid grid-cols-2 gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            className={actionClass}
                            disabled={form.processing}
                            onClick={onClose}
                        >
                            Cancel
                        </Button>
                        <Button
                            type="submit"
                            className={primaryActionClass}
                            disabled={form.processing}
                        >
                            {form.processing ? 'Saving…' : 'Save adjustment'}
                        </Button>
                    </div>
                </form>
            </DialogContent>
        </Dialog>
    );
}
