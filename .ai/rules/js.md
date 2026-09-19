---
paths:
  - 'resources/js/**'
---

# Js

## Cashier POS standalone authority and phase boundaries
Cashier POS composition, dimensions, responsive behavior and interaction sequence follow context/design/pos.html as close to 1:1 as practical. Keep backend authorization, exact totals, stock validation and snapshots authoritative. Save · Pay Later opens Order information; Pay Now opens Payment in the POS workspace. Until their phases exist, final operational commits remain disabled with explicit reasons. Kitchen/notification placeholders must not invent live data; Store Open and profile use real state.
