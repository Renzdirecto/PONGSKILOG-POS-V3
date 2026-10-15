---
paths:
  - '{app/Http/Controllers/ReportsController.php,resources/js/pages/workspaces/reports.tsx}'
---

# Pages Workspaces

## Drawer disclosure uses net channel collections and lazy report props
Supersedes the visible gross/corrections/void ladder: show overall Paid Sales once, then Opening + channel net Collections - channel Expenses = Expected for Cash and Cashless separately. Read summary[channel] and reconciliation.expected[channel]; hide correction/void rows only in this disclosure, retaining backend reconciliation and the existing cards. Resolve report/analytics lazily once per response so kitchenNow-only partial refreshes do not run financial analytics.
