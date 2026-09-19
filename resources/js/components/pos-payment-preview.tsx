import {
    Banknote,
    CreditCard,
    Delete,
    Split,
    UtensilsCrossed,
    ShoppingBag,
} from 'lucide-react';
import { useState } from 'react';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { cents, lineCents, pesos, selectedOptions } from '@/lib/pos-money';
import type {
    BranchTable,
    CartLine,
    OrderSummary,
    OrderType,
} from '@/types/pos';

export function PosPaymentPreview({
    orderType,
    lines,
    saved,
    tables,
    customerLabel,
    tableId,
    onCustomerChange,
    onTableChange,
}: {
    orderType: OrderType;
    lines: CartLine[];
    saved: OrderSummary | null;
    tables: BranchTable[];
    customerLabel: string;
    tableId: string;
    onCustomerChange: (value: string) => void;
    onTableChange: (value: string) => void;
}) {
    const [method, setMethod] = useState<'cash' | 'cashless' | 'split'>('cash');
    const [cash, setCash] = useState('');
    const [cashless, setCashless] = useState('');
    const [activeInput, setActiveInput] = useState<'cash' | 'cashless'>('cash');
    const total = saved
        ? cents(saved.total)
        : lines.reduce((sum, line) => sum + lineCents(line), 0n);
    const cashAmount = method === 'cashless' ? 0n : cents(cash || '0');
    const cashlessAmount = method === 'cash' ? 0n : cents(cashless || '0');
    const received = cashAmount + cashlessAmount;
    const remaining = total > received ? total - received : 0n;
    const change = received > total ? received - total : 0n;
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

    function enter(value: string, field = activeInput) {
        if (/^\d{0,12}(\.\d{0,2})?$/.test(value)) {
            if (field === 'cash') setCash(value);
            else setCashless(value);
        }
    }

    return (
        <div className="grid min-h-0 flex-1 overflow-y-auto min-[900px]:grid-cols-2 min-[900px]:overflow-hidden">
            <section className="flex min-w-0 flex-col gap-3.5 border-b border-neutral-200 p-4 min-[900px]:min-h-0 min-[900px]:overflow-y-auto min-[900px]:border-r min-[900px]:border-b-0">
                <div className="text-center">
                    <p className="text-[10px] font-semibold tracking-wider text-neutral-500 uppercase">
                        {saved ? 'Order number' : 'New order'}
                    </p>
                    <p className="text-[28px] font-bold tracking-tight wrap-anywhere text-red-700">
                        {saved ? `#${saved.order_number}` : 'Draft'}
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
                    <p className="text-center text-[13px] font-semibold text-red-700">
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
                        {orderType === 'dine_in' && (
                            <fieldset>
                                <legend className="mb-2 text-[10px] font-semibold tracking-wider text-neutral-500 uppercase">
                                    Table selection · required
                                </legend>
                                <div className="flex flex-wrap gap-2">
                                    {tables.map((table) => (
                                        <label
                                            key={table.id}
                                            className={`flex min-h-11 cursor-pointer items-center gap-2 rounded-xl border px-3 text-xs font-semibold ${tableId === table.id ? 'border-neutral-950 bg-neutral-950 text-white' : 'border-neutral-200'}`}
                                        >
                                            <input
                                                type="radio"
                                                name="payment-table"
                                                value={table.id}
                                                checked={tableId === table.id}
                                                onChange={() =>
                                                    onTableChange(table.id)
                                                }
                                            />
                                            {table.name}
                                        </label>
                                    ))}
                                </div>
                                {tables.length === 0 && (
                                    <p className="text-xs text-red-700">
                                        No active tables are available for this
                                        branch.
                                    </p>
                                )}
                            </fieldset>
                        )}
                    </>
                )}
                <h3 className="border-t border-neutral-200 pt-3 text-[10px] font-semibold tracking-wider text-neutral-500 uppercase">
                    Order summary
                </h3>
                <ul className="space-y-3">
                    {rows.map((row) => (
                        <li key={row.id}>
                            <div className="flex gap-2 text-[13px]">
                                <span className="font-bold text-red-700">
                                    {row.quantity}×
                                </span>
                                <span className="min-w-0 flex-1 font-semibold wrap-anywhere">
                                    {row.name}
                                </span>
                                <span className="shrink-0 font-bold text-red-700">
                                    {row.amount}
                                </span>
                            </div>
                            {row.modifiers.map((modifier) => (
                                <p
                                    key={modifier.id}
                                    className="mt-1 text-[11.5px] text-amber-800"
                                >
                                    {modifier.name} (+
                                    {pesos(modifier.price_delta)})
                                </p>
                            ))}
                            {row.notes && (
                                <p className="mt-1 text-[11px] wrap-anywhere whitespace-pre-wrap text-amber-800">
                                    {row.notes}
                                </p>
                            )}
                        </li>
                    ))}
                </ul>
                <div className="mt-auto flex items-baseline justify-between border-t border-neutral-200 pt-3">
                    <span className="text-[15px] font-semibold">Total</span>
                    <span className="text-[27px] font-bold text-red-700">
                        {pesos(total)}
                    </span>
                </div>
            </section>
            <section className="flex min-w-0 flex-col gap-3.5 p-4 min-[900px]:min-h-0 min-[900px]:overflow-y-auto">
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
                <p className="text-[11px] text-neutral-500">Payment preview</p>
                {(['cash', 'cashless'] as const)
                    .filter((field) => method === 'split' || method === field)
                    .map((field) => (
                        <div key={field} className="space-y-2">
                            <Label
                                htmlFor={`payment-${field}`}
                                className="text-[10px] tracking-wider text-neutral-500 uppercase"
                            >
                                {field === 'cash'
                                    ? 'Cash received'
                                    : 'Cashless amount'}
                            </Label>
                            <Input
                                id={`payment-${field}`}
                                inputMode="decimal"
                                value={field === 'cash' ? cash : cashless}
                                onFocus={() => setActiveInput(field)}
                                onChange={(event) =>
                                    enter(event.target.value, field)
                                }
                                placeholder="0.00"
                                className="h-[54px] rounded-xl text-right text-[24px] font-bold md:text-[24px]"
                            />
                        </div>
                    ))}
                <div className="grid grid-cols-3 gap-2">
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
                            aria-label={
                                key === 'delete'
                                    ? 'Delete payment digit'
                                    : `Enter ${key}`
                            }
                            className="flex h-12 items-center justify-center rounded-[10px] border border-neutral-200 bg-white text-[18px] font-semibold hover:bg-neutral-50"
                            onClick={() => {
                                const current =
                                    activeInput === 'cash' ? cash : cashless;
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
                <button
                    className="min-h-11 rounded-xl border border-neutral-200 text-[12.5px] font-semibold"
                    onClick={() => {
                        const other =
                            method === 'split'
                                ? activeInput === 'cash'
                                    ? cashlessAmount
                                    : cashAmount
                                : 0n;
                        const due = total > other ? total - other : 0n;
                        enter(
                            `${due / 100n}.${String(due % 100n).padStart(2, '0')}`,
                        );
                    }}
                >
                    Exact amount
                </button>
                <dl
                    className="space-y-2 rounded-xl bg-neutral-50 p-3 text-[12px]"
                    aria-live="polite"
                >
                    <div className="flex justify-between">
                        <dt>Total received</dt>
                        <dd className="font-semibold">{pesos(received)}</dd>
                    </div>
                    <div className="flex justify-between">
                        <dt>Remaining</dt>
                        <dd className="font-semibold text-red-700">
                            {pesos(remaining)}
                        </dd>
                    </div>
                    <div className="flex justify-between">
                        <dt>Change preview</dt>
                        <dd className="font-semibold text-green-700">
                            {pesos(change)}
                        </dd>
                    </div>
                </dl>
                <div className="mt-auto space-y-2">
                    <p
                        id="payment-disabled-reason"
                        className="text-center text-[11px] text-neutral-500"
                    >
                        Payment confirmation is enabled in Phase 6.
                    </p>
                    <button
                        disabled
                        aria-describedby="payment-disabled-reason"
                        className="h-12 w-full rounded-xl bg-green-700 text-[14px] font-semibold text-white opacity-50"
                    >
                        Confirm payment
                    </button>
                </div>
            </section>
        </div>
    );
}
