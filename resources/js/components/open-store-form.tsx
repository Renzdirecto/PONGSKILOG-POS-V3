import { useForm } from '@inertiajs/react';
import { Store } from 'lucide-react';
import { useRef } from 'react';
import type { FormEvent } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { isMoneyInput } from '@/lib/required-field';
import { open } from '@/routes/store-sessions';

/**
 * Open Store: the opening cash and cashless balances of a new Store Session. The one form behind every Open Store entry
 * point (the POS Store Closed page and the shared Store status control of every Store Operations page).
 */
export function OpenStoreForm({
    branchName,
    onCancel,
    onOpened,
    heading = true,
}: {
    branchName: string;
    onCancel: () => void;
    onOpened?: () => void;
    /** The POS page shows its own heading; a dialog supplies its title instead. */
    heading?: boolean;
}) {
    const { data, setData, submit, processing, errors } = useForm({
        opening_cash_amount: '',
        opening_cashless_amount: '',
    });
    const submitting = useRef(false);
    const cashInput = useRef<HTMLInputElement>(null);
    const cashlessInput = useRef<HTMLInputElement>(null);
    const cashMissing = !isMoneyInput(data.opening_cash_amount);
    const cashlessMissing = !isMoneyInput(data.opening_cashless_amount);

    function openStore(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        if (submitting.current) {
            return;
        }
        submitting.current = true;
        submit(open(), {
            preserveScroll: true,
            onSuccess: () => onOpened?.(),
            onError: (validationErrors) => {
                (validationErrors.opening_cash_amount
                    ? cashInput
                    : cashlessInput
                ).current?.focus();
            },
            onFinish: () => {
                submitting.current = false;
            },
        });
    }

    return (
        <form
            onSubmit={openStore}
            className="flex flex-col gap-6"
            aria-busy={processing}
        >
            <div className="space-y-3">
                {heading && (
                    <h2 id="store-heading" className="text-2xl font-bold">
                        Open Store
                    </h2>
                )}
                <p className="text-sm leading-6 text-neutral-600">
                    Enter the opening balances for {branchName}. Both amounts
                    are in Philippine pesos (PHP).
                </p>
            </div>
            <div className="grid gap-5 sm:grid-cols-2">
                <div className="space-y-2">
                    <Label htmlFor="opening-cash">Opening Cash (₱)</Label>
                    <Input
                        ref={cashInput}
                        autoFocus
                        id="opening-cash"
                        name="opening_cash_amount"
                        type="number"
                        inputMode="decimal"
                        min="0"
                        max="999999999999.99"
                        step="0.01"
                        required
                        placeholder="0.00"
                        value={data.opening_cash_amount}
                        onChange={(event) =>
                            setData('opening_cash_amount', event.target.value)
                        }
                        disabled={processing}
                        aria-invalid={
                            !!errors.opening_cash_amount || cashMissing
                        }
                        aria-describedby={
                            errors.opening_cash_amount
                                ? 'opening-cash-error'
                                : cashMissing
                                  ? 'opening-cash-required'
                                  : undefined
                        }
                        className="h-12 rounded-xl text-base"
                    />
                    {errors.opening_cash_amount && (
                        <p
                            id="opening-cash-error"
                            role="alert"
                            className="text-sm text-red-700"
                        >
                            {errors.opening_cash_amount}
                        </p>
                    )}
                    {!errors.opening_cash_amount && cashMissing && (
                        <p
                            id="opening-cash-required"
                            className="text-xs text-red-700"
                        >
                            Required · enter 0 if there is no opening cash.
                        </p>
                    )}
                </div>
                <div className="space-y-2">
                    <Label htmlFor="opening-cashless">
                        Opening Cashless (₱)
                    </Label>
                    <Input
                        ref={cashlessInput}
                        id="opening-cashless"
                        name="opening_cashless_amount"
                        type="number"
                        inputMode="decimal"
                        min="0"
                        max="999999999999.99"
                        step="0.01"
                        required
                        placeholder="0.00"
                        value={data.opening_cashless_amount}
                        onChange={(event) =>
                            setData(
                                'opening_cashless_amount',
                                event.target.value,
                            )
                        }
                        disabled={processing}
                        aria-invalid={
                            !!errors.opening_cashless_amount || cashlessMissing
                        }
                        aria-describedby={
                            errors.opening_cashless_amount
                                ? 'opening-cashless-error'
                                : cashlessMissing
                                  ? 'opening-cashless-required'
                                  : undefined
                        }
                        className="h-12 rounded-xl text-base"
                    />
                    {errors.opening_cashless_amount && (
                        <p
                            id="opening-cashless-error"
                            role="alert"
                            className="text-sm text-red-700"
                        >
                            {errors.opening_cashless_amount}
                        </p>
                    )}
                    {!errors.opening_cashless_amount && cashlessMissing && (
                        <p
                            id="opening-cashless-required"
                            className="text-xs text-red-700"
                        >
                            Required · enter 0 if there is no opening cashless.
                        </p>
                    )}
                </div>
            </div>
            <p className="text-xs leading-5 text-neutral-500">
                Use zero if there is no opening balance. Enter up to two decimal
                places.
            </p>
            <div className="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                <Button
                    type="button"
                    variant="outline"
                    disabled={processing}
                    onClick={onCancel}
                    className="min-h-12 rounded-xl border-neutral-200 bg-white px-6 text-neutral-950 hover:bg-neutral-100 hover:text-neutral-950"
                >
                    Cancel
                </Button>
                <Button
                    type="submit"
                    disabled={processing}
                    className="min-h-12 min-w-40 rounded-xl bg-neutral-950 px-6 text-white hover:bg-neutral-800"
                >
                    {processing ? (
                        <>
                            <Spinner /> Opening…
                        </>
                    ) : (
                        <>
                            <Store /> Open Store
                        </>
                    )}
                </Button>
            </div>
        </form>
    );
}
