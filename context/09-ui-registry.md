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
- Order Information (optional table choices for Dine In and Take Out)
- Pay Now (same optional table choices)
- Save / Pay Later
- Payment success
- Pay Later saved success
- Receipt

---

## 2.4 Payment

Branch table selection is optional for both Dine In and Take Out. If selected, the table must be active and belong to the current branch.

Pay Now and Order Information expose selected/deselected table chips for both order types. Pay Now uses compact bordered summary rows, grouped cash shortcuts and a prominent Change surface; tablet payment controls do not scroll independently.

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

**Implementation status:** Approved and implemented in Phase 8 (2026-09-22).

**Canonical implementation:** `resources/js/pages/workspaces/kitchen.tsx`,
`resources/js/lib/kitchen.ts`, and
`resources/js/hooks/use-branch-realtime-refresh.ts`.

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

**Implementation status:** Approved and implemented in Phase 9 (2026-09-22).

**Canonical implementation:**
`resources/js/pages/workspaces/customer-display.tsx`. This surface deliberately
does not render the employee workspace shell or shared auth/profile props.

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
- Optional active current-branch table for Dine In and Take Out
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

---

# 12. Implemented Owner Management Pattern

The decoded `context/design/PONGSKILOG-OWNER.html` is the primary visual and interaction reference for Owner management surfaces.

- Wide desktop uses a 248px dark sidebar and 72px top bar; tablet uses a 96px dark rail; mobile uses a 60px top bar and fixed four-item bottom dock with a More sheet.
- Owner pages use Poppins, a `#F7F7F7` canvas, compact 11–13.5px supporting text, 23px desktop page titles, white 20px-radius panels, restrained borders/shadows, and minimum 44px interactive targets.
- Products, Categories, and Modifiers share segmented route navigation. Product stock is branch-specific; All Branches never fabricates an aggregate stock value.
- Inventory uses full-dataset server summaries, compact filters, a dense desktop table, wrapped mobile rows, real update timestamps, and real adjustment/history actions.
- Management dialogs become bottom sheets on mobile and centered dialogs from the small desktop breakpoint upward.
- Dashboard, Products, Inventory, and Branch Management link to real protected routes. Unimplemented Transactions, Reports, and Staff destinations remain visibly disabled with a reason.
- The Owner presentation never replaces backend permission, branch, inventory, catalog, image, or Store Session authority.

## 12.1 Standalone Product Editor and Groups

- **Status:** Approved
- **Purpose:** Keep Add/Edit Product in one complete workflow while exposing real catalog, image, branch, inventory-configuration, and reusable Group behavior.
- **Canonical implementation:** `resources/js/components/product-editor-form.tsx`, hosted by `resources/js/pages/catalog/products.tsx`
- **Reference:** `context/design/PONGSKILOG-OWNER.html` product modal
- **Last updated:** 2026-09-21

| Property | Approved pattern |
| --- | --- |
| Anatomy | Compact kicker/title header; image panel; two-column product fields from small screens upward; optional description; branch configuration; Options; fixed Cancel/Save footer. |
| Groups | “Groups” is the user-facing term. Existing reusable Groups render as complete read-only assignment cards with option price/status rows; removing one detaches only its Product assignment. New Groups and Options are created inline in the same Product save. The behavior picker uses Standard options, Size, and Instructions rather than exposing raw enum values. |
| Branch and stock truth | A selected global branch exposes only that branch configuration and exact stock-on-hand. All Branches exposes authorized configurations but never a summed stock value. Inventory quantities remain read-only here and are changed only through Adjust Stock. |
| Images | Use the signed optimized image/fallback, a visible choose/replace control, file validation errors, and the existing protected image operations. |
| Interactive states | Disable all editor controls during submission, guard duplicate save, retain explicit active/branch-available/inventory-tracked states, and keep destructive assignment controls visually red with text/labels. |
| Responsive behavior | Centered, maximum-height dialog at wider widths; edge-to-edge bottom sheet on mobile. Header and action footer remain fixed while the form body scrolls. Controls stack without horizontal document/dialog overflow at 360, 390, and 430px. |
| Accessibility | Native labelled inputs/selects, an ARIA switch for Product availability, named icon buttons, 44px minimum actions, keyboard-dismissable modal, visible focus, and non-color status text. |

