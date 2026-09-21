---
paths:
  - resources/js/pages/workspaces/kitchen.tsx
---

# Workspaces

## Kitchen sounds require authoritative lifecycle confirmation
Seed known ticket IDs on initial hydration and only sound a new order after a live ticket-created event is confirmed by the refreshed board. Play the Ready PA only when the server flash reports a real changed transition to ready; reconnects, reloads, duplicate targets, rollbacks, and failed transitions stay silent.
