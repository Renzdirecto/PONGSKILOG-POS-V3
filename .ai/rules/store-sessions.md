---
paths:
  - 'resources/js/{components,layouts}/**/*.{ts,tsx},app/Actions/StoreSessions/**/*.php'
---

# Store Sessions

## Store Session writers take the Branch first
Every Store Session writer (Store Expense/Purchase, Store Session inventory adjustment, Giveaway and its reversal, Pay Later settlement, correction allocation) locks the Branch FOR SHARE before the OPEN Store Session (share) and its rows. POS commits hold the Branch FOR UPDATE and then the Session, and each insert needs a KEY SHARE on the Branch, so Session-first writers deadlocked with Pay Now (reproduced as 40P01 in `tests/verify-operations-postgres.php` scenario S).

## Giveaway is its own Store Session action
Record giveaway (`RecordStoreSessionGiveaway`) sits beside Add expense / purchase and Adjust inventory. It is ₱0 revenue and never an Order, Payment, Store Expense or generic adjustment; it validates the line through `OrderSnapshots::prepare()` (no second customization engine), deducts Recipe Ingredients (base Size recipe + Add-on effects) or Product stock (never both, never below zero), snapshots selections and recipe basis, and is reversible once (`ReverseStoreSessionGiveaway`) only while its own Store Session is open, restoring exactly the recorded movements. Reports keep it out of Net Sales, COGS, expenses and Cash/Cashless; Operations shows it separately.

## Keep Store expenses inside the Current Store Session surface
Cashier Store Purchases / Expenses are opened from the existing LIVE / STORE OPEN control, never a new primary navigation page. Writes derive and shared-lock the current OPEN Store Session; Phase 15 must extend this same surface and take the Session boundary exclusively for Close Store.

## Close Store has one reconciliation authority
`StoreSessionReconciliation` is the only source of pre-close blockers and exact Cash/Cashless expected balances for both the preview and `CloseStoreSession`; never re-derive totals in controllers, UI or tests. Expected = Opening + Payment rows − Store Expenses − corrections on non-voided Orders − all payments of voided Orders. Variance is always actual − expected; a shortage in either channel blocks with no note override or cross-channel offset. Close takes Branch → OPEN Store Session exclusively → QR Orders, recomputes everything, and is idempotent through the Audit idempotency key.

## Never guess a correction's refund source
Lower-total corrections record `cash_amount`/`cashless_amount`. A single-method Order is deterministic; a mixed-method correction requires the Cashier's explicit Cash portion, and a historical unallocated one blocks Close Store until allocated once through the audited allocation action. Never default to Cash, Cashless or a proportional split.
