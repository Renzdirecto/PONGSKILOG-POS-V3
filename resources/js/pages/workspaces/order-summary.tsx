import { Head, Link } from '@inertiajs/react';
import { CheckCircle2, Plus } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { pesos } from '@/lib/pos-money';
import { cashier } from '@/routes/workspaces';
import type { OrderSummary } from '@/types/pos';

export default function Summary({ order }: { order: OrderSummary }) {
    return (
        <div className="pos-surface mx-auto max-w-2xl space-y-4">
            <Head title={`Order #${order.order_number}`} />
            <header className="flex items-center gap-3 rounded-xl bg-[#111111] p-4 text-white">
                <CheckCircle2 className="text-emerald-300" />
                <div>
                    <h1 className="text-[17px] font-bold">Order summary</h1>
                    <p className="mt-1 text-xs text-neutral-300">
                        Draft saved · Unpaid · Not sent to kitchen
                    </p>
                </div>
            </header>
            <section className="space-y-5 rounded-xl border border-neutral-200 bg-white p-4 sm:p-6">
                <div className="space-y-3">
                    <p className="text-2xl font-bold wrap-anywhere text-red-700">
                        #{order.order_number}
                    </p>
                    <span
                        className={`inline-block rounded-lg px-3 py-2 text-xs font-bold ${order.order_type === 'dine_in' ? 'bg-emerald-100 text-emerald-900' : 'bg-sky-100 text-sky-900'}`}
                    >
                        {order.order_type === 'dine_in'
                            ? 'DINE IN'
                            : 'TAKE OUT'}
                    </span>
                    <p className="text-sm font-semibold wrap-anywhere">
                        {order.table_name ?? order.customer_label}
                    </p>
                </div>
                <ul className="divide-y">
                    {order.items.map((item) => (
                        <li key={item.id} className="space-y-2 py-4">
                            <div className="flex items-start gap-2 text-sm font-bold">
                                <span className="text-red-700">
                                    {item.quantity}×
                                </span>
                                <span className="min-w-0 flex-1 wrap-anywhere">
                                    {item.name}
                                </span>
                                <span className="text-red-700">
                                    {pesos(item.line_total)}
                                </span>
                            </div>
                            <p className="text-xs text-neutral-500">
                                Base price {pesos(item.unit_price)} each
                            </p>
                            {item.modifiers.map((modifier) => (
                                <p
                                    key={modifier.id}
                                    className="text-xs wrap-anywhere text-neutral-600"
                                >
                                    {modifier.group_name}: {modifier.name} (+
                                    {pesos(modifier.price_delta)} each)
                                </p>
                            ))}
                            {item.notes && (
                                <p className="rounded-lg bg-orange-50 p-2 text-sm wrap-anywhere whitespace-pre-wrap text-orange-900">
                                    {item.notes}
                                </p>
                            )}
                        </li>
                    ))}
                </ul>
                <div className="space-y-3 border-t pt-4">
                    <div className="flex justify-between text-sm">
                        <span>Subtotal</span>
                        <span>{pesos(order.subtotal)}</span>
                    </div>
                    <div className="flex justify-between text-lg font-bold">
                        <span>Total</span>
                        <span className="text-red-700">
                            {pesos(order.total)}
                        </span>
                    </div>
                </div>
                <p className="rounded-lg bg-neutral-100 p-3 text-xs leading-5 text-neutral-600">
                    This order is saved as a draft. Stock has not been reserved.
                    Payment and sending orders to the kitchen are not available
                    yet.
                </p>
                <div className="grid grid-cols-2 gap-3">
                    <Button
                        disabled
                        className="min-h-12 bg-emerald-700 text-white"
                        aria-describedby="payment-unavailable"
                    >
                        Pay Now
                    </Button>
                    <Button
                        disabled
                        className="min-h-12 bg-amber-200 text-amber-950"
                        aria-describedby="payment-unavailable"
                    >
                        Save / Pay Later
                    </Button>
                </div>
                <p
                    id="payment-unavailable"
                    className="text-center text-xs text-neutral-500"
                >
                    Payment and Pay Later will be enabled in a future update.
                </p>
                <Link
                    href={cashier()}
                    className="flex min-h-12 items-center justify-center gap-2 rounded-lg bg-neutral-950 px-4 text-sm font-semibold text-white"
                >
                    <Plus className="size-4" /> Start new order
                </Link>
            </section>
        </div>
    );
}
