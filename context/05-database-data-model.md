# PONGSKILOG POS V3 — Database & Data Model

**Status:** FROZEN — Batch 2  
**Database:** PostgreSQL via Supabase  
**ORM:** Eloquent

---

## 1. Data Model Goals

Support:

- Multi-branch operations
- Store Open/Close
- Opening/Closing Cash & Cashless
- Store purchases/expenses
- POS / QR orders
- Pay Now / Pay Later
- Inventory ledger
- Kitchen
- Staff branch assignments
- Cross-branch reporting
- Audit/control history
- QR Archive / Unclaimed lifecycle
- Realtime-friendly updates

Use UUIDs for primary business records where practical.

Use DB constraints/indexes in addition to application validation.

---

## 2. Identity / Access Tables

### `users`

- `id`
- `name`
- `email`
- `password`
- `is_active`
- timestamps

### `roles`

Examples:

- super_admin
- owner
- cashier
- kitchen_staff
- cashier_kitchen

### `permissions`

### `role_permissions`

### `user_roles`

### `user_branch_assignments`

- `user_id`
- `branch_id`
- `is_active`

Unique:

`(user_id, branch_id)`

---

## 3. Branch Tables

### `branches`

- `id`
- `code`
- `name`
- `status`
- `address`
- `contact`
- `operating_hours`
- timestamps

Unique:

- `code`

Statuses:

- active
- temporarily_closed
- inactive

### `branch_tables`

- `id`
- `branch_id`
- `name`
- `sort_order`
- `is_active`

Unique:

`(branch_id, name)`

---

## 4. Store Sessions

### `store_sessions`

- `id`
- `branch_id`
- `status`
- `opened_by_user_id`
- `opened_at`
- `opening_cash_amount`
- `opening_cashless_amount`
- `closing_cash_amount` nullable
- `closing_cashless_amount` nullable
- `expected_cash_amount` nullable
- `expected_cashless_amount` nullable
- `cash_variance` nullable
- `cashless_variance` nullable
- `closing_note` nullable
- `closed_by_user_id` nullable
- `closed_at` nullable
- timestamps

Statuses:

- open
- closed

DB must enforce:

**Only one OPEN Store Session per branch.**

Recommended:

PostgreSQL partial unique index on `branch_id` where `status = 'open'`.

---

## 5. Store Purchases / Expenses

### `store_session_expenses`

- `id`
- `branch_id`
- `store_session_id`
- `description`
- `amount`
- `payment_source`
- `note` nullable
- `receipt_image_path` nullable
- `created_by_user_id`
- timestamps

`payment_source`:

- cash
- cashless

### Optional inventory restock link

Use either a separate detail table or nullable fields:

Recommended:

### `store_session_expense_items`

- `id`
- `store_session_expense_id`
- `product_id`
- `quantity`
- timestamps

If present, closing the expense commit also creates inventory movement(s).

---

## 6. Catalog

### `categories`

- `id`
- `name`
- `sort_order`
- `is_active`

### `products`

- `id`
- `category_id`
- `name`
- `description`
- `default_price`
- `image_path`
- `is_active`
- timestamps

### `branch_products`

- `id`
- `branch_id`
- `product_id`
- `price_override` nullable
- `is_available`
- `tracks_inventory`
- `low_stock_threshold`
- timestamps

Unique:

`(branch_id, product_id)`

---

## 7. Modifiers

### `modifier_groups`

- `id`
- `name`
- `selection_type`
- `min_select`
- `max_select`
- `is_active`

### `modifier_options`

- `id`
- `modifier_group_id`
- `name`
- `price_delta`
- `is_active`
- `sort_order`

### `product_modifier_groups`

Maps products to modifier groups.

---

## 8. Orders

### `orders`

- `id`
- `branch_id`
- `store_session_id` attached at QR submission; nullable until commitment for direct POS
- `order_number` nullable for uncommitted Customer QR orders
- `reference_number` nullable for provisional QR and legacy rows
- `source`
- `order_type`
- `customer_label` nullable
- `branch_table_id` nullable
- `commercial_status`
- `payment_status`
- `payment_term` nullable
- `kitchen_status`
- `subtotal`
- `total`
- `created_by_user_id` nullable
- `loaded_by_user_id` nullable
- `submitted_at` nullable
- `archived_at` nullable
- `archive_reason` nullable
- `committed_at` nullable
- `completed_at` nullable
- `voided_at` nullable
- `version` default 1
- timestamps

