import { cleanupOutdatedCaches, matchPrecache, precacheAndRoute } from 'workbox-precaching';
import { NavigationRoute, registerRoute } from 'workbox-routing';
import { NetworkOnly } from 'workbox-strategies';
import {
    notificationContent,
    notificationTarget,
    OPEN_TARGET_MESSAGE,
    parsePushPayload,
} from '../lib/pwa-notifications';

/**
 * PONGSKILOG service worker — PWA Phase 1 (internet-first).
 *
 * Cached (precache, injected at build time, versioned and cleaned on update): fingerprinted Vite JS/CSS/font assets,
 * the brand icons and the static offline page. Nothing else is ever stored: no page HTML, Inertia JSON, CSRF token,
 * order, payment, stock, Staff, report, audit or notification data, and no signed or private URL.
 *
 * Network: page navigations always go to the network (authenticated HTML is never cached); only when the network is
 * unreachable does a navigation get the static offline page. Every other request, including every POST, PUT, PATCH
 * and DELETE, is left to the browser untouched: never cached, queued, replayed or answered with a fake success.
 *
 * Updates: a new version installs and then waits; it takes over only when the app asks (Update now).
 */
declare const self: ServiceWorkerGlobalScope & {
    __WB_MANIFEST: Array<{ url: string; revision: string | null } | string>;
};

type AppNotificationOptions = NotificationOptions & {
    vibrate?: number[];
    renotify?: boolean;
};

const OFFLINE_PAGE = '/offline.html';
const ICON = '/images/branding/icons/icon-192.png';

precacheAndRoute(self.__WB_MANIFEST);
cleanupOutdatedCaches();

const network = new NetworkOnly();
registerRoute(
    new NavigationRoute(async (options) => {
        try {
            return await network.handle(options);
        } catch {
            return (await matchPrecache(OFFLINE_PAGE)) ?? Response.error();
        }
    }),
);

self.addEventListener('message', (event) => {
    if ((event.data as { type?: unknown } | null)?.type === 'SKIP_WAITING') {
        void self.skipWaiting();
    }
});

/**
 * Show every push as a generic PONGSKILOG notification (fixed texts per type, one tag per event so a repeat
 * replaces instead of stacking). When a PONGSKILOG window is focused, its own realtime view and sounds already cover
 * the event, so the entry is added silently instead of alerting twice.
 */
self.addEventListener('push', (event) => {
    const content = notificationContent(parsePushPayload(event.data?.text()));

    event.waitUntil(
        (async () => {
            const windows = await self.clients.matchAll({
                type: 'window',
                includeUncontrolled: true,
            });
            const focused = windows.some(
                (client) => client.focused && client.visibilityState === 'visible',
            );
            const options: AppNotificationOptions = {
                body: content.body,
                tag: content.tag,
                icon: ICON,
                data: { url: content.url },
                silent: focused,
                renotify: false,
                ...(focused ? {} : { vibrate: [180, 80, 180] }),
            };
            await self.registration.showNotification(content.title, options);
        })(),
    );
});

/** Open or focus PONGSKILOG on the tapped notification's page — only same-origin pages from the allowlist. */
self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    const target = notificationTarget(
        (event.notification.data as { url?: unknown } | null)?.url,
        self.location.origin,
    );

    event.waitUntil(
        (async () => {
            const windows = await self.clients.matchAll({
                type: 'window',
                includeUncontrolled: true,
            });
            const sameOrigin = windows.filter(
                (client) => new URL(client.url).origin === self.location.origin,
            );
            const onTarget = sameOrigin.find(
                (client) => new URL(client.url).pathname === target,
            );
            const existing = onTarget ?? sameOrigin[0];
            if (existing) {
                const focused = await existing.focus();
                /** The app decides whether it can leave the current screen (a cart or payment is never abandoned). */
                if (!onTarget) {
                    focused.postMessage({ type: OPEN_TARGET_MESSAGE, url: target });
                }

                return;
            }
            await self.clients.openWindow(target);
        })(),
    );
});
