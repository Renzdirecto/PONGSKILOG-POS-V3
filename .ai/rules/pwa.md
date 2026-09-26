---
paths:
  - '{resources/js/service-worker/**,resources/js/lib/pwa-*.ts,resources/js/hooks/use-pwa.ts,resources/js/components/pwa-*.tsx,resources/js/app.tsx,resources/views/app.blade.php,public/manifest.webmanifest,public/offline.html,vite.config.ts}'
  - '{app/Support/{PushNotifications,PushMessage,PushRecipients,PushGateway,WebPushGateway,WebPushSender,PushDevice,BranchSignalAccess}.php,app/Jobs/SendPushNotification.php,app/Listeners/{QueueKitchenPushNotifications,QueueAdminAlertPushNotification,ForgetPushDeviceOnLogout}.php,app/Actions/Notifications/**,app/Http/Controllers/{PushSubscriptionController,ServiceWorkerController}.php,app/Http/Requests/{StorePushSubscriptionRequest,DestroyPushSubscriptionRequest}.php}'
---

# PWA (Phase 1, internet-first)

## The service worker never stores private data
`resources/js/service-worker/sw.ts` precaches only the fingerprinted Vite build (`/build/assets/*.{js,css,woff2}`), the brand icons and `/offline.html` (injected by vite-plugin-pwa `injectManifest`). Navigations are NetworkOnly with the static offline page as the only fallback; every other request (Inertia JSON, uploads, signed URLs, every POST/PUT/PATCH/DELETE) is left to the browser. Never add runtime caching of HTML, JSON or signed URLs, Background Sync, a write queue, `clients.claim()` or an unconditional `skipWaiting()`. The worker is served by `ServiceWorkerController` at `/sw.js` (outside the web middleware, `no-store`); public Customer QR / kiosk / receipt pages never link the manifest or register it.

## One guard for every server write
`lib/pwa-runtime.ts` wraps the Inertia HTTP client (`http.setClient`), so router visits, `useForm`, `<Form>`, `useHttp`, `http.getClient()` and `qrRequest` all pass `writeDecision()`: while the connectivity state is not `online`, every POST/PUT/PATCH/DELETE is refused before sending with `OfflineWriteBlockedError` and the canonical message (background writes silently). Send new writes through the Inertia client, never raw `fetch`/XHR, or they bypass the guard. Screens may also check `serverWritesAllowed()` for UX; the backend stays the authority.

## Connectivity is one state, confirmed against the server
Online / Reconnecting / Offline lives only in `pwa-connectivity.ts`: `online` → verify `/up` → one authoritative `router.reload()` (with `handleRevalidationException`) → online. Bounded backoff (≤30 s), paused while hidden, no timer at all once online. Existing realtime hooks keep their own reconnect refetches; never add a second realtime client, polling loop or "last synced" store.

## Updates wait for a safe moment
A new worker waits; only `requestUpdate()` (Update now) posts `SKIP_WAITING`, and only the window that asked reloads, once. A screen with transient work a reload would destroy calls `useUpdateBlocker(active, reason)` (the POS does for cart, loaded QR, payment and open dialogs); writes in flight block automatically. Inertia asset-version reloads (`location` event) are cancelled while blocked. Never persist carts or payments locally to "survive" a reload.

## Web Push is best effort and resolved at send time
Business code calls only `PushNotifications::queue()` (config check + rescued eager dispatch, no query in the request). `SendPushNotification` resolves recipients when it runs through `PushRecipients`: Kitchen = `BranchSignalAccess` with `kitchen.access`, Order Ready = `pos.access` (the same rule as the `branch.{id}.pos|kitchen` channels, minus the canonical Super Admin role, which gets only alerts), alerts = `AdminNotifier::receivesAlerts()` for the notified account; transient failures retry per subscription at most 3 times, 404/410/400/401/403 delete it. Payloads carry only type, tag, allowlisted path and Branch name; the worker shows fixed texts. Subscriptions are encrypted at rest, unique by `endpoint_hash`, owned by the signed-in account (endpoint hosts allowlisted against SSRF), removed on logout via the device cookie and by `UserSessions::invalidate()`. Never log or audit endpoints, keys or the VAPID private key.