**Usage:** Reuse this composition for future Owner catalog editors that combine global fields with authorized branch settings and reusable child records.

**Avoid:** Tabs that split one Product save across separate Details/Image/Branch forms; checkbox walls for Group assignment; editing inventory balances from the Product modal; fabricated All Branches totals; or UI-only authorization.

## 12.2 Reactive Owner Collections and Inventory History

- **Status:** Approved
- **Purpose:** Keep Owner list filters fast and truthful while supporting tile/list presentation and contextual history without leaving the current task.
- **Canonical implementation:** `resources/js/pages/catalog/products.tsx`, `resources/js/pages/catalog/categories.tsx`, and `resources/js/pages/inventory/index.tsx`
- **Reference:** `context/design/PONGSKILOG-OWNER.html`
- **Last updated:** 2026-09-21

| Property | Approved pattern |
| --- | --- |
| Filtering | Search/select changes issue debounced, replace-history Inertia GET visits against server-authoritative pagination; localized updating text avoids blanking the list. |
| Presentation | Tile/list is local presentation state only and does not trigger a backend visit. Disabled Product management cards use an explicit pale-red border/background and Disabled text badge while preserving Edit/Enable. |
| Category identity | Category icons are allowlisted keys rendered through the shared Lucide map; use a generic fallback and never persist arbitrary SVG/JSX. |
| Inventory scope | A selected global branch drives quantities, summaries, adjustment, and history. All Branches requires a local branch choice and never aggregates stock. |
| History | View History opens real paginated branch/Product movements in a responsive dialog/bottom sheet; the protected deep-link page remains a fallback. |
| POS availability and semantic Groups | Unavailable Products stay visible but disabled with a reason. Only a Group explicitly marked with the `size` semantic role may prefix the operational Product name. `instruction` selections render as price-neutral preparation details, while Standard options remain priced detail rows. |
| Accessibility and responsive behavior | Filters retain labels, state includes text as well as color, actions meet touch sizing, dialogs manage focus, and lists/tables collapse without horizontal overflow. |

**Usage:** Reuse for Owner catalog/inventory collections and for future history drill-ins where the current filtered context should remain visible.

**Avoid:** Client-only authoritative filtering, presentation toggles that refetch, unavailable cards that can open ordering, inferred Size semantics from names, cross-branch quantity sums, or unprotected history payloads.

## 12.3 Instructions Groups and realtime POS catalog refresh

- **Status:** Approved
- **Purpose:** Reuse the Group engine for structured preparation choices and keep active POS catalogs current without discarding cashier intent.
- **Canonical implementation:** `resources/js/pages/catalog/modifiers.tsx`, `resources/js/components/product-editor-form.tsx`, `resources/js/components/pos-product-dialog.tsx`, `resources/js/hooks/use-pos-catalog-realtime.ts`
- **Reference:** `context/design/PONGSKILOG-OWNER.html`, `context/design/pos.html`, and `context/06-realtime-contracts.md`
- **Last updated:** 2026-09-21

