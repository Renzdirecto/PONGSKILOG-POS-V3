---
paths:
  - '{app/Http/Controllers/TransactionHistoryController.php,resources/js/pages/workspaces/transaction-history.tsx,resources/js/lib/pos-money.ts}'
---

# Lib

## History edits return to the list and preserve committed modifier pricing
Supersedes the earlier finished-edit Details rule: successful edits close Edit, clear/invalidate selected Details, and refresh list/metrics. Settlement still returns to refreshed Details; edited paid-order balance resolution remains explicit. Initial History omits the edit catalog; fetch only catalog when opening Edit, with list props lazy so that request does not query history. Display unchanged combinations from committed base plus committed non-instruction deltas through lineCents; changed combinations use current canonical catalog pricing, and the server always recomputes edits.
