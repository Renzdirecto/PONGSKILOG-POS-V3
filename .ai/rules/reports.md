---
paths:
  - '{app/Support/StoreSessionSalesReport.php,app/Support/StoreSessionReconciliation.php,app/Http/Controllers/ReportsController.php,app/Http/Requests/ReportsRequest.php,resources/js/pages/workspaces/reports.tsx,resources/js/lib/reports.ts}'
  - '{app/Support/SalesAnalytics.php,app/Support/ReportPeriod.php,app/Support/ManilaSql.php,app/Support/BusinessSnapshot.php,app/Support/ReportCsvExport.php,app/Http/Controllers/OwnerDashboardController.php,resources/js/pages/workspaces/owner-dashboard.tsx,resources/js/components/owner-analytics.tsx,resources/js/lib/owner-analytics.ts}'
---

# Reports

## Owner reporting reuses the reconciliation authority
`StoreSessionSalesReport` is the one read-only report for Owner and Super Admin (`workspaces.reports`). Business date = the Manila date a Store Session opened; cross-midnight sessions stay under that date. Net Sales = current `orders.total` of committed Active/Completed Orders (Pay Later included). Cash/Cashless come from `StoreSessionReconciliation::flows()` (batched, same formula as Close Store); never re-derive correction/void math. CLOSED sessions use the persisted snapshot/close columns; OPEN sessions are live and provisional. No mutation routes or controls.

## Dashboard and Reports share SalesAnalytics
`SalesAnalytics` is the one analytics authority for the Owner Dashboard and Reports (`ReportPeriod` periods, `ManilaSql` hours, `BusinessSnapshot` live state). Payment mix = Cash/Cashless net collections from reconciliation flows; Split is explanatory, never a segment. Categories group by the current product category because Order Items have no category snapshot; category never filters collections. Order filters (type, payment class, cashier) narrow every figure via `flows($sessions, $orderScope)`; Store Session reconciliation sections stay unfiltered. Deltas are computed server-side; React only formats.
