---
paths:
  - '{app/Support/{OrderMoney,SalesAnalytics,StoreSessionSalesReport,CashierDashboard,ReportCsvExport,OperationsSummary}.php,resources/js/components/owner-analytics.tsx}'
---

# Support Js Components

## Paid Sales requires full ledger settlement
Paid Sales, paid transactions, products/categories/quantities/Sizes and cashier sales include only committed Active/Completed Orders with OrderMoney outstanding = 0 (payments minus corrections). Unpaid and partial Orders contribute zero; retain their collections, outstanding and operational counts separately. Full settlement counts the full final total exactly once on the ORIGINAL Store Session Manila business date. Keep close-time reconciliation snapshots and all transaction workflows unchanged.

Operations Plan sales and Products Sold use the same gate; Ingredient consumption costs retain all committed Orders.
