import { ShoppingBag } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import {
    changedLineKeys,
    orderTypeText,
    type CustomerScreenCart,
    type CustomerScreenCartLine,
} from '@/lib/customer-screen';
import { pesos } from '@/lib/pos-money';

/** Keys of cart lines that were just added or changed, highlighted briefly (shared by both cart presentations). */
export function useHighlightedLines(lines: CustomerScreenCartLine[]): string[] {
    const previous = useRef<CustomerScreenCartLine[] | null>(null);
    const [highlighted, setHighlighted] = useState<string[]>([]);

    useEffect(() => {
        const changed = changedLineKeys(previous.current, lines);
        previous.current = lines;
        if (changed.length === 0) return;
        setHighlighted(changed);
        const timer = window.setTimeout(() => setHighlighted([]), 1400);

        return () => window.clearTimeout(timer);
    }, [lines]);

    return highlighted;
}

/**
 * Ads mode + an active cart (Phase 19.6): the slideshow gives way to the full customer order summary while the
 * cashier builds the order — items, quantities, Size / add-ons, structured instructions and the running total, all
 * derived by the server from the Branch catalog. No ids, cost, staff notes or payment data. Clearing the cart returns
 * to the ads; paying shows the order confirmation.
 */
export function CustomerScreenOrderSummary({
    cart,
    live,
}: {
    cart: CustomerScreenCart;
    live: boolean;
}) {
    const highlighted = useHighlightedLines(cart.lines);

    return (
        <section
            aria-label="Your order"
            aria-live="polite"
            className="animate-in fade-in flex min-h-0 flex-1 flex-col bg-white duration-300"
        >
            <header className="flex shrink-0 flex-wrap items-center gap-x-4 gap-y-2 border-b border-neutral-200 px-5 py-4 sm:px-8 sm:py-5">
                <ShoppingBag className="size-7 text-amber-800 sm:size-8" />
                <h2 className="text-[clamp(22px,4vmin,40px)] font-black tracking-tight">
                    Your order
                </h2>
                {cart.order_type && (
                    <span className="rounded-full border border-neutral-200 bg-neutral-100 px-3.5 py-1 text-xs font-black tracking-[0.14em] sm:text-sm">
                        {orderTypeText(cart.order_type)}
                    </span>
                )}
                {!live && (
                    <span className="rounded-full bg-amber-500/15 px-3 py-1 text-xs font-bold text-amber-800">
                        May not be up to date
                    </span>
                )}
            </header>
            <ul className="min-h-0 flex-1 overflow-y-auto overscroll-contain px-3 py-3 sm:px-6">
                {cart.lines.map((line) => (
                    <li
                        key={line.key}
                        className={`grid grid-cols-[auto_minmax(0,1fr)_auto] items-start gap-3 rounded-2xl px-3 py-3 transition-colors duration-700 sm:gap-5 sm:px-4 ${highlighted.includes(line.key) ? 'bg-[#f5c542]/15' : ''}`}
                    >
                        <span className="min-w-10 text-[clamp(18px,3vmin,30px)] font-black tabular-nums">
                            {line.quantity}×
                        </span>
                        <div className="min-w-0">
                            <p className="text-[clamp(17px,2.8vmin,28px)] leading-tight font-bold break-words">
                                {line.name}
                            </p>
                            {line.details.length > 0 && (
                                <p className="mt-1 text-[clamp(13px,2vmin,19px)] text-neutral-600">
                                    {line.details
                                        .map((detail) => `+ ${detail}`)
                                        .join(' · ')}
                                </p>
                            )}
                            {line.instructions.length > 0 && (
                                <p className="mt-1 text-[clamp(13px,2vmin,19px)] text-amber-800/85">
                                    {line.instructions.join(' · ')}
                                </p>
                            )}
                        </div>
                        <span className="text-[clamp(16px,2.6vmin,26px)] font-bold tabular-nums">
                            {pesos(line.amount)}
                        </span>
                    </li>
                ))}
            </ul>
            <footer className="flex shrink-0 items-center justify-between gap-4 border-t border-neutral-200 bg-amber-50 px-5 py-4 pb-[max(16px,env(safe-area-inset-bottom))] sm:px-8 sm:py-5">
                <span className="text-sm text-neutral-600 tabular-nums sm:text-lg">
                    {cart.item_count} {cart.item_count === 1 ? 'item' : 'items'}
                </span>
                <span className="flex items-baseline gap-3">
                    <span className="text-sm font-bold tracking-[0.18em] text-neutral-600 uppercase sm:text-base">
                        Total
                    </span>
                    <strong className="text-[clamp(28px,6vmin,64px)] leading-none font-black text-amber-800 tabular-nums">
                        {pesos(cart.total)}
                    </strong>
                </span>
            </footer>
        </section>
    );
}
