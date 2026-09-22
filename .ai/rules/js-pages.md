---
paths:
  - '{app/Http/Controllers/**,app/Support/**,resources/js/pages/**}'
---

# Js Pages

## Voided sales are Super Admin records only
Once an order is voided, exclude it from cashier Transaction History and reject all receipt creation/viewing, including previously signed links. Preserve the order and payment data for Super Admin Void Orders, where the detail view shows the transaction breakdown and two-person authorization identities without raw audit JSON.
