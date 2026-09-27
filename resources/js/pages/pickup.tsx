import { Head } from '@inertiajs/react';
import {
    BellRing,
    CheckCircle2,
    ChefHat,
    CircleSlash,
    Facebook,
    Globe,
    LoaderCircle,
    MapPin,
    Printer,
    ReceiptText,
    ShoppingBag,
    WifiOff,
} from 'lucide-react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { flushSync } from 'react-dom';
import { DigitalReceiptCard } from '@/components/digital-receipt-card';
import {
    isPickupBuzzMessage,
    PICKUP_WORKER_SCOPE,
    PICKUP_WORKER_URL,
    pickupBuzzCue,
    pickupLinkButtons,
    pickupNotifyMessage,
    pickupNotifyState,
    pickupQueueView,
    pickupStatusView,
    type PickupLinks,
    type PickupStatusData,
} from '@/lib/pickup';
import { pesos } from '@/lib/pos-money';
import { connectionNotice } from '@/lib/customer-screen';
import { createPublicEcho } from '@/lib/public-echo';
import { detectInstallPlatform } from '@/lib/pwa-install';
import {
    pushSupport,
    subscriptionBody,
    urlBase64ToUint8Array,
    type PushSupport,
} from '@/lib/pwa-push';
import { qrError, qrRequest } from '@/lib/qr-http';
import { createRealtimeRefresh } from '@/lib/realtime-refresh';
import { auth as authorizeChannel } from '@/routes/pickup/broadcasting';
import {
    receipt as receiptRoute,
    status as statusRoute,
} from '@/routes/pickup';
import {
    destroy as unsubscribeRoute,
    store as subscribeRoute,
} from '@/routes/pickup/subscription';
import type { PublicReceipt } from '@/types/qr';

type Props = {
    token: string | null;
    pickup: PickupStatusData | null;
    problem: 'invalid' | 'expired' | null;
};

/**
 * The public Takeout pickup page (Phase 19.6): order number, Take Out, Preparing / Ready / Done, the Take Out queue
 * position (emphasized) and the overall queue position, live through this order's own channel; the order summary,
 * the receipt (view / print, while the 12-hour link is valid) and the Branch's configured links. The only thing a
 * customer can change is this order's Ready notification; nothing here can change, cancel or pay for the order.
 */
export default function Pickup({ token, pickup: initial, problem }: Props) {
    if (token === null || initial === null || problem !== null) {
        return <PickupProblem expired={problem === 'expired'} />;
    }

    return <PickupStatus token={token} initial={initial} />;
}

