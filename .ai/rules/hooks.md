---
paths:
  - '{app/Events/**,app/Actions/**,app/Http/Controllers/**,resources/js/hooks/**}'
---

# Hooks

## Catalog realtime is branch-scoped and refetch-based
Broadcast compact Product/availability/inventory events only after the complete DB transaction commits on private branch.{branch}.inventory. POS clients debounce and partial-refetch authoritative catalog state, refetch on reconnect, and preserve cart/dialog/payment input.

## Keep Customer Display realtime payload privacy-minimal
Customer Display must receive only the branch-scoped `display.orders_changed` invalidation signal (event identity, branch, time), never operational Kitchen payloads, order/internal IDs, customer/item/table data, or financial fields. Its client debounces/coalesces signals and refetches the authoritative order-number-only projection, including after reconnect.

## Owner reports realtime is an invalidation signal
Owner/Super Admin Dashboard and Reports listen on private `reports` (reports.view + business-wide scope). `BroadcastReportsChanged` turns OrderCommitted/OrderUpdated/OrderVoided/KitchenStatusChanged/StoreExpenseRecorded/StoreClosed (and `OpenStoreSession`) into `reports.changed` with only event id, branch, reason and time. Clients debounce and partial-reload the authorized report props (`useReportsRealtimeRefresh`), filter by the selected Branch, and poll every 30s only while disconnected (no other page timer). `router.reload` requests the current URL, so the hook holds refreshes during the page's own sync visits and cancels an in-flight reload when one starts; never add a reload/poll that bypasses this. Stock adjustments dispatch `ReportsChanged` directly (`inventory.adjusted`). New figure-changing actions must dispatch one of these events or `ReportsChanged`.
