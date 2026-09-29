import { http, router } from '@inertiajs/react';
import { toast } from 'sonner';
import { handleRevalidationException } from '@/hooks/use-user-context-realtime';
import {
    createConnectivityMonitor,
    type ConnectivityMonitor,
} from '@/lib/pwa-connectivity';
import {
    detectInstallPlatform,
    installState,
    type InstallPlatform,
    type InstallState,
} from '@/lib/pwa-install';
import {
    LAST_ROUTE_STORAGE_KEY,
    launchRestoreTarget,
    parseStoredRoute,
    restorableRoute,
} from '@/lib/pwa-launch';
import {
    isOfflineWriteBlock,
    isServerWrite,
    OFFLINE_WRITE_MESSAGE,
    OfflineWriteBlockedError,
    writeDecision,
} from '@/lib/pwa-mutation-guard';
import {
    notificationTarget,
    OPEN_TARGET_MESSAGE,
    PUSH_TARGETS,
} from '@/lib/pwa-notifications';
import {
    matchesApplicationServerKey,
    pushState,
    pushSupport,
    subscriptionBody,
    urlBase64ToUint8Array,
    type PushState,
    type PushSupport,
} from '@/lib/pwa-push';
import { createUpdateController, type UpdateController } from '@/lib/pwa-update';
import { logout } from '@/routes';
import { serviceWorker } from '@/routes/pwa';
import {
    destroy as disablePushRoute,
    show as pushStatusRoute,
    store as enablePushRoute,
} from '@/routes/pwa/push-subscription';

/**
 * The PWA Phase 1 runtime: one instance per page load, started once from the root component. It stays inert on the
 * public Customer QR and receipt pages (no service worker, prompts, guard or status UI there) and wherever a browser
 * lacks a feature: the app keeps working as a normal online web app.
 *
 * - Connectivity: one Online / Reconnecting / Offline state, confirmed against `/up`, completed by one authoritative
 *   page reload; the existing realtime hooks keep their own reconnect refetches (no second realtime system).
 * - Write guard: every Inertia request (router, forms, useHttp, direct http client) passes one wrapped client, which
 *   refuses server writes while not online and counts writes in flight.
 * - Updates: a new service worker waits for Update now; Inertia asset-version reloads also wait while work is at risk.
 */
type InstallPromptEvent = Event & {
    prompt: () => Promise<void>;
    userChoice: Promise<{ outcome: 'accepted' | 'dismissed' }>;
};

type ServerPushStatus = {
    available: boolean;
    public_key: string | null;
    enabled: boolean;
};

export type PwaInstallSnapshot = {
    state: InstallState;
    platform: InstallPlatform;
};

export type PwaPushSnapshot = {
    state: PushState;
    support: PushSupport;
    checked: boolean;
    busy: boolean;
};

export type PwaUiSnapshot = {
    active: boolean;
    statusSlots: number;
    appDialogOpen: boolean;
};

type Store<T> = {
    get: () => T;
    set: (next: Partial<T>) => void;
    subscribe: (listener: () => void) => () => void;
};

function createStore<T extends object>(initial: T): Store<T> {
    let value = initial;
    const listeners = new Set<() => void>();

    return {
        get: () => value,
        set: (next) => {
            value = { ...value, ...next };
            listeners.forEach((listener) => listener());
        },
        subscribe: (listener) => {
            listeners.add(listener);

            return () => {
                listeners.delete(listener);
            };
        },
    };
}

/**
 * Public customer surfaces never get the staff app's service worker, install prompts, guard or status UI. The pickup
 * page registers only its own `/pickup/` worker for the customer's Ready notification (Phase 19.6B).
 */
export function isPublicCustomerSurface(component: string): boolean {
    return (
        component.startsWith('qr/') ||
        component === 'public-receipt' ||
        component === 'customer-screen' ||
        component === 'pickup'
    );
}

const UPDATE_CHECK_INTERVAL_MS = 60 * 60 * 1000;
const PUSH_CLEANUP_TIMEOUT_MS = 2_500;
const WINDOW_SESSION_KEY = 'pongskilog.pwa.window';
const SAVING_BLOCKER = 'pwa:saving';

let deferredInstallPrompt: InstallPromptEvent | null = null;
let installPromptListener: (() => void) | null = null;

/**
 * Capture Chromium's install prompt as early as possible (it can fire before React mounts) and keep the browser from
 * showing its own automatic banner: installing starts only from the Install PONGSKILOG button.
 */
