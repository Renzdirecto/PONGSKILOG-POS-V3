---
paths:
  - 'resources/js/{components,layouts}/**/*.{ts,tsx},app/Actions/StoreSessions/**/*.php'
---

# Store Sessions

## Keep Store expenses inside the Current Store Session surface
Cashier Store Purchases / Expenses are opened from the existing LIVE / STORE OPEN control, never a new primary navigation page. Writes derive and shared-lock the current OPEN Store Session; Phase 15 must extend this same surface and take the Session boundary exclusively for Close Store.