`source`:

- pos
- customer_qr

`commercial_status` examples:

- draft
- submitted
- active
- completed
- voided
- archived_unclaimed

`payment_status`:

- unpaid
- partial
- paid

`payment_term`:

- immediate
- pay_later

`kitchen_status`:

- not_sent
- kitchen
- preparing
- ready
- done

QR submission:

- submitted
- unpaid
- not_sent
- no stock movement

Pay Later:

- active
- unpaid
- pay_later
- kitchen

Order number unique scope:

`(branch_id, order_number)`

New POS orders use a numeric `order_number` allocated from a per-branch locked counter. `reference_number` is a globally unique immutable audit identifier composed from the branch code, Asia/Manila business date, and an independent daily sequence (BRANCH-MMDDYY-####). Existing legacy numbers are not rewritten, and legacy rows may retain a null reference.

### `order_number_counters`

- `branch_id` primary/restrictive foreign key
- `next_number` positive bigint, default 1001
- timestamps

Allocation locks the persisted branch and its counter row. It does not derive the next number with `MAX(...) + 1`; historical numeric collisions are skipped without changing historical rows. Gaps are allowed when an early POS reservation is abandoned.

---

## 9. Order Items

### `order_items`

- `id`
- `order_id`
- `product_id` nullable
- `product_name_snapshot`
- `unit_price`
- `quantity`
- `line_total`
- `notes` nullable
- timestamps

### `order_item_modifiers`

- `id`
- `order_item_id`
- `modifier_option_id` nullable
- `group_name_snapshot`
- `option_name_snapshot`
- `price_delta_snapshot`
- `quantity`

Historical snapshots ensure receipts never change when catalog changes.

---

## 10. Payments

### `payments`

- `id`
- `branch_id`
- `store_session_id`
- `order_id`
- `method`
- `amount`
- `amount_received` nullable
- `change_amount` nullable
- `created_by_user_id`
- `idempotency_key`
- `paid_at`
- timestamps

Methods:

- cash
- cashless

Split = two payment rows committed atomically.

Unique:

- `idempotency_key`

---

## 11. Inventory

### `branch_inventory`

- `branch_id`
- `product_id`
- `on_hand`
- `low_stock_threshold`
- `version`
- `updated_at`

Unique/primary:

`(branch_id, product_id)`

### `inventory_movements`

- `id`
- `branch_id`
- `product_id`
- `order_id` nullable
- `store_session_expense_id` nullable
- `stock_transfer_id` nullable
- `movement_type`
- `quantity_delta`
- `reason` nullable
- `created_by_user_id` nullable
- `created_at`

Movement types:

- sale
- pay_later_commit
- order_edit_delta
- void_restore
- manual_adjustment
- store_purchase_restock
- transfer_out
- transfer_in

Signed delta:

- deduction = negative
- addition/restoration = positive

Never rewrite previous ledger rows.

---

## 12. Kitchen

### `kitchen_tickets`

- `id`
- `branch_id`
- `order_id`
- `status`
- `created_at`
- `updated_at`

Unique:

- `order_id`

Statuses:

- kitchen
- preparing
- ready
- done

Paid and Pay Later orders each get one operational ticket.

Pay Later settlement must not create another ticket.

---

## 13. Customer QR Session

### `customer_qr_sessions`

- `id`
- `branch_id`
- `token_hash`
- `active_order_id` nullable
- `expires_at`
- timestamps

No customer account required.

Do not use IP as primary identity.

---

## 14. QR Archive Lifecycle

Use existing `orders` status/fields rather than destructive deletion.

For no-action QR after 30 minutes:

- `commercial_status = archived_unclaimed`
- `archived_at = now()`
- `archive_reason = stale_30_minutes`

At Store Close:

- archive remaining active unclaimed QR
- `archive_reason = store_closed`

Archived records remain historical and have no inventory/Kitchen/payment side effect.

---

## 15. Stock Transfers

### `stock_transfers`

- `id`
- `source_branch_id`
- `destination_branch_id`
- `status`
- `requested_by_user_id`
- `sent_by_user_id` nullable
- `received_by_user_id` nullable
- timestamps

Statuses:

- requested
- sent
- received
- completed

### `stock_transfer_items`

- `id`
- `stock_transfer_id`
- `product_id`
- `quantity`

Completion creates:

- transfer_out
- transfer_in

---

## 16. Audit

### `audit_logs`

- `id`
- `branch_id` nullable
- `user_id`
- `action`
- `module`
- `auditable_type`
- `auditable_id`
- `before_data` JSONB nullable
- `after_data` JSONB nullable
- `metadata` JSONB nullable
- `created_at`

Must cover:

- Store Open
- Opening balances
- Store purchases/expenses
- Closing balances
- Variances
- Store Close
- Order edit
- Void
- Payment correction
- Inventory adjustment
- Transfer
- Access/settings changes

Audit rows are append-only in normal app behavior.

---

## 17. Settings

### `business_settings`

Business-wide values.

### `branch_settings`

Branch-specific values.

Frequently queried core values should remain typed columns instead of hiding everything in JSON.

---

## 18. Important Constraints

- Branch code unique
- One branch-product override per branch/product
- One branch inventory row per branch/product
- One open Store Session per branch
- One Kitchen ticket per operational order
- Payment idempotency key unique
- Quantity > 0 for order/transfer items
- Monetary values >= 0
- Source branch != destination branch
- Store Close only after business pre-check validation
- Negative stock blocked transactionally

---

## 19. Important Indexes

### Orders

- `(branch_id, created_at)`
- `(branch_id, commercial_status, created_at)`
- `(branch_id, payment_status, created_at)`
- `(branch_id, kitchen_status, created_at)`
- `(branch_id, archived_at)`
- `order_number`

### Payments

- `(branch_id, paid_at)`
- `order_id`

### Inventory Movements

- `(branch_id, product_id, created_at)`
- `order_id`
- `store_session_expense_id`

### Kitchen Tickets

- `(branch_id, status, created_at)`

### Store Sessions

- `(branch_id, opened_at)`
- `(branch_id, status)`

### Store Expenses

- `(branch_id, store_session_id, created_at)`

### Audit

- `(branch_id, created_at)`
- `(user_id, created_at)`
- `(module, created_at)`

---

## 20. Atomic Transaction Boundaries

### Open Store

- Verify no open session
- Create Store Session
- Save Opening Cash
- Save Opening Cashless
- Audit

### Pay Now

- Finalize order
- Save payment(s)
- Inventory movement(s)
- Update branch inventory
- Create Kitchen ticket

### Save Pay Later

- Finalize order
- Mark UNPAID / PAY LATER
- Inventory movement(s)
- Update inventory
- Create Kitchen ticket

### Edit committed order

- Update item snapshots
- Inventory delta movements
- Update inventory
- Update payment/balance state
- Audit

### Void

- Mark order voided
- Compensating inventory movement(s)
- Update inventory
- Audit

### Store Purchase / Restock

- Save expense
- If inventory-linked: create inventory movement + update balance
- Audit/history as required

### Close Store

- Revalidate no unresolved Pay Later
- Revalidate Kitchen all DONE
- Archive remaining unclaimed QR
- Calculate/store expected balances
- Save Closing Cash/Cashless
- Save variances/notes
- Close Store Session
- Audit

If any part fails, rollback and keep session Open.

---

## 21. Data Retention

Do not hard-delete historical:

- Paid orders
- Pay Later orders
- Payments
- Inventory movements
- Voids
- Audit logs
- Store Sessions
- Store purchases/expenses
- Archived QR orders
- Completed transfers

Catalog records may be disabled/soft-deleted while historical snapshots remain.


## Phase 10/11 implemented schema - 2026-09-22

Migration `2026_09_22_062703_add_customer_qr_sessions_and_tracking` creates the
frozen UUID `customer_qr_sessions` table. `token_hash` is a unique 64-character
SHA-256 digest; `active_order_id` is nullable and unique; `expires_at` is indexed.
Branch and Order foreign keys use restrictive deletion. The application issues a
seven-day, branch-named anonymous cookie (root path after the kiosk cutover) and enforces expiry on every
customer mutation/read authorization.

Added to `orders`: nullable `customer_qr_session_id`, unique nullable
`public_tracking_id` (64 random hexadecimal characters), UUID
`qr_idempotency_key`, `qr_intent_hash`, and `table_name_snapshot`.
`(customer_qr_session_id, qr_idempotency_key)` is unique. The queue index covers
branch/source/commercial status/submitted time. `order_item_modifiers` additionally
stores `modifier_group_id_snapshot`; existing name, semantic role, option, and
exact price snapshots remain authoritative.

Submit creates the existing Order aggregate as `customer_qr / submitted / unpaid /
null payment_term / not_sent`, with `submitted_at`, the current Store Session, and
no `committed_at`. Submit and LOAD create no Payment, inventory movement, or Kitchen
Ticket. LOAD records the winning cashier on this same Order; existing Pay Now or
Pay Later performs the single operational commitment without repricing snapshots.
Explicit Start new order clears the session pointer only after Done or archival.

Receipt expiry is derived from confirmed payment time plus 24 hours; it does not
delete the Order or financial records. Archive uses existing status/timestamp/reason
fields (`stale_30_minutes` or `cashier_archived`). Store Close integration remains
with the future Store Close implementation, which must honor the session lock
boundary. No additional order, payment, inventory, or Kitchen aggregate was added.


---

## Approved identity, lifecycle and Owner settings migrations - 2026-09-22

- `2026_09_22_093305_refine_customer_qr_identity_and_progress`: nullable orders.order_number with a CHECK allowing null only for uncommitted customer_qr; nullable qr_sequence with unique (store_session_id, qr_sequence); preparing_at/ready_at timestamps; customer_qr_order_counters keyed by store_session_id; order_reference_counters keyed by (branch_id, business_date). Both counters are locked, persisted and independent of each other and the existing short-number counter. Historical order/reference fields are not backfilled or rewritten.
- `2026_09_22_093958_add_branch_qr_settings_and_visits`: stable unique kiosk_code initialized from branch code; qr_ordering_enabled (default true); optional safe facebook_url/website_url; typed receipt_name/address/contact/footer and receipt_show_logo. customer_qr_visits contains only id, branch_id, customer_qr_session_id, visited_at, with branch/time and session/time indexes. Opens within two minutes deduplicate under the session lock; history returns timestamps only.
- `2026_09_22_102534_add_receipt_logo_path_to_branches_table`: nullable receipt_logo_path for validated branch-scoped branding stored on the configured S3 disk. Replacement/removal cleans up the old image; transaction failure cleans up the newly uploaded image. Public display streams only the persisted image through the branch logo endpoint.
- SQLite identity alteration reconstructs the original table DDL while preserving its existing CHECK constraints, foreign keys and explicit indexes. PostgreSQL uses ALTER COLUMN plus a CHECK. Rollback intentionally refuses when provisional null-number Orders exist rather than deleting history or fabricating official numbers; roll forward in that case. Fresh/up/down/reapply tests use isolated databases/schemas.
- Preparing/Ready represent actual transitions; rollback clears no-longer-reached stages. Receipt expiry remains derived, not a deletion deadline. Existing anonymous-session ownership authorizes prior paid receipts independently of active_order_id.

## Phase 12 additive transaction schema - 2026-09-22

- `orders.original_total` stores the first pre-edit committed total; `orders.edited_at` marks the latest committed edit. Existing `version` is the optimistic concurrency token.
- `payments.payment_group_id` groups the Cash and Cashless legs of one attempt; `payment_context` distinguishes initial, Pay Later settlement, and edit-balance settlement. Historical rows remain intact and can derive their group from the idempotency-key root.
- `order_adjustments` is append-only and records positive lower-total corrections with branch, Store Session, Order, actor, reason, and unique idempotency key.
- `payment_invoice_proofs` has exactly one private object per Cashless Payment row and stores disk/path plus safe file metadata and uploader. Replacement swaps the object without changing Payment history; failed DB work removes the new object.
- `audit_logs` is the canonical append-only mutation record with branch/user/module/action/auditable identity, before/after JSON, metadata, and an optional unique idempotency key.
- Migration `2026_09_22_125245_add_transaction_history_editing_support` is additive and has verified PostgreSQL up/down/reapply behavior.
