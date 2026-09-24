# PONGSKILOG POS V3 — Architecture

**Status:** FROZEN — Batch 2  
**Depends on:** Frozen Batch 1 (`01-project-overview.md`, `02-business-rules.md`, `03-user-flows.md`)

---

## 1. Architecture Goal

PONGSKILOG POS V3 uses a centralized cloud architecture for one PONGSKILOG business with multiple branches.

Priorities:

- Fast POS operation
- Strong branch isolation
- Reliable payments and inventory
- Realtime Kitchen / QR / Customer Display updates
- Auditable Store Open/Close and sensitive actions
- Remote Owner/Super Admin management
- Performance-first product image delivery
- Simple scaling without premature microservices

---

## 2. Application Architecture

Use:

**Laravel modular monolith + Inertia**

Core stack:

- Laravel 13
- PHP 8.4
- React 19
- Inertia.js 3
- TypeScript
- Tailwind CSS 4
- Vite 8
- PostgreSQL via Supabase
- Eloquent ORM
- Redis
- Laravel Reverb / Broadcasting
- Supabase Storage
- Railway

Do not introduce a separate Node/Express backend.

---

## 3. Main Modules

### Identity & Access

- Authentication
- Roles
- Permissions
- Branch assignments
- Active branch context
- Policies/Gates
- Super Admin restrictions

### Branches & Store Sessions

- Branch records/status
- Browse vs Open Store
- Opening Cash
- Opening Cashless
- Store Open detection
- Store purchases/expenses
- Closing Cash
- Closing Cashless
- Reconciliation
- Store Close
- One active Store Session per branch

### Catalog

- Categories
- Products
- Images
- Modifiers
- Global product definitions
- Branch product overrides

### Orders / POS

- Dine In / Take Out
- Cart/order creation
- Order items/modifiers
- Pay Now
- Pay Later
- Recorded-order edits
- QR LOAD

### Payments

- Cash
- Cashless
- Split
- Pay Later settlement
- Delta payments
- Payment history
- Idempotency

### Inventory

- Branch stock
- Inventory ledger
- Pay Now deduction
- Pay Later deduction
- Edit delta
- Void restoration
- Store purchase restock
- Manual adjustment
- Branch transfer

### Kitchen

- Kitchen ticket
- KITCHEN → PREPARING → READY → DONE
- Order update notifications
- Customer Display projection

### Customer QR

- Branch-bound QR session
- Store Open/Closed state
- Menu
- Unpaid QR submission
- Tracking
- Receipt
- 30-minute Archive / Unclaimed behavior

### Reporting

- Per-branch reports
- All-branch Owner reports
- Store Session summaries
- Sales/orders/payment mix
- Inventory/operational reporting

### Audit

- Store Open
- Opening balances
- Store purchases/expenses
- Closing balances
- Variance
- Store Close
- Order edits
- Voids
- Access/settings changes

---

## 4. Core Request Pattern

Read:

**Browser → Laravel/Inertia → PostgreSQL → Inertia response**

Critical write:

**Client → authorization → validation → DB transaction → commit → realtime broadcast**

Never broadcast success before commit.

---

## 5. Branch Context

Every operational request must be branch-scoped.

Backend validates:

1. User is authenticated and active.
2. Required role/permission exists.
3. User is assigned to requested branch, unless business-wide Owner/Super Admin access applies.
4. Record belongs to that branch.
5. Branch is in valid state.
6. Store Session is Open if mutation requires active operations.

Normal branch staff must never query all branches.

---

## 6. Store Session Architecture

A Store Session represents one operational opening of one branch.

Rules:

- Maximum one active Store Session per branch.
- First authorized Cashier opens it.
- Opening Cash recorded once.
- Opening Cashless recorded once.
- Additional Cashiers reuse the same open session.
- Browse mode remains read-only while no open session exists.

### Close Store

Close Store runs only after pre-close validation:

- No unresolved UNPAID / PAY LATER.
- All committed Kitchen orders = DONE.
- Unclaimed QR orders do not block closing.

Close flow records:

- Session purchases/expenses
- Closing Cash
- Closing Cashless
- Expected Cash
- Expected Cashless
- Variances
- Required notes
- QR archive actions
- Closed timestamp/user

### Variance

- Exact → normal close.
- Shortage → normal close blocked until corrected.
- Overage → allowed with required note/reason.

Close Store is atomic. If it fails, Store Session stays Open.

---

## 7. QR Archive Architecture

Initial QR submission:

- Unpaid
- No inventory deduction
- No Kitchen ticket

If untouched for 30 minutes:

- Mark/move to Archived / Unclaimed
- Remove from active operational queue
- Retain record for history

At Store Close:

- Archive any remaining active unclaimed QR submissions

Archived QR records do not create payment, inventory, or Kitchen effects.

---

## 8. Order Commitment Model

A draft/cart has no stock/Kitchen effect.

A QR submission has no stock/Kitchen effect.

Operational commitment happens through:

### Pay Now

One transaction writes:

- Final order
- Payment record(s)
- Inventory movements
- Branch inventory balance
- Kitchen ticket

### Pay Later

One transaction writes:

- Final order
- `UNPAID / PAY LATER`
- Inventory movements
- Branch inventory balance
- Kitchen ticket

When Pay Later is paid later:

- Add payment record(s)
- Update payment status

Do not deduct stock or create Kitchen ticket again.

---

