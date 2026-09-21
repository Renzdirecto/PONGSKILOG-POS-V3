import {
    router,
    useForm,
    useHttp,
    usePage,
    useRemember,
} from '@inertiajs/react';
import {
    Check,
    ChevronRight,
    Clock3,
    Plus,
    ShoppingBag,
    UtensilsCrossed,
} from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { CashierCatalog } from '@/components/cashier-catalog';
import { OperationalItemName } from '@/components/operational-item-name';
import { PosTableSelection } from '@/components/pos-table-selection';
import { PosPaid } from '@/components/pos-paid';
import { store as payNow } from '@/routes/pos/payments';
import { store as reserveOrder } from '@/routes/pos/orders/reservations';
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
import { savedItemName } from '@/lib/pos-item-name';
import {
    confirmedPayLaterState,
    payLaterAttemptForOrder,
} from '@/lib/pos-pay-later';
import {
    customerDisplayLabel,
    customerLabelAfterTableChange,
    freshOrderDetails,
    needsOrderReservation,
} from '@/lib/pos-order';
import { store as commitPayLater } from '@/routes/pos/orders/pay-later';
import type { BranchSummary } from '@/types';
import type { CashierCatalog as Catalog } from '@/types/catalog';
import type {
    BranchTable,
    CartLine,
    OrderSummary,
    OrderReservation,
    OrderType,
    PayLaterAttempt,
    PayLaterInput,
    PayLaterOrder,
    PosProduct,
    PaymentInput,
    PaymentAttempt,
    PaidReceipt,
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
    const [reservation, setReservation] = useRemember<OrderReservation | null>(
        null,
        `${rememberKey}:reservation`,
    );
    const [attempt, setAttempt] = useRemember<PaymentAttempt | null>(
        null,
        `${rememberKey}:payment-attempt`,
    );
    const [receipt, setReceipt] = useRemember<PaidReceipt | null>(
        null,
        `${rememberKey}:receipt`,
    );
    const [payLaterAttempt, setPayLaterAttempt] =
        useRemember<PayLaterAttempt | null>(
            null,
            `${rememberKey}:pay-later-attempt`,
        );
    const [payLaterSuccess, setPayLaterSuccess] =
        useRemember<PayLaterOrder | null>(
            null,
            `${rememberKey}:pay-later-success`,
        );
    const payment = useHttp<PaymentAttempt, { receipt: PaidReceipt }>({
        idempotency_key: '',
        payment_method: 'cash',
        cash_received: null,
        cashless_amount: null,
    });
    const reservationRequest = useHttp<
        { order_type: OrderType },
        { order: OrderReservation }
    >({ order_type: 'take_out' });
    const payLater = useHttp<
        PayLaterInput,
        { order: PayLaterOrder }
    >({ idempotency_key: '' });
    const reservationSubmitting = useRef(false);
    const [reservationError, setReservationError] = useState('');
    const [paymentError, setPaymentError] = useState('');
    const [payLaterError, setPayLaterError] = useState('');
    const paymentSubmitting = useRef(false);
    const payLaterSubmitting = useRef(false);
    const [dialog, setDialog] = useState<
        | 'type'
        | 'cart'
        | 'information'
        | 'payment'
        | 'paid'
        | 'payLaterSuccess'
        | 'receipt'
        | 'discard'
        | null
    >(
        payLaterSuccess
            ? 'payLaterSuccess'
            : attempt
            ? 'payment'
            : receipt
              ? 'paid'
              : initialDraft
                ? 'information'
                : orderType
                  ? null
                  : 'type',
    );
    const [pendingType, setPendingType] = useState<OrderType | null>(null);
    const [editing, setEditing] = useState<{
        product: PosProduct;
        line?: CartLine;
    } | null>(null);
    const form = useForm(`${rememberKey}:details`, freshOrderDetails());
    const total = lines.reduce((sum, line) => sum + lineCents(line), 0n);
    const orderNumber = saved?.order_number ?? reservation?.order_number ?? null;

    useEffect(() => {
        if (
            !needsOrderReservation(
                orderType,
                saved !== null,
                reservation !== null,
                reservationSubmitting.current,
            )
        )
            return;

        reservationSubmitting.current = true;
        setReservationError('');
        reservationRequest.transform(() => ({ order_type: orderType }));
        reservationRequest
            .submit(reserveOrder())
            .then((result) => {
                if (result.order) setReservation(result.order);
            })
            .catch(() => {
                setReservationError(
                    'Unable to allocate an order number. Check the store and connection, then try again.',
                );
                setOrderType(null);
                setDialog('type');
            })
            .finally(() => {
                reservationSubmitting.current = false;
            });
    }, [orderType, reservation, saved]);

    function changeType(type: OrderType) {
        if (type === orderType || (saved && lines.length === 0)) return;
        setSaved(null);
        setOrderType(type);
        form.setData((data) => ({
            ...data,
            customer_label: '',
        }));
        form.clearErrors();
    }
    function beginOrder(type: OrderType | null) {
        setReceipt(null);
        setPayLaterSuccess(null);
        setPayLaterAttempt(null);
        setPayLaterError('');
        setAttempt(null);
        setPaymentError('');
        setLines([]);
        setSaved(null);
        setReservationError('');
        setOrderType(type);
        setPendingType(null);
        form.reset();
        form.setData(freshOrderDetails());
        form.clearErrors();
        setDialog(type ? null : 'type');
    }
    async function confirmPayment(input: PaymentInput) {
        if (paymentSubmitting.current || !orderType) return;
        const reservedOrder = reservation;
        if (!saved && !reservedOrder) {
            setPaymentError(
                reservationError ||
                    'Wait for the order number before confirming payment.',
            );
            return;
        }
        if (!navigator.onLine) {
            setPaymentError(
                'You are offline. Reconnect before confirming payment.',
            );
            return;
        }
        const payload: PaymentAttempt = attempt ?? {
            ...input,
            idempotency_key: crypto.randomUUID(),
            ...(saved
                ? { draft_order_id: saved.id }
                : {
                      reserved_order_id: reservedOrder?.id,
                      order_type: orderType,
                      customer_label: form.data.customer_label,
                      branch_table_id: form.data.branch_table_id || null,
                      items: lines.map((line) => ({
                          product_id: line.product.id,
                          quantity: line.quantity,
                          notes: line.notes,
                          modifiers: line.modifiers,
                      })),
                  }),
        };
        paymentSubmitting.current = true;
        setAttempt(payload);
        setPaymentError('');
        payment.transform(() => payload);
        try {
            const result = await payment.submit(payNow());
            if (result.receipt?.payment_status !== 'paid')
                throw new Error('Unconfirmed payment');
            setReceipt(result.receipt);
            setAttempt(null);
            setLines([]);
            setSaved(null);
            setReservation(null);
            form.reset();
            form.setData(freshOrderDetails());
            form.clearErrors();
            setDialog('paid');
            router.reload({ only: ['catalog', 'storeSession'] });
        } catch (error: unknown) {
            const response =
                error && typeof error === 'object' && 'response' in error
                    ? (error.response as {
                          status: number;
                          data?: {
                              message?: string;
                              errors?: Record<string, string[]>;
                          };
                      })
                    : null;
            if (
                response &&
                [401, 403, 404, 409, 419, 422].includes(response.status)
            ) {
                setAttempt(null);
                const messages = Object.values(
                    response.data?.errors ?? {},
                ).flat();
                setPaymentError(
                    messages.join(' ') ||
                        ({
                            401: 'Your session expired. Sign in again before paying.',
                            403: 'Your cashier or branch access is no longer available.',
                            404: 'This order is unavailable in the current branch.',
                            409: 'This payment attempt has already been used. Check the order before proceeding.',
                            419: 'Your session expired. Refresh and sign in again.',
                            422: 'Check the payment and order details.',
                        }[response.status] ??
                            'Payment was rejected.'),
                );
            } else {
                setPaymentError(
                    'Payment result is unconfirmed. Retry the same payment to safely recover the result.',
                );
            }
        } finally {
            paymentSubmitting.current = false;
        }
    }
    async function savePayLater() {
        if (payLaterSubmitting.current || !orderType) return;
        const targetOrder = saved ?? reservation;
        if (!targetOrder) {
            setPayLaterError(
                reservationError ||
                    'Wait for the order number before saving this Pay Later order.',
            );
            return;
        }
        if (!navigator.onLine) {
            setPayLaterError(
                'You are offline. Reconnect before saving this Pay Later order.',
            );
            return;
        }

        const activation = payLaterAttemptForOrder(
            payLaterAttempt,
            {
                order_id: targetOrder.id,
                ...(saved
                    ? {}
                    : {
                          order_type: orderType,
                          customer_label: form.data.customer_label,
                          branch_table_id:
                              form.data.branch_table_id || null,
                          items: lines.map((line) => ({
                              product_id: line.product.id,
                              quantity: line.quantity,
                              notes: line.notes,
                              modifiers: line.modifiers,
                          })),
                      }),
            },
        );
        payLaterSubmitting.current = true;
        setPayLaterAttempt(activation);
        setPayLaterError('');
        const { order_id: orderId, ...payload } = activation;
        payLater.transform(() => payload);
        try {
            const result = await payLater.submit(commitPayLater(orderId));
            if (!result.order || !confirmedPayLaterState(result.order)) {
                throw new Error('Unconfirmed Pay Later result');
            }
            setPayLaterSuccess(result.order);
            setPayLaterAttempt(null);
            setLines([]);
            setSaved(null);
            setReservation(null);
            form.reset();
            form.setData(freshOrderDetails());
            form.clearErrors();
            setDialog('payLaterSuccess');
            router.reload({ only: ['catalog', 'storeSession'] });
        } catch (error: unknown) {
            const response =
                error && typeof error === 'object' && 'response' in error
                    ? (error.response as {
                          status: number;
                          data?: {
                              message?: string;
                              errors?: Record<string, string[]>;
                          };
                      })
                    : null;
            if (
                response &&
                [401, 403, 404, 409, 419, 422].includes(response.status)
            ) {
                setPayLaterAttempt(null);
                const messages = Object.values(
                    response.data?.errors ?? {},
                ).flat();
                setPayLaterError(
                    messages.join(' ') ||
                        response.data?.message ||
                        ({
                            401: 'Your session expired. Sign in again before saving this Pay Later order.',
                            403: 'Your cashier or branch access is no longer available.',
                            404: 'This order is unavailable in the current branch.',
                            409: 'This order was already committed by another Pay Later attempt.',
                            419: 'Your session expired. Refresh and sign in again.',
                            422: 'Check the order details and current stock before trying again.',
                        }[response.status] ??
                            'The Pay Later order was rejected.'),
                );
            } else {
                setPayLaterError(
                    'Pay Later result is unconfirmed. Retry to safely recover the same attempt.',
                );
            }
        } finally {
            payLaterSubmitting.current = false;
        }
    }
    const customer = saved
        ? customerDisplayLabel(saved.customer_label, saved.table_name)
        : customerDisplayLabel(
              form.data.customer_label,
              tables.find((table) => table.id === form.data.branch_table_id)
                  ?.name,
          );
    function changeTable(value: string) {
        form.setData((data) => ({
            ...data,
            customer_label: customerLabelAfterTableChange(
                tables,
                data.branch_table_id,
                data.customer_label,
                value,
            ),
            branch_table_id: value,
        }));
    }
    const cart = (
        <PosCart
            lines={lines}
            orderType={orderType}
            saved={saved}
            orderNumber={orderNumber}
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
                        if (
                            !open &&
                            !payment.processing &&
                            !payLater.processing &&
                            !attempt &&
                            !payLaterAttempt &&
                            dialog !== 'type'
                        )
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
                                : 'pos-surface flex max-h-[92dvh] flex-col gap-0 overflow-hidden rounded-[20px] border-neutral-200 bg-white p-0 text-neutral-950 max-md:top-auto max-md:bottom-0 max-md:max-w-full max-md:translate-y-0 max-md:rounded-b-none sm:max-w-[480px] [&:has([data-order-type-gate])>button:last-child]:hidden [&:has([data-pay-later-success])>button:last-child]:hidden [&>button:last-child]:top-2 [&>button:last-child]:right-2 [&>button:last-child]:flex [&>button:last-child]:size-11 [&>button:last-child]:items-center [&>button:last-child]:justify-center'
                        }
                        onInteractOutside={(event) => {
                            if (
                                payment.processing ||
                                payLater.processing ||
                                attempt ||
                                payLaterAttempt ||
                                dialog === 'type'
                            )
                                event.preventDefault();
                        }}
                        onEscapeKeyDown={(event) => {
                            if (
                                payment.processing ||
                                payLater.processing ||
                                attempt ||
                                payLaterAttempt ||
                                dialog === 'type'
                            )
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
                                {reservationError && (
                                    <p
                                        role="alert"
                                        className="mt-2 rounded-lg bg-red-50 px-3 py-2 text-center text-xs text-red-700"
                                    >
                                        {reservationError}
                                    </p>
                                )}
                                <div className="mt-4 flex w-full flex-col gap-2.5">
                                    {(['dine_in', 'take_out'] as const).map(
                                        (type) => (
                                            <button
                                                key={type}
                                                type="button"
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
                                <div
                                    className={
                                        dialog === 'paid' ||
                                        dialog === 'receipt' ||
                                        dialog === 'payLaterSuccess'
                                            ? 'sr-only'
                                            : 'shrink-0 border-b border-neutral-200 px-4 py-3.5 pr-14'
                                    }
                                >
                                    <DialogTitle className="text-[15px] font-bold">
                                        {dialog === 'cart'
                                            ? 'Your cart'
                                            : dialog === 'payment'
                                              ? 'Payment'
                                              : dialog === 'paid'
                                              ? 'Payment successful'
                                              : dialog === 'payLaterSuccess'
                                                ? 'Saved as Pay Later'
                                                : dialog === 'receipt'
                                                  ? 'Receipt'
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
                                {(dialog === 'paid' || dialog === 'receipt') &&
                                    receipt && (
                                        <PosPaid
                                            receipt={receipt}
                                            showReceipt={dialog === 'receipt'}
                                            onReceipt={() =>
                                                setDialog('receipt')
                                            }
                                            onBack={() => setDialog('paid')}
                                            onNewOrder={() => beginOrder(null)}
                                        />
                                    )}
                                {dialog === 'payLaterSuccess' &&
                                    payLaterSuccess && (
                                        <div
                                            data-pay-later-success
                                            className="flex min-h-0 flex-col"
                                        >
                                            <div className="flex min-h-0 flex-1 flex-col gap-4 overflow-y-auto px-[18px] pt-6 pb-[18px]">
                                                <div className="flex flex-col items-center gap-2 text-center">
                                                    <span className="mb-0.5 flex size-[50px] items-center justify-center rounded-[15px] bg-amber-50 text-amber-700">
                                                        <Clock3 className="size-6" />
                                                    </span>
                                                    <p className="text-[40px] leading-none font-bold tracking-tight wrap-anywhere text-red-700">
                                                        #
                                                        {
                                                            payLaterSuccess.order_number
                                                        }
                                                    </p>
                                                    <div className="flex flex-wrap justify-center gap-2">
                                                        <span className="inline-flex h-[26px] items-center rounded-full border border-red-200 bg-red-50 px-3 text-[11.5px] font-bold tracking-wider text-red-700">
                                                            UNPAID
                                                        </span>
                                                        <span className="inline-flex h-[26px] items-center rounded-full border border-amber-300 bg-amber-50 px-3 text-[11.5px] font-bold tracking-wider text-amber-800">
                                                            PAY LATER
                                                        </span>
                                                    </div>
                                                    <p className="max-w-72 text-[12.5px] leading-5 text-neutral-600">
                                                        Sent to the kitchen and
                                                        recorded in Transaction
                                                        History. Payment remains
                                                        pending.
                                                    </p>
                                                </div>
                                                <dl className="overflow-hidden rounded-[14px] border border-neutral-200">
                                                    {[
                                                        [
                                                            'Order type',
                                                            payLaterSuccess.order_type ===
                                                            'dine_in'
                                                                ? 'Dine in'
                                                                : 'Take out',
                                                        ],
                                                        [
                                                            'Customer / table',
                                                            customerDisplayLabel(
                                                                payLaterSuccess.customer_label,
                                                                payLaterSuccess.table_name,
                                                            ) || 'Walk-in',
                                                        ],
                                                        [
                                                            'Reference',
                                                            payLaterSuccess.reference_number ??
                                                                'â€”',
                                                        ],
                                                        [
                                                            'Payment status',
                                                            'Pending · Pay Later',
                                                        ],
                                                    ].map(([label, value]) => (
                                                        <div
                                                            key={label}
                                                            className="flex items-center justify-between gap-3 border-b border-neutral-100 px-3.5 py-3 last:border-b-0"
                                                        >
                                                            <dt className="shrink-0 text-xs text-neutral-500">
                                                                {label}
                                                            </dt>
                                                            <dd className="min-w-0 text-right text-[13px] font-semibold wrap-anywhere">
                                                                {value}
                                                            </dd>
                                                        </div>
                                                    ))}
                                                </dl>
                                                <div className="overflow-hidden rounded-[14px] border border-neutral-200">
                                                    <p className="bg-neutral-50 px-3.5 py-2.5 text-[10px] font-semibold tracking-wider text-neutral-500 uppercase">
                                                        Order items
                                                    </p>
                                                    <ul className="max-h-[200px] divide-y divide-neutral-100 overflow-y-auto">
                                                        {payLaterSuccess.items.map(
                                                            (item) => (
                                                                <li
                                                                    key={
                                                                        item.id
                                                                    }
                                                                    className="flex items-start justify-between gap-3 px-3.5 py-2.5"
                                                                >
                                                                    <span className="min-w-0 space-y-0.5">
                                                                        <span className="block text-[13px] font-semibold wrap-anywhere">
                                                                            {
                                                                                item.quantity
                                                                            }
                                                                            ×{' '}
                                                                            <OperationalItemName
                                                                                value={savedItemName(
                                                                                    item,
                                                                                )}
                                                                            />
                                                                        </span>
                                                                        {item.modifiers
                                                                            .filter(
                                                                                (
                                                                                    modifier,
                                                                                ) =>
                                                                                    modifier.semantic_role !==
                                                                                    'size',
                                                                            )
                                                                            .map(
                                                                            (
                                                                                modifier,
                                                                            ) => (
                                                                                <span
                                                                                    key={
                                                                                        modifier.id
                                                                                    }
                                                                                    className="block text-[11px] leading-4 text-neutral-500 wrap-anywhere"
                                                                                >
                                                                                    {
                                                                                        modifier.group_name
                                                                                    }
                                                                                    :{' '}
                                                                                    {
                                                                                        modifier.name
                                                                                    }
                                                                                </span>
                                                                            ),
                                                                            )}
                                                                        {item.notes && (
                                                                            <span className="block text-[11px] leading-4 text-amber-800 wrap-anywhere">
                                                                                Note:{' '}
                                                                                {
                                                                                    item.notes
                                                                                }
                                                                            </span>
                                                                        )}
                                                                    </span>
                                                                    <span className="shrink-0 text-[13px] font-semibold tabular-nums">
                                                                        {pesos(
                                                                            item.line_total,
                                                                        )}
                                                                    </span>
                                                                </li>
                                                            ),
                                                        )}
                                                    </ul>
                                                    <div className="flex items-baseline justify-between gap-3 bg-neutral-950 px-3.5 py-3 text-white">
                                                        <span className="text-[13px] font-semibold">
                                                            Total
                                                        </span>
                                                        <span className="text-[22px] font-bold tracking-tight tabular-nums">
                                                            {pesos(
                                                                payLaterSuccess.total,
                                                            )}
                                                        </span>
                                                    </div>
                                                </div>
                                            </div>
                                            <footer className="shrink-0 border-t border-neutral-200 px-3.5 py-3 pb-[max(14px,env(safe-area-inset-bottom))]">
                                                <Button
                                                    type="button"
                                                    className="h-12 w-full rounded-xl bg-neutral-950 text-[15.5px] text-white hover:bg-black"
                                                    onClick={() =>
                                                        beginOrder(null)
                                                    }
                                                >
                                                    <Plus className="size-4" />
                                                    New order
                                                </Button>
                                            </footer>
                                        </div>
                                    )}
                                {dialog === 'payment' &&
                                    orderType &&
                                    orderNumber && (
                                        <PosPaymentPreview
                                            attempt={attempt}
                                            processing={payment.processing}
                                            error={paymentError}
                                            onConfirm={confirmPayment}
                                            orderType={orderType}
                                            lines={lines}
                                            saved={saved}
                                            orderNumber={orderNumber}
                                            tables={tables}
                                            customerLabel={
                                                form.data.customer_label
                                            }
                                            tableId={form.data.branch_table_id}
                                            onCustomerChange={(value) =>
                                                form.setData(
                                                    'customer_label',
                                                    value,
                                                )
                                            }
                                            onTableChange={changeTable}
                                        />
                                    )}
                                {dialog === 'information' && (
                                    <form
                                        onSubmit={(event) => {
                                            event.preventDefault();
                                            void savePayLater();
                                        }}
                                        aria-busy={payLater.processing}
                                        className="flex min-h-0 flex-col"
                                    >
                                        <div className="flex min-h-0 flex-col gap-4 overflow-y-auto px-4 py-[18px]">
                                            <div className="space-y-1 text-center">
                                                <p className="text-[10px] font-semibold tracking-wider text-neutral-500 uppercase">
                                                    Order number
                                                </p>
                                                <p className="text-[28px] font-bold tracking-tight wrap-anywhere text-red-700">
                                                    {orderNumber
                                                        ? `#${orderNumber}`
                                                        : 'Preparing…'}
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
                                                                            <OperationalItemName
                                                                                value={savedItemName(
                                                                                    item,
                                                                                )}
                                                                            />
                                                                        </span>
                                                                        <span className="font-bold text-red-700">
                                                                            {pesos(
                                                                                item.line_total,
                                                                            )}
                                                                        </span>
                                                                    </div>
                                                                    {item.modifiers
                                                                        .filter(
                                                                            (
                                                                                modifier,
                                                                            ) =>
                                                                                modifier.semantic_role !==
                                                                                'size',
                                                                        )
                                                                        .map(
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
                                                            (optional)
                                                        </Label>
                                                        <Input
                                                            id="pos-customer"
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
                                                                payLater.processing ||
                                                                payLaterAttempt !==
                                                                    null
                                                            }
                                                            className="h-[52px] rounded-xl text-base"
                                                            placeholder="e.g. Alex Johnson"
                                                        />
                                                    </div>
                                                    <PosTableSelection
                                                        tables={tables}
                                                        tableId={
                                                            form.data
                                                                .branch_table_id
                                                        }
                                                        disabled={
                                                            payLater.processing ||
                                                            payLaterAttempt !== null
                                                        }
                                                        onChange={changeTable}
                                                    />
                                                </>
                                            )}
                                            <div className="space-y-1.5 rounded-xl bg-neutral-50 p-3 text-xs">
                                                <p className="font-semibold">
                                                    {saved
                                                        ? `${saved.items.reduce((sum, item) => sum + item.quantity, 0)} items · ${pesos(saved.total)}`
                                                        : `${lines.reduce((sum, line) => sum + line.quantity, 0)} items · ${pesos(total)} preview`}
                                                </p>
                                                <p className="leading-5 text-neutral-500">
                                                    Proceed deducts stock
                                                    immediately and sends this
                                                    order to the kitchen. Payment
                                                    remains unpaid.
                                                </p>
                                            </div>
                                            {payLaterError && (
                                                <div
                                                    role="alert"
                                                    className="rounded-xl border border-red-200 bg-red-50 p-3 text-xs leading-5 text-red-800"
                                                >
                                                    <p>{payLaterError}</p>
                                                    <p>
                                                        Your cart and order
                                                        details are kept. Fix the
                                                        issue, then retry safely.
                                                    </p>
                                                </div>
                                            )}
                                        </div>
                                        <footer className="flex shrink-0 flex-col gap-2 border-t border-neutral-200 px-3.5 py-3 pb-[max(14px,env(safe-area-inset-bottom))]">
                                            <Button
                                                type="submit"
                                                className="min-h-12 rounded-xl bg-neutral-950 text-white hover:bg-black"
                                                disabled={payLater.processing}
                                            >
                                                <Check className="size-4" />
                                                {payLater.processing
                                                    ? 'Saving as Pay Later…'
                                                    : 'Proceed'}
                                            </Button>
                                            <button
                                                type="button"
                                                className="min-h-11 text-xs font-semibold tracking-wide text-neutral-500 uppercase"
                                                disabled={
                                                    payLater.processing ||
                                                    payLaterAttempt !== null
                                                }
                                                onClick={() => {
                                                    setPayLaterError('');
                                                    setDialog(null);
                                                }}
                                            >
                                                Cancel
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
