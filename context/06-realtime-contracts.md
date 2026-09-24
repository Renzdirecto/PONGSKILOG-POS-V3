# PONGSKILOG POS V3 — Realtime Contracts

**Status:** FROZEN — Batch 2  
**Technology:** Laravel Broadcasting + Reverb/WebSockets

---

## 1. Realtime Goal

Realtime keeps branch operations synchronized without manual refresh.

Realtime must be:

- Branch-scoped
- Secure
- Lightweight
- Based on committed DB state
- Recoverable after reconnect

PostgreSQL remains authoritative.

---

## 2. Core Rule

**DB transaction → COMMIT → realtime broadcast**

Never broadcast operational success before DB commit.

---

## 3. Channel Strategy

Recommended private branch channels:

### `private-branch.{branchId}.pos`

For:

- QR arrival/archive
- Ready notifications
- Store state
- Relevant order updates

### `private-branch.{branchId}.kitchen`

For:

- New Kitchen ticket
- Order updates
- Kitchen status

### `private-branch.{branchId}.customer-display`

For:

- Preparing
- Ready

No financial/private details.

### `private-branch.{branchId}.inventory`

For:

- Stock changes
- Low/out-of-stock
- Availability changes

### `private-user.{userId}`

For user-specific notifications.

### Management

Owner/Super Admin may use a business-wide management channel for lightweight summaries.

---

## 4. Customer QR Realtime

Customer QR tracking must not subscribe to broad branch channels.

Use a narrow tracking token/channel for the current order.

Concept:

`private-order-tracking.{publicTrackingId}`

The public tracking identifier/token must be high entropy and separately authorized.

---

## 5. Event Envelope

Recommended common fields:

```json
{
  "event_id": "uuid",
  "event_type": "kitchen.status_changed",
  "branch_id": "uuid",
  "entity_id": "uuid",
  "occurred_at": "ISO-8601",
  "version": 12
}
```

Send only necessary event-specific fields.

Avoid huge object payloads.

---

## 6. Versioning / Ordering

Mutable records expose a monotonic `version` or equivalent sequence.

Client rule:

- Ignore older version than currently applied.
- Refetch if state becomes inconsistent.

Prevents late events from reverting newer UI state.

---

## 7. Store Events

### `store.opened`

Audience:

- Same-branch POS
- Authorized management

Payload:

- branch_id
- store_session_id
- opened_at
- opened_by_user_id
- state = open

Do not broadcast opening balances to general branch clients.

### `store.closed`

Audience:

- Same-branch POS
- Customer QR availability state
- Authorized management

Payload:

- branch_id
- store_session_id
- closed_at
- closed_by_user_id
- state = closed

Do not broadcast Closing Cash/Cashless or variance to general operational clients.

### `store.reconciliation_updated`

Optional management-only event after close/authorized change.

Contains no unnecessary sensitive detail for normal staff.

---

## 8. Store Purchase / Expense Events

### `store.expense_recorded`

Audience:

- Same-branch authorized POS/management
- Inventory channel if restock-linked

Payload:

- expense_id
- store_session_id
- payment_source
- amount
- inventory_linked boolean

If restock-linked, related `inventory.changed` event follows after commit.

---

## 9. QR Events

### `qr.order_submitted`

Audience:

- Same-branch POS / QR Orders

Payload:

- order_id
- order_number
- order_type
- submitted_at

No inventory/Kitchen event yet.

### `qr.order_archived`

Audience:

- Same-branch POS / QR Orders

Payload:

- order_id
- order_number
- archive_reason
- archived_at

Reasons:

- stale_30_minutes
- store_closed

Archived QR produces no inventory/Kitchen/payment event.

---

## 10. Order Commitment Events

### `order.committed`

Emitted when operationally committed through:

- Pay Now
- Pay Later

Audience:

- Same-branch POS
- Authorized management

Payload:

- order_id
- order_number
- payment_status
- payment_term
- kitchen_status
- version

### `kitchen.ticket_created`

Audience:

- Same-branch Kitchen

Payload:

- order_id
- kitchen_ticket_id
- order_number
- order_type
- version

---

## 11. Pay Later Events

Save Pay Later may emit after commit:

- `order.committed`
- `inventory.changed`
- `kitchen.ticket_created`

Later settlement emits:

### `order.payment_updated`

Payload:

- order_id
- payment_status = paid
- outstanding_amount
- version

Do not emit another stock deduction or Kitchen ticket creation.

---

## 12. Kitchen Events

### `kitchen.status_changed`

Audience:

- Kitchen
- POS
- Private branch channels only

Payload:

- branch_id
- order_id
- order_number
- from
- to
- changed_at
- version

Allowed lifecycle:

- kitchen
- preparing
- ready
- done

Customer mapping:

- kitchen → In Kitchen
- preparing → Preparing
- ready → Ready
- done → Completed

