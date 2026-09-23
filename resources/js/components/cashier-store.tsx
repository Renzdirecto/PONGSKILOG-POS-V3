import { useForm } from '@inertiajs/react';
import {
    ArrowLeft,
    BookOpen,
    CircleCheck,
    ClipboardList,
    Clock3,
    Eye,
    LockKeyhole,
    Store,
} from 'lucide-react';
import { useRef, useState } from 'react';
import type { FormEvent } from 'react';
import { CashierPos } from '@/components/cashier-pos';
import type { BranchTable } from '@/types/pos';
import { CashierCatalog } from '@/components/cashier-catalog';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { open } from '@/routes/store-sessions';
import type { BranchSummary, StoreContext } from '@/types';
import type { CashierCatalog as CashierCatalogData } from '@/types/catalog';
import type { KitchenStatusSummary } from '@/types/kitchen';

export type CashierStoreState = {
    branchStatus: 'active' | 'temporarily_closed' | 'inactive';
    canOpen: boolean;
};

export function CashierStore({
    branch,
    store,
    storeContext,
    catalog,
    tables,
    kitchenStatus,
}: {
    branch: BranchSummary;
    store: CashierStoreState;
    storeContext: StoreContext;
    catalog: CashierCatalogData;
    tables: BranchTable[];
    kitchenStatus: KitchenStatusSummary;
}) {
    const [view, setView] = useState<'store' | 'browse' | 'opening'>('store');
    const isOpen = storeContext.isOpen;
    const isAvailable = store.branchStatus === 'active';
    const buttonClass = 'min-h-12 rounded-xl px-6';
    /** The Store Closed hero shares its illustration row with the header on wide screens. */
    const closedHero =
        isAvailable &&
        !(view === 'browse') &&
        !(view === 'opening' && store.canOpen);

    if (isOpen && isAvailable) {
        return (
            <CashierPos
                branch={branch}
                catalog={catalog}
                tables={tables}
                kitchenStatus={kitchenStatus}
            />
        );
    }

    return (
        <div
            className={`mx-auto flex w-full flex-col gap-6 px-4 pt-6 pb-8 sm:px-6 sm:pt-8 lg:pt-10 ${view === 'browse' ? 'max-w-6xl' : view === 'opening' ? 'max-w-3xl' : 'max-w-5xl'}`}
        >
            <section
                className={`relative rounded-3xl border border-neutral-200 bg-white shadow-sm ${view === 'browse' ? 'p-3 sm:p-6' : 'p-6 sm:p-10'}`}
                aria-labelledby="store-heading"
            >
                <header
                    className={`flex items-start justify-between gap-3 ${closedHero ? 'mb-4 lg:absolute lg:inset-x-10 lg:top-10 lg:mb-0' : 'mb-6 border-b border-neutral-200 pb-5'}`}
                >
                    {/* At lg the header overlays the centred 300px illustration; keep the name beside it. */}
                    <div
                        className={`min-w-0 space-y-1 ${closedHero ? 'lg:max-w-[calc(50%-10.5rem)]' : ''}`}
                    >
                        <p className="text-[11px] font-bold tracking-[0.18em] text-[#8c671e] uppercase">
                            Cashier / POS
                        </p>
                        <h1 className="text-2xl font-bold tracking-tight wrap-break-word sm:text-3xl">
                            {branch.name}
                        </h1>
                        <p className="text-sm text-neutral-500">
                            Branch {branch.code}
                        </p>
                    </div>
                    <span
                        className={`inline-flex shrink-0 items-center gap-2 rounded-full border px-3 py-2 text-[11px] font-bold tracking-wide sm:text-xs ${isOpen ? 'border-emerald-200 bg-emerald-50 text-emerald-800' : 'border-neutral-300 bg-white text-neutral-600'}`}
                    >
                        <span
                            className={`size-2 rounded-full ${isOpen ? 'bg-emerald-600' : 'bg-neutral-400'}`}
                        />
                        {isOpen ? 'STORE OPEN' : 'STORE CLOSED'}
                    </span>
                </header>
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
                ) : view === 'opening' && store.canOpen ? (
                    <OpenStoreForm
                        branchName={branch.name}
                        onCancel={() => setView('store')}
                    />
                ) : (
                    <StoreClosedPanel
                        canOpen={store.canOpen}
                        onOpen={() => setView('opening')}
                        onBrowse={() => setView('browse')}
                    />
                )}
            </section>
        </div>
    );
}

