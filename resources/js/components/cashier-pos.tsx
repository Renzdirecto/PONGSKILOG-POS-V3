import { useForm } from '@inertiajs/react';
import { Plus, ShoppingBag, UtensilsCrossed } from 'lucide-react';
import { useRef, useState } from 'react';
import type { FormEvent } from 'react';
import { CashierCatalog } from '@/components/cashier-catalog';
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
import type { BranchTable, CartLine, OrderType, PosProduct } from '@/types/pos';

export function CashierPos({
    branch,
    catalog,
    tables,
}: {
    branch: BranchSummary;
    catalog: Catalog;
    tables: BranchTable[];
}) {
    const [orderType, setOrderType] = useState<OrderType | null>(null);
    const [lines, setLines] = useState<CartLine[]>([]);
    const [dialog, setDialog] = useState<
        'type' | 'cart' | 'information' | 'discard' | null
    >(null);
    const [editing, setEditing] = useState<{
        product: PosProduct;
        line?: CartLine;
    } | null>(null);
    const submitting = useRef(false);
    const form = useForm({
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

    function beginOrder() {
        setLines([]);
        setOrderType(null);
        form.reset();
        form.clearErrors();
        setDialog('type');
    }

    function submit(event: FormEvent) {
        event.preventDefault();
        if (submitting.current || !orderType || lines.length === 0) return;
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
            onFinish: () => {
                submitting.current = false;
            },
        });
    }

    const cart = (
        <PosCart
            lines={lines}
            onEdit={(line) => {
                setDialog(null);
                setEditing({ product: line.product, line });
            }}
            onRemove={(key) =>
                setLines((current) =>
                    current.filter((line) => line.key !== key),
                )
            }
            onReview={() => {
                form.clearErrors();
                setDialog('information');
            }}
        />
    );

    return (
        <div className="pos-surface min-w-0 pb-20 text-[13px] md:pb-0">
            <header className="mb-4 flex flex-wrap items-center justify-between gap-3 rounded-xl bg-[#111111] px-4 py-3 text-white">
                <div>
                    <h1 className="text-[17px] font-bold">POS / Order</h1>
                    <p className="mt-1 text-xs text-neutral-300">
                        {branch.name} ·{' '}
                        <span className="text-emerald-300">STORE IS OPEN</span>
                    </p>
                </div>
                <Button
                    className="min-h-12 bg-white text-neutral-950 hover:bg-neutral-200"
                    onClick={() =>
                        lines.length ? setDialog('discard') : beginOrder()
                    }
                >
                    <Plus /> New order
                </Button>
            </header>
            <div className="grid min-w-0 gap-4 md:grid-cols-[minmax(0,1fr)_300px] xl:grid-cols-[minmax(0,1fr)_350px]">
                <section className="min-w-0 rounded-xl border border-neutral-200 bg-white p-3 sm:p-4">
                    <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
                        <h2 className="text-[15px] font-bold">Menu</h2>
                        {orderType ? (
                            <span
                                className={`rounded-lg px-3 py-2 text-xs font-bold ${orderType === 'dine_in' ? 'bg-emerald-100 text-emerald-900' : 'bg-sky-100 text-sky-900'}`}
                            >
                                {orderType === 'dine_in'
                                    ? 'DINE IN'
                                    : 'TAKE OUT'}
                            </span>
                        ) : (
                            <Button
                                variant="outline"
                                className="min-h-11"
                                onClick={() => setDialog('type')}
                            >
                                Choose order type
                            </Button>
                        )}
                    </div>
                    {!orderType && (
                        <p className="mb-4 rounded-lg bg-neutral-100 p-3 text-xs text-neutral-600">
                            Start a new order and choose Dine In or Take Out to
                            add products.
                        </p>
                    )}
                    <CashierCatalog
                        catalog={catalog}
                        onSelect={
                            orderType
                                ? (product) => setEditing({ product })
                                : undefined
                        }
                    />
                </section>
                <aside className="sticky top-4 hidden h-fit min-w-0 rounded-xl border border-neutral-200 bg-white p-4 md:block">
                    {cart}
                </aside>
            </div>
            <div className="fixed right-3 bottom-3 left-3 z-30 md:hidden">
                <Button
                    className="min-h-14 w-full justify-between rounded-xl bg-neutral-950 px-4 text-white shadow-lg hover:bg-neutral-800"
                    onClick={() => setDialog('cart')}
                >
                    <span className="flex items-center gap-2">
                        <ShoppingBag /> View cart ·{' '}
                        {lines.reduce((sum, line) => sum + line.quantity, 0)}
                    </span>
                    <span>{pesos(total)}</span>
                </Button>
            </div>
            {editing && (
                <PosProductDialog
                    key={editing.line?.key ?? editing.product.id}
                    product={editing.product}
                    initial={editing.line}
                    onClose={() => setEditing(null)}
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
            <Dialog
                open={dialog !== null}
                onOpenChange={(open) => {
                    if (!open && !form.processing) setDialog(null);
                }}
            >
                <DialogContent
                    className={
                        dialog === 'cart'
                            ? posDialogClass
                            : 'pos-surface max-h-[90dvh] overflow-y-auto bg-white text-neutral-950 [&>button:last-child]:top-1 [&>button:last-child]:right-1 [&>button:last-child]:size-11'
                    }
                    onInteractOutside={(event) => {
                        if (form.processing) event.preventDefault();
                    }}
                    onEscapeKeyDown={(event) => {
                        if (form.processing) event.preventDefault();
                    }}
                >
                    <DialogTitle className="pr-8 text-base">
                        {dialog === 'type'
                            ? 'Select order type'
                            : dialog === 'cart'
                              ? 'Your cart'
                              : dialog === 'discard'
                                ? 'Start a new order?'
                                : 'Order information'}
                    </DialogTitle>
                    <DialogDescription>
                        {dialog === 'type'
                            ? 'Choose how this order will be served.'
                            : dialog === 'discard'
                              ? 'The items in the current cart will be cleared.'
                              : dialog === 'cart'
                                ? 'Review your items before continuing.'
                                : 'Confirm the details to review your order summary.'}
                    </DialogDescription>
                    {dialog === 'type' && (
                        <div className="grid grid-cols-2 gap-3">
                            {(['dine_in', 'take_out'] as const).map((type) => (
                                <button
                                    key={type}
                                    className={`flex min-h-32 flex-col items-center justify-center gap-3 rounded-xl border p-4 font-bold focus-visible:outline-2 ${type === 'dine_in' ? 'border-emerald-200 bg-emerald-50 text-emerald-900' : 'border-sky-200 bg-sky-50 text-sky-900'}`}
                                    onClick={() => {
                                        setOrderType(type);
                                        setDialog(null);
                                    }}
                                >
                                    {type === 'dine_in' ? (
                                        <UtensilsCrossed />
                                    ) : (
                                        <ShoppingBag />
                                    )}
                                    {type === 'dine_in'
                                        ? 'Dine In'
                                        : 'Take Out'}
                                </button>
                            ))}
                        </div>
                    )}
                    {dialog === 'discard' && (
                        <div className="flex justify-end gap-2">
                            <Button
                                variant="outline"
                                className="min-h-11"
                                onClick={() => setDialog(null)}
                            >
                                Keep order
                            </Button>
                            <Button className="min-h-11" onClick={beginOrder}>
                                Start new order
                            </Button>
                        </div>
                    )}
                    {dialog === 'cart' && cart}
                    {dialog === 'information' && (
                        <form
                            onSubmit={submit}
                            className="space-y-4"
                            aria-busy={form.processing}
                        >
                            <p
                                className={`w-fit rounded-lg px-3 py-2 text-xs font-bold ${orderType === 'dine_in' ? 'bg-emerald-100 text-emerald-900' : 'bg-sky-100 text-sky-900'}`}
                            >
                                {orderType === 'dine_in'
                                    ? 'DINE IN'
                                    : 'TAKE OUT'}
                            </p>
                            {orderType === 'dine_in' ? (
                                <div className="space-y-2">
                                    <Label htmlFor="pos-table">Table</Label>
                                    <select
                                        id="pos-table"
                                        value={form.data.branch_table_id}
                                        onChange={(event) =>
                                            form.setData(
                                                'branch_table_id',
                                                event.target.value,
                                            )
                                        }
                                        required
                                        disabled={form.processing}
                                        className="h-12 w-full rounded-lg border border-neutral-300 bg-white px-3 text-base"
                                    >
                                        <option value="">Choose a table</option>
                                        {tables.map((table) => (
                                            <option
                                                key={table.id}
                                                value={table.id}
                                            >
                                                {table.name}
                                            </option>
                                        ))}
                                    </select>
                                    {tables.length === 0 && (
                                        <p className="text-sm text-red-700">
                                            No active tables are available. Ask
                                            your manager to configure this
                                            branch’s tables.
                                        </p>
                                    )}
                                </div>
                            ) : (
                                <div className="space-y-2">
                                    <Label htmlFor="pos-customer">
                                        Customer name / order label
                                    </Label>
                                    <Input
                                        id="pos-customer"
                                        required
                                        maxLength={150}
                                        value={form.data.customer_label}
                                        onChange={(event) =>
                                            form.setData(
                                                'customer_label',
                                                event.target.value,
                                            )
                                        }
                                        disabled={form.processing}
                                        className="h-12 text-base"
                                        placeholder="e.g. Maria"
                                    />
                                </div>
                            )}
                            {Object.keys(form.errors).length > 0 && (
                                <div
                                    role="alert"
                                    className="space-y-1 rounded-lg bg-red-50 p-3 text-sm text-red-800"
                                >
                                    {Object.entries(form.errors).map(
                                        ([key, error]) => (
                                            <p key={key}>{error}</p>
                                        ),
                                    )}
                                    <p>
                                        Your cart is kept. Review the items and
                                        try again.
                                    </p>
                                </div>
                            )}
                            <div className="flex justify-between border-t pt-4 font-bold">
                                <span>Preview total</span>
                                <span className="text-red-700">
                                    {pesos(total)}
                                </span>
                            </div>
                            <Button
                                type="submit"
                                className="min-h-12 w-full bg-neutral-950 text-white hover:bg-neutral-800"
                                disabled={
                                    form.processing ||
                                    (orderType === 'dine_in' &&
                                        tables.length === 0)
                                }
                            >
                                {form.processing
                                    ? 'Checking order…'
                                    : 'Review order summary'}
                            </Button>
                            <Button
                                type="button"
                                variant="outline"
                                className="min-h-11 w-full"
                                disabled={form.processing}
                                onClick={() => setDialog('cart')}
                            >
                                Back to cart
                            </Button>
                        </form>
                    )}
                </DialogContent>
            </Dialog>
        </div>
    );
}
