---
paths:
  - resources/js/pages/workspaces/kitchen.tsx
---

# Workspaces

## Kitchen sounds require authoritative lifecycle confirmation
Seed known ticket IDs on initial hydration and only sound a new order after a live ticket-created event is confirmed by the refreshed board. Play the Ready PA only when the server flash reports a real changed transition to ready; reconnects, reloads, duplicate targets, rollbacks, and failed transitions stay silent.

## Kitchen Ready audio accepts authoritative JSON confirmation
KDS mutations use independent non-navigation JSON requests. PA SERVE plays only after a successful response matching the order and Ready target with changed=true; optimistic state, failures and duplicate targets stay silent. This supersedes the earlier flash-only wording; POS redirect clients still receive the flash contract.
