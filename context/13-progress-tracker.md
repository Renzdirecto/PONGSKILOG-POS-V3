# PONGSKILOG POS V3 — Development Progress Tracker

**Purpose:** Development implementation tracking only.  
**Source:** `10-build-plan.md`

Check an item only when the corresponding implementation and required verification are complete.

---

## Phase 0 — Foundation Verification

- [x] Laravel 13 / PHP 8.4 verified
- [x] React 19 / Inertia 3 verified
- [x] TypeScript / Tailwind 4 / Vite 8 verified
- [x] PostgreSQL / Supabase connected
- [x] Redis configured
- [x] Laravel Reverb configured
- [x] Supabase Storage configured
- [x] Pest configured
- [x] Larastan / PHPStan configured
- [x] Pint configured
- [ ] CI pipeline verified
- [x] Baseline build/tests passing

---

## Phase 1 — Identity, RBAC & Branch Foundation

- [x] Phase 1A — Database + Eloquent model foundation
- [x] Phase 1B — RBAC + seeders
- [x] Phase 1C — Branch context + authorization
- [x] Phase 1D — Branch selector + role routing UI
- [x] Phase 1E — Security tests + final verification

- [x] Login
- [x] Logout / session handling
- [x] Users
- [x] Roles
- [x] Permissions
- [x] User role assignment
- [x] Staff branch assignments
- [x] Branch selection
- [x] Active branch context
- [x] Owner business-wide scope
- [x] Super Admin business-wide scope
- [x] Policies / Gates
- [x] Branch authorization tests

---

## Phase 2 — Branches & Store Sessions

- [x] Phase 2A — Store Session database + Eloquent foundation
- [x] Phase 2B — Open Store business operation
- [x] Phase 2C — Store Closed / Browse / Open Store UI
- [x] Phase 2D — Existing Store Session + shared store state
- [x] Phase 2E — Security / concurrency / final verification
- [x] Phase 2F — Branch management foundation + Customer QR store-state bridge

- [x] Branch management foundation
- [x] Branch status
- [x] Store Closed state
- [x] Browse mode
- [x] Backend read-only enforcement for Browse (current Phase 2 route surface; see scope below)
- [x] Open Store
- [x] Opening Cash
- [x] Opening Cashless
- [x] Existing Open Store detection
- [x] One active Store Session per branch constraint
- [x] Concurrent Open Store protection
- [x] Store state propagated to Customer QR

Phase 2E verification (2026-09-18):

- Reviewed the complete Phase 2 diff against `dev`, routes, middleware, authorization, action transactions, schema, shared props, and local seeder. No production-code defect found; added four missing security/read-only regressions and an opt-in PostgreSQL harness.
- `php tests/verify-store-session-postgres.php` uses explicit local PostgreSQL settings with `DB_URL=null`. Two independent cashier processes must both be observed waiting on the branch lock before the harness releases it. Both returned session `01a0b3cb-13d2-73a0-902f-9dcb3e1b58b6`; exactly one OPEN row existed, with the winning opener, timestamp, Cash `111.11`, and Cashless `222.22` unchanged. Both connections remained usable with no open transaction.
- PostgreSQL 18 verification used a temporary `phase2e_*` schema on `127.0.0.1:5432`, because the local app role lacks database-creation permission. Fresh migrations/RBAC seed, UUID/numeric(14,2) schema, partial unique index, constraint rejection, retained history, multiple CLOSED sessions, and independent branch OPEN sessions passed. The schema was removed; the normal local database's partial unique index was also verified read-only. Supabase was untouched.
- Targeted Phase 2 tests: 117 passed / 634 assertions. `composer test`: 245 passed / 994 assertions, Pint and PHPStan passed (process-only 1 GB memory override). `npm run check:frontend`, `npm run types:check`, and `npm run build` passed. Isolated SQLite fresh migration/RBAC seed passed with `DB_URL` cleared.
- Security, existing-session preservation, branch switching/state isolation, safe shared props, and LocalDevelopmentSeeder production guard/idempotency passed. Phase 2C/2D developer manual QA remains accepted; no user-facing behavior changed.
- Browse/closed-store scope: the current workspace is read-only and exposes no order, payment, inventory, expense, or other operational mutation endpoint/button. Open Store is the sole operational write and is intentionally allowed while CLOSED, subject to backend authorization. Account settings and branch selection are separate identity/context operations. Future operational routes still require Store OPEN enforcement; no general mutation middleware is claimed here.
- At the Phase 2E checkpoint, Branch management and Customer QR integration remained incomplete; Phase 2F below completes those foundations. Phase 3 was not started.

