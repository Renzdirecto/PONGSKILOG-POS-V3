---
paths:
  - '{app/Events/**,app/Actions/**,app/Http/Controllers/**,resources/js/hooks/**}'
---

# Hooks

## Catalog realtime is branch-scoped and refetch-based
Broadcast compact Product/availability/inventory events only after the complete DB transaction commits on private branch.{branch}.inventory. POS clients debounce and partial-refetch authoritative catalog state, refetch on reconnect, and preserve cart/dialog/payment input. A Branch configuration change (assortment add/remove/copy, recipe mode, Recipes, effects, Ingredients, Plans, setup copy) signals only that Branch through `CatalogRealtime::branchConfigurationChanged()` / `branchProductsChanged()` (`ingredients.changed`, `qr.catalog_changed`, `reports.changed` for Operations pages); never a global invalidation for one Branch's change.

## Keep Customer Display realtime payload privacy-minimal
Customer Display must receive only the branch-scoped `display.orders_changed` invalidation signal (event identity, branch, time), never operational Kitchen payloads, order/internal IDs, customer/item/table data, or financial fields. Its client debounces/coalesces signals and refetches the authoritative order-number-only projection, including after reconnect.

## Owner reports realtime is an invalidation signal
Owner/Super Admin Dashboard, Reports and the Operations workspace listen on private `reports` (reports.view or operations.manage + business-wide scope); Branch accounts with Reports or Operations listen only on `branch.{branch}.reports` (`reportsChannelFor`). Operations is its own permission, so never gate its live refresh on reports.view. `BroadcastReportsChanged` turns OrderCommitted/OrderUpdated/OrderVoided/KitchenStatusChanged/StoreExpenseRecorded/StoreClosed (and `OpenStoreSession`) into `reports.changed` with only event id, branch, reason and time. Clients debounce and partial-reload the authorized report props (`useReportsRealtimeRefresh`), filter by the selected Branch, and poll every 30s only while disconnected (no other page timer). `router.reload` requests the current URL, so the hook holds refreshes during the page's own sync visits and cancels an in-flight reload when one starts; never add a reload/poll that bypasses this. Stock adjustments dispatch `ReportsChanged` directly (`inventory.adjusted`). New figure-changing actions must dispatch one of these events or `ReportsChanged`. A page may pass reasons it can never display to the guard (Operations ignores `kitchen.status_changed`); business Transactions uses this hook too and polls only for accounts that cannot subscribe (Transactions without Reports/Operations).

## Open sessions revalidate on `user.context_changed`
Every workspace shell mounts `UserContextRealtime` (own `App.Models.User.{id}` channel). A signal or a reconnect runs one debounced `router.reload()`; `onHttpException` sends 403/404 to `workspace` (server decides landing/Branch picker) and 401/419 to login, returning false so no error dialog shows. Staff and Access Control pages use `InvalidationRefresh` on `staff`/`branch.{id}.staff` and `access-control` with partial reloads. Every background reload (`useUserContextRealtime`, `useInvalidationRefresh`, `useReportsRealtimeRefresh`, `useBranchRealtimeRefresh`, `useAuditRealtimeRefresh` incl. its disconnected poll, `usePosQrRealtime`) passes `handleRevalidationException` and `onNetworkError: () => false`, so a page whose access was just revoked goes to the workspace instead of showing Laravel's raw 403 page in a modal. Never put permissions or Staff data in these payloads, never poll, and render the listener only when a channel exists (`useEcho` cannot be disabled).

## Operations partial reloads cover every changed prop
`liveProps` in `operations-ui.tsx` must list every prop a sale, purchase, recipe, Add-on effect, recipe mode or assortment change can alter for that page (e.g. Recipes reloads `products` and `ingredients`, Plans reloads `outside` and `products`). A Branch Product membership or stock-tracking change (`UpsertBranchProduct`) dispatches `ReportsChanged` for that Branch only, so its open Operations pages refetch.

## Server-rendered state is not refetched on the first connection (Phase 19)
A realtime hook refetches after a *re*connect (`shouldRefetchCatalogAfterConnectionChange` with a `hasConnected` ref), on `online`, and on its events, never on the first connect after a page load: the server just rendered that state. Keep the debouncer's activate/dispose effect separate from the connection-status effect so a status change never disposes pending refreshes.
