---
paths:
  - 'app/Actions/Orders/**'
  - app/Actions/Orders/TransitionKitchenOrder.php
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

## Kitchen transitions share the session boundary and serialize only their order
Lock the current OPEN StoreSession with sharedLock, then Order and KitchenTicket with lockForUpdate, in that order. Future Store Close must take the session exclusive lock first. Keep synchronized statuses and same-target idempotency; unrelated orders must complete while another order row is blocked.

## QR numbers are provisional until commercial commitment
Customer QR submission allocates only a per-Store-Session qr_sequence from customer_qr_order_counters. LOAD and Cancel LOAD never consume official identity. Pay Now/Pay Later atomically assign both official identifiers with payment/stock/Kitchen; direct POS keeps early reservation. New references use an independent branch/Manila-date counter and BRANCH-MMDDYY-####; preserve legacy identifiers.

## Committed order edits preserve histories and lock order
Committed-order mutations (and Pay Later settlement and correction allocation) require the Order's current OPEN Store Session, then lock Branch shared -> Session shared -> Order exclusive -> tracked Product inventory rows in sorted Product ID order -> Ingredient balances (Edit and Void take the Branch FOR SHARE first because POS commits hold Branch FOR UPDATE then the Session, and every movement insert needs a KEY SHARE on the Branch; the PostgreSQL harness races edit vs sale with zero deadlocks). Retained item/modifier configurations keep committed price/name snapshots; only new or materially reconfigured lines use current catalog values. Reconcile stock with one append-only order_edit_delta per tracked Product, money with append-only Payments/order_adjustments, and require expected version plus idempotency key.

## Loaded QR claims block Store Close
Store Close archives only submitted, unclaimed, uncommitted Customer QR orders with `archive_reason = store_closed`. A QR order still loaded by a Cashier is a pre-close blocker (complete payment or Cancel LOAD); Close never clears `loaded_by_user_id` or archives an in-progress claim.

## A loaded QR order accepts Cashier additions
After LOAD, the Cashier may add items (never edit or remove the customer's submitted items). Pay Now and Pay Later send them as `qr_additional_items`; `LoadedQrOrder::appendItems()` validates them through `OrderSnapshots::prepare()` and appends them to the locked order inside the same commit transaction (current prices; submitted prices unchanged), then the normal stock, Kitchen and payment effects cover the whole order. A replay must already contain every additional item it sends (else 409). The POS switches to the loaded order instantly with a client-side visit (`router.replace` with the LOAD response) and refreshes `loadedQr` / `qrWaitingCount` after it.
