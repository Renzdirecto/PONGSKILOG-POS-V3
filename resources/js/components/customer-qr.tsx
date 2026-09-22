import { Head, router, useRemember } from '@inertiajs/react';
import {
    ArrowLeft,
    Check,
    ChevronRight,
    Clock3,
    Facebook,
    Globe,
    MapPin,
    Minus,
    Plus,
    ShoppingBag,
    Trash2,
} from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { toast } from 'sonner';
import {
    CustomerQrProduct,
    QrImage,
    qrButton,
    qrPanel,
    qrPrimary,
} from '@/components/customer-qr-product';
import { CustomerQrTracking, QrItems } from '@/components/customer-qr-tracking';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import { useCustomerQrRealtime } from '@/hooks/use-customer-qr-realtime';
import {
    canStartQrOrder,
    mergeQrLine,
    qrLineCents,
    qrStatus,
} from '@/lib/qr-order';
import { qrError, qrRequest } from '@/lib/qr-http';
import { terms, privacy } from '@/lib/qr-copy';
import { pesos } from '@/lib/pos-money';
import { createClientUuid } from '@/lib/client-uuid';
import { reset } from '@/routes/qr';
import { store as submitQr } from '@/routes/qr/orders';
import type { BranchSummary } from '@/types';
import type { OrderType } from '@/types/pos';
import type { QrLine, QrOrder, QrProduct } from '@/types/qr';

type Props = {
    branch: BranchSummary;
    store: { status: 'open' | 'closed' };
    catalog: {
        categories: { id: string; name: string }[];
        products: QrProduct[];
    };
    order: QrOrder | null;
};
export type QrView =
    | 'welcome'
    | 'menu'
    | 'cart'
    | 'review'
    | 'success'
    | 'track'
    | 'receipt';
