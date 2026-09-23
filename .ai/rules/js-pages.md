---
paths:
  - '{app/Http/Controllers/**,app/Support/**,resources/js/pages/**}'
---

# Js Pages

## Voided sales are Super Admin records only
Once an order is voided, exclude it from cashier Transaction History and reject all receipt creation/viewing, including previously signed links. Preserve the order and payment data for Super Admin Void Orders, where the detail view shows the transaction breakdown and two-person authorization identities without raw audit JSON.

## Owner Transactions reuse the Cashier history page
`workspaces.transactions` renders the same `workspaces/transaction-history` page with surface=business in the management shell (All Branches or the selected Branch). Never fork it into a read-only copy: capabilities come from the server (`TransactionHistory::for($branch, $filters, $mutableBranch)`), the Owner never gets POS access, and every write route keeps its POS authorization.

## Super Admin operational registers stay live
Audit Trail and Void Orders search/filter controls must apply without a submit or manual page reload. New audit and void records must appear automatically through the private audit broadcast, with polling retained as a fallback when the realtime connection is unavailable.