This event is an operational invalidation signal. Kitchen and POS debounce
bursts, coalesce overlapping reloads, and refetch their authoritative
branch/session projections. Customer Display does not receive this payload
because it contains internal order identifiers and operational detail.

---

## 13. Customer Display

### `display.orders_changed`

Audience:

- Authorized same-branch Customer Display only

Payload:


```json
{
  "event_id": "uuid",
  "event_type": "display.orders_changed",
  "branch_id": "uuid",
  "occurred_at": "ISO-8601 timestamp"
}
```

This is a privacy-minimal invalidation signal, not an authoritative order
projection. It is emitted after a committed Pay Now, committed Pay Later, or
Kitchen lifecycle transition. The display debounces/coalesces signals and
refetches its order-number-only projection, including after reconnect.

Never include:

- Internal order IDs
- Order/customer/table/item details
- Prices
- Payment
- Customer private data
- Admin/audit fields

---

## 14. Order Edit Events

### `order.updated`

Audience:

- Same-branch POS
- Management
- Customer tracking when relevant

Payload:

- order_id
- order_number
- payment_status
- total
- version

### `kitchen.order_updated`

Audience:

- Same-branch Kitchen

Payload:

- order_id
- order_number
- version
- change_summary

Keep summary concise and operational.

---

## 15. Inventory Events

### `inventory.changed`

Audience:

- Authorized same-branch inventory/product views

Payload:

- branch_id
- product_id
- on_hand
- low_stock_threshold
- availability_state
- version

### `product.availability_changed`

Audience:

- Same-branch POS
- Same-branch Customer QR

Payload:

- product_id
- is_available
- effective_price optional
- version

Public QR should not receive raw stock quantity unless explicitly needed.

### `product.branch_configuration_changed`

Audience:

- Authorized same-branch POS and management clients

Payload:

- branch_id
- product_id
- is_available
- effective_price
- version

### Product and inventory implementation checkpoint — 2026-09-21

- `inventory.changed`, `product.availability_changed`, and `product.branch_configuration_changed` are implemented on the private `branch.{branch}.inventory` channel. Events implement the after-commit contract and contain compact IDs/state only; PostgreSQL remains authoritative.
- Inventory movements emit exactly one inventory event after their transaction commits. Product, Category, branch configuration, Group/Option, and Product image mutations emit the applicable compact catalog events after their complete mutation boundary succeeds.
- Active POS clients subscribe through Laravel Echo, coalesce bursts into one authoritative `catalog` partial reload, prevent simultaneous reloads, and perform a fresh catalog reload after reconnect. Cart, order type, payment state, open Product dialog, selected structured options, and manual notes remain local state.
- Channel authorization rechecks the authenticated active user, branch access, and an operational catalog/inventory permission. A normal branch event never fans out another branch's stock or configuration, and no public QR inventory subscription was added.

---

## 16. Void Events

### `order.voided`

Audience:

- Same-branch POS
- Authorized management

Payload:

- order_id
- order_number
- voided_at
- version

Do not broadcast sensitive authorization/reason details to normal operational clients.

The implemented event is `.order.voided` on private branch POS and Kitchen channels. It is dispatched only after commit and carries compact Order identity/version/time data. Audit management uses `.audit.recorded` on the private Super Admin-only `audit-trail` channel with only Audit identity and nullable Branch identity. Audit Trail and Void Orders use Echo as primary transport, poll every 10 seconds only while Echo is not connected, perform one authoritative refresh after reconnect/browser-online, and stop fallback polling while connected.

---

## 17. Payment Events

### `order.payment_updated`

Audience:

- Same-branch POS
- Owner/Super Admin
- Narrow customer tracking channel where applicable

Payload:

- order_id
- payment_status
- outstanding_amount
- version

Do not broadcast opening/closing balances or reconciliation detail to normal operational clients.

---

## 18. Branch / Product Events

### `branch.status_changed`

Audience:

- Assigned staff
- Management
- Customer QR state where needed

### `product.branch_configuration_changed`

Used for:

- Price override changes
- Availability changes
- Branch disable/enable

---

## 19. Close Store Event Flow

Close Store DB transaction commits:

- Pre-close checks pass
- Remaining unclaimed QR archived
- Closing Cash/Cashless recorded
- Expected balances recorded
- Variances/notes recorded
- Store Session closed
- Audit recorded

After commit, broadcast:

1. `qr.order_archived` for affected active QR items, or a compact queue refresh event
2. `store.closed`
3. Any management-only reconciliation refresh event if needed

Customer QR clients for branch immediately transition to Store Closed state.

---

## 20. Authorization

Branch private channel subscription requires:

- Active authenticated user
- Correct role/permission
- Correct branch assignment, unless Owner/Super Admin has business-wide access

Customer tracking uses narrow public-session authorization.

