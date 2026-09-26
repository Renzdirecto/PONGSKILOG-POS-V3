import { Head } from '@inertiajs/react';
import {
    BellRing,
    CheckCircle2,
    ChefHat,
    CircleSlash,
    LoaderCircle,
    ShoppingBag,
    WifiOff,
} from 'lucide-react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import {
    PICKUP_WORKER_SCOPE,
    PICKUP_WORKER_URL,
    pickupNotifyMessage,
    pickupNotifyState,
    pickupQueueText,
    pickupStatusView,
    type PickupStatusData,
} from '@/lib/pickup';
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
import { status as statusRoute } from '@/routes/pickup';
import {
    destroy as unsubscribeRoute,
    store as subscribeRoute,
} from '@/routes/pickup/subscription';

type Props = {
    token: string | null;
    pickup: PickupStatusData | null;
    problem: 'invalid' | 'expired' | null;
};

/**
 * The public Takeout pickup page (Phase 19.6B): order number, Take Out, Preparing / Ready / Done and the position in the
 * Take Out queue, live through this order's own channel. The only thing a customer can do is turn this order's Ready
 * notification on or off; nothing here can change, cancel or pay for the order.
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
    const queue =
        pickup.status === 'preparing'
            ? pickupQueueText(pickup.queue_position)
            : null;
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

    const notice = connectionNotice(connection);

    return (
        <>
            <Head title={`Order #${pickup.order_number}`} />
            <main className="flex min-h-dvh flex-col items-center bg-[#0f1010] px-4 pt-[max(24px,env(safe-area-inset-top))] pb-[max(24px,env(safe-area-inset-bottom))] text-white">
                <div className="flex w-full max-w-md flex-col gap-4">
                    <header className="flex items-center justify-center gap-3 py-2">
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
                        className={`flex flex-col items-center gap-3 rounded-3xl border px-6 py-8 text-center ${view.tone === 'green' ? 'border-emerald-400/40 bg-emerald-950/60' : view.tone === 'amber' ? 'border-amber-400/25 bg-white/5' : 'border-white/10 bg-white/5'}`}
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
                            <p className="rounded-2xl bg-white/10 px-4 py-2 text-base font-bold">
                                {queue}
                            </p>
                        )}
                    </section>

                    <PickupNotifications
                        token={token}
                        pickup={pickup}
                        onChanged={() => refresh.schedule(0)}
                    />

                    {notice && (
                        <p
                            role="status"
                            className="flex items-center justify-center gap-2 text-xs font-semibold text-amber-300"
                        >
                            <WifiOff className="size-3.5" /> {notice}
                        </p>
                    )}
                    <p className="text-center text-[11px] leading-5 text-white/40">
                        This page only shows your order’s pickup status. Keep it
                        open or come back any time.
                    </p>
                </div>
            </main>
        </>
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
        <section className="flex flex-col gap-3 rounded-3xl border border-white/10 bg-white/5 p-5">
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