function PickupStatus({
    token,
    initial,
}: {
    token: string;
    initial: PickupStatusData;
}) {
    const [pickup, setPickup] = useState(initial);
    const [connection, setConnection] = useState('connecting');
    const view = pickupStatusView(pickup.status);
    const queue = pickupQueueView(pickup);
    const previousStatus = useRef(initial.status);

    const refresh = useMemo(
        () =>
            createRealtimeRefresh((finish) => {
                qrRequest<{ pickup: PickupStatusData }>(statusRoute(token))
                    .then((result) => setPickup(result.pickup))
                    .catch(() => undefined)
                    .finally(finish);
            }, 120),
        [token],
    );

    /** One public connection on this order's own channel; every event and every reconnect refetches the status. */
    useEffect(() => {
        refresh.activate();
        const live = createPublicEcho(authorizeChannel.url(token));
        const wake = () => {
            if (document.visibilityState === 'visible') refresh.schedule(0);
        };
        const online = () => refresh.schedule(0);
        window.addEventListener('online', online);
        document.addEventListener('visibilitychange', wake);
        if (live === null) {
            setConnection('unavailable');
        } else {
            let hasConnected = false;
            live.client.connection.bind(
                'state_change',
                ({ current }: { current: string }) => {
                    setConnection(current);
                    if (current === 'connected') {
                        if (hasConnected) refresh.schedule(0);
                        hasConnected = true;
                    }
                },
            );
            live.echo
                .private(initial.channel)
                .listen('.pickup.changed', () => refresh.schedule());
        }

        return () => {
            refresh.dispose();
            live?.echo.disconnect();
            window.removeEventListener('online', online);
            document.removeEventListener('visibilitychange', wake);
        };
    }, [refresh, token, initial.channel]);

    /** Becoming Ready while the page is open: vibrate once where the device supports it. */
    useEffect(() => {
        if (previousStatus.current !== 'ready' && pickup.status === 'ready') {
            navigator.vibrate?.([300, 120, 300]);
        }
        previousStatus.current = pickup.status;
    }, [pickup.status]);

    usePickupBuzzCue(pickup.buzz_sound_url);

    const notice = connectionNotice(connection);

    return (
        <>
            <Head title={`Order #${pickup.order_number}`} />
            <main className="flex min-h-dvh flex-col items-center bg-[#0f1010] px-4 pt-[max(24px,env(safe-area-inset-top))] pb-[max(24px,env(safe-area-inset-bottom))] text-white print:min-h-0 print:bg-white print:p-0 print:text-black">
                <div className="flex w-full max-w-md flex-col gap-4">
                    <header className="flex items-center justify-center gap-3 py-2 print:hidden">
                        <img
                            src="/images/branding/pongskilog-emblem.png"
                            alt=""
                            className="size-10 rounded-full"
                        />
                        <span className="text-lg font-black tracking-[0.16em]">
                            PONGSKILOG
                        </span>
                    </header>

                    <section
                        aria-live="polite"
                        className={`flex flex-col items-center gap-3 rounded-3xl border px-6 py-8 text-center print:hidden ${view.tone === 'green' ? 'border-emerald-400/40 bg-emerald-950/60' : view.tone === 'amber' ? 'border-amber-400/25 bg-white/5' : 'border-white/10 bg-white/5'}`}
                    >
                        <p className="flex items-center gap-2 rounded-full bg-white/10 px-3 py-1 text-[11px] font-black tracking-[0.18em]">
                            <ShoppingBag className="size-3.5" /> TAKE OUT
                        </p>
                        <p className="text-sm font-semibold tracking-[0.2em] text-white/55 uppercase">
                            Order
                        </p>
                        <p
                            className={`text-7xl leading-none font-black tabular-nums ${view.tone === 'green' ? 'text-emerald-400' : 'text-white'}`}
                        >
                            #{pickup.order_number}
                        </p>
                        <StatusBadge status={pickup.status} />
                        <h1 className="text-2xl font-black">{view.title}</h1>
                        <p className="text-sm text-white/65">{view.message}</p>
                        {queue && (
                            <div className="flex w-full flex-col items-center gap-2">
                                {queue.takeOut !== null && (
                                    <div className="flex flex-col items-center rounded-2xl border-2 border-emerald-400 bg-emerald-400/15 px-7 py-2.5 shadow-[0_0_28px_rgba(52,211,153,0.2)]">
                                        <span className="text-[11px] font-black tracking-[0.2em] text-emerald-200">
                                            TAKE OUT QUEUE
                                        </span>
                                        <span className="text-5xl leading-tight font-black text-emerald-300 tabular-nums">
                                            #{queue.takeOut}
                                        </span>
                                    </div>
                                )}
                                {queue.overall && (
                                    <p className="text-sm font-semibold text-white/70">
                                        {queue.overall}
                                    </p>
                                )}
                            </div>
                        )}
                    </section>

                    <PickupNotifications
                        token={token}
                        pickup={pickup}
                        onChanged={() => refresh.schedule(0)}
                    />

                    <PickupOrderSummary summary={pickup.summary} />
                    <PickupReceipt
                        token={token}
                        available={pickup.receipt_available}
                    />
                    <PickupLinkButtons links={pickup.links} />

                    {notice && (
                        <p
                            role="status"
                            className="flex items-center justify-center gap-2 text-xs font-semibold text-amber-300 print:hidden"
                        >
                            <WifiOff className="size-3.5" /> {notice}
                        </p>
                    )}
                    <p className="text-center text-[11px] leading-5 text-white/40 print:hidden">
                        This page shows your order’s pickup status and receipt
                        for 12 hours. Keep it open or come back any time.
                    </p>
                </div>
            </main>
        </>
    );
}