Phase 2F final verification (2026-09-18):

- Developer manual QA accepted as PASS: Owner can list MAIN/QAVE, create a temporary branch, edit core fields, and change status; Cashier direct access is denied; no Branch delete action exists. Customer QR OPEN/CLOSED/unavailable states passed on desktop and approximately 390px mobile. Browser automation was not retried.
- Branch management is limited to list/create/update of code, name, status, address, and contact, with backend policy/Form Request authorization. Status changes preserve Store Sessions. No advanced settings, table management, cashless/receipt configuration, or deletion were added.
- Public QR reuses `StoreState`: an active branch with an OPEN session shows STORE IS OPEN; otherwise it shows STORE IS CURRENTLY CLOSED. Availability is resolved from persisted state on each request/refresh. Public props are exactly `branch: { id, name, code }` and `store: { status }`, including for signed-in staff; no balances, StoreSession IDs, staff, permissions, reconciliation, or internal assignments are exposed.
- Targeted Branch tests: 29 passed / 228 assertions. Targeted Customer QR tests: 11 passed / 129 assertions. `composer test`: 285 passed / 1351 assertions, with Pint and PHPStan passing; no memory override was needed. `npm run check:frontend`, `npm run types:check`, and `npm run build` passed. The build reports a non-blocking optional `fontaine` warning and plugin timing diagnostics; dependencies were not changed.
- Reviewed `git diff dev...HEAD` and all uncommitted Phase 2F files for authorization, StoreSession reuse/concurrency, exact decimal money, branch isolation, current store state, public data exposure, secrets, and scope. No defect requiring behavior changes was found. Phase 2F adds no migration, dependency, machine-specific configuration, or Supabase change.
- Phase 2 is fully complete. Full Customer QR ordering remains Phase 10: no cart, order submission, customer account, payment, inventory, or Kitchen logic was added. Phase 3 and later-phase checkboxes remain untouched.

---

## Phase 3 — Catalog & Product Images

- [x] Phase 3A — Catalog database + Eloquent foundation
- [ ] Phase 3B — Catalog rules + branch overrides + modifiers
- [ ] Phase 3C — Product image pipeline
- [ ] Phase 3D — Product management UI
- [ ] Phase 3E — Cashier real catalog Browse
- [ ] Phase 3F — Security / performance / final verification

- [ ] Categories
- [ ] Products
- [ ] Product modifiers
- [ ] Product image upload
- [ ] Branch product overrides
- [ ] Branch price override
- [ ] Branch availability
- [ ] Low-stock threshold
- [ ] Optimized image variants
- [ ] Product image fallback
- [ ] Lazy-loading / image performance

Phase 3A verification (2026-09-18):

- Added six catalog tables, five UUID Eloquent models and factories, Branch relationships, and the `single`/`multiple` modifier selection enum. Prices use `numeric(14,2)` with `decimal:2` string casts, matching StoreSession; no authoritative floats.
- Database constraints enforce non-negative prices/thresholds, valid selection bounds/types, unique branch/product and product/modifier mappings, and restrictive foreign keys. Indexes cover active/sorted categories, category/active products, branch availability, sorted modifier options, and reverse relationships.
- Targeted `CatalogFoundationTest`: 43 passed / 121 assertions. `composer test`: 328 passed / 1472 assertions; Pint and PHPStan passed with a temporary process-only 1 GB memory override after PHPStan reached the local 128 MB limit. No machine-specific configuration was committed.
- Additive migration passed on local PostgreSQL at `127.0.0.1:5432`. Read-only metadata queries verified all three `numeric(14,2)` columns, both mapping uniqueness constraints, seven CHECK constraints, six restrictive foreign keys, and lookup indexes. Isolated SQLite in-memory fresh migration passed with `DB_URL` explicitly cleared. Supabase was untouched.
- Scope is schema/model foundation only. Main Phase 3 behavior checkboxes remain incomplete; catalog actions, effective pricing/availability, image processing, management UI, cashier Browse, and inventory quantities are not implemented in this batch.

