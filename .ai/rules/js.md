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