const BUZZ_VIBRATION = [300, 120, 300, 120, 300];

/**
 * The open page's Buzz cue. The Buzz itself is the push notification (OS sound and vibration, also on a locked phone);
 * the pickup worker additionally tells this page, and while it is in the foreground it vibrates and plays the
 * optional Branch sound. Browsers only allow sound after a tap, so the first tap on the page unlocks it; a blocked,
 * failed or absent sound is silent and never breaks the page.
 */
function usePickupBuzzCue(soundUrl: string | null) {
    const audio = useRef<HTMLAudioElement | null>(null);

    useEffect(() => {
        if (soundUrl === null || typeof Audio === 'undefined') return;
        let element: HTMLAudioElement;
        try {
            element = new Audio(soundUrl);
            element.preload = 'auto';
        } catch {
            return;
        }
        audio.current = element;
        const unlock = () => {
            element.muted = true;
            element
                .play()
                .then(() => {
                    element.pause();
                    element.currentTime = 0;
                })
                .catch(() => undefined)
                .finally(() => {
                    element.muted = false;
                });
        };
        window.addEventListener('pointerdown', unlock, { once: true });

        return () => {
            window.removeEventListener('pointerdown', unlock);
            element.pause();
            audio.current = null;
        };
    }, [soundUrl]);

    useEffect(() => {
        const container =
            typeof navigator === 'undefined'
                ? undefined
                : navigator.serviceWorker;
        if (!container) return;
        const onMessage = (event: MessageEvent) => {
            if (!isPickupBuzzMessage(event.data)) return;
            const element = audio.current;
            const cue = pickupBuzzCue({
                visible: document.visibilityState === 'visible',
                soundUrl: element === null ? null : soundUrl,
            });
            if (cue.vibrate) navigator.vibrate?.(BUZZ_VIBRATION);
            if (cue.sound !== null && element !== null) {
                element.currentTime = 0;
                void element.play().catch(() => undefined);
            }
        };
        container.addEventListener('message', onMessage);
        container.startMessages();

        return () => container.removeEventListener('message', onMessage);
    }, [soundUrl]);
}

/** The customer-safe order summary from the order's own snapshots: names, Size / add-ons, instructions, amounts. */
function PickupOrderSummary({
    summary,
}: {
    summary: PickupStatusData['summary'];
}) {
    if (summary.items.length === 0) return null;

    return (
        <section
            aria-label="Your order"
            className="flex flex-col gap-3 rounded-3xl border border-white/10 bg-white/5 p-5 print:hidden"
        >
            <h2 className="text-sm font-black">Your order</h2>
            <ul className="divide-y divide-white/10">
                {summary.items.map((item, index) => (
                    <li
                        key={index}
                        className="grid grid-cols-[2rem_minmax(0,1fr)_auto] gap-2 py-2.5 text-sm"
                    >
                        <span className="font-black tabular-nums">
                            {item.quantity}×
                        </span>
                        <div className="min-w-0">
                            <p className="font-bold break-words">{item.name}</p>
                            {item.details.length > 0 && (
                                <p className="mt-0.5 text-xs text-white/55">
                                    {item.details
                                        .map((detail) => `+ ${detail}`)
                                        .join(' · ')}
                                </p>
                            )}
                            {item.instructions.length > 0 && (
                                <p className="mt-0.5 text-xs text-[#f5c542]/85">
                                    {item.instructions.join(' · ')}
                                </p>
                            )}
                        </div>
                        <span className="font-bold tabular-nums">
                            {pesos(item.amount)}
                        </span>
                    </li>
                ))}
            </ul>
            <div className="flex items-center justify-between border-t border-white/10 pt-3 text-base font-black">
                <span>Total</span>
                <span className="text-[#f5c542] tabular-nums">
                    {pesos(summary.total)}
                </span>
            </div>
        </section>
    );
}

