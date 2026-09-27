/*
 * PONGSKILOG pickup notifications — Phase 19.6B.
 *
 * Registered only by the public pickup page with scope `/pickup/`, completely separate from the staff app's service
 * worker (`/sw.js`, scope `/`): it caches nothing, intercepts no request and only shows the "ready for pickup" Buzz of
 * the order whose page subscribed. A tap opens that order's own pickup page (same origin, `/pickup/…` paths only).
 */
'use strict';

const PICKUP_PATH = /^\/pickup\/[A-Za-z0-9_-]{43}$/;
const ICON = '/images/branding/icons/icon-192.png';

function cleanNumber(value) {
    return typeof value === 'string' && /^[A-Za-z0-9-]{1,20}$/.test(value) ? value : null;
}

function targetPath(value) {
    try {
        const url = new URL(typeof value === 'string' ? value : '', self.location.origin);

        return url.origin === self.location.origin && PICKUP_PATH.test(url.pathname) ? url.pathname : null;
    } catch {
        return null;
    }
}

self.addEventListener('push', (event) => {
    let data = {};
    try {
        data = event.data ? event.data.json() : {};
    } catch {
        data = {};
    }
    if (data.type !== 'pickup.ready') {
        return;
    }
    const number = cleanNumber(data.order_number);
    const tag = typeof data.tag === 'string' ? data.tag.slice(0, 80) : 'pickup-ready';
    const target = targetPath(data.url);

    event.waitUntil(
        Promise.all([
            self.registration.showNotification('PONGSKILOG', {
                body: number ? `Order #${number} is ready for pickup.` : 'Your order is ready for pickup.',
                tag,
                renotify: true,
                requireInteraction: true,
                icon: ICON,
                badge: ICON,
                vibrate: [300, 120, 300, 120, 300],
                data: { url: target },
            }),
            tellOpenPage(target),
        ]),
    );
});

/**
 * The notification above is the Buzz (OS sound and vibration where the phone allows). When this order's pickup page
 * is also open, it is told too, so a page in the foreground can vibrate and play its optional sound. Never fails the push.
 */
async function tellOpenPage(target) {
    if (target === null) {
        return;
    }
    try {
        const windows = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
        windows
            .filter((client) => new URL(client.url).pathname === target)
            .forEach((client) => client.postMessage({ type: 'pickup.buzz' }));
    } catch {
        // The notification is already the Buzz.
    }
}

self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    const target = targetPath(event.notification.data && event.notification.data.url);
    if (target === null) {
        return;
    }

    event.waitUntil(
        (async () => {
            const windows = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
            const open = windows.find((client) => new URL(client.url).pathname === target);
            if (open) {
                await open.focus();

                return;
            }
            await self.clients.openWindow(target);
        })(),
    );
});
