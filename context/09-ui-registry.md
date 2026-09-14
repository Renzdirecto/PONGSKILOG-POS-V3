# PONGSKILOG POS V3 — UI Registry

**Status:** FROZEN — Batch 3  
**Purpose:** Master registry of required user-facing screens, states, and operational surfaces.

---

# 1. Shared / Authentication

## 1.1 Login

Required:

- PONGSKILOG logo/brand image
- Email
- Password
- Show/Hide Password
- Remember Me
- Forgot Password
- Sign In
- Loading state
- Invalid credentials
- Disabled account
- No assigned branch
- Session expired

## 1.2 Branch Selection

Shown if user has multiple assigned branches.

States:

- Branch list
- Branch unavailable
- No authorized branch

## 1.3 Connection State

- Connected
- Reconnecting
- Offline

---

# 2. Cashier / POS Workspace

Navigation:

- Dashboard
- POS / Order
- QR Orders
- Transaction History

## 2.1 Store Entry

### Store Closed

Actions:

- Browse
- Open Store

### Browse Mode

- Read-only indicator
- No operational mutation

### Open Store

Fields:

- Opening Cash
- Opening Cashless

Action:

- Open Store

### Store Already Open

State:

- STORE IS OPEN
- Continue to POS

---

## 2.2 Cashier Dashboard

May show:

- Active branch
- Store state
- Current Store Session
- Today's order count
- Sales summary
- Pending Pay Later count
- Kitchen summary
- QR order count
- Quick actions
- Close Store entry point

---

## 2.3 POS / Order

Required states/screens:

- Dine In / Take Out selection
- Product/category browser
- Search/filter
- Product unavailable/out-of-stock
- Product customization
- Cart
- Empty cart
- Order Information
- Pay Now
- Save / Pay Later
- Payment success
- Pay Later saved success
- Receipt

---

## 2.4 Payment

Contexts:

- New order
- Pay Later settlement
- Delta payment

Methods:

- Cash
- Cashless
- Split

Cash:

- Amount Received
- Exact/quick amounts
- Underpayment error
- Change

Split:

- Cashless amount
- Cash due
- Cash received
- Change
- Validation

---

## 2.5 QR Orders

Required:

- Active QR order list
- Search
- Realtime arrival
- QR order detail
- LOAD
- Archive/Delete confirmation
- Archived / Unclaimed state
- Empty state

Rules:

- No separate QR payment page
- LOAD continues into standard POS flow

---

## 2.6 Transaction History

Required:

- Transaction list
- Search
- Filters
- Pending Pay Later
- Paid
- Completed
- Voided
- Transaction detail

Actions depending on state:

- Pay Now
- Edit Order
- Receipt
- Print
- Void

---

## 2.7 Edit Order

Required:

- Edit items
- Modifier changes
- Quantity changes
- Notes
- Inventory delta preview where relevant
- Same total
- Higher total / delta payment
- Higher total / Pay Later
- Lower total / correction
- Save confirmation

---

## 2.8 Void

Required:

- Void confirmation
- Authorization/re-auth
- Reason
- Final confirmation
- Success
- Failure

---

## 2.9 Store Purchase / Expense

Required:

- Purchase/expense list for current Store Session
- Add purchase/expense
- Description
- Amount
- Cash/Cashless source
- Note/reason
- Optional receipt
- Optional inventory product/quantity
- Save
- Validation
- History/detail

---

## 2.10 Close Store

### Pre-close

Show blockers:

- Outstanding Pay Later
- Kitchen non-DONE orders

Show unclaimed QR summary without blocking.

### Reconciliation

Show:

- Opening Cash
- Opening Cashless
- Cash sales
- Cashless sales
- Split totals
- Store purchases/expenses
- Relevant adjustments
- Expected Cash
- Expected Cashless
- Closing Cash input
- Closing Cashless input
- Variance

### Variance states

- Exact
- Shortage requiring correction
- Overage requiring note

### Success

- Store Closed
- Remaining unclaimed QR archived
- QR ordering disabled

---

# 3. Kitchen Workspace

## 3.1 KDS

Required:

- Active tickets
- KITCHEN
- PREPARING
- READY
- DONE/history if exposed
- Search/filter
- Empty state

Card:

- Order number
- Dine In / Take Out
- Customer/table label
- Time
- Items
- Modifiers
- Notes

No financial data.

## 3.2 Fullscreen Kitchen

- Fullscreen board
- Status filters
- Exit fullscreen
- Page-level vertical scroll

## 3.3 Kitchen Order Update

For edited committed order:

- Added items
- Removed items
- Changed quantity/modifier/note

---

# 4. Customer Display

Branch-specific public display.

Required states:

- Preparing
- Ready
- Empty Preparing
- Empty Ready
- Reconnecting
- Store Closed / inactive state if used