/**
 * View / print the order's receipt through this pickup link: the canonical customer receipt card, fetched on demand
 * (and again for each view or print, so it is never stale). Printing shows only the receipt.
 */
function PickupReceipt({
    token,
    available,
}: {
    token: string;
    available: boolean;
}) {
    const [receipt, setReceipt] = useState<PublicReceipt | null>(null);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');

    if (!available) {
        return (
            <section className="flex items-start gap-3 rounded-3xl border border-white/10 bg-white/5 p-5 text-xs leading-5 text-white/60 print:hidden">
                <ReceiptText className="mt-0.5 size-5 shrink-0 text-white/40" />
                Your receipt will be available here once the order is paid.
            </section>
        );
    }

    const open = async (print: boolean) => {
        if (busy) return;
        setBusy(true);
        setError('');
        try {
            const fresh = await qrRequest<{ receipt: PublicReceipt }>(
                receiptRoute(token),
            );
            flushSync(() => setReceipt(fresh.receipt));
            if (print) window.print();
        } catch (reason) {
            const failure = qrError(reason);
            setError(
                failure.status === 410
                    ? 'This pickup link has expired, so its receipt is no longer available.'
                    : failure.message,
            );
        } finally {
            setBusy(false);
        }
    };

    return (
        <section className="flex flex-col gap-3 rounded-3xl border border-white/10 bg-white/5 p-5 print:border-0 print:bg-white print:p-0">
            <div className="flex flex-wrap items-center gap-2 print:hidden">
                <ReceiptText className="size-5 text-[#f5c542]" />
                <h2 className="mr-auto text-sm font-black">Receipt</h2>
                {receipt === null ? (
                    <button
                        type="button"
                        onClick={() => void open(false)}
                        disabled={busy}
                        className="inline-flex min-h-11 items-center justify-center gap-2 rounded-2xl border border-white/15 px-4 text-xs font-bold text-white/85 disabled:opacity-60"
                    >
                        {busy ? (
                            <LoaderCircle className="size-4 animate-spin" />
                        ) : (
                            <ReceiptText className="size-4" />
                        )}
                        View receipt
                    </button>
                ) : (
                    <button
                        type="button"
                        onClick={() => setReceipt(null)}
                        className="inline-flex min-h-11 items-center justify-center rounded-2xl border border-white/15 px-4 text-xs font-bold text-white/75"
                    >
                        Hide
                    </button>
                )}
                <button
                    type="button"
                    onClick={() => void open(true)}
                    disabled={busy}
                    className="inline-flex min-h-11 items-center justify-center gap-2 rounded-2xl bg-white px-4 text-xs font-black text-neutral-950 disabled:opacity-60"
                >
                    <Printer className="size-4" /> Print receipt
                </button>
            </div>
            {error && (
                <p role="alert" className="text-xs text-red-300 print:hidden">
                    {error}
                </p>
            )}
            {receipt && (
                <div data-pickup-receipt className="text-neutral-950">
                    <DigitalReceiptCard receipt={receipt} />
                </div>
            )}
        </section>
    );
}

const LINK_ICONS = {
    facebook: Facebook,
    website: Globe,
    maps: MapPin,
} as const;

/** The Branch's configured customer links only (none configured → nothing rendered). */
function PickupLinkButtons({ links }: { links: PickupLinks }) {
    const buttons = pickupLinkButtons(links);
    if (buttons.length === 0) return null;

    return (
        <nav
            aria-label="Find us"
            className="grid gap-2 print:hidden"
            style={{
                gridTemplateColumns: `repeat(${buttons.length}, minmax(0, 1fr))`,
            }}
        >
            {buttons.map(({ key, label, url }) => {
                const Icon = LINK_ICONS[key];

                return (
                    <a
                        key={key}
                        href={url}
                        target="_blank"
                        rel="noopener noreferrer"
                        className="inline-flex min-h-12 min-w-0 items-center justify-center gap-2 rounded-2xl border border-white/15 bg-white/5 px-3 text-sm font-bold text-white/85 hover:bg-white/10"
                    >
                        <Icon className="size-4 shrink-0" />
                        <span className="truncate">{label}</span>
                    </a>
                );
            })}
        </nav>
    );
}

