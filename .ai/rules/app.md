---
paths:
  - 'app/**'
---

# App

## Every throttle is a named limiter
Register limiters only in `App\Support\RateLimits` and reference them as `throttle:<name>`; never an un-named `throttle:X,Y` (it shares one counter per account/IP across routes, so heavy traffic starved Void and Close Store). `RateLimitIsolationTest` fails on any un-named throttle or unregistered name.

## One receipt
`ReceiptDocument` (per `ReceiptAudience`) is the only receipt builder and `ReceiptLayout` the only Receipt Settings authority; the React `ReceiptDocument` component renders every receipt surface. Never add a surface-specific receipt payload or markup, never read today's catalog for a receipt, and keep the audience allowlists (Pickup: no customer name, table or notes).

## Optional POS branch tables for both order types
Branch table selection is optional for Dine In and Take Out. Validate any selected table is active and belongs to the current branch; persist it for either type. Customer/order labels are optional for both order types. Do not restore conditional table or customer-label requirements.
