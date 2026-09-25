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
- `position` (nullable `varchar(100)`, Phase 18 Manual QA refinement #1): business/job title for display only; never a source of access
- timestamps

### `roles`

System roles (machine `name` never changes):

- super_admin
- owner
- cashier
- kitchen_staff
- cashier_kitchen

Since Phase 18 final: `label`, `is_system`, `scope` (`branch` / `business`), `archived_at`; Custom Roles use the stable key `custom_{id}` (see the Phase 18 final section below).

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

### `order_voids`

- UUID primary key with restrictive Branch, Store Session, Order, initiator, and authorizer relations
- One row per Order and one row per idempotency key
- Stable reason code/label, nullable Other detail, `super_admin_pin` authorization method, and timestamps
- Original Order, item, Payment, Kitchen, adjustment, and inventory movement history is retained

### `void_authorization_settings`

- One row for unique scope `global`
- Hashed four-digit PIN only; no plaintext PIN column
- Restrictive configuring Super Admin relation and required configuration timestamp

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

## Phase 14 Store Session expenses - 2026-09-23

- `store_session_expenses` is append-only current-session financial history: UUID identity, restrictive Branch/Store Session/actor parents, positive `numeric(14,2)` amount, constrained Cash/Cashless source, optional note, private receipt metadata, unique client idempotency UUID, and canonical intent hash. Session/source and bounded-history indexes support full-session exact aggregates plus newest-first display.
- `store_session_expense_items` permits at most one optional tracked Product and positive integer quantity per expense. A linked purchase calls the existing inventory movement primitive with `store_purchase_restock`; `inventory_movements.store_session_expense_id` has a restrictive PostgreSQL foreign key. Normal expenses create no item, movement, or balance change.
- Cash and Cashless totals are SQL aggregates over the complete current Store Session, independent of the bounded newest 50 records. Earlier sessions and other Branches are excluded. These separate totals are Phase 15 reconciliation inputs; no closing calculation is implemented here.
- Fresh/rollback/reapply passed on disposable SQLite and isolated PostgreSQL. PostgreSQL also verified positive checks, exact money, uniqueness, restrictive linkage, concurrent retry/restock behavior, and the future exclusive Store Session close boundary.

## Phase 15 Close Store reconciliation schema - 2026-09-23

- Migration `2026_09_23_053920_add_store_close_reconciliation_support` is additive. `order_adjustments` gains nullable `cash_amount`/`cashless_amount` (`numeric(14,2) >= 0`); PostgreSQL also enforces that both are null or both are set and sum to `amount`. Historical rows stay null; a mixed-method null row is allocated once from an explicit, audited Cashier answer and is otherwise immutable.
- `store_sessions` gains nullable JSON `reconciliation_snapshot` (opening, sales, split, expenses, corrections, voids, expected, actual, variance, QR archived count, zero blocker counts). The `expected_cash_amount`/`expected_cashless_amount >= 0` CHECKs are removed so expected balances keep exact signed math; opening and closing CHECKs remain. Rollback refuses while negative expected balances or allocated corrections exist.
- Closed Store Sessions are immutable at the model layer. A status-consistency CHECK was deliberately not added because existing fixtures close sessions with a bare status update; `CloseStoreSession` always writes the complete closing record.

Migration `2026_09_23_074723_add_store_session_index_to_order_adjustments_table` adds `(store_session_id, order_id)` on `order_adjustments` so close-time correction aggregates stay scoped to one session. Payments (`store_session_id`), Orders (`store_session_id, qr_sequence`), and expenses (`store_session_id, payment_source`) reuse existing indexes.

## Store Session inventory adjustments - 2026-09-23

Migration `2026_09_23_132208_create_store_session_inventory_adjustments_table` adds an append-only `store_session_inventory_adjustments` record (Branch, Store Session, Product, unique `inventory_movement_id`, reason code `complimentary|wastage|damaged|staff_meal|other`, positive quantity, optional note, actor, unique idempotency key, intent hash). The stock change itself is a canonical `manual_adjustment` movement written through `ApplyInventoryMovement`; no Store Expense, Payment, or reconciliation value is created, so Store Close financial totals are unaffected.

## Phase 16E Owner Operations schema - 2026-09-24

Migration `2026_09_24_053738_create_owner_operations_tables` is additive (the only change to an existing table is `products.no_recipe_needed boolean default false`). Rollback drops only the new tables and column.

- `operation_plans` (UUID, name, description, icon, `archived_at`, creator) and `operation_plan_products` (UUID, Plan, **unique `product_id`** — one active Plan per Product). `operation_plan_ingredients` (unique Plan + Ingredient) lets one Ingredient appear in many Plans.
- `ingredients`: unique name, icon, CHECKed base unit, `target_quantity numeric(18,4) >= 0`, purchase unit name, `purchase_unit_size numeric(18,4) > 0`, `purchase_unit_cost numeric(14,2) >= 0` (null = unknown), `replenishment_rule` (`top_up|reorder|none`), `reorder_point numeric(18,4)`, `archived_at`, creator/updater.
- `branch_ingredient_stocks`: the one balance per **unique (branch_id, ingredient_id)** — `on_hand numeric(18,4)` (may be negative), `version`. Written only by `ApplyIngredientMovement`, locked in Ingredient-id order.
- `ingredient_movements` (append-only; model forbids update/delete): Branch, Ingredient, type, `quantity_delta numeric(18,4) <> 0`, `balance_after`, signed `estimated_cost_cents` (null = unknown), Order, Order recipe snapshot, Plan snapshot, pamamalengke purchase, Store Session expense, reason code/text, actor, unique nullable idempotency key. Partial unique indexes `ingredient_movements_sale_once` and `ingredient_movements_void_once` on (snapshot, Ingredient) for `sale_consumption` / `void_restoration`. Indexes: (branch, ingredient, created_at), (branch, created_at), order, purchase.
- `recipes` (unique Product + `size_key` = size option id or `base`) with `recipe_lines` (unique recipe + Ingredient, `quantity numeric(18,4) > 0`). Recipe edits replace lines; history lives in snapshots.
- `order_recipe_snapshots` (immutable; unique Order + Product + size key): recipe state (`recipe|missing|not_needed`), product/size name snapshots, Plan snapshot. `order_recipe_snapshot_lines`: per-unit quantity and the purchase-unit cost basis (`cost_basis_cents`, `cost_basis_quantity`) in force at commitment. Keyed by Order + Product/size (not Order Item ids), because committed edits replace Order Items.
- `pamamalengke_purchases` (immutable): Branch, Store Session, Plan, **unique `store_session_expense_id`** (the canonical expense), estimated total + completeness, note, buyer, unique idempotency key, intent hash. `pamamalengke_purchase_items`: ingredient/manual line, recommended vs actual quantity, estimated vs actual unit cost, line total, purchase-unit size, exact base quantity, unique restock movement.
- `pamamalengke_list_entries`: the next run's manual items and skip marks per Branch + Plan (working list, cleared on confirm).
- Verified on SQLite (Pest) and an isolated PostgreSQL schema (`tests/verify-operations-postgres.php`: fresh, rollback, reapply, numeric types, partial indexes). The normal development database only needs a forward `php artisan migrate`.

### Phase 16E follow-up: Add-on / Modifier Ingredient effects (additive)

Migration `2026_09_24_072528_add_product_modifier_effects` adds four tables; no existing column, row, constraint or `semantic_role` value changes. Long constraint names are explicit (PostgreSQL truncates identifiers at 63 bytes).

- `product_modifier_effects` (UUID, Product, Modifier option, updater; **unique Product + option**) and `product_modifier_effect_lines` (unique effect + Ingredient, `quantity numeric(18,4) > 0`). Current configuration only; Business-wide definition, Branch-specific stock.
- `order_recipe_snapshot_modifiers` (immutable; unique snapshot + option; option/group name snapshots) and `order_recipe_snapshot_modifier_lines` (`quantity_per_selection numeric(18,4) > 0`, cost basis like recipe snapshot lines). A modifier snapshot without lines records "no Ingredient effect". Add-on usage is merged into the Product/size snapshot's per-Ingredient movements, so the existing sale/void partial unique indexes still apply.

### Phase 16E Final QA: Store Session Giveaways (additive)

Migration `2026_09_24_134328_create_store_session_giveaways`:

- `store_session_giveaways`: `branch_id`, `store_session_id`, `product_id` (restrict), `product_name_snapshot`, `size_key`, `size_name_snapshot`, `selections` (JSON: group, role, option snapshots), `quantity` (CHECK > 0), `stock_mode` (`recipe` / `product_stock` / `none`), `stock_basis` (JSON per-serving base recipe + Add-on effects), `inventory_movement_id` (unique, nullable), `reason_code`, `note`, `created_by_user_id`, `idempotency_key` (unique), `intent_hash`, timestamps. Immutable (model guards).
- `store_session_giveaway_reversals`: `giveaway_id` (**unique** → at most one reversal), `branch_id`, `store_session_id`, `inventory_movement_id` (unique, nullable), `reason`, actor, `idempotency_key` (unique), `intent_hash`. Immutable.
- `ingredient_movements.store_session_giveaway_id` (indexed; FK on PostgreSQL) and partial unique indexes `ingredient_movements_giveaway_once` / `ingredient_movements_giveaway_reversal_once` on (`store_session_giveaway_id`, `ingredient_id`).
- `movement_type` CHECK constraints of `ingredient_movements` and `inventory_movements` extended with `giveaway` and `giveaway_reversal` (PostgreSQL constraint swap; SQLite definition-preserving rebuild). Rollback refuses while giveaway history exists.
- Known PostgreSQL identifier truncation (functional, no collision; guarded against new ones by the harness): `operation_plan_ingredients_…_uniq`, `order_recipe_snapshot_lines_…_ingredient`, `pamamalengke_list_entries_…_entry_typ` and three pre-existing `store_session_inventory_adjustments_*` names.

## Phase 18 — Access Control and Notifications (additive) — 2026-09-25

Migration `2026_09_24_165603_create_user_permission_overrides_table`:

- `user_permission_overrides`: `id`, `user_id` (FK users, cascade), `permission_id` (FK permissions, cascade), `effect` `varchar(5)` CHECK (`allow`, `deny`), timestamps; **unique (`user_id`, `permission_id`)**, index `permission_id`. No row = INHERIT. Users are deactivated, never deleted, so the cascade removes only exceptions of a genuinely removed row; audit history is untouched.

Migration `2026_09_24_165604_create_notifications_table` (Laravel's standard database notifications table):

- `notifications`: UUID `id`, `type` (`admin.access`, `admin.staff`, `admin.stock`), morph `notifiable`, JSON-text `data` (`category`, `title`, `body`, `url`), `read_at`, timestamps; index (`notifiable_type`, `notifiable_id`, `read_at`) for the unread badge.

Role baselines keep using `role_permissions`; Cashier + Kitchen rows are always the union of Cashier and Kitchen Staff. Index names stay under PostgreSQL's 63-byte limit (checked by `tests/verify-access-admin-postgres.php`).

## Phase 18 final — Custom Roles (additive) — 2026-09-25

Migration `2026_09_25_052453_add_custom_role_metadata_to_roles_table` (additive; existing rows and assignments are kept):

- `roles.label` `varchar(60)` nullable — display name. System roles are backfilled (`Super Admin`, `Owner`, `Cashier`, `Kitchen Staff`, `Cashier + Kitchen`); any other pre-existing row gets its `name`.
- `roles.is_system` boolean, default false — backfilled true for the five canonical names. A System role is always identified by its canonical `name`; its stored metadata never widens it.
- `roles.scope` `varchar(16)` nullable, CHECK (`branch`, `business`) — WHERE a role works. System scope is canonical by name (Owner/Super Admin business, the three operational roles Branch).
- `roles.archived_at` timestamp nullable — an archived Custom Role keeps its row and baseline for audit meaning and cannot be assigned.
- Partial unique index `roles_active_label_unique` on `LOWER(label) WHERE archived_at IS NULL` (PostgreSQL and SQLite): active display names are unique ignoring case; an archived name may be reused.
- `roles.name` stays the unique machine key. A Custom Role is inserted with a temporary key and renamed to `custom_{id}` in the same transaction, so renaming the display name never changes identity. No UUID was added; the Role PK is the identity.
- Custom Role baselines use the existing `role_permissions`; assignments use the existing `user_roles` and `user_branch_assignments`. Rollback drops the index then the four columns (verified on disposable PostgreSQL and isolated SQLite).


## Phase 18 pass #2.1 — Branch-owned catalog configuration and Operations — 2026-09-25

Forward migration `2026_09_25_112126_make_branch_catalog_and_operations_independent` (one authoritative model after cutover; no legacy shared rows remain):

- `branch_products.no_recipe_needed` boolean (Branch recipe mode); `products.no_recipe_needed` dropped. A `branch_products` row is now explicit assortment membership (unique `branch_id, product_id` unchanged).
- `branch_id` (NOT NULL, FK) on `ingredients`, `operation_plans`, `recipes`, `product_modifier_effects`, `operation_plan_products`, `operation_plan_ingredients`; `lineage_id` (NOT NULL) on `ingredients` and `operation_plans` (copy provenance: own id when created, source lineage when copied).
- Uniques: `ingredients (branch_id, lower(name))` (replaces global `name`), `ingredients/operation_plans (branch_id, lineage_id)` and `(branch_id, id)`; `recipes (branch_id, product_id, size_key)`; `product_modifier_effects_branch_unique (branch_id, product_id, modifier_option_id)`; `operation_plan_products (branch_id, product_id)` (a Product may sit in a different Plan per Branch). Indexes: `operation_plans (branch_id, archived_at, name)`, `operation_plan_ingredients (branch_id, ingredient_id)`, `product_id` on recipes/effects/plan products.
- PostgreSQL composite FKs `(branch_id, ingredient_id) → ingredients (branch_id, id)` on `branch_ingredient_stocks`, `ingredient_movements`, `operation_plan_ingredients`, `pamamalengke_list_entries`, and `(branch_id, operation_plan_id) → operation_plans (branch_id, id)` on `operation_plan_ingredients`, `operation_plan_products`, `pamamalengke_list_entries` — a cross-Branch reference is rejected by the database.
- Cutover: explicit rows for every existing Branch × Product (existing rows kept). The oldest Branch keeps the original Ingredient/Plan/Recipe/effect ids; each other Branch gets copies through an explicit old → new id map, and its stock, movements (Ingredient and Plan ids), working list, purchases/purchase items, Order recipe snapshots (lines, add-on lines, Plan) and Giveaway recipe basis are re-pointed to its own copies. Quantities, balances, costs, totals and timestamps are untouched. `down()` works only while no Branch-owned setup exists (it refuses to merge Branch configurations).
