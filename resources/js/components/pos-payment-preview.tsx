import {
    Banknote,
    CreditCard,
    Delete,
    Split,
    UtensilsCrossed,
    ShoppingBag,
} from 'lucide-react';
import { useState } from 'react';
import { PosTableSelection } from '@/components/pos-table-selection';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    cents,
    exactCash,
    lineCents,
    paymentTotals,
    pesos,
    selectedOptions,
    validPayment,
} from '@/lib/pos-money';
import type {
    BranchTable,
    CartLine,
    OrderSummary,
    OrderType,
    PaymentInput,
    PaymentAttempt,
} from '@/types/pos';

export function PosPaymentPreview({
    orderType,
    lines,
    saved,
    orderNumber,
    tables,
    customerLabel,
    tableId,
    onCustomerChange,
    onTableChange,
    onConfirm,
    processing,
    error,
    attempt,
}: {
    onConfirm: (payment: PaymentInput) => void;
    processing: boolean;
    error: string;
    attempt: PaymentAttempt | null;
    orderType: OrderType;
    lines: CartLine[];
    saved: OrderSummary | null;
    orderNumber: string;
    tables: BranchTable[];
    customerLabel: string;
    tableId: string;
    onCustomerChange: (value: string) => void;
    onTableChange: (value: string) => void;
}) {
    const [method, setMethod] = useState<'cash' | 'cashless' | 'split'>(
        attempt?.payment_method ?? 'cash',
    );
    const [cash, setCash] = useState(attempt?.cash_received ?? '');
    const [cashless, setCashless] = useState(attempt?.cashless_amount ?? '');
    const [activeInput, setActiveInput] = useState<'cash' | 'cashless'>('cash');
    const total = saved
        ? cents(saved.total)
        : lines.reduce((sum, line) => sum + lineCents(line), 0n);
    const cashAmount = method === 'cashless' ? 0n : cents(cash || '0');
    const cashlessAmount =
        method === 'cashless'
            ? total
            : method === 'cash'
              ? 0n
              : cents(cashless || '0');
    const { received, remaining, change } = paymentTotals(
        total,
        cashAmount,
        cashlessAmount,
    );
    const rows =
        saved?.items.map((item) => ({
            ...item,
            amount: pesos(item.line_total),
        })) ??
        lines.map((line) => ({
            id: line.key,
            name: line.product.name,
            quantity: line.quantity,
            notes: line.notes,
            modifiers: selectedOptions(line),
            amount: pesos(lineCents(line)),
        }));

    const locked = processing || attempt !== null;
    const valid =
        validPayment(total, method, cash, cashless) &&
        (saved !== null ||
            orderType === 'dine_in' ||
            customerLabel.trim() !== '');

    function enter(value: string, field = activeInput) {
        if (locked) return;
        if (/^\d{0,12}(\.\d{0,2})?$/.test(value)) {
            if (field === 'cash') setCash(value);
            else setCashless(value);
        }
    }

    return (
        <div className="grid min-h-0 flex-1 overflow-y-auto md:grid-cols-2 md:overflow-hidden">
            <section className="flex min-w-0 flex-col gap-3 border-b border-neutral-200 p-4 md:min-h-0 md:overflow-y-auto md:border-r md:border-b-0">
                <div className="space-y-1">
                    <p className="text-[10px] font-semibold tracking-wider text-neutral-500 uppercase">
                        Order number
                    </p>
                    <p className="text-[28px] font-bold tracking-tight wrap-anywhere text-red-700">
                        #{orderNumber}
                    </p>
                </div>
                <div
                    className={`flex items-center justify-center gap-2 rounded-xl border py-3 text-[13px] font-bold ${orderType === 'dine_in' ? 'border-green-200 bg-green-50 text-green-700' : 'border-sky-200 bg-sky-50 text-sky-700'}`}
                >
                    {orderType === 'dine_in' ? (
                        <UtensilsCrossed className="size-4" />
                    ) : (
                        <ShoppingBag className="size-4" />
                    )}
                    {orderType === 'dine_in' ? 'Dine in' : 'Take out'}
                </div>
                {saved ? (
                    <p className="text-center text-[13px] font-semibold wrap-anywhere text-red-700">
                        {[saved.customer_label, saved.table_name]
                            .filter(Boolean)
                            .join(' / ')}
                    </p>
                ) : (
                    <>
                        <div className="space-y-2">
                            <Label
                                htmlFor="payment-customer"
                                className="text-[10px] tracking-wider text-neutral-500 uppercase"
                            >
                                Customer name / order label{' '}
                                {orderType === 'dine_in'
                                    ? '(optional)'
                                    : '(required)'}
                            </Label>
                            <Input
                                disabled={locked}
                                id="payment-customer"
                                value={customerLabel}
                                onChange={(event) =>
                                    onCustomerChange(event.target.value)
                                }
                                maxLength={150}
                                className="h-[48px] rounded-xl text-base md:text-[13px]"
                                placeholder="Customer name or order label"
                            />
                        </div>
                        <PosTableSelection
                            disabled={locked}
                            tables={tables}
                            tableId={tableId}
                            onChange={onTableChange}
                        />
                    </>
                )}
                <h3 className="border-t border-neutral-200 pt-3 text-[10px] font-semibold tracking-wider text-neutral-500 uppercase">
                    Order summary
                </h3>
                <div
                    className="shrink-0 overflow-hidden rounded-xl border border-neutral-200"
                    aria-label="Order summary items"
                >
                    {rows.map((row) => (
                        <div
                            key={row.id}
                            className="flex items-start gap-2.5 border-b border-neutral-100 px-3 py-2.5 last:border-b-0"
                        >
                            <span className="shrink-0 text-xs font-bold text-red-700">
                                {row.quantity}×
                            </span>
                            <div className="min-w-0 flex-1 space-y-0.5">
                                <p className="text-[12.5px] leading-[1.35] font-semibold wrap-anywhere text-neutral-950">
                                    {row.name}
                                </p>
                                {row.modifiers.map((modifier) => (
                                    <p
                                        key={modifier.id}
                                        className="text-[10.5px] leading-[1.45] wrap-anywhere text-neutral-500"
                                    >
                                        {modifier.name} (+
                                        {pesos(modifier.price_delta)})
                                    </p>
                                ))}
                                {row.notes && (
                                    <p className="text-[10.5px] leading-[1.45] wrap-anywhere whitespace-pre-wrap text-amber-800">
                                        {row.notes}
                                    </p>
                                )}
                            </div>
                            <span className="shrink-0 text-[12.5px] font-bold text-red-700 tabular-nums">
                                {row.amount}
                            </span>
                        </div>
                    ))}
                </div>
                <div className="mt-auto flex items-baseline justify-between border-t border-neutral-200 pt-3">
                    <span className="text-[15px] font-semibold">Total</span>
                    <span className="text-[27px] font-bold text-red-700">
                        {pesos(total)}
                    </span>
                </div>
            </section>
            <section
                aria-label="Payment entry"
                className="flex min-w-0 flex-col gap-2.5 p-3.5 md:overflow-visible"
            >
                <div
                    aria-label="Payment method"
                    className="flex gap-[3px] rounded-xl bg-neutral-100 p-[3px]"
                >
                    {(
                        [
                            { key: 'cash', label: 'Cash', icon: Banknote },
                            {
                                key: 'cashless',
                                label: 'Cashless',
                                icon: CreditCard,
                            },
                            { key: 'split', label: 'Split', icon: Split },
                        ] as const
                    ).map(({ key, label, icon: Icon }) => (
                        <button
                            key={key}
                            disabled={locked}
                            aria-pressed={method === key}
                            onClick={() => {
                                setMethod(key);
                                setActiveInput(
                                    key === 'cashless' ? 'cashless' : 'cash',
                                );
                            }}
                            className={`flex h-[46px] min-w-0 flex-1 items-center justify-center gap-1.5 rounded-[10px] text-[13px] font-semibold ${method === key ? 'bg-neutral-950 text-white' : 'text-neutral-500'}`}
                        >
                            <Icon className="size-4" />
                            {label}
                        </button>
                    ))}
                </div>
                <div
                    className={`grid gap-2.5 ${method === 'split' ? 'grid-cols-2' : 'grid-cols-1'}`}
                >
                    {method === 'cashless' ? (
                        <div className="rounded-xl border border-sky-200 bg-sky-50 p-4 text-center">
                            <p className="text-xs font-semibold text-sky-800">
                                Cashless due
                            </p>
                            <p className="mt-2 text-3xl font-bold">
                                {pesos(total)}
                            </p>
                            <p className="mt-3 text-xs text-sky-800">
                                Confirm only after payment is received
                                externally.
                            </p>
                            <p className="mt-2 text-[11px] font-semibold text-sky-800">
                                Invoice: —
                            </p>
                        </div>
                    ) : (
                        (['cash', 'cashless'] as const)
                            .filter(
                                (field) =>
                                    method === 'split' || method === field,
                            )
                            .map((field) => (
                                <div
                                    key={field}
                                    className="min-w-0 space-y-1.5"
                                >
                                    <Label
                                        htmlFor={`payment-${field}`}
                                        className="text-[10px] tracking-wider text-neutral-500 uppercase"
                                    >
                                        {field === 'cash'
                                            ? 'Cash received'
                                            : 'Cashless amount'}
                                    </Label>
                                    <Input
                                        disabled={locked}
                                        id={`payment-${field}`}
                                        inputMode="decimal"
                                        value={
                                            field === 'cash' ? cash : cashless
                                        }
                                        onFocus={() => setActiveInput(field)}
                                        onChange={(event) =>
                                            enter(event.target.value, field)
                                        }
                                        placeholder="0.00"
                                        className="h-[48px] rounded-xl text-right text-[24px] font-bold md:text-[24px]"
                                    />
                                </div>
                            ))
                    )}
                </div>
                {method === 'split' && (
                    <div className="flex items-center justify-between rounded-lg border border-dashed border-neutral-300 px-3 py-1.5 text-[11px] text-neutral-500">
                        <span>Cashless invoice</span>
                        <span className="font-semibold text-neutral-700">—</span>
                    </div>
                )}
                {method !== 'cashless' && (
                    <div
                        className="flex flex-wrap gap-1.5"
                        aria-label="Quick cash amounts"
                    >
                        {[
                            { label: 'Exact', amount: null },
                            { label: '₱50', amount: '50.00' },
                            { label: '₱100', amount: '100.00' },
                            { label: '₱500', amount: '500.00' },
                            { label: '₱1,000', amount: '1000.00' },
                        ].map(({ label, amount }) => (
                            <button
                                key={label}
                                type="button"
                                disabled={locked}
                                className="h-9 min-w-14 flex-1 rounded-full border border-neutral-300 px-2 text-[11px] font-semibold whitespace-nowrap hover:bg-neutral-50"
                                onClick={() => {
                                    const other =
                                        method === 'split'
                                            ? cashlessAmount
                                            : 0n;
                                    enter(
                                        amount ?? exactCash(total, other),
                                        'cash',
                                    );
                                    setActiveInput('cash');
                                }}
                            >
                                {label}
                            </button>
                        ))}
                    </div>
                )}
                {method !== 'cashless' && (
                    <div className="grid grid-cols-3 gap-1.5">
                        {[
                            '1',
                            '2',
                            '3',
                            '4',
                            '5',
                            '6',
                            '7',
                            '8',
                            '9',
                            '.',
                            '0',
                            'delete',
                        ].map((key) => (
                            <button
                                key={key}
                                disabled={locked}
                                aria-label={
                                    key === 'delete'
                                        ? 'Delete payment digit'
                                        : `Enter ${key}`
                                }
                                className="flex h-[42px] items-center justify-center rounded-[10px] border border-neutral-200 bg-white text-[18px] font-semibold hover:bg-neutral-50"
                                onClick={() => {
                                    const current =
                                        activeInput === 'cash'
                                            ? cash
                                            : cashless;
                                    enter(
                                        key === 'delete'
                                            ? current.slice(0, -1)
                                            : current + key,
                                    );
                                }}
                            >
                                {key === 'delete' ? (
                                    <Delete className="size-5" />
                                ) : (
                                    key
                                )}
                            </button>
                        ))}
                    </div>
                )}
                <div
                    className="grid grid-cols-2 gap-2"
                    aria-live="polite"
                    aria-label="Payment totals"
                >
                    <dl className="flex flex-col justify-center gap-1.5 rounded-xl border border-neutral-200 px-3 py-2.5 text-[11px] tabular-nums">
                        <div className="flex flex-wrap justify-between gap-x-2">
                            <dt>Total</dt>
                            <dd className="font-semibold">{pesos(total)}</dd>
                        </div>
                        <div className="flex flex-wrap justify-between gap-x-2">
                            <dt>Received</dt>
                            <dd className="font-semibold">{pesos(received)}</dd>
                        </div>
                        <div className="flex flex-wrap justify-between gap-x-2 font-bold text-red-700">
                            <dt>Remaining</dt>
                            <dd className="text-[14px]">{pesos(remaining)}</dd>
                        </div>
                    </dl>
                    <dl className="flex flex-col justify-center gap-1 rounded-xl bg-neutral-950 px-3 py-2.5 text-white">
                        <dt className="text-[10px] font-semibold tracking-wider uppercase">
                            Change
                        </dt>
                        <dd className="text-[28px] leading-tight font-bold tracking-tight wrap-anywhere tabular-nums">
                            {pesos(change)}
                        </dd>
                    </dl>
                </div>
                <div className="mt-auto space-y-2">
                    <p
                        id="payment-message"
                        role={error ? 'alert' : undefined}
                        className={`text-center text-[11px] ${error ? 'text-red-700' : 'text-neutral-500'}`}
                    >
                        {error ||
                            (method === 'split'
                                ? 'Cashless must be above zero and below the total.'
                                : method === 'cash'
                                  ? 'Enter cash received to confirm payment.'
                                  : 'Confirming records the Cashless payment.')}
                    </p>
                    <button
                        disabled={processing || (!attempt && !valid)}
                        aria-describedby="payment-message"
                        onClick={() =>
                            onConfirm({
                                payment_method: method,
                                cash_received:
                                    method === 'cashless' ? null : cash,
                                cashless_amount:
                                    method === 'split' ? cashless : null,
                            })
                        }
                        className="h-11 w-full rounded-xl bg-neutral-950 text-[14px] font-semibold text-white disabled:opacity-50"
                    >
                        {processing
                            ? 'Processing payment...'
                            : attempt
                              ? 'Retry same payment'
                              : 'Confirm payment'}
                    </button>
                </div>
            </section>
        </div>
    );
}