## 9. Order Editing

Recorded-order edits run through a domain/service operation.

For inventory-affecting edit:

1. Load committed order.
2. Authorize role + branch.
3. Calculate item deltas.
4. Apply inventory deltas.
5. Update order snapshot.
6. Update payment/balance state if needed.
7. Create audit record.
8. Notify Kitchen if relevant.
9. Commit.
10. Broadcast after commit.

Never erase earlier inventory movements.

---

## 10. Payment Architecture

Payments are separate append-only records.

One order may have multiple payment records due to:

- Split payment
- Pay Later settlement
- Delta payment after edit

High-risk payment confirmation uses idempotency key.

---

## 11. Inventory Architecture

Use:

### Current Balance

Fast lookup by:

- Branch
- Product

### Inventory Ledger

Append-only movement history.

Movement reasons include:

- sale
- pay_later_commit
- order_edit_delta
- void_restore
- manual_adjustment
- store_purchase_restock
- transfer_out
- transfer_in

Current balance and movement ledger must update in same DB transaction.

Use row locking or equivalent concurrency-safe strategy.

Negative stock blocked by default.

---

## 12. Store Purchases / Expenses Architecture

Store purchases/expenses belong to an active Store Session.

Each entry stores:

- Branch
- Store Session
- Description
- Amount
- Payment source: Cash / Cashless
- Note/reason
- Optional receipt image
- Optional linked inventory product/quantity

If linked to inventory restock:

- Record expense
- Increase inventory
- Create inventory movement

Cash/Cashless source affects expected closing balance.

---

## 13. Product / Image Architecture

Products are global with branch overrides.

Product images use Supabase Storage.

Database stores metadata/path only.

Use:

- Optimized card thumbnails
- Correct dimensions
- WebP/AVIF where practical
- Lazy loading
- Browser/CDN caching
- Queue-based optimization after upload

Image processing must never block POS operation.

---

## 14. Realtime Architecture

Use Laravel Broadcasting + Reverb.

Realtime is branch-scoped.

Examples:

- Store Open/Close state
- QR arrival/archive
- Kitchen ticket creation
- Kitchen status
- Customer Display
- Product availability
- Inventory alerts

Detailed contracts live in `06-realtime-contracts.md`.

---

## 15. Queue / Redis

Use Redis for:

- Queues
- Cache
- Realtime support where configured

Good queue candidates:

- Image optimization
- Report export
- Non-critical notifications
- QR stale/archive maintenance jobs

Do not queue authoritative transaction commits for:

- Payment
- Pay Later
- Inventory deduction
- Void
- Store Open
- Store Close

---

## 16. Failure Handling

### DB transaction fails

- Roll back.
- Do not broadcast success.
- Preserve previous valid state.

### Realtime disconnect

- UI shows Reconnecting/Offline.
- On reconnect, refetch authoritative state.

### Offline

Block:

- Payment
- Pay Later save
- Void
- Store Open
- Store Close
- Inventory-changing Store Purchase

No fake local success.

---

## 17. Performance Principles

- Branch-scope queries
- Index high-traffic branch columns
- Paginate history/reports
- Avoid N+1
- Keep realtime payloads small
- Update smallest UI region possible
- Optimize images aggressively
- Heavy analytics/export work in queue
- Avoid loading All Branches data for branch staff

---

## 18. Deployment Shape

Railway:

- Laravel web/app service
- Queue worker
- Reverb service if separated

Managed dependencies:

- Supabase PostgreSQL
- Supabase Storage
- Redis

---

## 19. Frozen Architecture Decisions

Frozen:

- Modular monolith
- Inertia frontend
- PostgreSQL source of truth
- Multi-branch
- One active Store Session per branch
- Opening Cash + Cashless
- Closing Cash + Cashless
- Pay Later deducts stock + enters Kitchen immediately
- QR submission has no stock/Kitchen effect
- 30-minute QR Archived / Unclaimed
- Store Close archives remaining unclaimed QR
- Pay Later/Kitchen DONE are Close Store blockers
- Store purchases/expenses affect reconciliation
- Inventory ledger + compensating movements
- DB commit before broadcast
- No offline-first financial writes
- Performance-first image handling

## 20. Owner Operations (Phase 16E)

- **Stock primitive:** `App\Actions\Operations\ApplyIngredientMovement` (lock rows in Ingredient-id order, append movement + move balance in one transaction). Nothing else writes Ingredient balances.
- **Order integration:** `RecordOrderIngredientUsage` — `commit()` from `ApplyOrderInventory` (Pay Now and Pay Later), `edit()` from `EditCommittedOrder`, `void()` from `VoidOrder`, all inside the caller's existing transaction and lock order (Store Session → Order → Product inventory → Ingredient balances). Immutable `order_recipe_snapshots` make edits and voids independent of today's recipe, costs and Plans.
- **Domain services:** `ReplenishmentAdvisor` (only recommendation authority), `IngredientStockReport` (batched canonical stock + today's movements), `OperationsSummary` (sales/COGS/profit on `StoreSessionSalesReport`), `OperationsWorkspace` (page props), `ExactQuantity` (integer ten-thousandths), `OperationsAccess` (scope + authorization).
- **Money:** Confirm Pamamalengke writes the canonical Store Session expense through `RecordStoreSessionExpense::persist()`; Purchases is a projection. React only formats server values; its recipe-cost and checklist totals are display previews with the same integer rounding.