function StatusBadge({ status }: { status: PickupStatusData['status'] }) {
    const steps: { key: 'preparing' | 'ready' | 'done'; label: string }[] = [
        { key: 'preparing', label: 'Preparing' },
        { key: 'ready', label: 'Ready' },
        { key: 'done', label: 'Done' },
    ];
    if (status === 'unavailable') {
        return <CircleSlash className="size-10 text-white/40" />;
    }
    const position = steps.findIndex((step) => step.key === status);

    return (
        <ol className="flex items-center gap-2" aria-label="Order progress">
            {steps.map((step, index) => (
                <li
                    key={step.key}
                    aria-current={index === position ? 'step' : undefined}
                    className={`flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-bold ${index === position ? (step.key === 'ready' ? 'bg-emerald-400 text-neutral-950' : 'bg-white text-neutral-950') : index < position ? 'bg-white/15 text-white/70' : 'bg-white/5 text-white/40'}`}
                >
                    {step.key === 'preparing' && index === position ? (
                        <ChefHat className="size-3.5" />
                    ) : step.key !== 'preparing' && index <= position ? (
                        <CheckCircle2 className="size-3.5" />
                    ) : null}
                    {step.label}
                </li>
            ))}
        </ol>
    );
}

function environmentSupport(): PushSupport {
    if (typeof window === 'undefined') return 'unsupported';
    const standalone =
        window.matchMedia?.('(display-mode: standalone)').matches ||
        (navigator as Navigator & { standalone?: boolean }).standalone === true;

    return pushSupport({
        secureContext: window.isSecureContext,
        serviceWorker: 'serviceWorker' in navigator,
        pushManager: 'PushManager' in window,
        notification: 'Notification' in window,
        platform: detectInstallPlatform(
            navigator.userAgent,
            navigator.maxTouchPoints ?? 0,
        ),
        standalone,
    });
}

/**
 * The explicit opt-in. Only this button asks for permission, registers the separate `/pickup/` worker and sends the
 * browser's subscription for this order; "Turn off" removes this order's subscription on the server.
 */