---

# 5. Customer QR Workspace

## 5.1 Store Closed

Required:

- Branch identity
- STORE IS CURRENTLY CLOSED
- Ordering disabled
- Optional read-only menu

## 5.2 Welcome

Required:

- PONGSKILOG identity
- 3-step explanation
- Continue to Menu
- Terms/Privacy access

## 5.3 Menu

Required:

- Search/categories
- Product list
- Availability
- Out-of-stock state
- Active-order browse-only state

## 5.4 Product Customization

Required:

- Modifiers
- Quantity
- Notes
- Add to Cart

## 5.5 Cart

Required:

- Items
- Edit
- Remove
- Total
- Submit

## 5.6 Order Submission

Required:

- Dine In / Take Out
- Branch-valid table where required
- Submit
- Duplicate submission protection

## 5.7 Submitted

Required:

- Order number
- Waiting for Cashier/Payment
- Show number to cashier

## 5.8 Active Tracking

States:

- Waiting for Cashier
- Payment Confirmed
- Unpaid / Pay Later
- In Kitchen
- Preparing
- Ready
- Completed

## 5.9 Archived / Unclaimed

For stale no-action QR order:

- Archived state
- No active ordering continuation
- Clear explanation if customer returns

## 5.10 Receipt

Required:

- Paid receipt
- Save Receipt
- Receipt available
- Receipt expired after 24 hours

---

# 6. Owner Workspace

Navigation:

- Dashboard
- Transactions
- Reports
- Products
- Inventory
- Staff
- Settings

## 6.1 Dashboard

Scopes:

- All Branches
- Specific Branch

May include:

- Sales
- Orders
- Payment mix
- Pending Pay Later
- Product performance
- Inventory alerts
- Branch comparison
- Store Open/Closed state

## 6.2 Transactions

- All Branches / branch filter
- Search/filter
- Transaction detail
- Operational review

## 6.3 Reports

- Date range
- Branch scope
- Sales
- Orders
- Product performance
- Payment mix
- Cashier activity
- Kitchen activity
- Store Session summaries
- Branch comparison

## 6.4 Products

- Product list
- Add/Edit product
- Categories
- Image
- Modifiers
- Global active state
- Branch price override
- Branch availability
- Branch stock threshold

## 6.5 Inventory

- Branch selector
- Stock list
- Low stock
- Out of stock
- Movement history
- Manual adjustment
- Stock transfer

## 6.6 Staff

- Staff list
- Add/Edit staff
- Employee ID
- Roles
- Branch assignments
- Active/Disabled

## 6.7 Settings

Business-wide:

- Brand/business info
- Defaults
- Receipt defaults

Branch-specific:

- Branch info
- Address/contact
- Hours
- Tables
- Customer QR
- Cashless display
- Receipt branch info

---

# 7. Super Admin Workspace

Core navigation:

- Dashboard
- Audit Trail
- Void Orders
- Access Control
- Settings

Super Admin retains full business-wide permission regardless of workspace presentation.

## 7.1 Dashboard

- Cross-branch overview
- Store state per branch
- Important operational alerts
- System/control summaries

## 7.2 Audit Trail

Filters:

- Branch
- User
- Action
- Module
- Date

Detail:

- Before
- After
- Actor
- Timestamp
- Branch
- Metadata

## 7.3 Void Orders

- Voided transaction list
- Branch/date/user filters
- Void reason
- Original payment
- Inventory restoration
- Audit reference

## 7.4 Access Control

- Role matrix
- Permissions
- Restricted controls
- Save
- Confirmation/audit

## 7.5 Settings

System/business controls not exposed to normal operational staff.

---

# 8. Branch Management

Required:

- Branch list
- Add branch
- Edit branch
- Branch code
- Address/contact
- Hours
- Active
- Temporarily Closed
- Inactive
- Tables
- Customer QR
- Cashless display
- Receipt info
- Store state
- Historical branch view

---

# 9. Stock Transfer

Required:

- New transfer
- Source branch
- Destination branch
- Product/quantity
- Requested
- Sent
- Received
- Completed
- Transfer detail/history

---

# 10. Global States

Every major surface should define:

- Loading
- Empty
- Error
- Permission denied
- Branch unavailable
- Reconnecting
- Offline
- Stale data/refetch state where needed

---

# 11. UI Registry Rules

1. Every screen must map to a real workflow.
2. Avoid duplicate payment surfaces.
3. Avoid duplicate transaction pages.
4. Customer QR and Customer Display remain public-style surfaces.
5. Internal UI must match backend authorization.
6. Mobile/tablet are required states, not separate products.
7. Realtime updates should modify the smallest practical UI region.
8. Visible production controls must function or be intentionally disabled with a clear reason.
