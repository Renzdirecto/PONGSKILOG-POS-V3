---
paths:
  - '{app/Events/**,app/Actions/**,app/Http/Controllers/**,resources/js/hooks/**}'
---

# Hooks

## Catalog realtime is branch-scoped and refetch-based
Broadcast compact Product/availability/inventory events only after the complete DB transaction commits on private branch.{branch}.inventory. POS clients debounce and partial-refetch authoritative catalog state, refetch on reconnect, and preserve cart/dialog/payment input.
