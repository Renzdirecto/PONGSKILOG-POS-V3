---
paths:
  - '{app/Events/**,app/Actions/**,app/Http/Controllers/**,resources/js/hooks/**}'
---

# Hooks

## Catalog realtime is branch-scoped and refetch-based
Broadcast compact Product/availability/inventory events only after the complete DB transaction commits on private branch.{branch}.inventory. POS clients debounce and partial-refetch authoritative catalog state, refetch on reconnect, and preserve cart/dialog/payment input.

## Keep Customer Display realtime payload privacy-minimal
Customer Display must receive only the branch-scoped `display.orders_changed` invalidation signal (event identity, branch, time), never operational Kitchen payloads, order/internal IDs, customer/item/table data, or financial fields. Its client debounces/coalesces signals and refetches the authoritative order-number-only projection, including after reconnect.
