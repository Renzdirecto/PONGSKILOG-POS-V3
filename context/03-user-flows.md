# PONGSKILOG POS V3 — User Flows

**Status:** FROZEN — Batch 1  
**Business model:** One PONGSKILOG business with multiple branches

---

## 1. Cashier Login

Cashier logs in  
↓  
System loads assigned branch/branches  
↓  
If multiple branches are assigned, choose active branch  
↓  
System checks branch Store Session

---

## 2. Store Closed Flow

Store Session is Closed  
↓  
Show:

- **Browse**
- **Open Store**

### Browse

Enter read-only mode.

Cashier may view allowed information but cannot:

- Create order
- Edit
- Pay
- Save Pay Later
- Void
- Delete
- Adjust inventory
- Record Store Purchase
- Perform other operational CRUD

### Open Store

Tap Open Store  
↓  
Enter:

- Opening Cash
- Opening Cashless

↓  
Confirm  
↓  
Backend creates active branch Store Session  
↓  
Store becomes Open  
↓  
Normal POS operational access begins

---

## 3. Another Cashier Logs In

Second/later Cashier logs in  
↓  
System detects active branch Store Session  
↓  
Show:

**STORE IS OPEN**

↓  
Do not request opening balances again  
↓  
Cashier enters normal POS

All authorized Cashiers share the same branch Store Session.

---

## 4. Customer QR — Store Closed

Customer opens branch QR site  
↓  
System checks branch Store Session  
↓  
Store is Closed  
↓  
Show:

**STORE IS CURRENTLY CLOSED**

↓  
Disable new order submission

---

## 5. Cashier — New Order

Store must be Open  
↓  
New Order  
↓  
Choose:

- Dine In
- Take Out

↓  
Browse branch-valid products  
↓  
Customize  
↓  
Add to cart  
↓  
Review  
↓  
Choose:

- Pay Now
- Save / Pay Later

---

## 6. Pay Now

Cart  
↓  
Pay Now  
↓  
Order Information / Summary  
↓  
Choose:

- Cash
- Cashless
- Split

### Cash

Enter amount  
↓  
Validate  
↓  
Commit:

- Paid order
- Payment
- Branch inventory deduction
- Branch Kitchen ticket

↓  
Broadcast after commit

### Cashless

Manual confirmation  
↓  
Commit payment/order/inventory/Kitchen flow

### Split

Set Cash + Cashless  
↓  
Validate total  
↓  
Commit both legs atomically  
↓  
Inventory/Kitchen update once

---

## 7. Save / Pay Later

Cart  
↓  
Save / Pay Later  
↓  
Order Information

### Dine In

Optionally enter a customer/order label and select a table.

### Take Out

Optionally enter a customer/order label and select a table.

Branch table selection is optional for both Dine In and Take Out. If selected, the table must be active and belong to the current branch. Both Order Information / Pay Later and Pay Now show the active branch table choices for either order type. Changing order type preserves the selected table; clicking the selected table again clears it. No separate No table option is required.

↓  
Save  
↓  
Backend commits:

- Order = **UNPAID / PAY LATER**
- Branch inventory deduction
- Branch Kitchen ticket
- Transaction History entry

↓  
Broadcast after commit  
↓  
Kitchen receives order immediately  
↓  
Cashier may begin another order

---

## 8. Pay Later Kitchen Flow

UNPAID / PAY LATER order  
↓  
KITCHEN  
↓  
PREPARING  
↓  
READY  
↓  
DONE

Payment may remain outstanding while Kitchen finishes the order.

---

## 9. Pay Pay Later Order

Transaction History  
↓  
Open UNPAID / PAY LATER order  
↓  
Pay Now  
↓  
Choose Cash / Cashless / Split  
↓  
Confirm payment  
↓  
Order becomes Paid

Do not:

- Deduct stock again
- Create another Kitchen ticket

---

## 10. Edit Pay Later Order

Open Pay Later transaction  
↓  
Edit Order  
↓  
Change items/modifiers/quantity  
↓  
Save  
↓  
System calculates inventory delta

