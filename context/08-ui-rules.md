# PONGSKILOG POS V3 — UI Rules

**Status:** FROZEN — Batch 3  
**Purpose:** Define the visual and interaction rules for all PONGSKILOG V3 workspaces.

---

## 1. Core Design Direction

Internal application should feel:

- Modern
- Fast
- Commercial
- Clean
- Operational
- Mobile/tablet-first
- Easy to scan under pressure

Base visual system:

- Dark sidebar: near-black / `#111111`
- Main surfaces: white / light neutral
- Primary internal UI font: Poppins
- Moderate radii
- Borders + subtle elevation
- Avoid heavy glassmorphism
- Avoid decorative clutter

---

## 2. Brand / Login

Login must use the real PONGSKILOG logo/brand artwork.

Desktop direction:

- Brand visual area
- Clean login panel
- Full-height layout
- No generic Laravel/admin-template feel

Mobile:

- Brand image at top
- Login form immediately below
- No horizontal overflow
- Inputs/buttons easy to tap

Required working interactions:

- Email input
- Password input
- Show/Hide Password
- Remember Me
- Forgot Password
- Sign In
- Validation states
- Loading state
- Prevent repeated Sign In clicks

---

## 3. Semantic Colors

Use consistently:

- DINE IN = light green
- TAKE OUT = light blue
- KITCHEN = light yellow
- PREPARING = light blue
- READY = light green
- UNPAID / PAY LATER = light red or amber-red treatment
- CASH = green
- CASHLESS = blue
- SPLIT = light blue
- VOID = red text/action
- PRINT = blue text/action
- PAY NOW = green
- PAY LATER = yellow/amber

Visible generic payment label is **Cashless**, never generic `GCash`.

---

## 4. Typography

Internal operational UI:

- Page title: ~17px / 700
- Section title: 14–15px / 700
- Product/card title: ~14px
- Price: 13–14px
- Body: 12–13px
- Metadata: 11–12px
- Button: 13–14px

Mobile input text should remain around 16px where browser zoom is a concern.

Do not reduce text until it becomes difficult to scan.

---

## 5. Mobile Density

Target:

- Horizontal padding: 12px
- Card padding: 10–12px
- Gap: ~8px
- Section spacing: 16–20px
- Primary CTA: 46–48px
- Practical touch target: 44px+

Reduce vertical footprint using:

- Better grouping
- Smaller images
- Tighter padding/gaps

Not tiny typography.

---

## 6. Responsive Behavior

Primary QA widths:

- 360px
- 390px
- 430px
- Tablet
- Desktop

Mobile must be intentionally designed, not merely a shrunken desktop.

No horizontal overflow.

---

## 7. Internal Navigation

Internal workspaces use the frozen dark-sidebar design language.

Mobile/tablet may use compact/floating navigation where appropriate.

Customer QR and Customer Display do not inherit the internal staff sidebar.

---

## 8. Store Entry UI

### Store Closed

Show two clear choices:

- Browse
- Open Store

### Browse

Show clear indicator:

**READ-ONLY / STORE CLOSED**

Mutation controls should be disabled/hidden appropriately, while backend remains authoritative.

### Open Store

Fields:

- Opening Cash
- Opening Cashless

Action:

- Open Store

### Store Already Open

Show:

**STORE IS OPEN**

Then continue into normal POS.

---

## 9. Customer QR — Store Closed

When Store Session is Closed:

- Site remains accessible
- Show clear closed state
- Disable new order submission

Required message:

**STORE IS CURRENTLY CLOSED**

---

## 10. POS Start

Before menu/cart interaction:

Choose:

- Dine In
- Take Out

This is required for every new POS order.

---

## 11. Product Cards

Product cards must:

- Load fast
- Keep consistent image ratios
- Avoid layout shifts
- Show fallback image/state
- Show branch-valid price
- Show out-of-stock/unavailable clearly

Use optimized thumbnails, not oversized originals.

---

## 12. Product Customization

Mobile:

- Fullscreen/native-like sheet preferred

Tablet/Desktop:

- Modal/dialog or side sheet

Show:

- Required modifiers
- Optional modifiers
- Quantity
- Notes
- Price impact

Drink size should appear in visible item name where configured.

---

## 13. Cart

Show:

- Quantity
- Product name
- Size/modifiers
- Notes
- Price/line total
- Edit/remove

Visual rules:

- Price = red
- Quantity prefix such as `1×` = red
- Notes = light orange highlight

---

## 14. Payment UI

Reusable payment surface for:

- New order
- Pay Later settlement
- Delta payment

Methods:

- Cash
- Cashless
- Split

Avoid creating duplicate payment screens for QR.

Branch table selection is optional for both Dine In and Take Out. If selected, the table must be active and belong to the current branch.

Both Order Information / Pay Later and Pay Now display the same optional table chips for both order types, with a selected highlight; clicking the selected table again clears it. No separate No table option is required. Switching order type does not clear the table. Take Out still requires a customer/order label; Dine In does not.

