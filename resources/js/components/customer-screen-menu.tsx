import { ShoppingBag, UtensilsCrossed } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { CategoryIcon } from '@/components/category-icon';
import {
    changedLineKeys,
    orderTypeText,
    type CustomerMenuData,
    type CustomerMenuProduct,
    type CustomerScreenCart,
    type CustomerScreenCartLine,
} from '@/lib/customer-screen';
import { pesos } from '@/lib/pos-money';
import type { CategoryIconKey } from '@/types/catalog';

/**
 * Browse-only Menu with the paired station's Live Cart (Phase 19.6A). When the cashier starts a cart, it appears as a
 * compact panel above the Menu (at most ~30% of the height, scrolling on its own) and the Menu stays fully usable
 * below it. Customers can change category and scroll; there is no add, edit, checkout or submit anywhere.
 */
export function CustomerScreenMenu({
    menu,
    cart,
    live,
}: {
    menu: CustomerMenuData | null;
    cart: CustomerScreenCart | null;
    live: boolean;
}) {
    return (
        <div className="flex min-h-0 flex-1 flex-col">
            {cart && cart.lines.length > 0 && (
                <LiveCart cart={cart} live={live} />
            )}
            <MenuBrowser menu={menu} />
        </div>
    );
}

function LiveCart({ cart, live }: { cart: CustomerScreenCart; live: boolean }) {
    const previous = useRef<CustomerScreenCartLine[] | null>(null);
    const [highlighted, setHighlighted] = useState<string[]>([]);

    useEffect(() => {
        const changed = changedLineKeys(previous.current, cart.lines);
        previous.current = cart.lines;
        if (changed.length === 0) return;
        setHighlighted(changed);
        const timer = window.setTimeout(() => setHighlighted([]), 1400);

        return () => window.clearTimeout(timer);
    }, [cart.lines]);

    return (
        <section
            aria-label="Your order"
            aria-live="polite"
            className="flex max-h-[30dvh] min-h-[96px] shrink-0 flex-col border-b-2 border-[#f5c542]/60 bg-[#18191a]"
        >
            <header className="flex shrink-0 flex-wrap items-center gap-x-3 gap-y-1 px-4 py-2.5 sm:px-6">
                <ShoppingBag className="size-5 text-[#f5c542]" />
                <h2 className="text-base font-black tracking-wide sm:text-lg">
                    Your order
                </h2>
                {cart.order_type && (
                    <span className="rounded-full bg-white/10 px-2.5 py-0.5 text-[11px] font-black tracking-[0.12em]">
                        {orderTypeText(cart.order_type)}
                    </span>
                )}
                {!live && (
                    <span className="rounded-full bg-amber-500/15 px-2.5 py-0.5 text-[11px] font-bold text-amber-300">
                        May not be up to date
                    </span>
                )}
                <span className="ml-auto text-sm text-white/60 tabular-nums">
                    {cart.item_count} {cart.item_count === 1 ? 'item' : 'items'}
                </span>
                <strong className="text-lg font-black text-[#f5c542] tabular-nums sm:text-2xl">
                    {pesos(cart.total)}
                </strong>
            </header>
            <ul className="min-h-0 flex-1 overflow-y-auto overscroll-contain px-3 pb-2 sm:px-5">
                {cart.lines.map((line) => (
                    <li
                        key={line.key}
                        className={`grid grid-cols-[auto_minmax(0,1fr)_auto] items-start gap-3 rounded-xl px-2 py-1.5 transition-colors duration-700 ${highlighted.includes(line.key) ? 'bg-[#f5c542]/15' : ''}`}
                    >
                        <span className="min-w-8 text-base font-black tabular-nums">
                            {line.quantity}×
                        </span>
                        <div className="min-w-0">
                            <p className="truncate text-sm font-bold sm:text-base">
                                {line.name}
                            </p>
                            {(line.details.length > 0 ||
                                line.instructions.length > 0) && (
                                <p className="truncate text-xs text-white/55">
                                    {[
                                        ...line.details.map(
                                            (detail) => `+ ${detail}`,
                                        ),
                                        ...line.instructions,
                                    ].join(' · ')}
                                </p>
                            )}
                        </div>
                        <span className="text-sm font-bold tabular-nums sm:text-base">
                            {pesos(line.amount)}
                        </span>
                    </li>
                ))}
            </ul>
        </section>
    );
}

