---
paths:
  - '{app/Support/StoreSessionSalesReport.php,app/Support/StoreSessionReconciliation.php,app/Http/Controllers/ReportsController.php,app/Http/Requests/ReportsRequest.php,resources/js/pages/workspaces/reports.tsx,resources/js/lib/reports.ts}'
---

# Reports

## Owner reporting reuses the reconciliation authority
`StoreSessionSalesReport` is the one read-only report for Owner and Super Admin (`workspaces.reports`). Business date = the Manila date a Store Session opened; cross-midnight sessions stay under that date. Net Sales = current `orders.total` of committed Active/Completed Orders (Pay Later included). Cash/Cashless come from `StoreSessionReconciliation::flows()` (batched, same formula as Close Store); never re-derive correction/void math. CLOSED sessions use the persisted snapshot/close columns; OPEN sessions are live and provisional. No mutation routes or controls.