Customer Display uses safe branch-specific display authorization.

---

## 21. Reconnect

On reconnect:

1. Rejoin channels.
2. Refetch authoritative current state.
3. Resume incremental updates.

Examples:

- Kitchen refetch active tickets.
- POS refresh QR queue/current relevant state.
- Customer tracking refetch current order.
- Customer Display refetch Preparing/Ready.
- Cashier refetch Store Session state.

Do not assume all missed events were delivered.

---

## 22. Offline

If truly offline, block:

- Payment
- Save Pay Later
- Void
- Store Open
- Store Close
- Inventory-changing Store Purchase

Do not silently queue them for later execution.

---

## 23. Event Idempotency

Every event has unique `event_id`.

Duplicate delivery must not:

- Duplicate Kitchen cards
- Duplicate order cards
- Double-apply client inventory state
- Repeat irreversible UI effects

Backend write idempotency remains separate.

---

## 24. Performance

- Small payloads
- Branch-specific channels
- Avoid full catalogs/history broadcasts
- Send IDs + changed state
- Refetch details only when needed
- Avoid global fan-out for normal branch events
- Customer Display uses minimal safe projection
- Coalesce low-value management refreshes where useful

---

## 25. Frozen Initial Event Set

- `store.opened`
- `store.closed`
- `store.expense_recorded`
- `qr.order_submitted`
- `qr.order_archived`
- `order.committed`
- `order.payment_updated`
- `order.updated`
- `order.voided`
- `kitchen.ticket_created`
- `kitchen.order_updated`
- `kitchen.status_changed`
- `inventory.changed`
- `product.availability_changed`
- `display.orders_changed`
- `branch.status_changed`


## Phase 8/9 post-merge operational refinement

`KitchenTicketCreated`, `KitchenStatusChanged`, and `DisplayOrdersChanged` implement
`ShouldBroadcastNow`, `ShouldDispatchAfterCommit`, and `ShouldRescue`. Laravel 13
waits for the outer database commit, then sends the compact signal synchronously
without generic queue pickup. Rollbacks send nothing; transport failures are
reported without failing the committed business operation. Other catalog events
retain their existing queued delivery and 160 ms debounce.

Kitchen, POS Ready, and Customer Display coalesce operational invalidations over
35 ms. A single refresh stays in flight, with one trailing refresh for later
signals, bounded event-ID deduplication, branch checks, and reconnect recovery.
All cards, Ready details, and display numbers still come from authoritative server
projections; Customer Display broadcasts still contain only identity, branch, and
time. No polling or full-order broadcasts were added.

KDS status writes use independent JSON PATCH requests through Inertia's XHR client
and Wayfinder, not navigation visits. An order-scoped optimistic overlay updates
status, filters, and count deltas immediately. Only that order blocks duplicate
clicks. Confirmed versions protect against stale refreshes; a rejected request
rolls back only its own overlay and shows a toast. The shared server action remains
authoritative; existing POS redirect/flash responses remain supported. PA SERVE is
queued once per successful changed Ready result, never for an optimistic click,
failure, duplicate target, or projection refresh.

Lock ordering is OPEN Store Session (shared), Order (exclusive), KitchenTicket
(exclusive). Different orders can proceed while an unrelated order is blocked.
A future Store Close must acquire the session's exclusive lock before changing
status or locking orders. Same-order idempotency and one-version-per-change remain
mandatory. The isolated PostgreSQL harness verifies both concurrency cases and
that an exclusive close boundary blocks then rejects a transition.


## Phase 10/11 customer QR delivery - 2026-09-22

All three new event classes use `ShouldBroadcastNow`, `ShouldDispatchAfterCommit`,
and `ShouldRescue`: delivery follows the outer commit without queue-worker pickup;
rolled-back writes emit nothing; transport failures are reported without undoing
successful business writes. Exact request replays do not emit duplicate lifecycle
signals.

| Event | Private audience | Payload |
| --- | --- | --- |
| `qr.order_submitted` | `branch.{branchId}.pos` | event ID/type, branch ID, Order ID/qr_number/type, submitted time, version |
| `qr.order_loaded` | same authorized POS branch | same compact identity/version envelope; removes a competing LOAD from waiting queues |
| `qr.order_archived` | same authorized POS branch | event ID/type, branch ID, Order ID/qr_number, archive reason/time, version |
| `order.tracking_changed` | `order-tracking.{publicTrackingId}` | event ID/type, public tracking ID, occurrence time, version only |
| `qr.catalog_changed` | `qr-catalog.{branchId}` | event ID/type, branch ID, occurrence time only |

Tracking invalidates after LOAD, Pay Now, Pay Later commit, later settlement,
Kitchen transitions, and archive. Customer-safe catalog invalidation follows
existing product/category/group/option/branch configuration and inventory write
paths, Store Open, and branch updates. Customer pages do not subscribe to staff
POS, Kitchen, inventory, management, or Customer Display channels.

