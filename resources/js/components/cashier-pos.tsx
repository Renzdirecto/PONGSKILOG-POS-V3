import { useForm, usePage, useRemember } from '@inertiajs/react';
import {
    Check,
    ChevronRight,
    ShoppingBag,
    UtensilsCrossed,
} from 'lucide-react';
import { useRef, useState } from 'react';
import type { FormEvent } from 'react';
import { CashierCatalog } from '@/components/cashier-catalog';
import { PosPaymentPreview } from '@/components/pos-payment-preview';
import { PosCart } from '@/components/pos-cart';
import {
    PosProductDialog,
    posDialogClass,
} from '@/components/pos-product-dialog';
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
import { store } from '@/routes/pos/orders';
import type { BranchSummary } from '@/types';
import type { CashierCatalog as Catalog } from '@/types/catalog';
import type {
    BranchTable,
    CartLine,
    OrderSummary,
    OrderType,
    PosProduct,
} from '@/types/pos';

export function CashierPos({
    branch,
    catalog,
    tables,
}: {
    branch: BranchSummary;
    catalog: Catalog;
    tables: BranchTable[];
}) {
    const rememberKey = `pos:${usePage().props.auth.user?.id}:${branch.id}`;
    const initialDraft = usePage().flash.posDraft as OrderSummary | undefined;
    const [orderType, setOrderType] = useRemember<OrderType | null>(
        initialDraft?.order_type ?? null,
        `${rememberKey}:type`,
    );
    const [lines, setLines] = useRemember<CartLine[]>(
        [],
        `${rememberKey}:lines`,
    );
    const [saved, setSaved] = useRemember<OrderSummary | null>(
        initialDraft ?? null,
        `${rememberKey}:saved`,
    );
    const [dialog, setDialog] = useState<
        'type' | 'cart' | 'information' | 'payment' | 'discard' | null
    >(initialDraft ? 'information' : orderType ? null : 'type');
    const [pendingType, setPendingType] = useState<OrderType | null>(null);
    const [editing, setEditing] = useState<{
        product: PosProduct;
        line?: CartLine;
    } | null>(null);
    const submitting = useRef(false);
    const form = useForm(`${rememberKey}:details`, {
        order_type: '',
        branch_table_id: '',
        customer_label: '',
        items: [] as {
            product_id: string;
            quantity: number;
            notes: string;
            modifiers: CartLine['modifiers'];
        }[],
    });
    const total = lines.reduce((sum, line) => sum + lineCents(line), 0n);

    function changeType(type: OrderType) {
        if (type === orderType || (saved && lines.length === 0)) return;
        setSaved(null);
        setOrderType(type);
        form.setData((data) => ({
            ...data,
            branch_table_id: '',
            customer_label: '',
        }));
        form.clearErrors();
    }
    function beginOrder(type: OrderType | null) {
        setLines([]);
        setSaved(null);
        setOrderType(type);
        setPendingType(null);
        form.reset();
        form.clearErrors();
        setDialog(type ? null : 'type');
    }
    function submit(event: FormEvent) {
        event.preventDefault();
        if (submitting.current || saved || !orderType || lines.length === 0)
            return;
        submitting.current = true;
        form.transform((data) => ({
            ...data,
            order_type: orderType,
            branch_table_id:
                orderType === 'dine_in' ? data.branch_table_id : null,
            items: lines.map((line) => ({
                product_id: line.product.id,
                quantity: line.quantity,
                notes: line.notes,
                modifiers: line.modifiers,
            })),
        }));
        form.submit(store(), {
            preserveScroll: true,
            preserveState: true,
            onFlash: (flash) => {
                const draft = flash.posDraft as OrderSummary | undefined;
                if (draft) {
                    setSaved(draft);
                    setDialog('information');
                }
            },
            onFinish: () => {
                submitting.current = false;
            },
        });
    }
    const customer = saved
        ? [saved.customer_label, saved.table_name].filter(Boolean).join(' / ')
        : orderType === 'dine_in'
          ? (tables.find((table) => table.id === form.data.branch_table_id)
                ?.name ?? '')
          : form.data.customer_label;
    const cart = (
        <PosCart
            lines={lines}
            orderType={orderType}
            saved={saved}
            customer={customer}
            onTypeChange={changeType}
            onEdit={(line) => {
                setDialog(null);
                setEditing({ product: line.product, line });
            }}
            onRemove={(key) =>
                setLines((current) =>
                    current.filter((line) => line.key !== key),
                )
            }
            onQuantityChange={(key, quantity) =>
                setLines((current) =>
                    current.map((line) =>
                        line.key === key ? { ...line, quantity } : line,
                    ),
                )
            }
            onCheckout={(flow) => {
                form.clearErrors();
                setDialog(flow);
            }}
            onClear={() => {
                setPendingType(null);
                if (saved) {
                    beginOrder(null);
                } else {
                    setDialog('discard');
                }
            }}
        />
    );

    return (
        <div className="pos-surface flex min-h-0 min-w-0 flex-1 flex-col text-[13px]">
            <div className="flex min-h-0 min-w-0 flex-1">
                <CashierCatalog
                    catalog={catalog}
                    workspace
                    lines={lines}
                    onSelect={
                        orderType && !saved
                            ? (product) => setEditing({ product })
                            : undefined
                    }
                />
                <aside
                    aria-label="Current order"
                    className="hidden w-[336px] shrink-0 border-l border-neutral-200 min-[1300px]:w-[382px] md:block"
                >
                    {cart}
                </aside>
            </div>
            <div className="fixed right-3 bottom-[88px] left-3 z-30 md:hidden">
                <Button
                    className="h-12 w-full justify-between rounded-xl bg-neutral-950 px-4 text-white shadow-lg hover:bg-black"
                    onClick={() => setDialog('cart')}
                >
                    <span className="flex items-center gap-2">
                        <ShoppingBag className="size-5" />
                        View cart ·{' '}
                        {(saved?.items ?? lines).reduce(
                            (sum, line) => sum + line.quantity,
                            0,
                        )}
                    </span>
                    <span>{saved ? pesos(saved.total) : pesos(total)}</span>
                </Button>
            </div>
            {editing && (
                <PosProductDialog
                    key={editing.line?.key ?? editing.product.id}
                    product={editing.product}
                    initial={editing.line}
                    onClose={() => setEditing(null)}
                    onRemove={() => {
                        setLines((current) =>
                            current.filter(
                                (line) => line.key !== editing.line?.key,
                            ),
                        );
                        setEditing(null);
                    }}
                    onSave={(line) => {
                        setLines((current) =>
                            editing.line
                                ? current.map((existing) =>
                                      existing.key === line.key
                                          ? line
                                          : existing,
                                  )
                                : [...current, line],
                        );
                        setEditing(null);
                    }}
                />
            )}
            {dialog !== null && (
                <Dialog
                    open
                    onOpenChange={(open) => {
                        if (!open && !form.processing && dialog !== 'type')
                            setDialog(null);
                    }}
                >
                    <DialogContent
                        style={
                            dialog === 'type'
                                ? { maxWidth: 'min(100%, 420px)' }
                                : undefined
                        }
                        className={
                            dialog === 'payment'
                                ? `${posDialogClass} pos-payment-dialog`
                                : dialog === 'cart'
                                  ? `${posDialogClass} sm:max-w-[480px]`
                                  : 'pos-surface flex max-h-[92dvh] flex-col gap-0 overflow-hidden rounded-[20px] border-neutral-200 bg-white p-0 text-neutral-950 max-md:top-auto max-md:bottom-0 max-md:max-w-full max-md:translate-y-0 max-md:rounded-b-none sm:max-w-[480px] [&:has([data-order-type-gate])>button:last-child]:hidden [&>button:last-child]:top-2 [&>button:last-child]:right-2 [&>button:last-child]:flex [&>button:last-child]:size-11 [&>button:last-child]:items-center [&>button:last-child]:justify-center'
                        }
                        onInteractOutside={(event) => {
                            if (form.processing || dialog === 'type')
                                event.preventDefault();
                        }}
                        onEscapeKeyDown={(event) => {
                            if (form.processing || dialog === 'type')
                                event.preventDefault();
                        }}
                    >
                        {dialog === 'type' ? (
                            <div
                                data-order-type-gate
                                className="flex flex-col items-center gap-2 overflow-y-auto px-[22px] py-[26px]"
                            >
                                <span className="mb-1 flex size-[52px] items-center justify-center rounded-2xl bg-neutral-950 text-white">
                                    <UtensilsCrossed className="size-6" />
                                </span>
                                <DialogTitle className="text-center text-[21px] font-bold tracking-tight">
                                    Select order type
                                </DialogTitle>
                                <DialogDescription className="max-w-64 text-center text-[12.5px] leading-5 text-neutral-500">
                                    How would you like to enjoy your Pongskilog
                                    today?
                                </DialogDescription>
                                <div className="mt-4 flex w-full flex-col gap-2.5">
                                    {(['dine_in', 'take_out'] as const).map(
                                        (type) => (
                                            <button
                                                key={type}
                                                className="flex min-h-[76px] items-center gap-3.5 rounded-[14px] border border-neutral-200 px-4 py-3.5 text-left hover:border-neutral-950 hover:bg-neutral-50"
                                                onClick={() => {
                                                    if (
                                                        lines.length &&
                                                        !saved
                                                    ) {
                                                        setPendingType(type);
                                                        setDialog('discard');
                                                    } else beginOrder(type);
                                                }}
                                            >
                                                <span className="flex size-[46px] shrink-0 items-center justify-center rounded-xl bg-neutral-100">
                                                    {type === 'dine_in' ? (
                                                        <UtensilsCrossed />
                                                    ) : (
                                                        <ShoppingBag />
                                                    )}
                                                </span>
                                                <span className="min-w-0 flex-1">
                                                    <span className="block text-base font-bold">
                                                        {type === 'dine_in'
                                                            ? 'Dine in'
                                                            : 'Take out'}
                                                    </span>
                                                    <span className="text-xs text-neutral-500">
                                                        {type === 'dine_in'
                                                            ? 'Eat at our comfortable tables'
                                                            : 'Freshly packed to go'}
                                                    </span>
                                                </span>
                                                <ChevronRight className="size-4 shrink-0 text-neutral-400" />
                                            </button>
                                        ),
                                    )}
                                </div>
                                <div className="mt-[18px] flex w-full flex-col gap-[9px] border-t border-neutral-200 pt-4">
                                    <p className="text-[9.5px] font-semibold tracking-widest text-neutral-400 uppercase">
                                        Kitchen status
                                    </p>
                                    <div className="flex gap-[9px]">
                                        {(['dine_in', 'take_out'] as const).map(
                                            (type) => (
                                                <div
                                                    key={type}
                                                    className="flex min-w-0 flex-1 items-center gap-[9px] rounded-[11px] bg-[#f7f7f7] px-[11px] py-[9px]"
                                                >
                                                    {type === 'dine_in' ? (
                                                        <UtensilsCrossed className="size-4 text-neutral-500" />
                                                    ) : (
                                                        <ShoppingBag className="size-4 text-neutral-500" />
                                                    )}
                                                    <div>
                                                        <p className="text-[9px] font-semibold tracking-wide text-neutral-400 uppercase">
                                                            {type === 'dine_in'
                                                                ? 'Dine in'
                                                                : 'Take out'}
                                                        </p>
                                                        <p
                                                            aria-label="Not yet available"
                                                            className="text-[15px] font-bold"
                                                        >
                                                            &mdash;
                                                        </p>
                                                    </div>
                                                </div>
                                            ),
                                        )}
                                    </div>
                                </div>
                            </div>
                        ) : (
                            <>
                                <div className="shrink-0 border-b border-neutral-200 px-4 py-5 pr-14">
                                    <DialogTitle className="text-[15px] font-bold">
                                        {dialog === 'cart'
                                            ? 'Your cart'
                                            : dialog === 'payment'
                                              ? 'Payment'
                                              : dialog === 'discard'
                                                ? 'Clear this order?'
                                                : 'Order information'}
                                    </DialogTitle>
                                    <DialogDescription className="sr-only">
                                        {dialog === 'discard'
                                            ? 'Clear the current items before starting another order.'
                                            : 'Review your items and order details.'}
                                    </DialogDescription>
                                </div>
                                {dialog === 'cart' && (
                                    <div className="min-h-0 flex-1 overflow-hidden">
                                        {cart}
                                    </div>
                                )}
                                {dialog === 'discard' && (
                                    <div className="space-y-4 p-5">
                                        <p className="text-xs leading-5 text-neutral-500">
                                            The current cart will be cleared.
                                            Nothing has been sent to the kitchen
                                            and no stock is affected.
                                        </p>
                                        <div className="flex justify-end gap-2">
                                            <Button
                                                variant="outline"
                                                className="min-h-11"
                                                onClick={() => setDialog(null)}
                                            >
                                                Keep order
                                            </Button>
                                            <Button
                                                className="min-h-11"
                                                onClick={() =>
                                                    beginOrder(pendingType)
                                                }
                                            >
                                                Clear and continue
                                            </Button>
                                        </div>
                                    </div>
                                )}
                                {dialog === 'payment' && orderType && (
                                    <PosPaymentPreview
                                        orderType={orderType}
                                        lines={lines}
                                        saved={saved}
                                        tables={tables}
                                        customerLabel={form.data.customer_label}
                                        tableId={form.data.branch_table_id}
                                        onCustomerChange={(value) =>
                                            form.setData(
                                                'customer_label',
                                                value,
                                            )
                                        }
                                        onTableChange={(value) =>
                                            form.setData(
                                                'branch_table_id',
                                                value,
                                            )
                                        }
                                    />
                                )}
                                {dialog === 'information' && (
                                    <form
                                        onSubmit={submit}
                                        aria-busy={form.processing}
                                        className="flex min-h-0 flex-col"
                                    >
                                        <div className="flex min-h-0 flex-col gap-4 overflow-y-auto px-4 py-[18px]">
                                            <div className="space-y-1 text-center">
                                                <p className="text-[10px] font-semibold tracking-wider text-neutral-500 uppercase">
                                                    {saved
                                                        ? 'Order number'
                                                        : 'New order'}
                                                </p>
                                                <p className="text-[28px] font-bold tracking-tight wrap-anywhere text-red-700">
                                                    {saved
                                                        ? `#${saved.order_number}`
                                                        : 'Draft'}
                                                </p>
                                            </div>
                                            <div
                                                className={`flex items-center justify-center gap-2 rounded-xl p-3 text-xs font-bold ${orderType === 'dine_in' ? 'bg-green-100 text-green-800' : 'bg-sky-100 text-sky-800'}`}
                                            >
                                                {orderType === 'dine_in' ? (
                                                    <UtensilsCrossed className="size-4" />
                                                ) : (
                                                    <ShoppingBag className="size-4" />
                                                )}
                                                {orderType === 'dine_in'
                                                    ? 'DINE IN'
                                                    : 'TAKE OUT'}
                                            </div>
                                            {saved ? (
                                                <>
                                                    <p className="text-center text-sm font-semibold wrap-anywhere">
                                                        {customer}
                                                    </p>
                                                    <ul className="divide-y divide-neutral-100">
                                                        {saved.items.map(
                                                            (item) => (
                                                                <li
                                                                    key={
                                                                        item.id
                                                                    }
                                                                    className="space-y-1 py-3"
                                                                >
                                                                    <div className="flex gap-2 text-xs">
                                                                        <span className="font-bold text-red-700">
                                                                            {
                                                                                item.quantity
                                                                            }
                                                                            ×
                                                                        </span>
                                                                        <span className="min-w-0 flex-1 font-semibold wrap-anywhere">
                                                                            {
                                                                                item.name
                                                                            }
                                                                        </span>
                                                                        <span className="font-bold text-red-700">
                                                                            {pesos(
                                                                                item.line_total,
                                                                            )}
                                                                        </span>
                                                                    </div>
                                                                    {item.modifiers.map(
                                                                        (
                                                                            modifier,
                                                                        ) => (
                                                                            <p
                                                                                key={
                                                                                    modifier.id
                                                                                }
                                                                                className="text-[11px] text-amber-800"
                                                                            >
                                                                                {
                                                                                    modifier.group_name
                                                                                }
                                                                                :{' '}
                                                                                {
                                                                                    modifier.name
                                                                                }{' '}
                                                                                (+
                                                                                {pesos(
                                                                                    modifier.price_delta,
                                                                                )}
                                                                                )
                                                                            </p>
                                                                        ),
                                                                    )}
                                                                    {item.notes && (
                                                                        <p className="rounded-md bg-orange-50 p-2 text-[11px] wrap-anywhere text-amber-800">
                                                                            {
                                                                                item.notes
                                                                            }
                                                                        </p>
                                                                    )}
                                                                </li>
                                                            ),
                                                        )}
                                                    </ul>
                                                    <div className="flex justify-between text-xs text-neutral-500">
                                                        <span>Subtotal</span>
                                                        <span>
                                                            {pesos(
                                                                saved.subtotal,
                                                            )}
                                                        </span>
                                                    </div>
                                                    <div className="flex justify-between text-lg font-bold">
                                                        <span>Total</span>
                                                        <span className="text-red-700">
                                                            {pesos(saved.total)}
                                                        </span>
                                                    </div>
                                                </>
                                            ) : (
                                                <>
                                                    <div className="space-y-2">
                                                        <Label
                                                            htmlFor="pos-customer"
                                                            className="text-[10px] tracking-wider text-neutral-500 uppercase"
                                                        >
                                                            Customer name /
                                                            order label{' '}
                                                            {orderType ===
                                                            'dine_in'
                                                                ? '(optional)'
                                                                : ''}
                                                        </Label>
                                                        <Input
                                                            id="pos-customer"
                                                            required={
                                                                orderType ===
                                                                'take_out'
                                                            }
                                                            maxLength={150}
                                                            value={
                                                                form.data
                                                                    .customer_label
                                                            }
                                                            onChange={(event) =>
                                                                form.setData(
                                                                    'customer_label',
                                                                    event.target
                                                                        .value,
                                                                )
                                                            }
                                                            disabled={
                                                                form.processing
                                                            }
                                                            className="h-[52px] rounded-xl text-base"
                                                            placeholder="e.g. Alex Johnson"
                                                        />
                                                    </div>
                                                    {orderType ===
                                                        'dine_in' && (
                                                        <fieldset className="space-y-2">
                                                            <legend className="mb-2 text-[10px] font-semibold tracking-wider text-neutral-500 uppercase">
                                                                Table selection
                                                                · required
                                                            </legend>
                                                            <div className="flex flex-wrap gap-2">
                                                                {tables.map(
                                                                    (table) => (
                                                                        <label
                                                                            key={
                                                                                table.id
                                                                            }
                                                                            className={`flex min-h-11 cursor-pointer items-center gap-2 rounded-xl border px-3 text-xs font-semibold ${form.data.branch_table_id === table.id ? 'border-neutral-950 bg-neutral-950 text-white' : 'border-neutral-200'}`}
                                                                        >
                                                                            <input
                                                                                type="radio"
                                                                                name="pos-table"
                                                                                value={
                                                                                    table.id
                                                                                }
                                                                                required
                                                                                checked={
                                                                                    form
                                                                                        .data
                                                                                        .branch_table_id ===
                                                                                    table.id
                                                                                }
                                                                                disabled={
                                                                                    form.processing
                                                                                }
                                                                                onChange={() =>
                                                                                    form.setData(
                                                                                        'branch_table_id',
                                                                                        table.id,
                                                                                    )
                                                                                }
                                                                                className="accent-neutral-500"
                                                                            />
                                                                            {
                                                                                table.name
                                                                            }
                                                                        </label>
                                                                    ),
                                                                )}
                                                            </div>
                                                            {tables.length ===
                                                                0 && (
                                                                <p className="text-xs text-red-700">
                                                                    No active
                                                                    tables are
                                                                    available.
                                                                    Ask your
                                                                    manager to
                                                                    configure
                                                                    this
                                                                    branch's
                                                                    tables.
                                                                </p>
                                                            )}
                                                        </fieldset>
                                                    )}
                                                </>
                                            )}
                                            <div className="space-y-1.5 rounded-xl bg-neutral-50 p-3 text-xs">
                                                <p className="font-semibold">
                                                    {saved
                                                        ? 'Draft saved · unpaid'
                                                        : `${lines.reduce((sum, line) => sum + line.quantity, 0)} items · ${pesos(total)} preview`}
                                                </p>
                                                <p className="leading-5 text-neutral-500">
                                                    {saved
                                                        ? 'Stock has not been reserved. This is an uncommitted draft.'
                                                        : 'Proceed saves a draft and checks current prices and availability. No payment, stock deduction or kitchen ticket.'}
                                                </p>
                                            </div>
                                            {Object.keys(form.errors).length >
                                                0 && (
                                                <div
                                                    role="alert"
                                                    className="space-y-1 rounded-xl bg-red-50 p-3 text-xs text-red-800"
                                                >
                                                    {Object.entries(
                                                        form.errors,
                                                    ).map(([key, error]) => (
                                                        <p key={key}>{error}</p>
                                                    ))}
                                                    <p>
                                                        Your cart is kept.
                                                        Review the items and try
                                                        again.
                                                    </p>
                                                </div>
                                            )}
                                        </div>
                                        <footer className="flex shrink-0 flex-col gap-2 border-t border-neutral-200 px-3.5 py-3 pb-[max(14px,env(safe-area-inset-bottom))]">
                                            <p className="text-center text-[11px] text-neutral-500">
                                                Pay Later activation will be
                                                enabled in the payment phase.
                                            </p>
                                            {saved ? (
                                                <Button
                                                    type="button"
                                                    disabled
                                                    className="h-12 rounded-xl bg-amber-50 text-amber-800"
                                                >
                                                    Activate Pay Later
                                                </Button>
                                            ) : (
                                                <Button
                                                    type="submit"
                                                    className="min-h-12 rounded-xl bg-neutral-950 text-white hover:bg-black"
                                                    disabled={
                                                        form.processing ||
                                                        (orderType ===
                                                            'dine_in' &&
                                                            tables.length === 0)
                                                    }
                                                >
                                                    <Check className="size-4" />
                                                    {form.processing
                                                        ? 'Checking order…'
                                                        : 'Proceed'}
                                                </Button>
                                            )}
                                            <button
                                                type="button"
                                                className="min-h-11 text-xs font-semibold tracking-wide text-neutral-500 uppercase"
                                                disabled={form.processing}
                                                onClick={() => setDialog(null)}
                                            >
                                                {saved
                                                    ? 'Back to POS'
                                                    : 'Cancel'}
                                            </button>
                                        </footer>
                                    </form>
                                )}
                            </>
                        )}
                    </DialogContent>
                </Dialog>
            )}
        </div>
    );
}