| Property | Approved pattern |
| --- | --- |
| Semantic behaviors | Standard options may affect price; Size may prefix the operational display name; Instructions are structured preparation selections that never change name or price. Behavior comes from the semantic role, never the Group name. |
| Owner editor | Show a plain-language Behavior select. Instructions force optional minimum `0`, multiple selection, a practical maximum, and fixed `₱0.00` options with a short explanatory state. The same behavior is available to inline Add Group in Product Add/Edit. |
| POS interaction | Render instruction options as compact, touch-friendly selectable chips with a strong selected state and no price label. Show an `Instructions:` preview separately from the free-text `Note:` value. |
| Realtime state | Subscribe once to the active branch inventory channel, debounce event bursts, request only the authoritative catalog prop, and refetch on reconnect. Preserve cart, order type, payment flow, open Product dialog, chip selections, and notes. |
| Availability transition | A refreshed open Product dialog reflects the latest availability, preserves current input, explains the conflict, and disables Add/Update while unavailable. Existing cart intent is not silently rewritten; the backend revalidates at commit. |
| Historical output | Persist Group name, option name, semantic role, and zero instruction price snapshots. Cart, payment, paid, receipt, and order-summary surfaces aggregate instructions without presenting them as priced modifiers. |
| Responsive behavior | Chips wrap without horizontal overflow and retain at least 44px touch height. Owner and POS dialogs remain scrollable/reachable at 360, 390, and 430px, tablet, and desktop widths. |
| Accessibility | Use native checkbox/radio semantics where visible, labelled controls, text in addition to color for selected/unavailable states, and an alert for a live availability conflict. |

**Usage:** Reuse semantic Instructions for future Kitchen and Customer QR rendering; keep the stored structured selections distinct from manual notes.

**Avoid:** Inferring behavior from names, copying instruction labels into the notes textarea, attaching prices to instruction options, remounting the Product dialog during catalog refresh, or treating a realtime payload as authoritative catalog data.

## 12.4 Kitchen lifecycle, Customer Display, and POS Ready queue

- **Status:** Approved
- **Purpose:** Keep Kitchen, cashier handoff, and customer-facing order status synchronized without exposing financial or private operational data.
- **Canonical implementation:** `resources/js/pages/workspaces/kitchen.tsx`, `resources/js/pages/workspaces/customer-display.tsx`, `resources/js/components/pos-ready-notifications.tsx`, and `resources/js/hooks/use-branch-realtime-refresh.ts`
- **Reference:** `context/design/pos.html` and `context/06-realtime-contracts.md`
- **Last updated:** 2026-09-22

| Property | Approved pattern |
| --- | --- |
| Kitchen board | Use the operational shell, status tabs/counts, order/customer search, colored lifecycle cards, immutable item/modifier/instruction/note snapshots, summary footer, and a bounded Done view. Never include financial data. |
| Lifecycle controls | Allow forward jumps and one-step rollback for Kitchen users. Cashier handoff permits only Ready to Done. Duplicate targets are idempotent no-ops and server state remains authoritative. |
| Fullscreen | Use the browser Fullscreen API with a fixed dense board, responsive 2/3/4/5/6-column progression, page-level scrolling, and a visible Exit action; hide employee navigation, search, and footer while fullscreen. |
| Customer Display | Render a full-canvas, order-number-only Preparing/Ready surface. Map Kitchen into Preparing, remove Done, stack on phones, retain two readable columns on tablet/desktop, and show truthful empty/closed states. Do not render employee chrome, profile, customer names, tables, items, money, or internal IDs. |
| POS Ready | Keep the bell/list, lower-left Ready queue, detail dialog, Not yet, and Mark as done outside the cart component so realtime refresh does not discard cart, dialog, or payment state. |
| Realtime | Treat compact branch-private events as invalidation signals. Debounce/coalesce partial authoritative reloads and refetch after reconnect rather than applying payloads as source data. |

**Usage:** Reuse this lifecycle and projection boundary for future Kitchen and customer-status enhancements.

**Avoid:** Trusting client status, broadcasting customer-display order lists or internal IDs, leaking shared employee props into the customer surface, remounting POS state during refresh, or adding card-internal scrolling in fullscreen.


## 12.5 Customer QR and staff QR retrieval

- **Status:** Implemented; latest UI/UX direction manually accepted by the user before final release audit.
- **Reference:** Decoded `customer-qr.html`, `pos.html`, and `PONGSKILOG-OWNER.html`.
- **Canonical implementation:** `customer-qr.tsx`, `customer-qr-product.tsx`,
  `customer-qr-tracking.tsx`, `staff-qr-orders.tsx`, existing `cashier-pos.tsx`, and
  `pages/branches/index.tsx`.