---

## Phase 4 — Inventory Foundation

- [ ] Branch inventory balance
- [ ] Inventory movement ledger
- [ ] Low-stock state
- [ ] Out-of-stock state
- [ ] Manual adjustment
- [ ] Negative stock protection
- [ ] Concurrency-safe stock updates
- [ ] Inventory branch-isolation tests

---

## Phase 5 — Core POS Order Flow

- [ ] Dine In / Take Out
- [ ] Product browser
- [ ] Search / categories
- [ ] Product customization
- [ ] Modifier handling
- [ ] Cart
- [ ] Notes
- [ ] Branch-valid table handling
- [ ] Order Information
- [ ] Order numbering
- [ ] Server-side total calculation
- [ ] Historical order snapshots

---

## Phase 6 — Pay Now

- [ ] Cash
- [ ] Cashless
- [ ] Split
- [ ] Payment modal
- [ ] Amount received / change
- [ ] Underpayment validation
- [ ] Payment idempotency
- [ ] Atomic payment transaction
- [ ] Inventory deduction
- [ ] Kitchen ticket creation
- [ ] Payment success state
- [ ] Receipt
- [ ] Duplicate-submit tests

---

## Phase 7 — Pay Later

- [ ] Save as UNPAID / PAY LATER
- [ ] Immediate inventory deduction
- [ ] Immediate Kitchen ticket
- [ ] Transaction History entry
- [ ] Later payment settlement
- [ ] Prevent second inventory deduction
- [ ] Prevent duplicate Kitchen ticket
- [ ] Pay Later idempotency tests

---

## Phase 8 — Kitchen / KDS

- [ ] Kitchen board
- [ ] KITCHEN state
- [ ] PREPARING state
- [ ] READY state
- [ ] DONE state
- [ ] Lifecycle validation
- [ ] Fullscreen mode
- [ ] Realtime Kitchen updates
- [ ] Order-edit Kitchen updates
- [ ] Branch isolation
- [ ] No financial data exposure

---

## Phase 9 — Customer Display

- [ ] Preparing order numbers
- [ ] Ready order numbers
- [ ] Branch-scoped display
- [ ] Realtime updates
- [ ] Reconnect/refetch
- [ ] Safe public payload

---

## Phase 10 — Customer QR Ordering

- [ ] Branch QR entry
- [ ] Store Closed state
- [ ] Welcome
- [ ] Menu
- [ ] Product customization
- [ ] Cart
- [ ] Dine In / Take Out
- [ ] QR submission
- [ ] No stock deduction on initial submission
- [ ] No Kitchen ticket on initial submission
- [ ] Anonymous QR session
- [ ] One active order/session
- [ ] Active tracking
- [ ] Receipt
- [ ] 24-hour receipt expiry
- [ ] 30-minute Archived / Unclaimed behavior
- [ ] Customer tracking security tests

---

## Phase 11 — QR Orders Staff Flow

- [ ] Active QR queue
- [ ] Search
- [ ] Realtime arrival
- [ ] QR order detail
- [ ] LOAD
- [ ] Branch-safe LOAD
- [ ] Archived / Unclaimed handling
- [ ] Archive/Delete confirmation
- [ ] Pay Now after LOAD
- [ ] Pay Later after LOAD
- [ ] Duplicate LOAD protection

---

## Phase 12 — Transaction History & Editing

- [ ] Transaction list
- [ ] Search
- [ ] Filters
- [ ] Transaction detail
- [ ] Pay Later settlement from history
- [ ] Edit committed order
- [ ] Inventory delta calculation
- [ ] Inventory compensating movement
- [ ] Higher-total delta payment
- [ ] Higher-total Pay Later delta
- [ ] Lower-total correction
- [ ] Receipt actions
- [ ] Edit audit trail
- [ ] Kitchen update after relevant edit

---

## Phase 13 — Void & Audit