function MenuBrowser({ menu }: { menu: CustomerMenuData | null }) {
    const [category, setCategory] = useState<string>('all');
    const scroller = useRef<HTMLDivElement>(null);
    const categories = menu?.categories ?? [];
    const selected = categories.some((item) => item.key === category)
        ? category
        : 'all';
    const products = (menu?.products ?? []).filter(
        (product) => selected === 'all' || product.category === selected,
    );

    if (menu === null) {
        return (
            <div className="grid min-h-0 flex-1 grid-cols-2 gap-3 overflow-hidden p-4 sm:grid-cols-3 lg:grid-cols-4">
                {Array.from({ length: 8 }, (_, index) => (
                    <div
                        key={index}
                        className="h-56 animate-pulse rounded-2xl bg-white/6"
                    />
                ))}
            </div>
        );
    }

    return (
        <div className="flex min-h-0 flex-1 flex-col">
            <nav
                aria-label="Menu categories"
                className="flex shrink-0 [scrollbar-width:none] gap-2 overflow-x-auto px-4 py-3 sm:px-6"
            >
                {[
                    { key: 'all', name: 'All', icon_key: 'utensils' },
                    ...categories,
                ].map((item) => (
                    <button
                        key={item.key}
                        type="button"
                        aria-pressed={selected === item.key}
                        onClick={() => {
                            setCategory(item.key);
                            scroller.current?.scrollTo({ top: 0 });
                        }}
                        className={`inline-flex min-h-11 shrink-0 items-center gap-2 rounded-full border px-4 text-sm font-bold transition ${selected === item.key ? 'border-[#f5c542] bg-[#f5c542] text-neutral-950' : 'border-white/15 bg-white/5 text-white/80 hover:bg-white/10'}`}
                    >
                        <CategoryIcon
                            iconKey={item.icon_key as CategoryIconKey}
                            className="size-4"
                        />
                        {item.name}
                    </button>
                ))}
            </nav>
            <div
                ref={scroller}
                className="min-h-0 flex-1 overflow-y-auto overscroll-contain px-4 pb-6 sm:px-6"
            >
                {products.length === 0 ? (
                    <div className="flex h-full min-h-48 flex-col items-center justify-center gap-3 text-center text-white/45">
                        <UtensilsCrossed className="size-10" />
                        <p className="text-lg font-semibold">
                            Menu items will appear here.
                        </p>
                    </div>
                ) : (
                    <ul className="grid grid-cols-2 gap-3 sm:grid-cols-3 sm:gap-4 lg:grid-cols-4 2xl:grid-cols-5">
                        {products.map((product) => (
                            <MenuCard key={product.key} product={product} />
                        ))}
                    </ul>
                )}
            </div>
        </div>
    );
}

function MenuCard({ product }: { product: CustomerMenuProduct }) {
    const [imageFailed, setImageFailed] = useState(false);
    const unavailable = !product.available;

    return (
        <li
            className={`flex min-w-0 flex-col overflow-hidden rounded-2xl border border-white/10 bg-[#1a1b1b] ${unavailable ? 'opacity-55' : ''}`}
        >
            <div className="relative aspect-[4/3] bg-white/5">
                {product.image_url && !imageFailed ? (
                    <img
                        src={product.image_url}
                        alt=""
                        loading="lazy"
                        decoding="async"
                        onError={() => setImageFailed(true)}
                        className="size-full object-cover"
                    />
                ) : (
                    <div className="flex size-full items-center justify-center">
                        <img
                            src="/images/branding/pongskilog-emblem.png"
                            alt=""
                            className="size-14 rounded-full opacity-40"
                        />
                    </div>
                )}
                {unavailable && (
                    <span className="absolute top-2 left-2 rounded-full bg-neutral-950/85 px-2.5 py-1 text-[11px] font-black tracking-wide text-white uppercase">
                        {product.status === 'sold_out'
                            ? 'Sold out'
                            : 'Unavailable'}
                    </span>
                )}
            </div>
            <div className="flex min-w-0 flex-1 flex-col gap-1 p-3">
                <h3 className="text-sm leading-snug font-bold wrap-break-word sm:text-base">
                    {product.name}
                </h3>
                {product.description && (
                    <p className="line-clamp-2 text-xs text-white/50">
                        {product.description}
                    </p>
                )}
                <div className="mt-auto pt-1">
                    {product.sizes.length > 0 ? (
                        <ul className="flex flex-wrap gap-x-3 gap-y-0.5 text-xs text-white/70 sm:text-sm">
                            {product.sizes.map((size) => (
                                <li
                                    key={size.name}
                                    className={
                                        size.available
                                            ? ''
                                            : 'text-white/35 line-through'
                                    }
                                >
                                    {size.name}{' '}
                                    <strong className="text-[#f5c542]">
                                        {pesos(size.price)}
                                    </strong>
                                </li>
                            ))}
                        </ul>
                    ) : (
                        <strong className="text-base text-[#f5c542] sm:text-lg">
                            {pesos(product.price)}
                        </strong>
                    )}
                    {product.has_options && (
                        <p className="text-[11px] text-white/40">
                            Add-ons available
                        </p>
                    )}
                </div>
            </div>
        </li>
    );
}
