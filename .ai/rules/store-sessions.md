---
paths:
  - 'resources/js/{components,layouts}/**/*.{ts,tsx},app/Actions/StoreSessions/**/*.php'
---

# Store Sessions

## Keep Store expenses inside the Current Store Session surface
Cashier Store Purchases / Expenses are opened from the existing LIVE / STORE OPEN control, never a new primary navigation page. Writes derive and shared-lock the current OPEN Store Session; Phase 15 must extend this same surface and take the Session boundary exclusively for Close Store.

## Close Store has one reconciliation authority
`StoreSessionReconciliation` is the only source of pre-close blockers and exact Cash/Cashless expected balances for both the preview and `CloseStoreSession`; never re-derive totals in controllers, UI or tests. Expected = Opening + Payment rows − Store Expenses − corrections on non-voided Orders − all payments of voided Orders. Variance is always actual − expected; a shortage in either channel blocks with no note override or cross-channel offset. Close takes Branch → OPEN Store Session exclusively → QR Orders, recomputes everything, and is idempotent through the Audit idempotency key.

## Never guess a correction's refund source
Lower-total corrections record `cash_amount`/`cashless_amount`. A single-method Order is deterministic; a mixed-method correction requires the Cashier's explicit Cash portion, and a historical unallocated one blocks Close Store until allocated once through the audited allocation action. Never default to Cash, Cashless or a proportional split.
