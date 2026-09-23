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
- `reference_number` is the immutable full audit reference, such as `MAIN-092226-0001` (new branch/Manila-date sequence; legacy references are retained).
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
- Cashless Payment rows may carry one private, manually supplied invoice proof. It is evidence only, not gateway verification; replacement and removal are authorized, audited operations.

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

- One global four-digit approval PIN configured by an active Super Admin and stored only as a hash
- The configuring Super Admin recorded as authorizer and a distinct active assigned Cashier/Cashier+Kitchen user, or another full-access Super Admin on the selected Branch, recorded as initiator
- Reason
- Confirmation
- Audit

If inventory was already deducted:

- Restore using compensating inventory movement

Original records remain.

Voided Orders leave normal Cashier Transaction History and all normal receipt surfaces, but remain available in the protected Super Admin Void Orders register. Inventory restoration is the aggregate net negative effect of the Order's `sale`, `pay_later_commit`, and `order_edit_delta` ledger movements, appended once as sorted `void_restore` movements.

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


---

## Approved Phase 10/11 refinement cutover - 2026-09-22

This section supersedes earlier QR identity and reference rules. New Customer QR submissions have `qr_sequence` only, displayed by one backend helper as QR-01, QR-02, ... (minimum two digits). The explicit counter is keyed by Store Session, which belongs to one branch; a new Store Session starts at 1. Submit, LOAD, Cancel LOAD, archive and restore never allocate official identity or create Payment/inventory/Kitchen effects.

Both Pay Now and Pay Later assign official `order_number` and `reference_number` atomically at commercial commitment. The model permits the one null-to-official transition for a submitted Customer QR Order at commitment; identifiers are immutable afterward. Direct POS still reserves a real identity early and permits documented abandoned-reservation gaps. New references use `BRANCH-MMDDYY-####`, with an independent locked branch/Asia-Manila-date counter starting at 1. Historical identifiers are never rewritten.

Only the owning cashier may cancel a still-submitted loaded QR Order. Cancellation releases the claim and preserves the original submitted snapshots. Cashier name/table changes remain temporary until commitment; both payment flows accept optional name and active current-branch table, preserving order type. No fabricated Walk-in name is introduced.

DELETE means archival. Restore requires an open current Store Session, valid unexpired anonymous session, no conflicting current Order, no load owner or operational effects, and no official identity. It clears archive fields, reattaches the session pointer and renews submitted_at. Legacy archived Orders that already consumed official identity remain retained and are not eligible for restore.

Customer availability requires active branch AND qr_ordering_enabled AND open Store Session. A permanent kiosk_code, initialized from branch code, keeps /kiosk/{kiosk_code} stable if the business code later changes; /qr/{branchUuid} redirects compatibly. Branch-specific encrypted HttpOnly cookies now use / to cover both entry and existing QR APIs; branch/session authorization remains server-enforced.

Preparing/Ready timestamps record actual transitions; rollback clears downstream timestamps. Browse Menu with a current Order is read-only, and New Order is available only after Done/archive. Receipt access remains paid_at + 24 hours, even after another Order begins in the same anonymous session. Internal records persist.

Owner Settings is a partial Phase 16 slice: existing Branch Management, QR toggle/link/history, and typed receipt name/address/contact/footer/show-brand-logo settings plus approved social URLs. Unconfigured social URLs are visibly disabled. Visit history records kiosk link opens (not verified camera scans), deduplicates the same session/branch for two minutes, and stores no IP, user agent or fingerprint. Full Phase 16 remains unimplemented.

## 35. Phase 12 committed transaction rules

History is cashier-only, branch-scoped, server-paginated, and includes committed Orders across prior Store Sessions. Historical reading remains available while mutations require the Order's current OPEN Store Session. Committed edits use an expected Order version and an idempotency UUID. Retained item configurations keep committed snapshots; new or materially reconfigured lines use the current branch catalog.

