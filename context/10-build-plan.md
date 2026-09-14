# PONGSKILOG POS V3 — Build Plan

**Status:** FROZEN — Batch 4  
**Depends on:** Frozen Context `01`–`09`  
**Purpose:** Define the implementation sequence for the production build.

---

## 1. Build Principle

Build by dependency, not by page.

Core order:

**Foundation → Security/Branches → Store Session → Catalog → Inventory → POS/Payments → Kitchen/QR → History/Audit → Reconciliation → Management → Hardening**

Do not skip lower-level integrity work just to finish visible UI faster.

Every phase should include:

- Backend implementation
- Frontend integration
- Authorization
- Tests
- Branch isolation where relevant
- Responsive QA
- Realtime integration where relevant

---

# Phase 0 — Foundation Verification

Verify the existing project foundation before feature work.

Required:

- Laravel 13 / PHP 8.4
- React 19
- Inertia 3
- TypeScript
- Tailwind 4
- Vite 8
- PostgreSQL / Supabase connection
- Redis
- Reverb
- Supabase Storage
- Pest
- Larastan / PHPStan
- Pint
- CI pipeline
- Environment configuration

Exit criteria:

- Application boots cleanly
- Database connection works
- Queue works
- Realtime handshake works
- Storage access works
- Baseline tests/build pass

---

# Phase 1 — Identity, RBAC & Branch Foundation

Build:

- Login
- Logout/session handling
- Users
- Roles
- Permissions
- User role assignment
- Staff branch assignments
- Active branch context
- Branch selection
- Owner business-wide scope
- Super Admin business-wide scope
- Policies/Gates

Exit criteria:

- Unauthorized branch access is blocked backend-side
- Kitchen cannot access financial surfaces
- Cashier cannot access Owner/Super Admin control surfaces
- Multi-branch staff can switch only to assigned branches

---

# Phase 2 — Branches & Store Sessions

Build:

- Branch model/management foundation
- Branch status
- Store Closed state
- Browse mode
- Open Store
- Opening Cash
- Opening Cashless
- Existing Open Store detection
- One active Store Session per branch
- Store state propagation to Customer QR

Exit criteria:

- First authorized Cashier can open Store
- Second Cashier reuses same Store Session
- Concurrent Store Open cannot create duplicates
- Browse remains read-only
- Closed Store blocks operational mutations

---

# Phase 3 — Catalog & Product Images

Build:

- Categories
- Products
- Product modifiers
- Product image upload
- Branch product overrides
- Branch price
- Branch availability
- Stock threshold
- Optimized image delivery

Image pipeline:

- Upload source
- Store metadata
- Generate optimized variants
- Use small card thumbnail in POS
- Lazy-load below fold
- Cache

Exit criteria:

- Branch-valid price/availability enforced server-side
- Missing/broken image does not break POS
- Product images do not materially slow product browsing

---

# Phase 4 — Inventory Foundation

Build:

- Branch inventory balance
- Append-only inventory movement ledger
- Low-stock/out-of-stock
- Manual adjustment
- Concurrency-safe stock update
- Negative stock protection

Exit criteria:

- Branch A stock cannot affect Branch B
- Every stock mutation creates a traceable movement
- Concurrent last-unit sale cannot corrupt stock

---

# Phase 5 — Core POS Order Flow

Build:

- Dine In / Take Out
- Product browser
- Search/categories
- Product customization
- Cart
- Notes
- Branch-valid table
- Order information
- Order numbering
- Server-side total calculation

Exit criteria:

- Cart/order totals are server-authoritative
- Modifier snapshots are preserved
- Historical item data remains stable after product changes

---

# Phase 6 — Pay Now

Build:

- Cash
- Cashless
- Split
- Payment modal
- Idempotency
- Inventory deduction
- Kitchen ticket creation
- Payment success
- Receipt

Atomic boundary:

**Order + Payment + Inventory + Kitchen**

Exit criteria:

- Failed transaction causes no partial stock/payment/Kitchen state
- Double-submit cannot duplicate payment
- Split commits atomically

---

# Phase 7 — Pay Later

Build:

- Save as UNPAID / PAY LATER
- Immediate inventory deduction
- Immediate Kitchen ticket
- Transaction History entry
- Later payment settlement
- Duplicate protection

Exit criteria:

- Pay Later deducts stock once
- Pay Later enters Kitchen once
- Later settlement does not repeat inventory/Kitchen effects

---

# Phase 8 — Kitchen / KDS

Build:

- Branch KDS
- KITCHEN
- PREPARING
- READY
- DONE
- Realtime updates
- Fullscreen mode
- Order edit update handling

Exit criteria:

- Paid and Pay Later orders appear
- QR submission alone does not appear
- Kitchen never sees financial data
- Invalid lifecycle transition is rejected

---

# Phase 9 — Customer Display

Build:

- Preparing order numbers
- Ready order numbers
- Branch-scoped realtime
- Reconnect/refetch behavior

Exit criteria:

- Only safe display data exposed
- No private or financial information

