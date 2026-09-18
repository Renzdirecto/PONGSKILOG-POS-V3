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
- [ ] Phase 2E — Security / concurrency / final verification

- [ ] Branch management foundation
- [ ] Branch status
- [x] Store Closed state
- [x] Browse mode
- [ ] Backend read-only enforcement for Browse
- [x] Open Store
- [x] Opening Cash
- [x] Opening Cashless
- [x] Existing Open Store detection
- [ ] One active Store Session per branch constraint
- [ ] Concurrent Open Store protection
- [ ] Store state propagated to Customer QR

---

## Phase 3 — Catalog & Product Images

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
