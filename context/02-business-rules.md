# PONGSKILOG POS V3 — Business Rules

**Status:** FROZEN — Batch 1  
**Business model:** One PONGSKILOG business with multiple branches

---

## 1. General Rules

1. Server/database is authoritative.
2. Critical write flow:
   **validate → DB transaction → commit → broadcast**
3. High-risk writes must be idempotent.
4. System is not offline-first.
5. Sensitive actions are auditable.
6. Backend enforces role permission + branch assignment.
7. Branch data must never mix.
8. Visible payment terms:
   - Cash
   - Cashless
   - Split

---

## 2. Branch Rules

Each branch has its own:

- Store Session
- Orders
- Payments
- Kitchen
- Inventory
- Tables
- Staff assignments
- Customer QR orders
- Customer Display
- Branch settings
- Product overrides
- Opening/Closing balances
- Store-session purchases/expenses

Recommended branch states:

- Active
- Temporarily Closed
- Inactive

Historical data is retained.

---

## 3. Store Session Rules

Each branch may have only **one active Store Session at a time**.

### Store Closed

Cashier sees:

- Browse
- Open Store

### Browse

Read-only.

Backend blocks:

- New order
- Edit
- Payment
- Save Pay Later
- Void
- Delete
- Inventory adjustment
- Store purchase/expense mutation
- Other operational CRUD

### Open Store

Any authorized Cashier assigned to the branch may open it.

The first Cashier enters:

- Opening Cash
- Opening Cashless

After confirmation:

- Store Session = Open
- Normal operations enabled

### Additional Cashier

If Store Session is already Open:

- System shows **Store is Open**
- No new opening balances requested
- Cashier proceeds into normal operation

Opening balances belong to Store Session, not individual Cashiers.

---

## 4. Customer QR Availability by Store State

Customer QR ordering follows branch Store Session.

### Open

New QR ordering/submission is enabled.

### Closed

Customer QR site remains accessible but shows:

**Store is currently closed**

New order submission is disabled.

---

## 5. QR Archive Rules

A Customer QR submission that receives no action for 30 minutes becomes:

**Archived / Unclaimed**

Rules:

- No inventory deduction
- No Kitchen ticket
- No payment effect
- Record is retained for history
- It is removed from the active QR operational queue

At Store Close:

- Any remaining unclaimed QR submissions for that branch are archived
- Unclaimed QR submissions do not block Close Store

---

## 6. Product Catalog

Use:

**Global Product Catalog + Branch Overrides**

Branch overrides may include:

- Availability
- Price
- Stock
- Low-stock threshold

Final price and availability are server-validated.

---

## 7. Product Images

Images must not slow POS.

Use:

- Optimized sizes
- Compression
- Responsive variants
- Lazy loading
- CDN/storage delivery
- Caching
- WebP/AVIF where practical

---

## 8. Order Types

Supported:

- Dine In
- Take Out

Branch table selection is optional for both Dine In and Take Out. If selected, the table must be active and belong to the current branch.

The customer/order label is optional for both Dine In and Take Out.

---

## 9. Commercial / Payment State

Important states include:

- Draft
- Submitted QR
- UNPAID / PAY LATER
- Paid
- Completed
- Voided
- Archived / Unclaimed QR

Commercial/payment state and Kitchen state are separate.

---

## 10. Kitchen Status

Lifecycle:

**KITCHEN → PREPARING → READY → DONE**

Kitchen receives:

- Paid orders
- Saved Pay Later orders

Close Store is blocked while any committed Kitchen order is not DONE.

---

## 11. Pay Now

Supported:

- Cash
- Cashless
- Split

On successful new-order payment:

- Order becomes Paid
- Branch inventory is deducted
- Branch Kitchen ticket is created
- Realtime broadcast occurs after commit

The POS reserves the real operational order identity when a new order type is selected so the same numeric order number is visible in the cart, Payment modal, paid-success state, and receipt. Allocation is server-owned, branch-serialized, and may contain gaps when an abandoned reservation is never paid. Clients cannot choose or replace either identifier.

- `order_number` is the short numeric operational number used by staff and customers, such as `1043` / `#1043`.
- `reference_number` is the immutable full audit reference, such as `MAIN-260919-1043`.
- Existing legacy orders retain their historical order numbers and may have no reference number.

Failed payment does not deduct inventory or create a Kitchen ticket.

---

## 12. Cash Payment

Rules:

- Amount Received required
- Underpayment blocked
- Change = Amount Received - Cash Due

Quick values may include:

- Exact
- 50
- 100
- 500
- 1000

---

## 13. Cashless Payment

Rules:

- Visible label = Cashless
- Manual confirmation
- No provider reference required in MVP
- No automatic provider verification assumed
- The Phase 6 invoice field is an explicit placeholder only. Invoice capture and management belong to Transaction History & Editing in Phase 12.

---

## 14. Split Payment

Split = Cash + Cashless.

Rules:

- Cash + Cashless = order total
- Change applies only to Cash leg
- Both legs commit atomically
- Both records remain traceable

---

## 15. Pay Later — Final Rule

Saving as Pay Later means:

- Order = **UNPAID / PAY LATER**
- Inventory is deducted immediately
- Kitchen ticket is created immediately
- Order enters Kitchen immediately
- Payment remains outstanding

Pay Later is a committed operational order, not a reservation.

---

## 16. Paying a Pay Later Order