---

# Phase 10 — Customer QR Ordering

Build:

- Branch QR entry
- Store Closed state
- Welcome
- Menu
- Product customization
- Cart
- Submission
- Active tracking
- Receipt
- 24-hour receipt availability
- Anonymous QR session
- 30-minute Archived / Unclaimed behavior

Important:

Initial QR submission:

- No payment
- No inventory deduction
- No Kitchen ticket

Exit criteria:

- Closed Store disables submission
- One active QR order/session behavior works
- QR customer cannot access another customer’s order

---

# Phase 11 — QR Orders Staff Flow

Build:

- Active QR queue
- Search
- Realtime arrival
- LOAD
- Archived / Unclaimed handling
- Archive/Delete confirmation as defined by UI

After LOAD:

- Pay Now uses normal POS payment flow
- Pay Later uses normal committed Pay Later flow

Exit criteria:

- No duplicate QR-specific payment system
- LOAD remains branch-safe

---

# Phase 12 — Transaction History & Editing

Build:

- Transaction list
- Search/filters
- Transaction detail
- Pay Later settlement
- Edit committed order
- Inventory delta
- Delta payment
- Lower-total correction
- Receipt actions

Exit criteria:

- Inventory delta is correct
- Original payment history is retained
- Changes are audited
- Kitchen receives relevant update only

---

# Phase 13 — Void & Audit

Build:

- Void authorization
- Reason
- Confirmation
- Compensating inventory restoration
- Audit Trail
- Super Admin Void Orders history

Exit criteria:

- Original transaction remains
- Restoration is traceable
- Unauthorized roles cannot access protected control surfaces

---

# Phase 14 — Store Purchases / Expenses

Build:

- Store Session purchase/expense list
- Description
- Amount
- Cash/Cashless source
- Note/reason
- Optional receipt image
- Optional inventory product/quantity
- Restock inventory movement

Exit criteria:

- Cash expense affects expected Closing Cash
- Cashless expense affects expected Closing Cashless
- Restock purchase increases inventory
- Expense is traceable to Store Session/user

---

# Phase 15 — Close Store & Reconciliation

Build pre-close checks:

- Block unresolved UNPAID / PAY LATER
- Block Kitchen non-DONE orders
- Do not block unclaimed QR

Build reconciliation:

- Opening Cash
- Opening Cashless
- Cash sales
- Cashless sales
- Split breakdown
- Store purchases/expenses
- Relevant adjustments/void effects
- Expected Closing Cash
- Expected Closing Cashless
- Closing Cash input
- Closing Cashless input
- Variances

Frozen rules:

- Shortage blocks normal close
- Overage requires note and may proceed
- Remaining unclaimed QR archived at close

Atomic close:

- Revalidate blockers
- Archive unclaimed QR
- Save expected/actual balances
- Save variances/notes
- Close Store Session
- Audit
- Broadcast Store Closed after commit

Exit criteria:

- Closed Store blocks operational mutations
- Customer QR shows Store Closed
- Session is fully auditable

---

# Phase 16 — Owner Workspace

Build:

- Dashboard
- Transactions
- Reports
- Products
- Inventory
- Staff
- Settings
- All Branches / specific branch scope

Exit criteria:

- Consolidated values reconcile with branch values
- Owner cannot access Super Admin-only control surfaces

---

# Phase 17 — Stock Transfers

Build:

- Requested
- Sent
- Received
- Completed
- Transfer history
- Source/destination inventory movements

Exit criteria:

- No double receive
- No silent stock creation/loss
- Source and destination movements are traceable

---

# Phase 18 — Super Admin Workspace

Build:

- Dashboard
- Audit Trail
- Void Orders
- Access Control
- Settings/control surfaces

Exit criteria:

- Highest business-wide permission works
- Dedicated audit/access surfaces remain protected

---

# Phase 19 — Reporting & Performance Hardening

Optimize:

- Query indexes
- Pagination
- Report aggregation
- Cache
- Queue-based export
- Realtime payload size
- Product image delivery
- N+1 queries
- All-branch management queries

Exit criteria:

- POS remains responsive with realistic data
- Reports do not degrade operational transaction paths

---

# Phase 20 — Final Hardening

Perform:

- Security review
- Branch isolation testing
- Payment concurrency testing
- Inventory race testing
- Store Open race testing
- Store Close reliability testing
- QR archive testing
- Realtime reconnect testing
- Mobile/tablet QA
- Staging validation
- Backup/restore verification
- Production-readiness review

Exit criteria:

- No known financial/inventory integrity blocker
- CI green
- Staging acceptance complete
- Deployment/rollback procedures ready

---

## Development Rules

1. One phase may overlap another only when dependency is already stable.
2. Do not bypass backend rules to match a prototype.
3. UI reference files guide presentation, not data integrity.
4. High-risk writes must be transactional.
5. Every critical feature requires test evidence.
6. If implementation changes a frozen business rule, update context through an intentional context-change review before treating the new behavior as authoritative.