export function captureInstallPrompt(): void {
    if (typeof window === 'undefined') {
        return;
    }
    window.addEventListener('beforeinstallprompt', (event) => {
        event.preventDefault();
        deferredInstallPrompt = event as InstallPromptEvent;
        installPromptListener?.();
    });
}

function isStandalone(): boolean {
    return (
        ['standalone', 'fullscreen', 'minimal-ui'].some(
            (mode) => window.matchMedia(`(display-mode: ${mode})`).matches,
        ) ||
        (navigator as Navigator & { standalone?: boolean }).standalone === true
    );
}

function readStorage(storage: () => Storage, key: string): string | null {
    try {
        return storage().getItem(key);
    } catch {
        return null;
    }
}

function writeStorage(storage: () => Storage, key: string, value: string) {
    try {
        storage().setItem(key, value);
    } catch {
        /** Storage can be unavailable (private mode); recovery is a convenience only. */
    }
}

async function requestJson<T>(
    route: { url: string; method: 'get' | 'post' | 'delete' },
    data?: unknown,
): Promise<T> {
    const response = await http.getClient().request({
        method: route.method,
        url: route.url,
        data,
        headers: { Accept: 'application/json' },
    });

    return (response.data ? JSON.parse(response.data) : null) as T;
}

function withTimeout<T>(promise: Promise<T>, timeoutMs: number): Promise<T | null> {
    return Promise.race([
        promise,
        new Promise<null>((resolve) => window.setTimeout(() => resolve(null), timeoutMs)),
    ]);
}

