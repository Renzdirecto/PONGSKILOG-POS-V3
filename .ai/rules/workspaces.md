---
paths:
  - resources/js/pages/workspaces/kitchen.tsx
---

# Workspaces

## Kitchen sounds require authoritative lifecycle confirmation
Seed known ticket IDs on initial hydration and only sound a new order after a live ticket-created event is confirmed by the refreshed board. Play the Ready PA only when the server flash reports a real changed transition to ready; reconnects, reloads, duplicate targets, rollbacks, and failed transitions stay silent.

## Kitchen Ready audio accepts authoritative JSON confirmation
KDS mutations use independent non-navigation JSON requests. PA SERVE plays only after a successful response matching the order and Ready target with changed=true; optimistic state, failures and duplicate targets stay silent. This supersedes the earlier flash-only wording; POS redirect clients still receive the flash contract.

## All orders is the active queue and Done is confirmed (Phase 20)
All orders holds only `kitchen` and `preparing` tickets (`isActiveKitchenWork`), on the server (`KitchenBoard::countsForSession`), in `filterKitchenTickets` and in the optimistic `projectKitchenBoard` count. Ready tickets appear only under Ready, Done only under Done. The Done button opens an in-surface confirmation ("Are you sure you want to mark this order as Done?", Cancel / green Done) before the authoritative transition; it is rendered inside the board surface, never through a portal, so it stays visible in native full screen. Other transitions keep their immediate behaviour.

## The landscape ticket header is compact
Header order: `#number` (smaller) | customer (red, unchanged emphasis), then UPDATED beneath the title; the Dine In / Take Out pill sits top-right with the elapsed timer directly under it. There is no absolute clock time. Pills are `rounded-md px-1 py-px text-[8px]` so an UPDATED ticket never stretches the header.
