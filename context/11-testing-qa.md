# PONGSKILOG POS V3 — Testing & QA

**Status:** FROZEN — Batch 4  
**Depends on:** Frozen Context `01`–`10`

---

## 1. QA Principle

A feature is not Done because the UI works once.

Production readiness requires evidence across:

- Business logic
- Database integrity
- Authorization
- Branch isolation
- Concurrency
- Realtime
- Responsive UI
- Failure/recovery

High-risk operations require automated tests plus manual acceptance testing.

---

## 2. Test Layers

### Unit / Domain

For:

- Totals
- Change
- Variance
- Inventory delta
- Closing reconciliation calculations
- State transitions

### Feature / Integration

For:

- Authentication
- Authorization
- Store Sessions
- Orders
- Payments
- Inventory
- Kitchen
- QR
- Close Store
- Reports

### Browser / UI

For critical user journeys.

### Database Constraint Tests

For:

- One open Store Session
- Unique payment idempotency
- One Kitchen ticket/order
- Branch/product uniqueness
- Transfer constraints

---

## 3. Authentication & RBAC

Test:

- Valid login
- Invalid credentials
- Disabled account
- No branch assignment
- Session expiration
- Cashier blocked from Owner pages
- Kitchen blocked from payments
- Owner blocked from dedicated Super Admin controls
- Unauthorized branch URL/request manipulation rejected
- Cashier + Kitchen permission union works as intended

---

## 4. Store Open

Test:

- Closed branch shows Browse/Open Store
- Browse rejects backend mutation
- Opening Cash required
- Opening Cashless required
- Store Open succeeds
- Existing Store Session reused by later Cashier
- Concurrent Store Open results in exactly one active session
- Store Open audit created

---

## 5. Closed Store Enforcement

When Store Session is Closed:

- New POS order blocked
- Payment blocked
- Pay Later blocked
- Void/operational mutation blocked as applicable
- Store Purchase blocked
- Customer QR submission blocked
- Customer QR displays Store Closed

---

## 6. Product / Catalog

Test:

- Branch price override
- Branch availability
- Global disabled product
- Out-of-stock
- Modifier validation
- Historical snapshot stability
- Missing/broken image fallback
- Optimized image usage

---

## 7. Inventory

Test:

- Correct branch deduction
- Correct current balance
- Ledger movement created
- Negative stock blocked
- Manual adjustment audited
- Concurrent last-unit sale safe
- Branch A never affects Branch B

---

## 8. Pay Now — Cash

Test:

- Exact payment
- Overpayment/change
- Underpayment blocked
- Server recalculates total
- Double-click protected
- DB failure rolls everything back
- Stock/Kitchen created only after successful commit

---

## 9. Pay Now — Cashless

Test:

- Manual Cashless confirmation
- Correct payment record
- Duplicate request protected
- No unsupported provider reference requirement

---

## 10. Pay Now — Split

Test:

- Cash + Cashless = total
- Change only from Cash leg
- Both payment records commit atomically
- Partial commit impossible

---

## 11. Pay Later

On Save Pay Later verify:

- Order = UNPAID / PAY LATER
- Inventory deducted immediately
- Kitchen ticket created immediately
- Transaction History entry exists
- Realtime Kitchen event occurs after commit

Later settlement verify:

- Payment added
- Order becomes Paid
- No second stock deduction
- No duplicate Kitchen ticket

---

## 12. Pay Later Edit

Example:

Before:
- 2 Pork

After:
- 1 Pork
- 1 Chicken

Expected:

- Pork +1
- Chicken -1

Verify:

- Prior movement remains
- New delta movements correct
- Current stock correct
- Audit correct
- Kitchen update correct
- Insufficient stock edit rejected

---

## 13. Customer QR

Test:

- Correct branch QR
- Open Store enables submission
- Closed Store disables submission
- Initial QR submission is unpaid
- No inventory deduction
- No Kitchen ticket
- One active order/session
- Tracking token cannot expose another customer order

---

## 14. QR Archive / Unclaimed

Test:

- No-action QR reaches Archived / Unclaimed after 30 minutes
- Archive removes it from active queue
- Archive does not affect stock/payment/Kitchen
- Record remains in history
- Store Close archives remaining unclaimed QR
- Archived order cannot be replayed across branch/session incorrectly

---

## 15. QR LOAD

Test:

- Same-branch only
- One LOAD flow
- No duplicate QR-specific payment page

After LOAD:

### Pay Now
- Payment
- Inventory
- Kitchen

### Pay Later
- UNPAID / PAY LATER
- Inventory
- Kitchen

---

## 16. Kitchen

Test:

- Paid order enters Kitchen
- Pay Later enters Kitchen
- QR submission alone does not
- Valid lifecycle:
  KITCHEN → PREPARING → READY → DONE
- Invalid transition rejected
- Financial data absent
- Branch isolation
- Reconnect refetches authoritative active tickets

---

## 17. Customer Display

Test:

- Correct branch
- Preparing projection
- Ready projection
- No prices
- No private data
- Reconnect works

---

## 18. Transaction Editing

### Same total

- No additional payment

### Higher total

- Delta Pay Now works
- Delta Pay Later works where allowed

### Lower total

- Explicit correction/adjustment
- Original payment retained

All:

- Inventory delta correct
- Audit exists
- Kitchen ticket not duplicated

---

## 19. Void

Test:

- Authorization required
- Reason required
- Original order retained
- Payment history retained
- Compensating inventory movement created
- Audit created
- Super Admin Void Orders protected

---

## 20. Store Purchase / Expense

Test:

- Must belong to active Store Session
- Cash expense reduces expected Closing Cash
- Cashless expense reduces expected Closing Cashless
- Restock increases inventory
- Normal expense does not change inventory
- Optional receipt works
- Branch/user trace retained

---

## 21. Close Store Pre-Checks

Test:

### Unpaid Pay Later exists
- Close blocked

### Kitchen order not DONE
- Close blocked

### Unclaimed QR exists
- Close is not blocked

---

## 22. Closing Reconciliation

Verify calculations for:

- Opening Cash
- Opening Cashless
- Cash sales
- Cashless sales
- Split payment legs
- Store purchases/expenses
- Relevant adjustments
- Expected Closing Cash
- Expected Closing Cashless
- Actual Closing Cash
- Actual Closing Cashless
- Variances

---

## 23. Closing Variance

### Exact

- Close allowed

### Shortage

- Normal close blocked
- Correction/review required

### Overage

- Note required
- Close allowed after valid note

---

## 24. Successful Close Store

After commit verify:

- Store Session = Closed
- Closing values saved
- Expected values saved
- Variances saved
- Audit created
- Remaining unclaimed QR archived
- Operational mutations blocked
- QR ordering disabled
- `store.closed` emitted after commit

If transaction fails:

- Session remains Open

---

## 25. Multi-Branch Isolation

Mandatory scenario:

Create Branch A and Branch B.

Verify:

- A Cashier cannot see B orders
- A Kitchen cannot see B tickets
- A QR cannot create/update B order
- A inventory does not affect B
- A realtime event does not leak to B
- Owner can intentionally view All Branches
- Super Admin can intentionally view All Branches

---

## 26. Stock Transfer

Test:

- Source != destination
- Valid quantity
- Valid state transitions
- No double receive
- Source movement traceable
- Destination movement traceable
- Completed transfer history immutable in normal operation

---

## 27. Realtime

Test:

- Broadcast after commit only
- Failed transaction emits no success event
- Duplicate event does not duplicate UI
- Older version cannot overwrite newer state
- Branch channel authorization
- Reconnect refetch
- Customer tracking scope
- Safe Customer Display payload

---

## 28. Concurrency

Test at minimum:

- Two Cashiers Open Store simultaneously
- Two Cashiers buy last stock unit
- Double-click Pay Now
- Double-click Pay Later
- Duplicate QR LOAD
- Concurrent committed-order edit
- Duplicate transfer receive
- Concurrent Close Store attempt

Final state must remain valid.

---

## 29. Performance

Measure:

- POS initial product load
- Product search
- Product image load
- Pay Now commit
- Pay Later commit
- Kitchen update latency
- QR arrival latency
- Transaction pagination
- Owner dashboard filters
- Close Store reconciliation

Use realistic historical transaction and product-image volume.

---

## 30. Responsive QA

Minimum:

- 360px
- 390px
- 430px
- Tablet
- Desktop

Check:

- No horizontal overflow
- 44px practical tap targets
- POS density
- Modals/sheets
- Kitchen readability
- QR usability
- Store Open/Close forms
- Closing reconciliation readability

---

## 31. Failure / Recovery

Simulate:

- DB failure during Pay Now
- DB failure during Pay Later
- DB failure during Close Store
- Redis unavailable
- Reverb disconnected
- Queue delayed
- Storage/image unavailable
- Session expiration
- Browser refresh

No financial/inventory corruption is acceptable.

---

## 32. Release Gate

A feature/release cannot be considered production-ready until:

- Relevant automated tests pass
- Authorization tests pass
- Branch isolation passes
- Concurrency risk addressed
- Browser flow passes
- Mobile/tablet QA passes
- Realtime behavior verified where applicable
- No unresolved financial/inventory integrity defect
- Required audit evidence exists
- CI passes

## Phase 12 focused verification matrix

- History authorization/branch isolation, committed-only scope, server pagination, search/filter boundaries, and metrics independent of the current page.
- Same-total, higher-total, lower-total, unpaid Pay Later, and repeated-edit reconciliation with append-only Payment/Adjustment/Audit histories.
- Retained-price versus current-price item snapshots; aggregate tracked inventory delta, untracked no-op, insufficient-stock rollback, idempotent replay, stale version 409, closed/prior-session denial, and unchanged KitchenTicket lifecycle.
- Cash/Cashless/Split grouped attempts; exact outstanding settlement; duplicate replay; invoice allowlist/dimensions/size, private authorized stream, replace/remove cleanup, cash-row denial, and earlier-session read-only behavior.
- Compact after-commit POS/Kitchen events, reconnect refetch, temporary KDS UPDATED indicator, local-only view preference, camera fallback, and disabled Phase 13 Void.
- Required gates: focused Pest suite, adjacent Pay Now/Pay Later/Kitchen/receipt regression suite, PostgreSQL harness, Pint, PHPStan, frontend check, TypeScript, production build, migration fresh/up/down/reapply, whitespace and secret/artifact review.