When later paid:

- Create payment record(s)
- Mark order Paid

Do not:

- Deduct inventory again
- Create duplicate Kitchen ticket

---

## 17. Editing Pay Later

Use inventory delta/compensating movements.

Example:

Before:
- 2 Pork

After:
- 1 Pork
- 1 Chicken

Inventory:
- +1 Pork
- -1 Chicken

Prior inventory history remains.

---

## 18. QR Orders

Initial QR submission:

- Branch-bound
- Unpaid
- No inventory deduction
- No Kitchen ticket

Cashier action:

**LOAD**

After LOAD:

### Pay Now

- Payment commits
- Inventory deducts
- Kitchen ticket created

### Save as Pay Later

- Order = UNPAID / PAY LATER
- Inventory deducts
- Kitchen ticket created

---

## 19. Customer Tracking

Direct payment path:

- Waiting for Payment
- Payment Confirmed
- In Kitchen
- Preparing
- Ready
- Completed

Pay Later path:

- Waiting for Cashier
- Unpaid / Pay Later
- In Kitchen
- Preparing
- Ready
- Completed

No fake ETA.

---

## 20. Customer Receipt

Rules:

- Unavailable before payment
- Available for 24 hours after payment
- Save Receipt supported
- Correct branch/order/payment details shown

---

## 21. Inventory Rules

Inventory is branch-specific.

Deduct stock when:

- New order Pay Now succeeds
- Pay Later is saved

Do not deduct when:

- Customer merely submits QR order
- Order remains a draft/cart

Inventory correction happens when:

- Order is edited
- Order is voided
- Manual adjustment occurs
- Inventory-linked Store Purchase occurs
- Stock transfer occurs

Negative stock is disabled by default.

---

## 22. Store Purchases / Expenses

While Store Session is Open, authorized Cashier may record branch operating purchases/expenses.

Fields:

- Description/item
- Amount
- Payment source:
  - Cash
  - Cashless
- Note/reason
- Optional receipt image
- Optional product + quantity for inventory restock

### Financial effect

Cash purchase:

- Reduces expected Closing Cash

Cashless purchase:

- Reduces expected Closing Cashless

### Restock effect

If purchase is linked to inventory:

- Inventory increases by recorded quantity
- Financial expense remains recorded
- Inventory movement remains traceable

---

## 23. Close Store Pre-Checks

Close Store is blocked if:

1. Any UNPAID / PAY LATER order remains unresolved.
2. Any committed Kitchen order is not DONE.

A Pay Later order must be resolved through normal flow, such as:

- Paid; or
- Voided through authorized Void flow when legitimate

Unclaimed QR submissions do not block closing.

---

## 24. Closing Reconciliation

Cashier manually enters:

- Closing Cash
- Closing Cashless

System calculates expected balances from:

- Opening Cash
- Opening Cashless
- Cash sales
- Cashless sales
- Split-payment legs
- Store purchases/expenses
- Relevant adjustments/void effects

System displays:

- Expected Closing Cash
- Expected Closing Cashless
- Actual Closing Cash
- Actual Closing Cashless
- Cash variance
- Cashless variance

---

## 25. Closing Variance Rules

### Exact

Proceed normally.

### Shortage

Normal Close Store is blocked.

Cashier must review and correct the cause before closing.

### Overage

Close Store may proceed.

Cashier must enter a note/reason explaining the overage.

---

## 26. Close Store Commit

After all blockers and reconciliation rules pass:

- Archive remaining unclaimed QR submissions
- Record Closing Cash
- Record Closing Cashless
- Record expected balances
- Record variances
- Record required notes
- Close Store Session
- Audit Close Store

After close:

- Operational POS mutations are disabled
- Customer QR ordering is disabled
- Customer QR site shows Store Closed

---

## 27. Void Rules

Void requires:

- Authorization/PIN/re-auth
- Reason
- Confirmation
- Audit

If inventory was already deducted:

- Restore using compensating inventory movement

Original records remain.

---

## 28. Transaction Editing

Every edit stores:

- Branch
- Before
- After
- User
- Timestamp

Inventory is reconciled by delta.

For paid orders:

- Same total → no new payment
- Higher total → collect or save delta
- Lower total → explicit correction/adjustment record

Original payment history is retained.

---

## 29. Branch-to-Branch Stock Transfer

Architecture supports:

**Requested → Sent → Received → Completed**

Source and destination movements remain traceable.

---

## 30. Audit Rules

Super Admin Audit Trail should include:

- Store Open
- Opening Cash
- Opening Cashless
- Store purchases/expenses
- Closing Cash
- Closing Cashless
- Closing variances
- Store Close
- Order edits
- Voids
- Payment corrections
- Inventory adjustments
- Stock transfers
- Branch changes
- Staff branch assignments
- Access changes
- Settings changes

---

## 31. Access Control

Roles:

- Super Admin
- Owner
- Cashier
- Kitchen Staff
- Cashier + Kitchen

Super Admin-only:

- Audit Trail
- Void Orders
- Access Control

Backend authorization is mandatory.

---

## 32. Realtime

Realtime is branch-scoped.

Broadcast only after successful database commit.

---

## 33. Performance

- Index branch-scoped queries
- Avoid all-branch loads for branch staff
- Paginate/aggregate reports
- Heavy reports must not block POS
- Optimize images
- Scope realtime subscriptions by branch