- **Last updated:** 2026-09-22.

| Surface | Implemented behavior |
| --- | --- |
| Customer entry | Existing branch QR route, no employee shell/props, authoritative Closed state, logo/branch, three reference steps and Terms/Privacy tabs. |
| Menu | Search, categories, available reference favorites matched by real product name, responsive favorites tiles and menu rows, product images/fallbacks, availability labels, cart/current-order bar, and reference social actions. No invented favorites or sales ranking. |
| Customization/cart | Quantity, required/optional Groups, Size, zero-price Instructions, separate notes, edit/remove confirmation, exact totals, required Dine In/Take Out, optional name. The current customer reference has no table picker. |
| Submit | Stable retry intent, confirmed success/order number, no optimistic payment/Kitchen state, clear validation/offline conflicts and preserved input. |
| Active order | Browse-only product views and current-order return; truthful payment and kitchen stages; explicit Start new order only after Done or archive. Pay Later can progress through Kitchen while Payment remains pending. |
| Receipt | Owned paid snapshots, cash/cashless/split legs, cash received/change, real 24-hour expiry, receipt-card-only PNG save at 2x resolution with configured branding. |
| Cashier | Existing operational navigation and QR badge, Waiting/Archived queue, search, detail, LOAD and Delete confirmation; paginated server data and realtime claim removal. |
| Owner | Existing Branch management with real scannable QR display, View QR, ordering link and Copy link; existing BaconQrCode dependency, no permanent customer identity encoded. No analytics/hours/settings expansion or unsupported QR download/print control. |

The standalone LOAD routine replaces its demo cart directly. The explicit
no-data-loss requirement takes precedence: a current POS cart blocks LOAD and is
preserved. The loaded QR uses the existing persisted-order POS path with immutable
submitted selections/prices; unrestricted demo-cart editing is not reproduced.
Committed editing remains Phase 12. New Closed/Archived/expiry/error states use
the frozen requirements where the reference only simulated or omitted them.

**User-directed tablet adjustment:** From 768px upward, the operational POS/Kitchen
shell retains its compact 94px sidebar, including iPad Mini portrait and landscape.
Only phones below 768px use the floating navigation dock and its bottom clearance.
Normal Kitchen uses one column below 768px, two columns from 768px, and three
columns from 1180px, matching the user-requested iPad layout. Fullscreen remains
independent.

The user supplied manual acceptance of the latest UI/UX direction. The final audit
uses source and automated checks without repeating a broad browser visual sweep.


---

## Approved manual-QA refinement slice - 2026-09-22

Primary decoded references remain customer-qr.html, pos.html and PONGSKILOG-OWNER.html; customer-qr-old.html is legacy only. Cashier cards now use provisional red QR identity, optional black name, green Dine In / sky Take Out badge, clock + shared elapsed timer, compact item summary, UNPAID/total, and LOAD | VIEW | DELETE. Archived cards expose VIEW/RESTORE. Delete confirmation uses the reference's Keep it / Delete order hierarchy and backend archival.

Customer updates include configured/disabled welcome social links, allowlisted category icons, cart icon/quantity count, top-scoped success toast, Confirm Order CTA, green/blue order-type selection, Track/View icons, green top View Order action, actual timestamped colored timeline, rounded Browse/New Order + View Order + Receipt actions, and Stay connected on tracking/receipt. Existing browse-only protection and terminal-only reset remain.

Owner Settings exposes Branch Management and Receipt only. Existing branch CRUD is reused. QR modal adds QR/History tabs, stable kiosk link/image, independent enablement, date-filtered bounded activity, and truthful copy/open behavior. Receipt fields configure safe public identity, logo visibility, and validated custom logo upload/replacement/removal. Customer receipt export is PNG. Printer integration and full Phase 16 remain deferred.

Visual acceptance comes from the user's manual QA. No broad browser sweep was performed during the final audit.
