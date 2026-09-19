---
paths:
  - 'app/Actions/Orders/**'
---

# Orders

## Pay Now atomicity and root idempotency
PayNowOrder owns one outer transaction for new draft or existing snapshot, payment legs, inventory Sale movements and initial Kitchen ticket. Never reprice persisted drafts. Lock the payment root before branch/order work on PostgreSQL: cash and cashless leg keys alone do not prevent the same root racing across branches with different methods. Replay must verify current cashier/branch and original order/cart/tender before returning persisted success; broadcasts occur only after commit.