Example:

Before:
- 2 Pork

After:
- 1 Pork
- 1 Chicken

Inventory:
- +1 Pork
- -1 Chicken

↓  
Audit change

---

## 11. Customer QR — Open Store Submission

Customer scans branch QR  
↓  
Store is Open  
↓  
Browse branch menu  
↓  
Customize  
↓  
Cart  
↓  
Choose Dine In / Take Out  
↓  
Submit  
↓  
Create unpaid QR order  
↓  
Return branch order number

At submission:

- No stock deduction
- No Kitchen ticket

Status:

**Waiting for Cashier / Payment**

---

## 12. QR Auto-Archive

QR order receives no staff action for 30 minutes  
↓  
Move to:

**ARCHIVED / UNCLAIMED**

↓  
Remove from active QR queue

No inventory/payment/Kitchen effect occurs.

Record remains retained for history.

---

## 13. Cashier — QR Retrieval

Cashier opens QR Orders  
↓  
Search/open active QR order  
↓  
Tap **LOAD**  
↓  
Order enters normal POS flow

Then choose:

### Pay Now

Payment  
↓  
Inventory deduction  
↓  
Kitchen ticket

### Save / Pay Later

UNPAID / PAY LATER  
↓  
Inventory deduction  
↓  
Kitchen ticket

---

## 14. Transaction History

Branch staff see authorized branch records.

Owner/Super Admin may choose:

- All Branches
- Specific Branch

Filters may include:

- Branch
- Order number
- Customer/table
- Date
- Status
- Kitchen status
- Payment method
- Order type

---

## 15. Edit Recorded Order

Open transaction  
↓  
Edit  
↓  
Authorization if required  
↓  
Save  
↓  
Audit + inventory delta reconciliation

If already paid:

### Higher total

Collect difference now or save outstanding balance.

### Lower total

Create explicit correction/adjustment record.

Original payment remains.

---

## 16. Void

Open transaction  
↓  
Void  
↓  
Authorization  
↓  
Reason  
↓  
Confirm  
↓  
Mark Voided  
↓  
Restore inventory through compensating movement  
↓  
Retain original order/payment history  
↓  
Audit

---

## 17. Kitchen

Kitchen Staff enters branch  
↓  
Sees branch tickets from:

- Paid orders
- Pay Later orders

Lifecycle:

KITCHEN  
↓  
PREPARING  
↓  
READY  
↓  
DONE

---

## 18. Customer Display

Branch-specific display shows:

- Preparing order numbers
- Ready order numbers

No financial/private information.

---

## 19. Customer QR Tracking

### Direct payment path

Waiting for Payment  
↓  
Payment Confirmed  
↓  
In Kitchen  
↓  
Preparing  
↓  
Ready  
↓  
Completed

### Pay Later path

Waiting for Cashier  
↓  
Unpaid / Pay Later  
↓  
In Kitchen  
↓  
Preparing  
↓  
Ready  
↓  
Completed

---

## 20. Customer Receipt

After payment  
↓  
Receipt becomes available  
↓  
Customer may Save Receipt

Available for 24 hours after payment.

---

## 21. Store Purchase / Expense

While Store Session is Open  
↓  
Cashier records Store Purchase / Expense  
↓  
Enter:

- Description/item
- Amount
- Payment source:
  - Cash
  - Cashless
- Note/reason
- Optional receipt image
- Optional inventory product + quantity

### If normal expense

Record financial expense only.

### If inventory restock

Record financial expense  
+  
Increase branch inventory  
+  
Create inventory movement

Store Purchase affects expected closing balance based on payment source.

---

## 22. Cashier Initiates Close Store

Cashier taps **Close Store**  
↓  
System runs pre-close checks

---

## 23. Close Store — Pay Later Check

If any UNPAID / PAY LATER remains:

**BLOCK CLOSE STORE**

↓  
Cashier must resolve each order through normal flow:

- Pay; or
- Authorized Void when legitimate

↓  
Recheck

---

## 24. Close Store — Kitchen Check