function createRuntime() {
    const platform = detectInstallPlatform(
        navigator.userAgent,
        navigator.maxTouchPoints ?? 0,
    );
    const ui = createStore<PwaUiSnapshot>({
        active: false,
        statusSlots: 0,
        appDialogOpen: false,
    });
    const install = createStore<PwaInstallSnapshot>({
        state: 'browser-menu',
        platform,
    });
    const push = createStore<PwaPushSnapshot>({
        state: 'off',
        support: 'unsupported',
        checked: false,
        busy: false,
    });
    const connectivity: ConnectivityMonitor = createConnectivityMonitor({
        probe: async () => {
            try {
                const response = await fetch('/up', {
                    cache: 'no-store',
                    credentials: 'omit',
                    headers: { Accept: 'application/json' },
                    signal: AbortSignal.timeout(8_000),
                });

                return response.ok;
            } catch {
                return false;
            }
        },
        /** One authoritative reload: the server re-checks the session, Branch context and page access. */
        revalidate: () =>
            new Promise((resolve) => {
                router.reload({
                    onSuccess: () => resolve('revalidated'),
                    onHttpException: (response) => {
                        resolve('revalidated');

                        return handleRevalidationException(response);
                    },
                    onNetworkError: () => {
                        resolve('unreachable');

                        return false;
                    },
                    onFinish: () => resolve('revalidated'),
                });
            }),
        isNavigatorOnline: () => navigator.onLine,
        isDocumentVisible: () => document.visibilityState !== 'hidden',
        now: () => Date.now(),
        setTimeout: (callback, delayMs) => window.setTimeout(callback, delayMs),
        clearTimeout: (handle) => window.clearTimeout(handle as number),
    });
    const update: UpdateController = createUpdateController({
        reload: () => window.location.reload(),
        now: () => Date.now(),
        setTimeout: (callback, delayMs) => window.setTimeout(callback, delayMs),
    });
    let registration: ServiceWorkerRegistration | null = null;
    let browserSubscribed = false;
    let serverStatus: ServerPushStatus | null = null;
    let savingWrites = 0;
    let pushCleanupDone = false;
    let started = false;

    const refreshInstall = () => {
        install.set({
            state: installState({
                standalone: isStandalone(),
                promptAvailable: deferredInstallPrompt !== null,
                platform,
                secureContext: window.isSecureContext,
            }),
        });
    };

    const currentPushSupport = (): PushSupport =>
        pushSupport({
            secureContext: window.isSecureContext,
            serviceWorker: registration !== null,
            pushManager: 'PushManager' in window,
            notification: 'Notification' in window,
            platform,
            standalone: isStandalone(),
        });

    const refreshPushState = (enabled: boolean) => {
        const support = currentPushSupport();
        push.set({
            support,
            state: pushState({
                support,
                serverAvailable: serverStatus?.available ?? true,
                permission:
                    'Notification' in window ? Notification.permission : null,
                enabled,
            }),
        });
    };

    const trackSaving = (delta: number) => {
        savingWrites = Math.max(0, savingWrites + delta);
        update.setBlocker(
            SAVING_BLOCKER,
            savingWrites > 0 ? 'A change is still being saved.' : null,
        );
    };

    /** Every Inertia request passes here: writes are refused while not online, and responses feed connectivity. */
    const guardHttpClient = () => {
        const inner = http.getClient();
        http.setClient({
            request: async (config) => {
                const write = isServerWrite(config.method);
                const decision = writeDecision({
                    method: config.method,
                    url: config.url,
                    serverWritesAllowed: connectivity.allowsServerWrites(),
                });
                if (decision !== 'send') {
                    if (decision === 'block') {
                        toast.error(OFFLINE_WRITE_MESSAGE, {
                            id: 'pwa-offline-write',
                        });
                    }
                    throw new OfflineWriteBlockedError(config.url);
                }
                if (write) {
                    trackSaving(1);
                }
                try {
                    const response = await inner.request(config);
                    connectivity.reportServerResponse();

                    return response;
                } catch (error) {
                    const name = (error as { name?: unknown } | null)?.name;
                    if (name === 'HttpNetworkError') {
                        connectivity.reportNetworkFailure();
                    } else if (name === 'HttpResponseError') {
                        connectivity.reportServerResponse();
                    }
                    throw error;
                } finally {
                    if (write) {
                        trackSaving(-1);
                    }
                }
            },
        });
        /** A blocked router write already showed the offline message; never surface it as a network error. */
        router.on('networkError', (event) =>
            isOfflineWriteBlock(event.detail.error) ? false : undefined,
        );
    };

    /** Announced once per new version (or source), and again after a "Later" snooze has passed; never on every change. */
    let announcedSource: string | null = null;
    let announcedSnooze: number | null = null;
    const announceUpdate = () => {
        const snapshot = update.getSnapshot();
        if (
            !update.isPromptDue() ||
            (announcedSource === snapshot.source &&
                announcedSnooze === snapshot.snoozedUntil)
        ) {
            return;
        }
        announcedSource = snapshot.source;
        announcedSnooze = snapshot.snoozedUntil;
        const elsewhere = snapshot.source === 'activated-elsewhere';
        toast(
            elsewhere
                ? 'PONGSKILOG was updated'
                : 'PONGSKILOG update available',
            {
                id: 'pwa-update',
                description: elsewhere
                    ? 'Reload this window to finish updating.'
                    : 'Update now to use the latest version.',
                duration: Infinity,
                action: { label: 'Update now', onClick: () => requestUpdate() },
                cancel: { label: 'Later', onClick: () => update.snooze() },
            },
        );
    };

    const requestUpdate = () => {
        if (update.apply() === 'blocked') {
            toast.info('The update will wait.', {
                id: 'pwa-update-blocked',
                description:
                    update.getSnapshot().blockers[0] ??
                    'Finish the current work first.',
            });
        }
    };

    const registerServiceWorker = async () => {
        if (!('serviceWorker' in navigator)) {
            return;
        }
        if (import.meta.env.DEV) {
            /** The Vite dev server has no service worker; remove one left by a production build so HMR is never cached. */
            const registrations =
                await navigator.serviceWorker.getRegistrations();
            await Promise.all(
                registrations.map((existing) => existing.unregister()),
            );

            return;
        }
        registration = await navigator.serviceWorker.register(
            serviceWorker.url(),
            { scope: '/', updateViaCache: 'none' },
        );
        const trackInstalling = (worker: ServiceWorker | null) => {
            worker?.addEventListener('statechange', () => {
                if (
                    worker.state === 'installed' &&
                    navigator.serviceWorker.controller
                ) {
                    update.markWaiting(worker);
                }
            });
        };
        if (registration.waiting && navigator.serviceWorker.controller) {
            update.markWaiting(registration.waiting);
        }
        trackInstalling(registration.installing);
        registration.addEventListener('updatefound', () =>
            trackInstalling(registration?.installing ?? null),
        );
        navigator.serviceWorker.addEventListener('controllerchange', () =>
            update.handleControllerChange(),
        );
        navigator.serviceWorker.addEventListener('message', (event) => {
            if (
                (event.data as { type?: unknown } | null)?.type ===
                OPEN_TARGET_MESSAGE
            ) {
                openNotificationTarget(
                    (event.data as { url?: unknown }).url,
                );
            }
        });
        /** Long-running POS and Kitchen tabs never reload by themselves: check for a new version hourly while visible. */
        window.setInterval(() => {
            if (
                document.visibilityState === 'visible' &&
                connectivity.allowsServerWrites()
            ) {
                void registration?.update().catch(() => undefined);
            }
        }, UPDATE_CHECK_INTERVAL_MS);
        browserSubscribed =
            (await registration.pushManager
                ?.getSubscription()
                .catch(() => null)) != null;
        refreshPushState(browserSubscribed);
    };

    /** A notification tap focused this window: open its page unless that would abandon work in progress. */
    const openNotificationTarget = (url: unknown) => {
        const target = notificationTarget(url, window.location.origin);
        if (window.location.pathname === target) {
            return;
        }
        if (update.getSnapshot().blockers.length > 0) {
            const page =
                target === PUSH_TARGETS['kitchen.new_order']
                    ? 'Kitchen'
                    : target === PUSH_TARGETS['order.ready']
                      ? 'POS'
                      : 'Notifications';
            toast.info(`Finish the current work, then open ${page}.`, {
                id: 'pwa-open-target',
            });

            return;
        }
        router.visit(target);
    };

    /** Installed app launch: return to the last top-level screen when the start URL redirected to the landing page. */
    const recoverLaunchRoute = (signedIn: boolean) => {
        const standalone = isStandalone();
        const firstPageOfWindow =
            readStorage(() => window.sessionStorage, WINDOW_SESSION_KEY) ===
            null;
        writeStorage(() => window.sessionStorage, WINDOW_SESSION_KEY, '1');
        const navigation = performance.getEntriesByType('navigation')[0] as
            | PerformanceNavigationTiming
            | undefined;
        const target = launchRestoreTarget({
            standalone,
            firstPageOfWindow,
            navigationType: navigation?.type ?? null,
            redirectCount: navigation?.redirectCount ?? 0,
            signedIn,
            stored: parseStoredRoute(
                readStorage(() => window.localStorage, LAST_ROUTE_STORAGE_KEY),
            ),
            currentUrl: window.location.href,
            origin: window.location.origin,
            now: Date.now(),
        });
        const remember = (url: string) => {
            const path = isStandalone()
                ? restorableRoute(url, window.location.origin)
                : null;
            if (path) {
                writeStorage(
                    () => window.localStorage,
                    LAST_ROUTE_STORAGE_KEY,
                    JSON.stringify({ path, savedAt: Date.now() }),
                );
            }
        };
        if (target) {
            router.visit(target, { replace: true });
        } else {
            remember(window.location.href);
        }
        router.on('navigate', (event) => remember(event.detail.page.url));
    };

    /** Explicit logout: unsubscribe this browser from push first (best effort, bounded), then log out as before. */
    const cleanUpPushOnLogout = () => {
        router.on('before', (event) => {
            const visit = event.detail.visit;
            if (
                pushCleanupDone ||
                !browserSubscribed ||
                visit.method !== 'post' ||
                visit.url.pathname !== logout.url() ||
                !connectivity.allowsServerWrites()
            ) {
                return;
            }
            pushCleanupDone = true;
            void withTimeout(
                (async () => {
                    const subscription =
                        await registration?.pushManager.getSubscription();
                    await subscription?.unsubscribe();
                })().catch(() => undefined),
                PUSH_CLEANUP_TIMEOUT_MS,
            ).finally(() => router.post(logout.url()));

            return false;
        });
    };

    return {
        ui,
        install,
        push,
        connectivity,
        update,
        start: (options: { component: string; signedIn: boolean }) => {
            if (started || isPublicCustomerSurface(options.component)) {
                return;
            }
            started = true;
            ui.set({ active: true });
            guardHttpClient();
            cleanUpPushOnLogout();
            window.addEventListener('online', connectivity.handleOnline);
            window.addEventListener('offline', connectivity.handleOffline);
            document.addEventListener(
                'visibilitychange',
                connectivity.handleVisibilityChange,
            );
            window.addEventListener('pageshow', (event) => {
                if (event.persisted) {
                    connectivity.retry();
                }
            });
            installPromptListener = refreshInstall;
            window.addEventListener('appinstalled', () => {
                deferredInstallPrompt = null;
                refreshInstall();
            });
            window
                .matchMedia('(display-mode: standalone)')
                .addEventListener('change', refreshInstall);
            refreshInstall();
            refreshPushState(false);
            update.subscribe(announceUpdate);
            router.on('location', (event) => {
                if (!event.detail.versionChange) {
                    return;
                }
                update.markServerVersionChanged();
                void registration?.update().catch(() => undefined);
                /** A new version must not reload over a cart, payment or save in progress. */
                if (update.getSnapshot().blockers.length > 0) {
                    toast.info(
                        'PONGSKILOG was updated. Finish the current work, then choose Update now.',
                        { id: 'pwa-update-deferred' },
                    );

                    return false;
                }
            });
            router.on('navigate', () => announceUpdate());
            recoverLaunchRoute(options.signedIn);
            void registerServiceWorker().catch(() => undefined);
        },
        requestUpdate,
        promptInstall: async () => {
            const prompt = deferredInstallPrompt;
            if (!prompt) {
                return;
            }
            deferredInstallPrompt = null;
            await prompt.prompt();
            await prompt.userChoice.catch(() => null);
            refreshInstall();
        },
        /** Server availability and this browser's state; used when the app panel opens (no permission prompt). */
        refreshPush: async () => {
            const enabledInBrowser =
                (await registration?.pushManager
                    .getSubscription()
                    .catch(() => null)) != null;
            browserSubscribed = enabledInBrowser;
            if (currentPushSupport() !== 'supported') {
                refreshPushState(false);
                push.set({ checked: true });

                return;
            }
            try {
                serverStatus = await requestJson<ServerPushStatus>(
                    pushStatusRoute(),
                );
            } catch {
                serverStatus = null;
            }
            refreshPushState(
                enabledInBrowser && (serverStatus?.enabled ?? false),
            );
            push.set({ checked: true });
        },
        /** Must run from the Enable Notifications click: the permission prompt needs that user gesture. */
        enablePush: async () => {
            const publicKey = serverStatus?.public_key;
            if (!registration || !publicKey || push.get().busy) {
                return;
            }
            push.set({ busy: true });
            try {
                const permission =
                    Notification.permission === 'default'
                        ? await Notification.requestPermission()
                        : Notification.permission;
                if (permission !== 'granted') {
                    refreshPushState(false);

                    return;
                }
                let subscription =
                    await registration.pushManager.getSubscription();
                if (
                    subscription &&
                    !matchesApplicationServerKey(
                        subscription.options.applicationServerKey,
                        publicKey,
                    )
                ) {
                    await subscription.unsubscribe();
                    subscription = null;
                }
                subscription ??= await registration.pushManager.subscribe({
                    userVisibleOnly: true,
                    applicationServerKey: urlBase64ToUint8Array(publicKey),
                });
                const body = subscriptionBody(
                    subscription.toJSON(),
                    PushManager.supportedContentEncodings,
                );
                if (body === null) {
                    throw new Error('Incomplete push subscription');
                }
                await requestJson(enablePushRoute(), body);
                browserSubscribed = true;
                serverStatus = { ...serverStatus, available: true, public_key: publicKey, enabled: true };
                refreshPushState(true);
                toast.success('Notifications are on for this device.');
            } catch (error) {
                if (!isOfflineWriteBlock(error)) {
                    toast.error(
                        'Notifications could not be turned on. Try again.',
                    );
                }
            } finally {
                push.set({ busy: false });
            }
        },
        disablePush: async () => {
            if (push.get().busy) {
                return;
            }
            push.set({ busy: true });
            try {
                const subscription =
                    await registration?.pushManager.getSubscription();
                await requestJson(disablePushRoute(), {
                    endpoint: subscription?.endpoint ?? null,
                });
                await subscription?.unsubscribe();
                browserSubscribed = false;
                if (serverStatus) {
                    serverStatus = { ...serverStatus, enabled: false };
                }
                refreshPushState(false);
                toast.success('Notifications are off for this device.');
            } catch (error) {
                if (!isOfflineWriteBlock(error)) {
                    toast.error(
                        'Notifications could not be turned off. Try again.',
                    );
                }
            } finally {
                push.set({ busy: false });
            }
        },
    };
}

export type PwaRuntime = ReturnType<typeof createRuntime>;

let runtime: PwaRuntime | null = null;

/** The page's runtime, created on first use in the browser; null during server rendering. */
export function pwaRuntime(): PwaRuntime | null {
    if (typeof window === 'undefined') {
        return null;
    }
    runtime ??= createRuntime();

    return runtime;
}

/** Whether a server write may be sent now; true where the runtime is not active (public pages, server rendering). */
export function serverWritesAllowed(): boolean {
    const current = pwaRuntime();

    return (
        current === null ||
        !current.ui.get().active ||
        current.connectivity.allowsServerWrites()
    );
}