/** Decorative closed storefront; the page text carries the meaning. */
function ClosedStoreIllustration() {
    return (
        <svg
            viewBox="0 0 320 150"
            className="h-auto w-[260px] sm:w-[300px]"
            aria-hidden="true"
            focusable="false"
        >
            <defs>
                <radialGradient id="closed-glow" cx="50%" cy="60%" r="55%">
                    <stop offset="0%" stopColor="#FCEBD6" />
                    <stop offset="100%" stopColor="#FEF6EC" />
                </radialGradient>
            </defs>
            <circle cx="160" cy="112" r="98" fill="url(#closed-glow)" />
            <g fill="#F7E6CF">
                <ellipse cx="46" cy="64" rx="18" ry="8" />
                <ellipse cx="58" cy="58" rx="12" ry="9" />
                <ellipse cx="276" cy="104" rx="20" ry="8" />
                <ellipse cx="288" cy="98" rx="12" ry="9" />
            </g>
            <g stroke="#E8C792" strokeLinecap="round" strokeWidth="3">
                <line x1="70" y1="104" x2="102" y2="104" />
                <line x1="78" y1="114" x2="102" y2="114" />
            </g>
            <rect x="112" y="60" width="96" height="90" rx="4" fill="#C9CCD1" />
            <rect x="120" y="68" width="80" height="82" fill="#AEB2B8" />
            <rect x="130" y="80" width="60" height="44" rx="3" fill="#DDE0E4" />
            <path d="M104 40h112l-6 22H110z" fill="#C79A52" />
            <g>
                {[0, 1, 2, 3, 4].map((stripe) => (
                    <path
                        key={stripe}
                        d={`M${106 + stripe * 22} 40h22v14a11 11 0 0 1-22 0z`}
                        fill={stripe % 2 === 0 ? '#C79A52' : '#F4E3C3'}
                    />
                ))}
            </g>
            <rect x="104" y="34" width="112" height="8" rx="3" fill="#B08040" />
            <line
                x1="148"
                y1="90"
                x2="160"
                y2="78"
                stroke="#6B4E22"
                strokeWidth="1.5"
            />
            <line
                x1="172"
                y1="90"
                x2="160"
                y2="78"
                stroke="#6B4E22"
                strokeWidth="1.5"
            />
            <circle cx="160" cy="78" r="2" fill="#6B4E22" />
            <g transform="rotate(-8 160 101)">
                <rect
                    x="128"
                    y="89"
                    width="64"
                    height="24"
                    rx="4"
                    fill="#B98434"
                />
                <rect
                    x="131"
                    y="92"
                    width="58"
                    height="18"
                    rx="3"
                    fill="none"
                    stroke="#F4E3C3"
                    strokeWidth="1"
                />
                <text
                    x="160"
                    y="105.5"
                    textAnchor="middle"
                    fontSize="11"
                    fontWeight="800"
                    fill="#FFF8EC"
                    fontFamily="Poppins, sans-serif"
                    letterSpacing="0.5"
                >
                    CLOSED
                </text>
            </g>
        </svg>
    );
}