If any committed Kitchen order is:

- KITCHEN
- PREPARING
- READY

then:

**BLOCK CLOSE STORE**

↓  
All committed Kitchen orders must become:

**DONE**

↓  
Recheck

---

## 25. Close Store — QR Handling

Unclaimed QR orders do not block close.

At close:

- Archive all remaining active unclaimed QR submissions
- Preserve records for history

---

## 26. Closing Reconciliation

After blockers pass  
↓  
System shows session summary:

- Opening Cash
- Opening Cashless
- Cash sales
- Cashless sales
- Split-payment breakdown
- Store purchases/expenses
- Relevant adjustments/void effects
- Expected Closing Cash
- Expected Closing Cashless

↓  
Cashier manually enters:

- Closing Cash
- Closing Cashless

↓  
System calculates:

- Cash variance
- Cashless variance

---

## 27. Closing Variance

### Exact

Proceed to Close Store.

### Shortage

Normal Close Store is blocked.

Cashier reviews/corrects:

- cash count
- cashless balance
- missing Store Purchase/Expense
- unresolved transaction
- incorrect entry

Then recalculate.

### Overage

Cashier enters required note/reason.

Close Store may proceed.

---

## 28. Close Store Commit

Confirm Close Store  
↓  
Backend atomically records:

- Closing Cash
- Closing Cashless
- Expected balances
- Variances
- Required notes
- Remaining QR archives
- Store Session close
- Audit event

↓  
Store Session = CLOSED  
↓  
Operational POS actions disabled  
↓  
Customer QR ordering disabled  
↓  
QR site shows Store is currently closed

---

## 29. Owner

Owner can:

- View All Branches
- Switch to one branch
- Review Dashboard
- Transactions
- Reports
- Products
- Inventory
- Staff
- Settings

Store-session summaries may be used for branch oversight and reconciliation reporting.

---

## 30. Super Admin

Super Admin can review:

- Audit Trail
- Void Orders
- Access Control
- Cross-branch activity

Audit includes:

- Store Open
- Opening Cash/Cashless
- Store purchases/expenses
- Closing Cash/Cashless
- Variances
- Store Close

---

## 31. Branch Realtime

Branch A write commits  
↓  
Broadcast Branch A event  
↓  
Branch A operational clients update

Branch B receives nothing.

---

## 32. Offline

If offline, block:

- Payment
- Pay Later save
- Void
- Store Open
- Store Close
- Inventory-changing Store Purchase

No fake local success.


---

## Approved QR refinement flows - 2026-09-22

Customer: permanent kiosk link -> welcome/social links -> menu/category icons -> cart quantity count -> Confirm Order -> submit QR-01 -> track. Before commitment, the provisional label is used; after Pay Now/Pay Later, the official number and reference are shown. Current-order menu browsing cannot add or submit another order and offers Back to Track Order. Tracking uses real Kitchen/Preparing/Ready/Done times and amber/blue/green/gray treatments. Receipt stays disabled when unavailable; Back to Order and Stay connected are shared with receipt. Paid receipt A remains owned after beginning order B until A's own 24-hour deadline.

Cashier: QR queue -> server-confirmed LOAD -> optional name/table -> Pay Now or Pay Later. An unrelated POS cart blocks LOAD. Cancel Loaded Order releases only the loaded QR, discards its temporary metadata and returns to the waiting queue. DELETE opens the standalone confirmation and archives; Archived exposes VIEW/RESTORE, never LOAD. Eligible RESTORE renews the archive timer and invalidates staff/customer projections. Navigation is Dashboard, POS, QR Orders, Kitchen, History, Display with existing permission checks and unavailable History.

Owner: Settings -> Branch Management -> EDIT or VIEW QR -> QR/History tabs. QR shows the permanent public link, image, availability, independent toggle, VIEW QR and COPY LINK. History defaults to today's Manila date, shows bounded link-open activity and supports date selection. Receipt settings save typed customer-visible identity fields per branch. Final responsive and interaction acceptance remains USER MANUAL QA REQUIRED.