Tracked inventory changes are aggregated to one net `order_edit_delta` movement per Product and applied in deterministic Product order. Payments and inventory movements are never rewritten. A higher total creates an outstanding balance, the same total creates no Payment, and a lower paid total appends a `lower_total_correction` adjustment. Settlement appends an exact Cash, Cashless, or Split Payment group against authoritative outstanding. Every edit and proof mutation appends an Audit Log.

## 36. Phase 15 Close Store reconciliation rules - 2026-09-23

- Pre-close blockers: any committed, non-voided current-session Order with authoritative outstanding > 0 (unpaid or partial Pay Later and higher-total Balance Due, whatever the payment term); any committed non-voided Kitchen/Preparing/Ready Order; any loaded (claimed) uncommitted Customer QR order; any mixed-method payment correction without a recorded Cash/Cashless source. Unclaimed QR orders and uncommitted POS drafts/reservations do not block.
- Expected Closing Cash/Cashless = Opening + that channel's Payment rows − that channel's Store Expenses − that channel's corrections on non-voided Orders − that channel's payments on voided Orders. Split legs are already separate Payment rows and are shown as an explanatory breakdown only. A voided Order's reversal covers all of its payments, so earlier corrections on it are not subtracted again.
- Expected balances may be negative and are recorded exactly. Actual Closing Cash/Cashless are required, non-negative, and limited to 12 integer digits and 2 decimals.
- Variance = actual − expected for each channel independently. A shortage in either channel blocks normal close and cannot be overridden by a note or offset by the other channel. An overage requires an explanation of at least 5 characters; an exact close needs no note.
- A lower-total correction records the Cash and Cashless amounts returned. Single-method Orders are attributed automatically; mixed-method Orders require the Cashier's explicit Cash portion. Historical unallocated mixed-method corrections must be allocated once, with Audit, before the Store can close.
- Close archives remaining unclaimed QR orders with `store_closed`, records expected/actual/variance/note and a reconciliation snapshot, closes the session, and audits once. A closed Store Session cannot be edited or reopened; the next business day uses normal Open Store.

## 37. Phase 16A Owner Sales & Store Session reporting - 2026-09-24

