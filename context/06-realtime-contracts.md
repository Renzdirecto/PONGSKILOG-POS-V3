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
