---
paths:
  - '{app/Support/WebsiteAnalytics.php,resources/js/pages/workspaces/website-analytics.tsx,resources/js/hooks/use-website-analytics-refresh.ts}'
---

# Js Hooks

## Website Analytics refreshes one authoritative report
Manual Refresh and the 30-second poll share one guarded partial reload of report. Keep data and custom inputs mounted; skip hidden/offline tabs, cancel the old report on navigation, and cancel on unmount. refreshed_at comes from the completed server report and is displayed in Asia/Manila; failed requests must retain figures and timestamp. Keep the report at three aggregate queries and preserve its business-wide reports.view authorization.