function StoreClosedPanel({
    canOpen,
    onOpen,
    onBrowse,
}: {
    canOpen: boolean;
    onOpen: () => void;
    onBrowse: () => void;
}) {
    const cards = [
        {
            title: 'What you can do',
            icon: BookOpen,
            tone: 'border-amber-100 bg-amber-50/40',
            iconTone: 'bg-amber-100 text-[#8c671e]',
            checkTone: 'text-[#b8862f]',
            items: [
                'Browse products and prices',
                'View order history',
                'Check stock availability',
            ],
        },
        {
            title: 'Before opening',
            icon: ClipboardList,
            tone: 'border-neutral-200 bg-white',
            iconTone: 'bg-neutral-100 text-neutral-700',
            checkTone: 'text-neutral-500',
            items: [
                'Prepare opening cash balance',
                'Prepare cashless balances',
                'Ensure all devices are ready',
            ],
        },
    ];

    return (
        <div className="flex flex-col items-center gap-6 text-center">
            <ClosedStoreIllustration />
            <div className="space-y-2">
                <h2
                    id="store-heading"
                    className="text-3xl font-bold tracking-tight sm:text-4xl"
                >
                    Store Closed
                </h2>
                <p className="mx-auto max-w-xl text-sm leading-6 text-neutral-600 sm:text-base">
                    {canOpen
                        ? 'The store is currently in read-only mode. Open a new session by entering the opening cash and cashless balances to start taking orders.'
                        : 'The store is currently in read-only mode. You can browse the catalog while an authorized cashier opens the store.'}
                </p>
            </div>
            <div className="flex w-full flex-col gap-3 sm:w-auto sm:flex-row">
                {canOpen && (
                    <Button
                        className="min-h-12 rounded-xl bg-neutral-950 px-8 text-white hover:bg-neutral-800"
                        onClick={onOpen}
                    >
                        <Store /> Open Store
                    </Button>
                )}
                <Button
                    variant="outline"
                    className="min-h-12 rounded-xl border-neutral-200 bg-white px-6 text-neutral-950 shadow-sm hover:bg-neutral-100 hover:text-neutral-950"
                    onClick={onBrowse}
                >
                    <Eye /> Browse Read-Only
                </Button>
            </div>
            {!canOpen && (
                <p className="text-sm text-neutral-600">
                    You do not have permission to open this store. Contact your
                    manager.
                </p>
            )}
            <div className="grid w-full gap-3 border-t border-neutral-200 pt-6 text-left md:grid-cols-3">
                {cards.map(
                    ({
                        title,
                        icon: Icon,
                        tone,
                        iconTone,
                        checkTone,
                        items,
                    }) => (
                        <section
                            key={title}
                            className={`rounded-2xl border p-5 ${tone}`}
                        >
                            <div className="flex items-center gap-3">
                                <span
                                    className={`flex size-11 shrink-0 items-center justify-center rounded-full ${iconTone}`}
                                >
                                    <Icon className="size-5" aria-hidden />
                                </span>
                                <h3 className="text-sm font-bold">{title}</h3>
                            </div>
                            <ul className="mt-4 space-y-2.5">
                                {items.map((item) => (
                                    <li
                                        key={item}
                                        className="flex items-center gap-2.5 text-sm text-neutral-700"
                                    >
                                        <CircleCheck
                                            className={`size-4 shrink-0 ${checkTone}`}
                                            aria-hidden
                                        />
                                        {item}
                                    </li>
                                ))}
                            </ul>
                        </section>
                    ),
                )}
                <section className="rounded-2xl border border-neutral-100 bg-neutral-50 p-5">
                    <div className="flex items-center gap-3">
                        <span className="flex size-11 shrink-0 items-center justify-center rounded-full bg-white text-neutral-700 ring-1 ring-neutral-200">
                            <Clock3 className="size-5" aria-hidden />
                        </span>
                        <h3 className="text-sm font-bold">Session status</h3>
                    </div>
                    <p className="mt-4 inline-flex items-center gap-2 rounded-lg bg-neutral-200/70 px-4 py-2.5 text-sm font-semibold">
                        <span className="size-2 rounded-full bg-neutral-500" />
                        Store Closed
                    </p>
                    <p className="mt-3 text-xs leading-5 text-neutral-500">
                        Open a new session to start taking orders.
                    </p>
                </section>
            </div>
            <p className="flex w-full items-center justify-center gap-4 text-[11px] font-bold tracking-[0.25em] text-[#8c671e] uppercase">
                <span className="h-px w-12 bg-neutral-200 sm:w-20" />
                Ready when you are
                <span className="h-px w-12 bg-neutral-200 sm:w-20" />
            </p>
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