- [ ] Void authorization
- [ ] Void reason
- [ ] Re-auth / protected confirmation
- [ ] Compensating inventory restoration
- [ ] Original order retained
- [ ] Payment history retained
- [ ] Audit Trail
- [ ] Super Admin Void Orders
- [ ] Void authorization tests

---

## Phase 14 — Store Purchases / Expenses

- [ ] Current Store Session expense list
- [ ] Add Store Purchase / Expense
- [ ] Description
- [ ] Amount
- [ ] Cash payment source
- [ ] Cashless payment source
- [ ] Note / reason
- [ ] Optional receipt image
- [ ] Optional inventory product link
- [ ] Optional quantity
- [ ] Restock inventory movement
- [ ] Cash closing-balance effect
- [ ] Cashless closing-balance effect
- [ ] Store Session/user trace

---

## Phase 15 — Close Store & Reconciliation

- [ ] Close Store entry point
- [ ] Block unresolved UNPAID / PAY LATER
- [ ] Block Kitchen non-DONE orders
- [ ] Allow unclaimed QR without blocking
- [ ] Opening Cash summary
- [ ] Opening Cashless summary
- [ ] Cash sales
- [ ] Cashless sales
- [ ] Split breakdown
- [ ] Store purchases/expenses summary
- [ ] Relevant adjustments / void effects
- [ ] Expected Closing Cash
- [ ] Expected Closing Cashless
- [ ] Closing Cash input
- [ ] Closing Cashless input
- [ ] Cash variance
- [ ] Cashless variance
- [ ] Shortage blocks normal close
- [ ] Overage requires note
- [ ] Archive remaining unclaimed QR
- [ ] Atomic Store Close transaction
- [ ] Store Close audit
- [ ] `store.closed` broadcast after commit
- [ ] Customer QR switches to Store Closed
- [ ] Concurrent Close Store protection

---

## Phase 16 — Owner Workspace

- [ ] Dashboard
- [ ] All Branches scope
- [ ] Specific Branch scope
- [ ] Transactions
- [ ] Reports
- [ ] Products
- [ ] Inventory
- [ ] Staff
- [ ] Settings
- [ ] Branch comparison
- [ ] Store Session summaries
- [ ] Owner blocked from Super Admin-only controls

---

## Phase 17 — Stock Transfers

- [ ] Create transfer
- [ ] Source branch
- [ ] Destination branch
- [ ] Product / quantity
- [ ] Requested
- [ ] Sent
- [ ] Received
- [ ] Completed
- [ ] Transfer-out movement
- [ ] Transfer-in movement
- [ ] No double receive
- [ ] Transfer history / audit

---

## Phase 18 — Super Admin Workspace

- [ ] Dashboard
- [ ] Audit Trail
- [ ] Void Orders
- [ ] Access Control
- [ ] Settings / system controls
- [ ] Cross-branch visibility
- [ ] Protected Super Admin authorization

---

## Phase 19 — Reporting & Performance Hardening

- [ ] Query/index review
- [ ] Pagination
- [ ] Report aggregation
- [ ] Cache safe read-heavy data
- [ ] Queue heavy exports
- [ ] Eliminate N+1 issues
- [ ] Realtime payload optimization
- [ ] Product image optimization verification
- [ ] Owner all-branch query optimization
- [ ] POS performance verification

---

## Phase 20 — Final Hardening

- [ ] Full RBAC review
- [ ] Full branch-isolation test pass
- [ ] Payment concurrency test pass
- [ ] Inventory race-condition test pass
- [ ] Store Open concurrency test pass
- [ ] Store Close reliability test pass
- [ ] QR archive test pass
- [ ] Realtime reconnect test pass
- [ ] 360px QA
- [ ] 390px QA
- [ ] 430px QA
- [ ] Tablet QA
- [ ] Desktop QA
- [ ] Staging validation
- [ ] Backup / restore verification
- [ ] Production health checks
- [ ] CI green
- [ ] Production readiness approved

---

# Completion Rule

A checkbox may be marked complete only when:

- Implementation is complete
- Relevant automated tests pass
- Authorization is correct
- Branch isolation is verified where relevant
- Data-integrity requirements are satisfied
- UI loading/error states are handled where relevant
- Responsive QA is completed where relevant
- Realtime behavior is verified where relevant

Do not check items merely because the standalone prototype already contains the UI.
