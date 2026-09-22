---
paths:
  - 'resources/js/**'
---

# Js

## Cashier POS standalone authority and phase boundaries
Cashier POS composition, dimensions, responsive behavior and interaction sequence follow context/design/pos.html as close to 1:1 as practical. Keep backend authorization, exact totals, stock validation and snapshots authoritative. Save · Pay Later opens Order information; Pay Now opens Payment in the POS workspace. Until their phases exist, final operational commits remain disabled with explicit reasons. Kitchen/notification placeholders must not invent live data; Store Open and profile use real state.

## Optional POS tables and Phase 5 payment density
Both Order Information and Pay Now show optional active branch table chips for Dine In and Take Out, with deselection/No table; type switches preserve the selected table. Pay Now uses compact bordered black/red summary rows, cash-targeted Exact/denominations, prominent Change, and a non-scrolling right panel at 820/1024px. Phase 5 payment confirmation and Pay Later activation remain disabled.

## Table options omit the No table chip
User follow-up: do not show a separate No table option in Pay Later or Pay Now. Table selection remains optional; clicking the selected table again clears it. This supersedes the earlier No table chip guidance.

## Phase 6 activates approved Pay Now; preserve standalone payment and success flow
Pay Now is implemented in the approved in-place Payment modal, followed by paid-success and receipt modals using persisted server data. Preserve the Phase 5 sizing, compact black/red summary, quick cash and prominent Change. Cashless is manual confirmation; Split is cash + cashless. Keep a stable request/key on ambiguous failures and clear the cart only after confirmed success. Pay Later remains disabled until Phase 7. This supersedes earlier Phase 5 rules requiring Pay Now confirmation to remain disabled.

## Browser-generated IDs must work on LAN HTTP
Use the existing createClientUuid helper for cart IDs and payment/Pay Later idempotency keys. Tablet browsers on HTTP IP links may expose crypto.getRandomValues but not crypto.randomUUID; direct randomUUID calls break these flows. Preserve stable keys on ambiguous retries.
