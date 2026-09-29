---
paths:
  - '{app/Support/StoreSessionSalesReport.php,app/Support/StoreSessionReconciliation.php,app/Http/Controllers/ReportsController.php,app/Http/Requests/ReportsRequest.php,resources/js/pages/workspaces/reports.tsx,resources/js/lib/reports.ts}'
  - '{app/Support/SalesAnalytics.php,app/Support/ReportPeriod.php,app/Support/ManilaSql.php,app/Support/BusinessSnapshot.php,app/Support/ReportCsvExport.php,app/Http/Controllers/OwnerDashboardController.php,resources/js/pages/workspaces/owner-dashboard.tsx,resources/js/components/owner-analytics.tsx,resources/js/lib/owner-analytics.ts}'
  - '{app/Http/Controllers/SuperAdminDashboardController.php,app/Support/ExecutiveSnapshot.php,resources/js/pages/super-admin/dashboard.tsx,resources/js/lib/executive-dashboard.ts}'
---

# Reports

## Owner reporting reuses the reconciliation authority
`StoreSessionSalesReport` is the one read-only report for Owner and Super Admin (`workspaces.reports`). Business date = the Manila date a Store Session opened; cross-midnight sessions stay under that date. Net Sales = current `orders.total` of committed Active/Completed Orders (Pay Later included). Cash/Cashless come from `StoreSessionReconciliation::flows()` (batched, same formula as Close Store); never re-derive correction/void math. CLOSED sessions use the persisted snapshot/close columns; OPEN sessions are live and provisional. No mutation routes or controls.

## Dashboard and Reports share SalesAnalytics
`SalesAnalytics` is the one analytics authority for the Owner Dashboard and Reports (`ReportPeriod` periods, `ManilaSql` hours, `BusinessSnapshot` live state). Dashboard payment mix and the Cashless KPI = Cash/Cashless net collections from reconciliation flows (Split legs already inside). Categories group by the current product category because Order Items have no category snapshot. Order filters (type, payment class, cashier) narrow every figure via `flows($sessions, $orderScope)`; Store Session reconciliation sections stay unfiltered. Deltas and shares are computed server-side; React only formats.

## Reports payment donut and category filter (manual-QA 2026-09-24)
The Reports Payment method donut uses `analytics.payment_mix`, shares of paid sales (₱) in exact 0.1% steps. User decision: Include split OFF (default) = `combined` — Cash and Cashless with each Split Order's cash/cashless parts inside them (Split ₱100 = ₱50 + ₱50 with a ₱100 cash order → Cash ₱150, Cashless ₱50). Include split ON = `separate` — Cash-only, Cashless-only and Split (`paymentClassSql()`) order totals (→ Cash ₱100, Cashless ₱0, Split ₱100). Split parts are Payment legs net of allocated corrections; unallocated ones are reported as `split_pending`, never guessed. Never add Split on top of the combined view. Unpaid Pay Later is listed separately. The `categories` report filter (category UUID or `uncategorized`) narrows only Top products and Product performance (and the CSV product table); it never changes KPIs, payments, collections, branches or the category card.

## The Executive Dashboard never recalculates money
`SuperAdminDashboardController` calls `SalesAnalytics::for()` exactly like the Owner Dashboard and only trims sections (Top products to 5); Expenses/voids/sessions come from the same report summary. Non-financial state comes from `BusinessSnapshot` and `ExecutiveSnapshot` (bounded aggregate queries, payload-free audit rows). Props are lazy + memoized so realtime partial reloads compute only what they request; refresh reuses `useReportsRealtimeRefresh` and the viewer's own `notifications.changed` signal (no new channel). Attention items are real state only; red means something cannot be sold.

## Dashboard props are lazy (Phase 19)
`OwnerDashboardController` builds every prop as a closure and runs `SalesAnalytics::for()` at most once per response (like `SuperAdminDashboardController`): a period switch (`period, analytics, report`) never runs the Kitchen / inventory / recent-transaction queries and a live reload never recomputes analytics. Keep new Dashboard/Reports props lazy; never compute a figure eagerly just to have it discarded by `only`. Structural query-count tests (`PerformanceHardeningTest`, PostgreSQL `verify-reporting-performance-postgres.php`) must stay flat as Orders and Branches grow.