function PickupNotifications({
    token,
    pickup,
    onChanged,
}: {
    token: string;
    pickup: PickupStatusData;
    onChanged: () => void;
}) {
    const [support] = useState<PushSupport>(environmentSupport);
    const [permission, setPermission] = useState<NotificationPermission | null>(
        () =>
            typeof Notification === 'undefined'
                ? null
                : Notification.permission,
    );
    const [browserSubscribed, setBrowserSubscribed] = useState(false);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');

    const readBrowserSubscription = useCallback(async () => {
        if (support !== 'supported') return;
        const registration =
            await navigator.serviceWorker.getRegistration(PICKUP_WORKER_SCOPE);
        const subscription = await registration?.pushManager.getSubscription();
        setBrowserSubscribed(Boolean(subscription));
    }, [support]);
    useEffect(() => {
        void readBrowserSubscription().catch(() => undefined);
    }, [readBrowserSubscription]);

    const state = pickupNotifyState({
        support,
        status: pickup.status,
        serverAvailable:
            pickup.notifications.available &&
            pickup.notifications.public_key !== null,
        permission,
        browserSubscribed,
        serverSubscribed: pickup.notifications.subscribed,
    });
    if (state === 'not-needed') return null;

    const enable = async () => {
        const publicKey = pickup.notifications.public_key;
        if (busy || publicKey === null) return;
        setBusy(true);
        setError('');
        try {
            const granted = await Notification.requestPermission();
            setPermission(granted);
            if (granted !== 'granted') return;
            await navigator.serviceWorker.register(PICKUP_WORKER_URL, {
                scope: PICKUP_WORKER_SCOPE,
            });
            const registration = await navigator.serviceWorker.ready.then(
                async () =>
                    (await navigator.serviceWorker.getRegistration(
                        PICKUP_WORKER_SCOPE,
                    )) ?? null,
            );
            if (registration === null) throw new Error('No pickup worker');
            const subscription =
                (await registration.pushManager.getSubscription()) ??
                (await registration.pushManager.subscribe({
                    userVisibleOnly: true,
                    applicationServerKey: urlBase64ToUint8Array(publicKey),
                }));
            const body = subscriptionBody(
                subscription.toJSON(),
                (
                    PushManager as unknown as {
                        supportedContentEncodings?: string[];
                    }
                ).supportedContentEncodings,
            );
            if (body === null) throw new Error('Incomplete subscription');
            await qrRequest(subscribeRoute(token), body);
            setBrowserSubscribed(true);
            onChanged();
        } catch (reason) {
            const failure = qrError(reason);
            setError(
                failure.status > 0
                    ? failure.message
                    : 'Notifications could not be turned on. Keep this page open — it updates live.',
            );
        } finally {
            setBusy(false);
        }
    };

    const disable = async () => {
        if (busy) return;
        setBusy(true);
        setError('');
        try {
            await qrRequest(unsubscribeRoute(token));
            onChanged();
        } catch (reason) {
            setError(qrError(reason).message);
        } finally {
            setBusy(false);
        }
    };

    return (
        <section className="flex flex-col gap-3 rounded-3xl border border-white/10 bg-white/5 p-5 print:hidden">
            <div className="flex items-start gap-3">
                <BellRing
                    className={`mt-0.5 size-5 shrink-0 ${state === 'on' ? 'text-emerald-400' : 'text-[#f5c542]'}`}
                />
                <div className="min-w-0">
                    <h2 className="text-sm font-black">
                        {state === 'on'
                            ? 'Ready notification on'
                            : 'Notify me when it’s ready'}
                    </h2>
                    <p className="mt-1 text-xs leading-5 text-white/60">
                        {pickupNotifyMessage(state)}
                    </p>
                </div>
            </div>
            {state === 'off' && (
                <button
                    type="button"
                    onClick={enable}
                    disabled={busy}
                    className="inline-flex min-h-12 items-center justify-center gap-2 rounded-2xl bg-[#f5c542] px-5 text-sm font-black text-neutral-950 disabled:opacity-60"
                >
                    {busy ? (
                        <LoaderCircle className="size-4 animate-spin" />
                    ) : (
                        <BellRing className="size-4" />
                    )}
                    Turn on notifications
                </button>
            )}
            {state === 'on' && (
                <button
                    type="button"
                    onClick={disable}
                    disabled={busy}
                    className="inline-flex min-h-11 items-center justify-center rounded-2xl border border-white/15 px-5 text-xs font-bold text-white/75 disabled:opacity-60"
                >
                    Turn off
                </button>
            )}
            {error && (
                <p role="alert" className="text-xs text-red-300">
                    {error}
                </p>
            )}
        </section>
    );
}

function PickupProblem({ expired }: { expired: boolean }) {
    return (
        <>
            <Head title="Pickup" />
            <main className="flex min-h-dvh flex-col items-center justify-center gap-4 bg-[#0f1010] px-6 text-center text-white">
                <img
                    src="/images/branding/pongskilog-emblem.png"
                    alt=""
                    className="size-16 rounded-full"
                />
                <h1 className="text-2xl font-black">
                    {expired
                        ? 'This pickup link has expired'
                        : 'Pickup link not found'}
                </h1>
                <p className="max-w-sm text-sm text-white/60">
                    Please ask the cashier about your order.
                </p>
            </main>
        </>
    );
}
