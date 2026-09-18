import { useForm } from '@inertiajs/react';
import { ArrowLeft, CheckCircle2, Eye, LockKeyhole, Store } from 'lucide-react';
import { useRef, useState } from 'react';
import type { FormEvent } from 'react';
import { CashierCatalog } from '@/components/cashier-catalog';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { open } from '@/routes/store-sessions';
import type { BranchSummary, StoreContext } from '@/types';
import type { CashierCatalog as CashierCatalogData } from '@/types/catalog';

export type CashierStoreState = {
    branchStatus: 'active' | 'temporarily_closed' | 'inactive';
    canOpen: boolean;
};

export function CashierStore({
    branch,
    store,
    storeContext,
    catalog,
}: {
    branch: BranchSummary;
    store: CashierStoreState;
    storeContext: StoreContext;
    catalog: CashierCatalogData;
}) {
    const [view, setView] = useState<'store' | 'browse' | 'opening'>('store');
    const isOpen = storeContext.isOpen;
    const isAvailable = store.branchStatus === 'active';
    const buttonClass = 'min-h-12 rounded-xl px-6';

    return (
        <div
            className={`mx-auto flex flex-col gap-6 ${view === 'browse' ? 'max-w-6xl' : 'max-w-3xl'}`}
        >
            <div className="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                <div className="min-w-0 space-y-2">
                    <p className="text-xs font-bold tracking-[0.18em] text-[#8c671e] uppercase">
                        Cashier / POS
                    </p>
                    <h1 className="text-3xl font-bold tracking-tight wrap-break-word sm:text-4xl">
                        {branch.name}
                    </h1>
                    <p className="text-sm text-neutral-500">
                        Branch {branch.code}
                    </p>
                </div>
                <span
                    className={`inline-flex w-fit items-center gap-2 rounded-full border px-3 py-2 text-xs font-bold tracking-wide ${isOpen ? 'border-emerald-200 bg-emerald-50 text-emerald-800' : 'border-neutral-300 bg-white text-neutral-600'}`}
                >
                    <span
                        className={`size-2 rounded-full ${isOpen ? 'bg-emerald-600' : 'bg-neutral-400'}`}
                    />
                    {isOpen ? 'STORE OPEN' : 'STORE CLOSED'}
                </span>
            </div>

            <section
                className={`rounded-3xl border border-neutral-200 bg-white shadow-sm ${view === 'browse' ? 'p-3 sm:p-6' : 'p-6 sm:p-10'}`}
                aria-labelledby="store-heading"
            >
                {!isAvailable ? (
                    <div className="flex flex-col gap-4">
                        <LockKeyhole className="size-8 text-neutral-500" />
                        <h2 id="store-heading" className="text-2xl font-bold">
                            Branch unavailable
                        </h2>
                        <p className="text-sm leading-6 text-neutral-600">
                            This branch is{' '}
                            {store.branchStatus === 'temporarily_closed'
                                ? 'temporarily closed'
                                : 'inactive'}
                            . Store opening and POS operations are unavailable.
                            Contact your manager or choose another assigned
                            branch.
                        </p>
                    </div>
                ) : view === 'browse' ? (
                    <div className="flex flex-col gap-5">
                        <div className="flex flex-wrap items-center justify-between gap-3">
                            <span className="inline-flex items-center gap-2 rounded-full bg-amber-50 px-3 py-2 text-xs font-bold text-amber-900">
                                <Eye className="size-4 shrink-0" />
                                {isOpen
                                    ? 'READ-ONLY / STORE OPEN'
                                    : 'READ-ONLY / STORE CLOSED'}
                            </span>
                            <Button
                                variant="outline"
                                className={`${buttonClass} border-neutral-200 bg-white text-neutral-950 hover:bg-neutral-100 hover:text-neutral-950`}
                                onClick={() => setView('store')}
                            >
                                <ArrowLeft /> Back to store
                            </Button>
                        </div>
                        <h2
                            id="store-heading"
                            className="text-[15px] font-bold"
                            tabIndex={-1}
                            ref={(node) => node?.focus()}
                        >
                            Browse catalog
                        </h2>
                        <CashierCatalog catalog={catalog} />
                    </div>
                ) : isOpen ? (
                    <div className="flex flex-col gap-4" role="status">
                        <span className="flex size-14 items-center justify-center rounded-2xl bg-emerald-50 text-emerald-700">
                            <CheckCircle2 className="size-7" />
                        </span>
                        <h2 id="store-heading" className="text-2xl font-bold">
                            Store is open
                        </h2>
                        <p className="text-sm leading-6 text-neutral-600">
                            Browse this branch’s products and prices in
                            read-only mode.
                        </p>
                        <Button
                            variant="outline"
                            className={`${buttonClass} w-fit border-neutral-200 bg-white text-neutral-950 hover:bg-neutral-100 hover:text-neutral-950`}
                            onClick={() => setView('browse')}
                        >
                            <Eye /> Browse
                        </Button>
                    </div>
                ) : view === 'opening' && store.canOpen ? (
                    <OpenStoreForm
                        branchName={branch.name}
                        onCancel={() => setView('store')}
                    />
                ) : (
                    <div className="flex flex-col gap-6">
                        <span className="flex size-16 items-center justify-center rounded-2xl bg-neutral-100 text-neutral-700">
                            <Store className="size-8" />
                        </span>
                        <div className="space-y-3">
                            <h2
                                id="store-heading"
                                className="text-2xl font-bold"
                            >
                                STORE CLOSED
                            </h2>
                            <p className="max-w-lg text-sm leading-6 text-neutral-600">
                                {store.canOpen
                                    ? 'Browse in read-only mode, or open the store by entering the opening Cash and Cashless balances.'
                                    : 'Browse the store in read-only mode.'}
                            </p>
                        </div>
                        <div className="flex flex-col gap-3 sm:flex-row">
                            <Button
                                variant="outline"
                                className={`${buttonClass} border-neutral-200 bg-white text-neutral-950 hover:bg-neutral-100 hover:text-neutral-950`}
                                onClick={() => setView('browse')}
                            >
                                <Eye /> Browse
                            </Button>
                            {store.canOpen && (
                                <Button
                                    className={`${buttonClass} bg-neutral-950 text-white hover:bg-neutral-800`}
                                    onClick={() => setView('opening')}
                                >
                                    <Store /> Open Store
                                </Button>
                            )}
                        </div>
                        {!store.canOpen && (
                            <p className="text-sm text-neutral-600">
                                You do not have permission to open this store.
                                Contact your manager.
                            </p>
                        )}
                    </div>
                )}
            </section>
        </div>
    );
}

function OpenStoreForm({
    branchName,
    onCancel,
}: {
    branchName: string;
    onCancel: () => void;
}) {
    const { data, setData, submit, processing, errors } = useForm({
        opening_cash_amount: '',
        opening_cashless_amount: '',
    });
    const submitting = useRef(false);
    const cashInput = useRef<HTMLInputElement>(null);
    const cashlessInput = useRef<HTMLInputElement>(null);

    function openStore(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        if (submitting.current) {
            return;
        }
        submitting.current = true;
        submit(open(), {
            preserveScroll: true,
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
                <h2 id="store-heading" className="text-2xl font-bold">
                    Open Store
                </h2>
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
                        aria-invalid={!!errors.opening_cash_amount}
                        aria-describedby={
                            errors.opening_cash_amount
                                ? 'opening-cash-error'
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
                        aria-invalid={!!errors.opening_cashless_amount}
                        aria-describedby={
                            errors.opening_cashless_amount
                                ? 'opening-cashless-error'
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