Pay Now follows the standalone compact bordered Order Summary rows, with red quantity/amount, black product names, and compact modifier/note details. Group Exact / PHP50 / PHP100 / PHP500 / PHP1,000 together; shortcuts always set Cash received, and Split Exact uses the remaining cash due after Cashless. Hide cash shortcuts for Cashless-only. Use exact integer-cent previews.

Show Total, Received, Remaining and a prominent dark Change surface (including zero). Omit the redundant Payment preview heading. At 820px and 1024px, use a two-column modal with a non-scrolling right payment panel; long left content may scroll. Mobile may scroll without horizontal overflow. Phase 5 Confirm Payment remains disabled: "Payment confirmation will be enabled in Phase 6." Pay Later activation remains disabled until Phase 7.

---

## 15. Pay Later UI

Use explicit status:

**UNPAID / PAY LATER**

It must communicate:

- Inventory is already committed
- Kitchen is already active
- Payment remains outstanding

Do not visually present it as an uncommitted draft.

---

## 16. Kitchen UI

Kitchen cards show:

- Order number
- Dine In / Take Out
- Time
- Items
- Modifiers
- Notes

No financial values.

Lifecycle:

- KITCHEN
- PREPARING
- READY
- DONE

Fullscreen:

- Hide normal shell
- Page-level scroll allowed
- No internal card scroll

---

## 17. Customer Display

Show only:

- Preparing
- Ready

Use large readable order numbers.

No prices/private data.

---

## 18. QR Orders Staff UI

Primary action:

**LOAD**

Secondary action:

**Archive/Delete** with confirmation where appropriate.

No direct Pay Now button from QR Orders page.

Expired/no-action QR may show:

**Archived / Unclaimed**

---

## 19. Customer QR UI

Public, mobile-first.

Core states:

- Store Closed
- Welcome
- Menu
- Product customization
- Cart
- Submitted / Waiting for Cashier
- Unpaid / Pay Later
- In Kitchen
- Preparing
- Ready
- Completed
- Receipt
- Receipt expired

No customer login.

---

## 20. Transaction History

Filters may include:

- Branch
- Order number
- Customer/table
- Date
- Commercial status
- Kitchen status
- Payment method
- Order type

Use compact cards/rows and semantic statuses.

---

## 21. Edit / Void UX

Edit flow should show:

- Current state
- Updated state
- Payment impact
- Inventory impact when relevant

Void flow shows:

- Warning
- Authorization/re-auth step
- Reason
- Confirmation

Do not hide operational consequences.

---

## 22. Store Purchase / Expense UI

While Store is Open:

Fields:

- Description/item
- Amount
- Payment source:
  - Cash
  - Cashless
- Note/reason
- Optional receipt
- Optional inventory product + quantity

If inventory-linked, clearly indicate that stock will increase.

---

## 23. Close Store UI

### Pre-close blockers

Show blockers clearly:

- UNPAID / PAY LATER remains
- Kitchen order not DONE

Unclaimed QR does not block close.

### Session summary

Show:

- Opening Cash
- Opening Cashless
- Cash sales
- Cashless sales
- Split breakdown
- Store purchases/expenses
- Relevant adjustments/void effects
- Expected Closing Cash
- Expected Closing Cashless

### Manual inputs

- Closing Cash
- Closing Cashless

### Variance

Show:

- Expected
- Actual
- Difference

Rules:

- Exact → proceed
- Shortage → block normal close and require correction
- Overage → require note/reason, then allow close

### After close

Show:

- Store Closed confirmation
- QR ordering disabled
- Remaining unclaimed QR archived

---

## 24. Loading States

Use layout-preserving skeletons where useful.

Avoid full-screen spinners unless necessary.

Product images must not block card text/actions.

---

## 25. Error States

Errors should be specific and actionable.

Examples:

- Email or password is incorrect
- Account is disabled
- No branch assigned
- Store already opened by another Cashier
- Product became unavailable
- Insufficient stock
- Payment failed
- Reconnecting to realtime

---

## 26. Offline / Reconnecting

Show connection state:

- Connected
- Reconnecting
- Offline

When Offline:

- Disable high-risk mutations
- Keep safe read-only views usable where practical

---

## 27. Accessibility

- Practical 44px touch targets
- Keyboard/focus support on desktop
- Visible focus states
- Sufficient contrast
- Do not rely on color alone
- Clear text labels for actions

---

## 28. Image Performance

- Optimized thumbnails
- Correct dimensions
- Lazy-load below fold
- Cache aggressively
- Use lightweight fallback
- Avoid unnecessary rerenders
- Avoid full-resolution image download for product cards

---

## 29. Functional UI Rule

Every visible control in the implemented app must do one of:

- Perform its intended action
- Open the correct modal/sheet
- Navigate correctly
- Be intentionally disabled with a clear reason

No decorative dead buttons in production UI.