- **Business date** is the Asia/Manila calendar date on which a Store Session **opened**. A Branch may open and close several Store Sessions on one business date; all of them belong to it. A session that crosses midnight stays entirely under its opening date and is never split across two daily reports. Boundaries are computed in application code (Manila) and queried as UTC half-open ranges; database, server and browser time zones are never relied on.
- Date filters: Today (default), Yesterday, Last 7 days, This month, Custom (at most 31 business dates, validated on the server). Phase 16C adds Last 30 days and Last 12 months; see §38. Store Session filter: All Sessions or one session in the selected dates and Branch scope; a session outside that scope or range is never reported (the report falls back to All Sessions with a notice).
- Branch scope is the global Owner/Super Admin Branch context: All Branches aggregates every Branch the business-wide user may view (including inactive Branches' history); a selected Branch reports only its own sessions. Every Store Session row keeps its Branch identity. Phase 16C adds a neutral, alphabetical Branch comparison for All Branches (§38); there is still no ranking language.
- **Net Sales is sales value, not cash.** It is the current final `orders.total` of committed Active or Completed Orders in the selected sessions. Drafts, submitted/uncommitted or archived QR Orders and voided Orders are excluded. Committed edits are already in the final total and are never subtracted again. Pay Later Orders count as sales before they are paid (Sale ≠ Collection).
- **Orders** counts those same eligible committed, non-voided Orders.
- **Cash / Cashless Collected** are net collections per channel from `StoreSessionReconciliation`: Payment amounts − that channel's allocated corrections on non-voided Orders − that channel's payments on voided Orders. Tendered cash and change are never used. A mixed-method correction still awaiting allocation is reported as pending and never guessed into a channel.
- **Split** is informational only: its legs are already inside Cash and Cashless and are never added again.
- **Corrections** and **Void reversals** are shown separately. A corrected Order that is later voided reverses its payments once; the correction is not subtracted twice. A void reversal equals the payments actually collected, not the Order total.
- **Expenses** are Store Session expenses only (Cash and Cashless). A restock expense has one financial effect. Stock-only Store Session inventory adjustments (Complimentary, Wastage, Damaged, Staff meal, Other) have zero financial effect and are excluded.
- A **CLOSED** session reports its persisted close-time values: reconciliation snapshot, expected, actual, per-channel variance, closing note, closed by and closed at. Nothing is recomputed from browser state. A legacy closed session without a snapshot uses its append-only payment, expense and correction records, and a missing close value is shown as Not available. Negative expected balances are shown exactly. Cash and Cashless variances are never netted: a shortage in either channel makes the session a Shortage.
- An **OPEN** session appears as LIVE with provisional figures from the same canonical read-only reconciliation. It has no actual closing counts. Its expected balance is unavailable while a correction allocation is pending.
- A Store Session with zero activity still appears under its business date with 0.00 values.
- Reporting is read-only for Owner and Super Admin (`reports.view` with business-wide scope). It has no POST/PATCH/DELETE route and never closes a Store, edits, voids, settles, records expenses or adjusts stock.

## 38. Phase 16B–16D Owner workspace completion - 2026-09-24

**Status: implemented, pending USER MANUAL QA and FINAL QA.**

### Business date, periods and comparison

- Phase 16A business-date semantics are unchanged: a period selects the Store Sessions that **opened** on its Asia/Manila business dates. A date with several Store Sessions combines all of them; the Store Session filter drills into one and is additional, never a replacement for the date.
- Reports period tabs follow the Owner standalone: Daily (today), Weekly (last 7 days), Monthly (last 30 days), Yearly (last 12 calendar months, month buckets) and Custom (≤ 31 dates). Yesterday and This month remain valid server presets. The Dashboard tabs are Today, 7 days and 30 days.
- The previous period is the immediately preceding equal span (today → yesterday, N days → the previous N days, 12 months → the same months a year earlier, This month → the same days last month). It uses the same filters and bucket count. Drilling into one Store Session has no comparison. Deltas are computed on the server from exact integers (percent, percentage points for the cashless share, seconds for prep time). A zero previous value with a positive current value reads "New".
- Trend buckets: one day → Manila clock hour of `committed_at`; up to 31 days → business date; 12 months → calendar month of the business date. Hour charts show 6 AM–9 PM, widened to include any real activity outside it.

### Metric definitions (one authority: `SalesAnalytics` over `StoreSessionSalesReport` and `StoreSessionReconciliation`)

- **Total sales** = Phase 16A Net Sales: the current final `orders.total` of committed Active/Completed Orders. **Transactions** = those Orders. **Items sold** = their Order Item quantities. **Average order** = sales ÷ transactions (half-up to the centavo). Voided Orders contribute nothing to any of them.
- **Payment mix / Payment method** = Cash and Cashless net collections from Payment.amount (− allocated corrections − payments of voided Orders), attributed to the Store Session that received the payment, exactly as Close Store. A CLOSED session uses its close-time snapshot. Split legs are already inside Cash and Cashless: Split is an explanatory row and never a third segment or an added amount. `amount_received` and change are never sales. **Cashless share** = Cashless ÷ (Cash + Cashless) collections; it is "—" when nothing was collected.
- **Sales by category / Top products / Product performance** sum immutable Order Item `line_total` snapshots and name products by `product_name_snapshot`. Order Items do not snapshot a category, so categories group by each product's **current** category (a deleted product is "Uncategorized"); this is labelled in the UI and never recomputes amounts from current prices. More than seven categories fold the rest into one "N other categories" row.
- **Peak sales hours** = sales per Manila clock hour of `committed_at` (fixed UTC+8; Manila has no DST). The Dashboard groups them into two-hour blocks.
- **Order type** = sales, transactions, items and average per Dine in / Take out.
- **Kitchen performance**: Orders completed = eligible Orders whose Kitchen status is Done; Average prep time = committed_at → ready_at for Orders that reached Ready (Orders that skipped Ready are not timed); per-hour averages mark the fastest and slowest hours. Preparing now / Ready to serve are live counts of open Store Sessions.
- **Cashier performance**: an Order is credited to its POS creator, or to the cashier who loaded it from the Customer QR queue ("Unattributed" otherwise). Cash / Cashless / Split are sales of that cashier's Orders by how the Order was paid; unpaid Pay Later value is shown separately.
- **Period highlights** are plain selections of the figures above (top category, peak hour, top product, cashless share, strongest day or month) — no estimates or projections.
- **Branch comparison** (All Branches only): per Branch Store Sessions, Orders, sales, Cash, Cashless and expenses, alphabetical, with no ranking language and no stock figures.

### Report filters

- Order type, Payment method (how the Order was paid from all of its legs: Cash only, Cashless only or Split; unpaid Pay Later Orders match none) and Cashier are server-side filters. They narrow every KPI, chart, table and the CSV, **including Cash/Cashless collections**, which are then computed from the same reconciliation formula over the matching Orders.
- Category is **not** a report-wide filter: Payments are recorded per Order, not per item, so Cash and Cashless cannot be split by category truthfully. Category narrows the Product performance table only ("this table only").
- Store Session reconciliation (Collections & drawer effects, Daily summary, Store Sessions and their detail) always covers the whole drawer and is never narrowed by order filters; the page says so while a filter is active. Expenses in the Branch comparison show "—" while an order filter is active.

### Store Session summaries

- Reports lists the Store Sessions of the period (the latest 100 for long periods; totals always include all). The detail shows Branch, opened/closed at and by, orders, Net Sales, Cash/Cashless collections, Split, expenses, corrections, void reversals, opening, expected, actual and variance per channel, closing note and the count of unclaimed Customer QR orders archived at close. CLOSED sessions use the persisted snapshot/close columns; OPEN sessions are LIVE and provisional. The Dashboard shows the latest four with a link that drills Reports into that session.

### Print and export

- Print uses the browser print view (Save as PDF); the management shell navigation is hidden in print. Export offers CSV (`GET workspaces/reports/export`, same validation, scope and filters, throttled, formula-injection safe) and PDF via print. No PDF library was added.

### Owner Dashboard live state

- Inventory attention uses `InventoryState` per Branch for active tracked products: a selected Branch lists out-of-stock then low-stock products with Adjust links into Inventory; All Branches shows low/out **counts per Branch** and never sums stock quantities.
- Kitchen snapshot counts Kitchen / Preparing / Ready tickets of currently OPEN Store Sessions and times the oldest Kitchen/Preparing ticket from `committed_at`; it exposes no money. All Branches sums the discrete ticket counts and names the oldest ticket's Branch.
- Recent transactions are the latest five committed, non-voided Orders in scope; each opens the shared Transaction History detail and receipt.
- Kitchen, inventory and recent transactions refresh every 30 seconds; period analytics reload when the period changes.

### Owner Transactions

- Owner and Super Admin use the **same** Transaction History page (`workspaces/transaction-history`, `surface = business`) at `GET workspaces/transactions` inside their management shell, across All Branches or the selected Branch, with the same server search, filters, metrics, pagination (10 per page), cards, details and receipt. All Branches cards carry the Branch code; details carry Branch identity. Voided Orders stay excluded.
- Edit, Settle and Void are offered only when the viewer also holds POS access to the selected Branch (Super Admin) and the Order belongs to that Branch's OPEN Store Session. The Owner is always read-only: invoice proofs show that they exist but open only from the Branch POS, and Show QR receipt sharing is hidden. Every write endpoint keeps its POS authorization, so hidden buttons are never the control.
