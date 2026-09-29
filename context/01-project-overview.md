# PONGSKILOG POS V3 — Project Overview

**Status:** FROZEN — Batch 1  
**Business model:** One PONGSKILOG business with multiple branches  
**Architecture direction:** Centralized cloud-based multi-branch restaurant POS and operations system

---

## 1. Project Summary

PONGSKILOG POS V3 is the centralized operations platform for PONGSKILOG.

Core workspaces:

- Cashier / POS
- Kitchen / KDS
- Customer QR Ordering
- Owner
- Super Admin
- Customer Display

The system is designed so PONGSKILOG can scale to multiple branches while Owner and Super Admin can monitor and manage operations remotely.

Primary goals:

- Fast live-service operation
- Strong branch isolation
- Accurate payment and inventory handling
- Realtime Kitchen / QR / Customer Display updates
- Auditable sensitive actions
- Mobile/tablet-friendly operations
- Multi-branch scalability without becoming a multi-company SaaS platform

---

## 2. Business Structure

PONGSKILOG uses:

**One Business → Many Branches**

Each branch has its own:

- Branch identity/code
- Address/contact
- Operating hours
- Store Session
- POS orders
- Kitchen queue
- Inventory
- Staff assignments
- Tables
- Customer QR context
- Customer Display
- Receipt information
- Opening Cash
- Opening Cashless
- Closing Cash
- Closing Cashless
- Store-session purchases/expenses
- Branch reports
- Branch-specific product overrides

Branch data must never accidentally mix.

---

## 3. Roles

Internal roles:

- Super Admin
- Owner
- Cashier
- Kitchen Staff
- Cashier + Kitchen

Public/non-staff experiences:

- Customer QR user
- Customer Display

Staff may be assigned to one or more branches.

Backend authorization must enforce both:

- Role permission
- Branch assignment

---

## 4. Store Session Model

Each branch has a Store Session representing one operational opening.

Only **one active Store Session per branch** may exist at a time.

### If Store is Closed

After Cashier login and branch selection, show:

- **Browse**
- **Open Store**

### Browse

Browse is read-only.

Cashier may inspect allowed information but cannot:

- Create orders
- Edit operational records
- Take payment
- Save Pay Later
- Void
- Delete
- Adjust inventory
- Perform other operational CRUD

### Open Store

Any authorized Cashier assigned to the branch may open it.

The first Cashier enters:

- Opening Cash
- Opening Cashless

After confirmation:

- Store Session becomes Open
- Normal POS operations are enabled

### Additional Cashiers

If another authorized Cashier logs in while the branch Store Session is already Open:

- System shows **Store is Open**
- No new opening balances are requested
- Cashier proceeds into normal POS operation

Opening balances belong to the Store Session, not to each Cashier.

---

## 5. Close Store Model

Any authorized Cashier may initiate Close Store for the active branch Store Session.

Before closing, the system must verify:

1. No unresolved **UNPAID / PAY LATER** orders remain.
2. All committed Kitchen orders are **DONE**.
3. Unclaimed Customer QR submissions do not block closing.

### Unclaimed QR submissions

- A QR submission with no action for 30 minutes becomes **Archived / Unclaimed**.
- At Store Close, all remaining unclaimed QR submissions are archived.
- Archived QR submissions have no payment, inventory, or Kitchen effect.
- Archived records remain retained for history.

### Store purchases / expenses

While Store Session is Open, Cashier may record branch purchases/expenses such as:

- Yakult restock
- Cleaning supplies
- Other operating purchases

Each entry may include:

- Item/description
- Amount
- Payment source: Cash or Cashless
- Note/reason
- Optional receipt image
- Optional linked product/quantity when the purchase is inventory restock

If linked to inventory restock:

- Expense is recorded
- Inventory is increased
- Both financial and stock history remain traceable

### Closing inputs

Cashier manually enters:

- Closing Cash
- Closing Cashless

System calculates and displays:

- Opening Cash
- Opening Cashless
- Cash sales
- Cashless sales
- Split-payment breakdown
- Store purchases/expenses
- Relevant adjustments/void effects
- Expected Closing Cash
- Expected Closing Cashless
- Actual Closing Cash
- Actual Closing Cashless
- Cash variance
- Cashless variance

### Variance rules

If there is a shortage:

- Normal Close Store is blocked.
- Cashier must review and correct the cause before closing.

If there is an overage:

- Store may proceed to close.
- Cashier must enter a note/reason for the overage.

After successful Close Store:

- Store Session becomes Closed
- Operational POS mutations are disabled
- Customer QR ordering becomes unavailable
- Customer QR site shows **Store is currently closed**
- Remaining unclaimed QR submissions are archived

---

## 6. Cashier / POS

Main navigation:

- Dashboard
- POS / Order
- QR Orders
- Transaction History

Cashier can:

- Start Dine In / Take Out orders
- Browse branch-valid products
- Customize items
- Maintain cart
- Pay Now
- Save as Pay Later
- Load Customer QR orders
- View transaction history
- Edit transactions when authorized
- Void with authorization
- View/print receipts
- Record Store Session purchases/expenses
- Open/Close the branch Store Session when allowed

Normal operational mutations require an Open Store Session.

---

## 7. Pay Later — Final Behavior

Pay Later is a committed operational order.

When saved as Pay Later:

- Status = **UNPAID / PAY LATER**
- Inventory is deducted immediately
- Kitchen ticket is created immediately
- Order enters Kitchen immediately
- Payment may be completed after the customer eats

When later paid:

- Payment record(s) are added
- Order becomes Paid
- Stock is **not** deducted again
- Kitchen ticket is **not** created again

### Pay Later edit example

Original:

- 2 Pork

Edited to:

- 1 Pork
- 1 Chicken

Inventory delta:

- +1 Pork
- -1 Chicken

Inventory history is preserved using delta/compensating movements.

---

## 8. Kitchen / KDS

Kitchen is branch-scoped.

Kitchen receives:

- Paid orders
- Saved Pay Later orders

Lifecycle:

**KITCHEN → PREPARING → READY → DONE**

Close Store is blocked while any committed Kitchen order is not DONE.

Kitchen does not expose financial/admin controls.

---

## 9. Customer QR Ordering

Customer QR is branch-bound and follows branch Store Session state.

### Store Open

Customer QR ordering is enabled.

### Store Closed

The site remains accessible but shows:

**Store is currently closed**

New order submission is disabled.

### Initial QR submission

A newly submitted Customer QR order:

- Is unpaid
- Does not deduct inventory
- Does not enter Kitchen

Cashier must LOAD the order into normal POS.

After LOAD:

### Pay Now

- Payment commits
- Inventory deducts
- Kitchen ticket is created

### Save as Pay Later

- Order becomes UNPAID / PAY LATER
- Inventory deducts
- Kitchen ticket is created

### QR archive behavior

- No-action QR submissions are archived after 30 minutes.
- Remaining unclaimed QR submissions are archived at Store Close.
- Archived QR records do not affect inventory/Kitchen/payment.

---

## 10. Owner

Owner workspace:

- Dashboard
- Transactions
- Reports
- Products
- Inventory
- Staff
- Settings

Owner may view:

- All Branches
- Specific Branch

Owner manages normal business operations but does not have dedicated:

- Audit Trail
- Void Orders
- Access Control

---

## 11. Super Admin

Super Admin is the highest internal authority with business-wide access.

Super Admin-only control surfaces:

- Audit Trail
- Void Orders
- Access Control
- Staff account creation

Super Admin is the full-access role (2026-09-24). It can use every Cashier, Cashier + Kitchen, Kitchen, and Owner surface for the Branch it selects, under the same Store Session and business rules, audited as itself. See `07-security-rbac.md`, Super Admin foundation.

---

## 12. Product Model

Use:

**Global Product Catalog + Branch Overrides**

Global product data may include:

- Name
- Category
- Default price
- Image
- Modifiers
- Global active state

Branch overrides may include:

- Price
- Availability
- Stock
- Low-stock threshold

---

## 13. Inventory Model

Inventory is branch-specific.

Inventory changes when:

- Pay Now succeeds
- Pay Later is saved
- Order contents are edited
- Order is voided
- Manual inventory adjustment occurs
- Inventory-linked Store Purchase occurs
- Stock transfer occurs

Negative stock is disabled by default.

Historical inventory movements are never silently erased.

---

## 14. Payments

Supported:

- Cash
- Cashless
- Split

Payment records belong to the same branch/order context.

Payment records are retained.

---

## 15. Realtime

Realtime is branch-scoped.

Core rule:

**Commit database transaction first → broadcast after commit**

Branch A operational events must not leak into Branch B.

---

## 16. Performance

Performance is a first-class requirement.

Product images must not slow the POS.

Use:

- Correct image dimensions
- Compression
- WebP/AVIF where practical
- Responsive variants
- Lazy loading
- CDN/storage delivery
- Caching
- No unnecessary full-resolution downloads

---

## 17. Technical Foundation

- Laravel 13
- PHP 8.4
- React 19
- Inertia.js 3
- TypeScript
- Tailwind CSS 4
- Vite 8
- Wayfinder
- Laravel Fortify
- PostgreSQL via Supabase
- Eloquent ORM
- Laravel session authentication
- Policies/Gates + custom RBAC
- Laravel Reverb / Broadcasting / WebSockets
- Redis
- Supabase Storage
- Railway
- GitHub + GitHub Actions
- Pest
- Larastan / PHPStan
- Pint
- Laravel Boost / Codex

Architecture:

**Laravel modular monolith + Inertia**

---

## 18. Core Scope Exclusions

Not part of current core build:

- Multi-company SaaS tenancy
- Customer accounts
- Online customer payment
- Delivery logistics
- Central warehouse/commissary
- Complex procurement
- Offline-first payment processing
- AI forecasting
