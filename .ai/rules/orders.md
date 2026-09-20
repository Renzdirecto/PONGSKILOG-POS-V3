---
paths:
  - 'app/Actions/Orders/**'
---

# Orders

## Pay Now atomicity and root idempotency
PayNowOrder owns one outer transaction for new draft or existing snapshot, payment legs, inventory Sale movements and initial Kitchen ticket. Never reprice persisted drafts. Lock the payment root before branch/order work on PostgreSQL: cash and cashless leg keys alone do not prevent the same root racing across branches with different methods. Replay must verify current cashier/branch and original order/cart/tender before returning persisted success; broadcasts occur only after commit.

## Keep Pay Later activation and settlement effects separate
Pay Later activation owns the order state transition, PayLaterCommit inventory movements, and the single kitchen ticket in one transaction, keyed by the order-level activation idempotency key. Later settlement may only create exact payment legs and mark the order paid; it must never reapply inventory or kitchen effects, and an exact replay produces no new effects or events.

## Pay Later commits reservations or drafts atomically
CommitPayLaterOrder must accept either the current empty POS reservation plus local cart details or an existing persisted draft. Cart hydration, inventory deduction, the single Kitchen ticket, Store Session attachment, and the active/unpaid/pay_later transition belong to one outer transaction while preserving order identity and the activation key.

## Pay Later replay validates the original intent
An exact-key Pay Later replay may recover only when any supplied local cart still matches the committed snapshot (order type, customer/table, products, quantities, notes, and option IDs). Reject changed payloads with HTTP 409, and never compare current catalog prices when replaying a saved draft.