type Intent = {
    idempotency_key: string;
    order_type: OrderType;
    customer_label: string;
    items: {
        product_id: string;
        quantity: number;
        notes: string;
        modifiers: QrLine['modifiers'];
    }[];
};
const steps = [
    [
        'Pumili at mag-order',
        'Browse the menu, customize your items, at i-review ang order.',
    ],
    [
        'Get your order number',
        'After submitting, bibigyan ka namin ng unique order number.',
    ],
    [
        'Show it to the cashier',
        'Ipakita ang order number sa cashier para sa payment. Hindi pa ipapadala sa kitchen ang order hangga’t hindi confirmed ang payment.',
    ],
];
export function QrSocials({ branch }: { branch: BranchSummary }) {
    return (
        <div className="flex gap-1.5">
            <button
                className={qrButton}
                aria-label="Facebook"
                onClick={() =>
                    window.open(
                        'https://www.facebook.com/search/top?q=Pongskilog',
                        '_blank',
                        'noopener,noreferrer',
                    )
                }
            >
                <Facebook size={17} />
            </button>
            <button
                className={qrButton}
                aria-label="Website"
                onClick={() =>
                    toast.info('Wala pang naka-configure na website link.')
                }
            >
                <Globe size={17} />
            </button>
            <button
                className={qrButton}
                aria-label="Maps"
                onClick={() =>
                    window.open(
                        `https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(`Pongskilog ${branch.name}`)}`,
                        '_blank',
                        'noopener,noreferrer',
                    )
                }
            >
                <MapPin size={17} />
            </button>
        </div>
    );
}
export default function CustomerQr({
    branch,
    store,
    catalog,
    order: serverOrder,
}: Props) {
    const key = `qr:${branch.id}`;
    const [view, setView] = useRemember<QrView>('welcome', `${key}:view`);
    const [cart, setCart] = useRemember<QrLine[]>([], `${key}:cart`);
    const [orderType, setOrderType] = useRemember<OrderType | null>(
        null,
        `${key}:type`,
    );
    const [name, setName] = useRemember('', `${key}:name`);
    const [intent, setIntent] = useRemember<Intent | null>(
        null,
        `${key}:intent`,
    );
    const [order, setOrder] = useState(serverOrder);
    const [query, setQuery] = useState('');
    const [category, setCategory] = useState('all');
    const [editing, setEditing] = useState<{
        product: QrProduct;
        line?: QrLine;
    } | null>(null);
    const [modal, setModal] = useState<
        'details' | 'terms' | 'privacy' | 'remove' | null
    >(null);
    const [removeKey, setRemoveKey] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);
    const submitting = useRef(false);
    const [error, setError] = useState('');
    const [online, setOnline] = useState(
        () => typeof navigator === 'undefined' || navigator.onLine,
    );
    const connection = useCustomerQrRealtime(
        branch.id,
        order?.public_tracking_id,
    );
    useEffect(() => {
        setOrder(serverOrder);
        if (serverOrder && ['cart', 'review'].includes(view)) {
            setView('track');
            setCart([]);
            setIntent(null);
        } else if (
            !serverOrder &&
            ['success', 'track', 'receipt'].includes(view)
        ) {
            setView('welcome');
        }
    }, [serverOrder]);
    useEffect(() => {
        const update = () => setOnline(navigator.onLine);
        window.addEventListener('online', update);
        window.addEventListener('offline', update);
        return () => {
            window.removeEventListener('online', update);
            window.removeEventListener('offline', update);
        };
    }, []);
    const total = cart.reduce((sum, line) => sum + qrLineCents(line), 0n);
    const count = cart.reduce((sum, line) => sum + line.quantity, 0);
    const unavailable = cart.some(
        (line) =>
            !catalog.products.find((product) => product.id === line.product.id)
                ?.is_available,
    );
    const priceChanged = cart.some((line) => {
        const product = catalog.products.find(
            (product) => product.id === line.product.id,
        );
        return (
            product &&
            (product.effective_price !== line.product.effective_price ||
                JSON.stringify(product.modifier_groups) !==
                    JSON.stringify(line.product.modifier_groups))
        );
    });
    const terminal = order ? canStartQrOrder(order) : false;
    const go = (next: QrView) => {
        setView(next);
        setModal(null);
        setEditing(null);
        setError('');
        window.scrollTo(0, 0);
    };
    const begin = async () => {
        if (submitting.current) return;
        submitting.current = true;
        setBusy(true);
        try {
            await qrRequest(reset(branch.id));
            setOrder(null);
            setCart([]);
            setIntent(null);
            setOrderType(null);
            setName('');
            go('menu');
            router.reload({ only: ['order', 'store', 'catalog'] });
        } catch (reason) {
            setError(qrError(reason).message);
        } finally {
            submitting.current = false;
            setBusy(false);
        }
    };
    const submit = async () => {
        if (
            submitting.current ||
            !orderType ||
            !cart.length ||
            !online ||
            (unavailable && !intent) ||
            (priceChanged && !intent)
        )
            return;
        submitting.current = true;
        setBusy(true);
        setError('');
        const payload = intent ?? {
            idempotency_key: createClientUuid(),
            order_type: orderType,
            customer_label: name,
            items: cart.map((line) => ({
                product_id: line.product.id,
                quantity: line.quantity,
                notes: line.notes,
                modifiers: line.modifiers,
            })),
        };
        setIntent(payload);
        try {
            const result = await qrRequest<{ order: QrOrder }>(
                submitQr(branch.id),
                payload,
            );
            setOrder(result.order);
            setCart([]);
            setIntent(null);
            go('success');
        } catch (reason) {
            const failure = qrError(reason);
            setError(failure.message);
            if ([401, 403, 404, 409, 419, 422].includes(failure.status))
                setIntent(null);
            router.reload({ only: ['store', 'catalog', 'order'] });
        } finally {
            submitting.current = false;
            setBusy(false);
        }
    };
    const openProduct = (product: QrProduct, line?: QrLine) => {
        if (!intent) setEditing({ product, line });
    };
    const cartItems: QrOrder['items'] = cart.map((line) => {
        const mods = (line.product.modifier_groups ?? []).flatMap((group) =>
            group.options
                .filter((option) =>
                    line.modifiers.some(
                        (selected) => selected.option_id === option.id,
                    ),
                )
                .map((option) => ({
                    name: option.name,
                    group_name: group.name,
                    semantic_role: group.semantic_role,
                    price_delta: option.price_delta,
                    quantity: 1,
                })),
        );
        const size = mods
            .filter((mod) => mod.semantic_role === 'size')
            .map((mod) => mod.name)
            .join(' ');
        return {
            name: line.product.name,
            display_name: `${size ? `${size} ` : ''}${line.product.name}`,
            quantity: line.quantity,
            unit_price: line.product.effective_price,
            line_total: `${qrLineCents(line) / 100n}.${String(qrLineCents(line) % 100n).padStart(2, '0')}`,
            notes: line.notes,
            modifiers: mods,
        };
    });
    const filtered = catalog.products.filter(
        (product) =>
            (category === 'all' || category === product.category_id) &&
            `${product.name} ${product.description ?? ''}`
                .toLowerCase()
                .includes(query.toLowerCase()),
    );
    const favorites = [
        'liemposilog',
        'porksilog',
        'chiksilog',
        'tapsilog',
        'bangsilog',
        'pares',
        'lemoncucumber',
        'lemonyakult',
    ].flatMap((name) =>
        catalog.products.filter(
            (product) =>
                product.name.toLowerCase().replaceAll(/[^a-z]/g, '') === name,
        ),
    );
    const closed =
        store.status === 'closed' &&
        (!order || ['welcome', 'menu', 'cart', 'review'].includes(view));
    return (
        <div className="pos-surface min-h-dvh bg-[#fafafa] [font-family:Poppins,sans-serif] text-[#111]">
            <Head title={`${branch.name} · Order`} />
            {view !== 'welcome' && (
                <header className="border-b border-neutral-200 bg-white">
                    <div className="mx-auto flex max-w-[1120px] items-center gap-2 px-3 py-1.5">
                        <button
                            onClick={() => go('menu')}
                            aria-label="PONGSKILOG menu"
                        >
                            <img
                                src="/images/branding/logo.png"
                                alt="Pongskilog"
                                className="h-7 w-auto"
                            />
                        </button>
                        <div className="min-w-0 flex-1">
                            <p className="truncate text-[11.5px] font-semibold">
                                {branch.name}
                            </p>
                            <p className="text-[10.5px] text-neutral-500">
                                {store.status === 'open'
                                    ? 'Open now · Order mula sa phone mo'
                                    : 'Store is currently closed'}
                            </p>
                        </div>
                        <QrSocials branch={branch} />
                    </div>
                </header>
            )}
            {(!online || connection !== 'connected') && (
                <div
                    role="status"
                    className="mx-auto flex max-w-[1120px] items-center justify-between gap-2 px-3 py-2 text-xs text-amber-800"
                >
                    <span>
                        {!online
                            ? 'You are offline. Ordering is unavailable.'
                            : 'Live updates unavailable. Showing your last confirmed status.'}
                    </span>
                    <button
                        className={qrButton}
                        onClick={() =>
                            router.reload({
                                only: ['order', 'catalog', 'store'],
                            })
                        }
                    >
                        Retry now
                    </button>
                </div>
            )}
            {error && (
                <p
                    role="alert"
                    className="mx-auto my-3 max-w-[560px] rounded-xl border border-red-200 bg-red-50 p-3 text-sm text-red-800"
                >
                    {error}
                </p>
            )}
            {closed ? (
                <main className="mx-auto flex max-w-[520px] flex-col items-center gap-5 px-4 py-16 text-center">
                    <img
                        src="/images/branding/logo.png"
                        alt="Pongskilog"
                        className="h-12"
                    />
                    <h1 className="text-xl font-bold">
                        STORE IS CURRENTLY CLOSED
                    </h1>
                    <p className="text-sm text-neutral-500">
                        {branch.name} · Ordering is paused. Please check again
                        when the store opens.
                    </p>
                    <button
                        className={qrPrimary}
                        onClick={() =>
                            router.reload({
                                only: ['store', 'catalog', 'order'],
                            })
                        }
                    >
                        Check again
                    </button>
                    {order && (
                        <button
                            className={qrButton}
                            onClick={() => go('track')}
                        >
                            View current order
                        </button>
                    )}
                </main>
            ) : (
                <>
                    {view === 'welcome' && (
                        <main className="mx-auto flex min-h-dvh max-w-[520px] flex-col gap-[18px] bg-white px-[14px] py-5 min-[900px]:gap-6 min-[900px]:px-6 min-[900px]:py-[38px]">
                            <div className="flex flex-col items-center gap-2.5 text-center">
                                <img
                                    src="/images/branding/logo.png"
                                    alt="Pongskilog"
                                    className="h-[46px] w-auto"
                                />
                                <strong className="text-sm">
                                    {branch.name}
                                </strong>
                                <h1 className="text-[26px] font-bold tracking-tight">
                                    Order mula sa phone mo
                                </h1>
                            </div>
                            {!order ? (
                                <>
                                    <div className="flex flex-col gap-3">
                                        {steps.map(([title, body], i) => (
                                            <div
                                                key={title}
                                                className={`${qrPanel} flex gap-3`}
                                            >
                                                <span className="flex size-9 shrink-0 items-center justify-center rounded-xl bg-neutral-100 font-bold">
                                                    {i + 1}
                                                </span>
                                                <div>
                                                    <h2 className="text-sm font-bold">
                                                        {title}
                                                    </h2>
                                                    <p className="mt-1 text-[12px] leading-5 text-neutral-500">
                                                        {body}
                                                    </p>
                                                </div>
                                            </div>
                                        ))}
                                    </div>
                                    <button
                                        className={`${qrPrimary} h-12 w-full`}
                                        onClick={() => go('menu')}
                                    >
                                        Continue to menu{' '}
                                        <ChevronRight size={18} />
                                    </button>
                                    <p className="text-center text-[11px] leading-5 text-neutral-500">
                                        By continuing, you agree to our{' '}
                                        <button
                                            className="text-red-700 underline"
                                            onClick={() => setModal('terms')}
                                        >
                                            Terms &amp; Conditions
                                        </button>{' '}
                                        and acknowledge our{' '}
                                        <button
                                            className="text-red-700 underline"
                                            onClick={() => setModal('privacy')}
                                        >
                                            Privacy Notice
                                        </button>
                                        .
                                    </p>
                                </>
                            ) : (
                                <div
                                    className={`${qrPanel} flex flex-col gap-3 text-center`}
                                >
                                    <strong>
                                        {terminal
                                            ? 'Last order'
                                            : 'May current order ka pa'}
                                    </strong>
                                    <h2 className="text-4xl font-bold">
                                        #{order.order_number}
                                    </h2>
                                    <p>{qrStatus(order)}</p>
                                    <button
                                        className={qrPrimary}
                                        onClick={() => go('track')}
                                    >
                                        View current order
                                    </button>
                                    {terminal && (
                                        <button
                                            className={qrButton}
                                            disabled={busy}
                                            onClick={begin}
                                        >
                                            Start new order
                                        </button>
                                    )}
                                    {order.payment_status === 'paid' && (
                                        <button
                                            className={qrButton}
                                            onClick={() => go('receipt')}
                                        >
                                            View receipt
                                        </button>
                                    )}
                                    <button
                                        className={qrButton}
                                        onClick={() => go('menu')}
                                    >
                                        Browse menu
                                    </button>
                                    <p className="text-xs text-neutral-500">
                                        Read-only ang menu habang may active
                                        order.
                                    </p>
                                </div>
                            )}
                        </main>
                    )}
                    {view === 'menu' && (
                        <main className="mx-auto flex max-w-[1120px] flex-col gap-3 px-3 pt-3 pb-28 min-[900px]:px-6 min-[900px]:pt-5">
                            <input
                                className="h-11 rounded-xl border border-neutral-200 bg-white px-3 text-base"
                                type="search"
                                placeholder="Search menu"
                                aria-label="Search menu"
                                value={query}
                                onChange={(event) =>
                                    setQuery(event.target.value)
                                }
                            />
                            <div className="flex gap-2 overflow-x-auto pb-1">
                                {[
                                    { id: 'all', name: 'All menu' },
                                    ...catalog.categories,
                                ].map((cat) => (
                                    <button
                                        key={cat.id}
                                        className={`${qrButton} shrink-0 rounded-full ${category === cat.id ? 'border-neutral-950 bg-neutral-950 text-white' : ''}`}
                                        onClick={() => setCategory(cat.id)}
                                    >
                                        {cat.name}
                                    </button>
                                ))}
                            </div>
                            {order && (
                                <div className="rounded-xl border border-amber-200 bg-amber-50 p-3 text-xs">
                                    <strong>
                                        {terminal
                                            ? 'Tapos na ang huling order mo'
                                            : 'May current order ka pa'}
                                    </strong>
                                    <p className="mt-1">
                                        {terminal
                                            ? 'Tap Start new order sa current order screen kung gusto mong mag-order muli.'
                                            : 'Browse-only ang menu habang may active order. May additional order? Please approach the cashier.'}
                                    </p>
                                </div>
                            )}
                            {category === 'all' &&
                                !query &&
                                favorites.length > 0 && (
                                    <>
                                        <div className="mt-2 flex justify-between text-xs">
                                            <h2 className="text-sm font-bold">
                                                Pongskilog favorites
                                            </h2>
                                            <span className="text-neutral-500">
                                                Paborito ng customers
                                            </span>
                                        </div>
                                        <div className="grid grid-cols-2 gap-2 min-[560px]:grid-cols-3 min-[900px]:grid-cols-4">
                                            {favorites.map((product) => (
                                                <button
                                                    key={product.id}
                                                    className="overflow-hidden rounded-[14px] border border-neutral-200 bg-white text-left disabled:opacity-50"
                                                    disabled={
                                                        !product.is_available ||
                                                        !!intent
                                                    }
                                                    onClick={() =>
                                                        openProduct(product)
                                                    }
                                                >
                                                    <QrImage
                                                        product={product}
                                                        className="aspect-[4/3] w-full"
                                                    />
                                                    <div className="p-2.5">
                                                        <strong className="block text-[13px]">
                                                            {product.name}
                                                        </strong>
                                                        <span className="text-xs font-semibold text-red-700">
                                                            {pesos(
                                                                product.effective_price,
                                                            )}
                                                        </span>
                                                        {!product.is_available && (
                                                            <p className="text-[11px]">
                                                                {product.stock_status ===
                                                                'out_of_stock'
                                                                    ? 'Out of stock'
                                                                    : 'Unavailable'}
                                                            </p>
                                                        )}
                                                    </div>
                                                </button>
                                            ))}
                                        </div>
                                    </>
                                )}
                            <div className="mt-2 flex justify-between">
                                <h2 className="text-sm font-bold">
                                    {query ? 'Search results' : 'All menu'}
                                </h2>
                                <span className="text-xs text-neutral-500">
                                    {filtered.length} items
                                </span>
                            </div>
                            <div className="grid gap-2 min-[760px]:grid-cols-2">
                                {filtered.map((product) => (
                                    <button
                                        key={product.id}
                                        className={`${qrPanel} flex items-center gap-2.5 p-[9px] text-left disabled:opacity-50`}
                                        disabled={
                                            !product.is_available || !!intent
                                        }
                                        onClick={() => openProduct(product)}
                                    >
                                        <QrImage
                                            product={product}
                                            className="size-[74px] rounded-lg"
                                        />
                                        <div className="min-w-0 flex-1">
                                            <strong className="text-[13px]">
                                                {product.name}
                                            </strong>
                                            <p className="line-clamp-2 text-[11px] text-neutral-500">
                                                {product.description}
                                            </p>
                                            <span className="text-xs font-bold text-red-700">
                                                {pesos(product.effective_price)}
                                            </span>
                                            {!product.is_available && (
                                                <span className="ml-2 text-[10px]">
                                                    {product.stock_status ===
                                                    'out_of_stock'
                                                        ? 'Out of stock'
                                                        : 'Unavailable'}
                                                </span>
                                            )}
                                        </div>
                                        <ChevronRight size={16} />
                                    </button>
                                ))}
                            </div>
                            {!filtered.length && (
                                <div className="py-12 text-center">
                                    <h2 className="font-bold">
                                        {query
                                            ? `No menu items match “${query}”`
                                            : 'Nothing in this category yet'}
                                    </h2>
                                    <p className="mt-2 text-xs text-neutral-500">
                                        Try a shorter word, or browse the
                                        categories above.
                                    </p>
                                    <button
                                        className={`${qrButton} mt-4`}
                                        onClick={() => {
                                            setQuery('');
                                            setCategory('all');
                                        }}
                                    >
                                        Show the full menu
                                    </button>
                                </div>
                            )}
                        </main>
                    )}
                    {(view === 'cart' || view === 'review') && (
                        <main className="mx-auto flex max-w-[720px] flex-col gap-3 px-3 py-4 pb-28">
                            <div className="flex items-center gap-3">
                                <button
                                    className={qrButton}
                                    onClick={() =>
                                        go(view === 'cart' ? 'menu' : 'cart')
                                    }
                                >
                                    <ArrowLeft size={16} />
                                    {view === 'cart' ? 'Menu' : 'Cart'}
                                </button>
                                <h1 className="text-lg font-bold">
                                    {view === 'cart'
                                        ? 'Your cart'
                                        : 'Review your order'}
                                </h1>
                            </div>
                            {intent && (
                                <p className="rounded-xl bg-amber-50 p-3 text-xs">
                                    The previous result is unconfirmed. Retry
                                    Submit order to recover it before changing
                                    your selections.
                                </p>
                            )}
                            {unavailable && (
                                <p
                                    role="alert"
                                    className="rounded-xl bg-red-50 p-3 text-xs text-red-800"
                                >
                                    One or more items are no longer available.
                                    Please edit or remove them to continue.
                                </p>
                            )}
                            {priceChanged && !intent && (
                                <div className={`${qrPanel} text-xs`}>
                                    <strong>
                                        Some prices or options changed
                                    </strong>
                                    <p className="my-2">
                                        Review the current menu details before
                                        submitting.
                                    </p>
                                    <button
                                        className={qrButton}
                                        onClick={() =>
                                            setCart((current) =>
                                                current.map((line) => ({
                                                    ...line,
                                                    product:
                                                        catalog.products.find(
                                                            (product) =>
                                                                product.id ===
                                                                line.product.id,
                                                        ) ?? line.product,
                                                })),
                                            )
                                        }
                                    >
                                        Accept new prices
                                    </button>
                                </div>
                            )}
                            {!cart.length ? (
                                <div className="py-12 text-center">
                                    <ShoppingBag className="mx-auto mb-4" />
                                    <h2 className="font-bold">
                                        Your cart is empty.
                                    </h2>
                                    <p className="my-3 text-xs text-neutral-500">
                                        Choose something from the menu to start
                                        your order.
                                    </p>
                                    <button
                                        className={qrPrimary}
                                        onClick={() => go('menu')}
                                    >
                                        Browse menu
                                    </button>
                                </div>
                            ) : (
                                <>
                                    {view === 'cart' ? (
                                        cart.map((line, i) => (
                                            <div
                                                className={qrPanel}
                                                key={line.key}
                                            >
                                                <div className="flex gap-3">
                                                    <QrImage
                                                        product={line.product}
                                                        className="size-16 rounded-lg"
                                                    />
                                                    <div className="min-w-0 flex-1">
                                                        <div className="flex justify-between gap-2 text-sm">
                                                            <strong>
                                                                {
                                                                    cartItems[i]
                                                                        .display_name
                                                                }
                                                            </strong>
                                                            <strong className="text-red-700">
                                                                {pesos(
                                                                    qrLineCents(
                                                                        line,
                                                                    ),
                                                                )}
                                                            </strong>
                                                        </div>
                                                        {cartItems[i].modifiers
                                                            .filter(
                                                                (mod) =>
                                                                    mod.semantic_role !==
                                                                    'size',
                                                            )
                                                            .map((mod, j) => (
                                                                <p
                                                                    key={j}
                                                                    className="text-[11px] text-neutral-500"
                                                                >
                                                                    {mod.semantic_role ===
                                                                    'instruction'
                                                                        ? 'Instructions: '
                                                                        : ''}
                                                                    {mod.name}
                                                                </p>
                                                            ))}
                                                        {line.notes && (
                                                            <p className="mt-1 rounded bg-orange-50 p-1 text-[11px] text-orange-900">
                                                                Note:{' '}
                                                                {line.notes}
                                                            </p>
                                                        )}
                                                    </div>
                                                </div>
                                                <div className="mt-3 flex items-center gap-2">
                                                    <button
                                                        disabled={!!intent}
                                                        className={qrButton}
                                                        aria-label="Decrease quantity"
                                                        onClick={() => {
                                                            if (
                                                                line.quantity <=
                                                                1
                                                            ) {
                                                                setRemoveKey(
                                                                    line.key,
                                                                );
                                                                setModal(
                                                                    'remove',
                                                                );
                                                            } else
                                                                setCart(
                                                                    (current) =>
                                                                        current.map(
                                                                            (
                                                                                row,
                                                                            ) =>
                                                                                row.key ===
                                                                                line.key
                                                                                    ? {
                                                                                          ...row,
                                                                                          quantity:
                                                                                              row.quantity -
                                                                                              1,
                                                                                      }
                                                                                    : row,
                                                                        ),
                                                                );
                                                        }}
                                                    >
                                                        <Minus size={16} />
                                                    </button>
                                                    <span className="text-sm">
                                                        {line.quantity}
                                                    </span>
                                                    <button
                                                        disabled={
                                                            !!intent ||
                                                            line.quantity >= 999
                                                        }
                                                        className={qrButton}
                                                        aria-label="Increase quantity"
                                                        onClick={() =>
                                                            setCart((current) =>
                                                                current.map(
                                                                    (row) =>
                                                                        row.key ===
                                                                        line.key
                                                                            ? {
                                                                                  ...row,
                                                                                  quantity:
                                                                                      row.quantity +
                                                                                      1,
                                                                              }
                                                                            : row,
                                                                ),
                                                            )
                                                        }
                                                    >
                                                        <Plus size={16} />
                                                    </button>
                                                    <button
                                                        disabled={!!intent}
                                                        className={`${qrButton} ml-auto`}
                                                        onClick={() =>
                                                            openProduct(
                                                                catalog.products.find(
                                                                    (product) =>
                                                                        product.id ===
                                                                        line
                                                                            .product
                                                                            .id,
                                                                ) ?? {
                                                                    ...line.product,
                                                                    is_available: false,
                                                                },
                                                                line,
                                                            )
                                                        }
                                                    >
                                                        Edit
                                                    </button>
                                                    <button
                                                        disabled={!!intent}
                                                        className={`${qrButton} text-red-700`}
                                                        aria-label="Remove item"
                                                        onClick={() => {
                                                            setRemoveKey(
                                                                line.key,
                                                            );
                                                            setModal('remove');
                                                        }}
                                                    >
                                                        <Trash2 size={16} />
                                                    </button>
                                                </div>
                                            </div>
                                        ))
                                    ) : (
                                        <QrItems
                                            order={{
                                                items: cartItems,
                                                total: `${total / 100n}.${String(total % 100n).padStart(2, '0')}`,
                                            }}
                                        />
                                    )}
                                    {view === 'cart' ? (
                                        <div
                                            className={`${qrPanel} flex flex-col gap-3`}
                                        >
                                            <div className="flex justify-between font-bold">
                                                <span>
                                                    Total · {count} items
                                                </span>
                                                <span className="text-red-700">
                                                    {pesos(total)}
                                                </span>
                                            </div>
                                            <p className="text-[11px] text-neutral-500">
                                                Sa counter ang bayad. Ang
                                                pag-submit ay para lang sa order
                                                number mo.
                                            </p>
                                            <button
                                                disabled={
                                                    unavailable || priceChanged
                                                }
                                                className={qrPrimary}
                                                onClick={() => go('review')}
                                            >
                                                View order{' '}
                                                <ChevronRight size={16} />
                                            </button>
                                            <button
                                                className={qrButton}
                                                onClick={() => go('menu')}
                                            >
                                                <Plus size={16} />
                                                Add more items
                                            </button>
                                        </div>
                                    ) : (
                                        <>
                                            <div
                                                className={`${qrPanel} flex flex-col gap-3`}
                                            >
                                                <strong className="text-sm">
                                                    Order type{' '}
                                                    <span className="text-red-700">
                                                        *
                                                    </span>
                                                </strong>
                                                <p className="text-xs text-neutral-500">
                                                    Kailangan itong piliin bago
                                                    mag-submit. Dine In o Take
                                                    Out.
                                                </p>
                                                <div className="grid grid-cols-2 gap-2">
                                                    {(
                                                        [
                                                            'dine_in',
                                                            'take_out',
                                                        ] as const
                                                    ).map((type) => (
                                                        <button
                                                            key={type}
                                                            disabled={
                                                                !!intent || busy
                                                            }
                                                            className={
                                                                orderType ===
                                                                type
                                                                    ? qrPrimary
                                                                    : qrButton
                                                            }
                                                            onClick={() =>
                                                                setOrderType(
                                                                    type,
                                                                )
                                                            }
                                                        >
                                                            {type === 'dine_in'
                                                                ? 'Dine in'
                                                                : 'Take out'}
                                                        </button>
                                                    ))}
                                                </div>
                                                <label className="mt-2 flex flex-col gap-2 text-xs font-semibold">
                                                    Name for this order ·
                                                    optional
                                                    <input
                                                        className="h-11 rounded-xl border border-neutral-200 px-3 text-base font-normal"
                                                        placeholder="e.g. Renz"
                                                        maxLength={24}
                                                        value={name}
                                                        disabled={
                                                            !!intent || busy
                                                        }
                                                        onChange={(event) =>
                                                            setName(
                                                                event.target
                                                                    .value,
                                                            )
                                                        }
                                                    />
                                                </label>
                                            </div>
                                            <p className="rounded-xl bg-neutral-100 p-3 text-[11px] leading-5">
                                                Pagka-submit, may makukuha kang
                                                order number. Pakita ito sa
                                                cashier para ma-process ang
                                                payment.
                                            </p>
                                            <button
                                                className={qrPrimary}
                                                disabled={
                                                    busy ||
                                                    !online ||
                                                    !orderType ||
                                                    (unavailable && !intent) ||
                                                    (priceChanged && !intent)
                                                }
                                                onClick={submit}
                                            >
                                                {busy
                                                    ? 'Submitting…'
                                                    : 'Submit order'}
                                            </button>
                                            <button
                                                className={qrButton}
                                                onClick={() => go('cart')}
                                            >
                                                Back to cart
                                            </button>
                                        </>
                                    )}
                                </>
                            )}
                        </main>
                    )}
                    {view === 'success' && order && (
                        <main className="mx-auto flex max-w-[520px] flex-col gap-4 px-4 py-10 text-center">
                            <Check className="mx-auto size-14 rounded-full bg-green-50 p-3 text-green-700" />
                            <p className="text-sm text-neutral-500">
                                Your order number
                            </p>
                            <h1 className="text-5xl font-extrabold">
                                #{order.order_number}
                            </h1>
                            <p className="mx-auto rounded-full border border-amber-200 bg-amber-50 px-4 py-2 text-sm font-semibold text-amber-800">
                                {qrStatus(order)}
                            </p>
                            <p className="text-sm">
                                Pakita ang order number na ito sa cashier para
                                ma-process ang payment.
                            </p>
                            <div
                                className={`${qrPanel} flex justify-between text-sm`}
                            >
                                <span>Amount due</span>
                                <strong>{pesos(order.total)}</strong>
                            </div>
                            <button
                                className={qrPrimary}
                                onClick={() => go('track')}
                            >
                                <Clock3 size={18} />
                                Track order
                            </button>
                            <button
                                className={qrButton}
                                onClick={() => setModal('details')}
                            >
                                View order
                            </button>
                            <p className="text-[11px] text-neutral-500">
                                Waiting for Cashier / Payment. Hintayin lang ang
                                live update ng order mo.
                            </p>
                        </main>
                    )}
                    {(view === 'track' || view === 'receipt') && (
                        <CustomerQrTracking
                            branch={branch}
                            order={order}
                            view={view}
                            go={go}
                            begin={begin}
                            busy={busy}
                            details={() => setModal('details')}
                        />
                    )}
                </>
            )}
            {!closed && view === 'menu' && (order || count > 0) && (
                <div className="fixed right-0 bottom-0 left-0 border-t border-neutral-200 bg-white p-3 pb-[max(12px,env(safe-area-inset-bottom))]">
                    <button
                        className={`${qrPrimary} mx-auto flex h-14 w-full max-w-[720px] justify-between`}
                        onClick={() => go(order ? 'track' : 'cart')}
                    >
                        <span>
                            {order
                                ? `${terminal ? 'Last order' : 'Current order'} #${order.order_number} · ${qrStatus(order)}`
                                : `${count} items in cart`}
                        </span>
                        <span>
                            {order ? <ChevronRight size={18} /> : pesos(total)}
                        </span>
                    </button>
                </div>
            )}
            {editing && (
                <CustomerQrProduct
                    key={editing.line?.key ?? editing.product.id}
                    product={
                        catalog.products.find(
                            (product) => product.id === editing.product.id,
                        ) ?? { ...editing.product, is_available: false }
                    }
                    initial={editing.line}
                    locked={!!order}
                    onClose={() => setEditing(null)}
                    onTrack={() => go('track')}
                    onSave={(line) => {
                        setCart((current) =>
                            editing.line
                                ? current.map((row) =>
                                      row.key === line.key ? line : row,
                                  )
                                : mergeQrLine(current, line),
                        );
                        setEditing(null);
                        toast.success(
                            `${editing.line ? 'Updated' : 'Added'} ${line.product.name}`,
                        );
                    }}
                />
            )}
            {modal && (
                <Dialog
                    open
                    onOpenChange={(open) => {
                        if (!open) setModal(null);
                    }}
                >
                    <DialogContent className="pos-surface flex max-h-[90dvh] flex-col gap-4 overflow-y-auto rounded-2xl bg-white text-neutral-950 max-sm:top-auto max-sm:bottom-0 max-sm:max-w-full max-sm:translate-y-0 max-sm:rounded-b-none">
                        <DialogTitle>
                            {modal === 'details'
                                ? `#${order?.order_number}`
                                : modal === 'remove'
                                  ? 'Remove this item?'
                                  : 'Before you order'}
                        </DialogTitle>
                        <DialogDescription>
                            {modal === 'details'
                                ? 'Hindi na ma-edit ang submitted order dito. Lumapit sa cashier kung may kailangang baguhin.'
                                : modal === 'remove'
                                  ? 'Remove this item from your cart?'
                                  : 'Terms & Conditions and Privacy Notice'}
                        </DialogDescription>
                        {modal === 'details' && order && (
                            <QrItems order={order} />
                        )}
                        {modal === 'remove' && (
                            <>
                                <button
                                    className={`${qrPrimary} bg-red-700`}
                                    onClick={() => {
                                        setCart((current) =>
                                            current.filter(
                                                (line) =>
                                                    line.key !== removeKey,
                                            ),
                                        );
                                        setModal(null);
                                    }}
                                >
                                    Remove item
                                </button>
                                <button
                                    className={qrButton}
                                    onClick={() => setModal(null)}
                                >
                                    Keep it
                                </button>
                            </>
                        )}
                        {(modal === 'terms' || modal === 'privacy') && (
                            <>
                                <div className="flex gap-2">
                                    <button
                                        className={
                                            modal === 'terms'
                                                ? qrPrimary
                                                : qrButton
                                        }
                                        onClick={() => setModal('terms')}
                                    >
                                        Terms
                                    </button>
                                    <button
                                        className={
                                            modal === 'privacy'
                                                ? qrPrimary
                                                : qrButton
                                        }
                                        onClick={() => setModal('privacy')}
                                    >
                                        Privacy
                                    </button>
                                </div>
                                <ul className="flex list-disc flex-col gap-3 pl-5 text-[12px] leading-5">
                                    {(modal === 'terms' ? terms : privacy).map(
                                        (point) => (
                                            <li key={point}>{point}</li>
                                        ),
                                    )}
                                </ul>
                                <button
                                    className={qrPrimary}
                                    onClick={() => setModal(null)}
                                >
                                    Naiintindihan ko
                                </button>
                            </>
                        )}
                    </DialogContent>
                </Dialog>
            )}
        </div>
    );
}