`POST /qr/{branch}/broadcasting/auth` uses the encrypted HttpOnly anonymous cookie,
CSRF protection, expiry, and branch/session ownership. It signs only that branch's
customer-safe catalog channel or an owned high-entropy tracking channel. Knowing
an Order number or tracking ID alone grants no access. Event payloads contain no
customer labels, item lists, payment data, exact stock quantities, session token,
or token hash for customer audiences.

Customer tracking/catalog and staff queue/badge refreshes coalesce over 35 ms,
allow one request in flight with a trailing refresh, deduplicate bounded event IDs,
and refetch authoritative projections after reconnect. Catalog refresh preserves
unsubmitted cart intent and open customization. Offline/disconnected states expose
Refresh/Retry and never pretend that a write succeeded. No periodic polling or
simulated kitchen progress is used. Browser-to-frame latency remains manual QA;
automated delivery/rollback/failure and PostgreSQL concurrency checks are covered.


---

## Approved QR refinement invalidations - 2026-09-22

Before commitment, QR staff events expose qr_number (QR-01), not order_number. Added qr.order_released and qr.order_restored on the same authorized private branch POS channel. Cancel and restore emit customer tracking invalidation as well. QR toggle changes emit customer catalog invalidation. Official committed Order/Kitchen/Display paths continue using operational identity.

Immediate after-commit delivery, transport rescue, compact payloads, event deduplication, bounded coalesced refetch, reconnect refresh and branch/session isolation remain. No HTTP polling was added. The queue has one client clock for all elapsed labels. Normal connected QR queue/tracking/receipt screens omit manual Refresh controls; recovery remains in unavailable/error states.

## Phase 12 transaction invalidations - 2026-09-22

`order.updated` is a compact after-commit event on the branch POS private channel with event/entity/order IDs, Order version, changed-domain names, and occurred time. It carries no item, finance, proof, or Audit payload; History coalesces and refetches server projections and also refetches after reconnect.

`kitchen.order_updated` is a compact after-commit event on the branch Kitchen private channel. KDS refetches its authoritative ticket and briefly marks the affected Order `UPDATED`; the existing KitchenTicket identity, status, and lifecycle timestamps are unchanged. Settlement emits `order.updated` for the payment domain and the existing customer tracking invalidation.

## Phase 14 Store Session expense invalidation - 2026-09-23

`store.expense_recorded` is an immediate, rescued, after-commit invalidation on `private-branch.{branchId}.store-session`. Its compact payload contains event/type/Branch/expense/Store Session identity, payment source, amount, inventory-linked boolean, and occurrence time; it contains no note, receipt path, actor, balance, variance, or Audit detail. Authorized Cashier clients with the Store Session dialog open coalesce the signal and refetch the authoritative current-session projection, including after reconnect. Exact replay and rolled-back writes emit no duplicate success signal. Linked restocks retain the existing inventory/catalog invalidations from `ApplyInventoryMovement` rather than broadcasting a second inventory event.

## Phase 15 Store Close delivery - 2026-09-23

`store.closed` is an immediate, rescued, after-commit event on `private-branch.{branchId}.pos`, `.kitchen` and `.store-session`. Payload: event ID/type, branch ID, Store Session ID, `closed_at`, `closed_by_user_id`, `state = closed`, occurred time; no balances, variances, note or Audit detail. Operational layouts reload authoritative Store state; clients other than the closing Cashier close the stale Store Session dialog. Because the event is broadcast before the HTTP response returns, the closing client records the Store Session it is closing before sending the request (cleared on a definitive failure) so its own `store.closed` never dismisses the pending summary. The same commit emits `qr.order_archived` and `order.tracking_changed` per archived QR order, `qr.catalog_changed` for Customer QR availability, and `display.orders_changed` for Customer Display. Exact replay and rolled-back closes emit nothing. `store.reconciliation_updated` was not added; reconciliation reaches Super Admin through the existing Audit Trail broadcast. While Close Store is open, compact order, Kitchen, QR and expense events debounce an authoritative preview refetch; no client arithmetic uses event payloads.

## Phase 16E Operations invalidation - 2026-09-24

Operations pages reuse the private `reports` invalidation channel and `useReportsRealtimeRefresh` (Branch filter, debounce, hold during the page's own visits, 30s fallback only while disconnected). No new channel or event class was added. Existing signals already cover sale consumption, edit delta, void restoration and Pamamalengke confirmation (`order.committed`, `order.updated`, `order.voided`, `store.expense_recorded`); ingredient-only changes dispatch `ReportsChanged` with reasons `ingredients.wastage`, `ingredients.count_corrected` and `ingredients.opening_balance`. Payloads stay identity/time only — no quantities, costs or money. Broadcast failure never undoes the committed transaction.
