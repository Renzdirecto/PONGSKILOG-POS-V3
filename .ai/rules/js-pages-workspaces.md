---
paths:
  - '{app/Events/**,resources/js/pages/workspaces/transaction-history.tsx}'
---

# Js Pages Workspaces

## Operational broadcasts run after the response; History settlement returns to the list
v1.2.1: best-effort operational broadcast transports use ShouldBroadcast with Connection('deferred'); dispatch remains after the outer database commit, and rollback must emit nothing. The deferred connection sends after the HTTP response without depending on a busy worker. History settlement uses the authoritative paid receipt response and closes Details/Payment, then refreshes the list; do not fetch and reopen Details after payment. Preserve stable full tender attempts on ambiguous retries.
