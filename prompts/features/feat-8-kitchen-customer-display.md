# Feature 8/9 — Kitchen and Customer Displays

**Status:** Approved, implemented, and verified on 2026-09-22
**Branch:** `feature/kitchen-display`
**Base:** `feature/dev` at `6b04dc868a1fc3984664546994d99b46ba9a5284`
**Build-plan owner:** Phase 8 — Kitchen Display System and Phase 9 — Customer Display

## What we are building

Deliver a server-authoritative Kitchen Display System (KDS), a privacy-safe customer order-status display, and a cashier POS Ready-for-pickup integration. The implementation will follow the decoded `context/design/pos.html` standalone for layout, interaction, status controls, fullscreen density, responsive behavior, and visual hierarchy while preserving the repository's authorization, order snapshot, store-session, realtime, and payment invariants.

## Canonical context inspected

- `context/01-project-overview.md`, `04-architecture.md`, `06-realtime-contracts.md`, `08-ui-rules.md`, `09-ui-registry.md`, `10-build-plan.md`, `11-testing-qa.md`, `13-progress-tracker.md`, `14-coding-standards.md`, and `15-library-docs.md`.
- The decoded POS standalone, including KDS normal/fullscreen states, Customer Display, POS Ready queue, Ready detail dialog, tab counts, search, status controls, and responsive breakpoints.
- Existing Phase 6/7 order actions, kitchen tickets, order snapshots, branch/store middleware, permissions, broadcasting channels, Echo hooks, Wayfinder usage, workspace layout, and Pest/frontend testing patterns.

## Language and lifecycle

- **Kitchen:** the persisted initial kitchen-ticket state; the standalone labels this first status as Kitchen and groups it into Preparing on the public display.
- **Preparing:** work has actively started.
- **Ready:** the order can be handed to the customer and appears in the POS Ready queue and Customer Display Ready column.
- **Done:** operationally completed; removed from active KDS, POS Ready, and Customer Display lists, but retained in history/a bounded KDS Done tab.
- **All orders:** all non-Done tickets in the current open store session.
- **Duplicate transition:** a replay to the already-persisted target status; it succeeds as an idempotent no-op without another version increment or broadcast.

## Decisions

1. Reuse the existing unique `kitchen_tickets.order_id`, `orders.kitchen_status`, order version, immutable line/modifier snapshots, branch scope, store session, and permissions. No schema migration or dependency change is required.
2. Treat the kitchen ticket as the queue record and `orders.kitchen_status` as the synchronized cross-surface state. Every transition locks and validates both records and updates them atomically; a mismatch is rejected rather than silently repaired.
3. Match the standalone transition matrix: any forward move is allowed, a one-step rollback is allowed, and a rollback of more than one state is rejected. The cashier POS is further restricted to Ready → Done; users with `kitchen.access` can use the full KDS matrix.
4. Require the order to be committed, belong to the active branch, and belong to the current OPEN store session. A session closing between render and mutation causes a server rejection and a refresh to the closed state.
5. Use compact branch-scoped private broadcasts only after commit. Operational status/ticket signals are delivered only to Kitchen/POS channels; a separate privacy-minimal `display.orders_changed` invalidation signal is delivered to Customer Display. Clients debounce/coalesce these signals and refetch authoritative projections, including on reconnect.
6. Keep KDS props operational only: order number, customer label, order type/table, placed time, lifecycle state, and immutable item/modifier/instruction/note snapshots. Do not expose totals, payment method, tender, change, or settlement data.
7. Give Customer Display a minimal dedicated Inertia response and layout with no shared auth, employee/profile, financial, item, customer-name, internal-ID, or table data. Its visible lists contain order numbers only.
8. Intentionally diverge from the standalone's employee shell on Customer Display because the privacy contract is stricter: the production display is a full-canvas customer-facing surface with only store branding, clock, Preparing/Ready order numbers, closed/empty states, and Exit.
9. Preserve the standalone KDS behavior: All orders/Kitchen/Preparing/Ready/Done tabs and counts, order/customer search, status buttons, summary footer, Customer Display launch, and Full screen/Exit full screen. Fullscreen uses the browser Fullscreen API plus a fixed app surface, hides navigation/search/footer, uses page scrolling rather than card-internal scrolling, and restores safely on Escape/fullscreen-change.
10. Preserve POS cart, dialogs, payment state, and remembered cashier state by rendering Ready notifications outside the cart component and using partial authoritative reloads. The cashier-only Ready detail may show existing order/payment summary data because it is not a customer-facing or KDS surface.
11. Query the current store session only. Return every non-Done ticket and at most the latest 100 Done tickets; compute tab/footer counts independently so the bounded Done list never falsifies counts. Eager-load snapshots to prevent N+1 queries.
12. Reuse current RBAC exactly. Do not broaden Owner permissions in this slice; Super Admin retains its existing all-permissions behavior, and other users require the existing `kitchen.access`, `customer_display.launch`, or `pos.access` permission as appropriate.
13. Phase 12 order editing remains out of scope. Clients may listen for the frozen future `kitchen.order_updated` signal and refetch, but this slice will not create an edit workflow or fabricate that event.

## Scope

### Included

- KDS server projection, page, responsive cards, tabs/counts/search, transition controls, summary, normal/fullscreen presentation, closed/empty/loading/error states, and Customer Display launch.
- Transactional and idempotent lifecycle transitions for Kitchen → Preparing → Ready → Done, including the standalone's allowed forward jumps and one-step rollback.
- Customer Display Preparing/Ready board, responsive number grids, clock, pulse state, same-tab launch/exit flow, privacy-safe props, reconnect behavior, and Store closed handling.
- POS Ready count/bell/queue, clickable Ready detail, Ready → Done action, and non-destructive realtime refresh.
- Private channel authorization, after-commit compact events, reconnect refetch, multi-window synchronization, and duplicate-subscription protection.
- Focused backend/frontend/concurrency tests, requested full verification, manual three-window and responsive QA, standalone side-by-side comparison, context updates, commit, and push.

### Excluded

- Kitchen editing of item quantities/modifiers/notes, cancellation/refunds, printing, sound/push notifications, transaction history, dashboard completion, customer QR ordering, new permissions/roles, database redesign, dependency upgrades, PR creation, and any unrelated Phase 10+ work.

## System invariants

- Pay Now and Pay Later commit exactly one Kitchen ticket. Later Pay Later settlement never creates or resends a ticket.
- Authorization, branch isolation, store-session state, lifecycle validation, locking, idempotency, and version advancement are server-authoritative.
- Product and modifier names, Size prefix, standard modifiers, special instructions, and line notes render from immutable order snapshots. Instructions and notes remain distinct; only an explicitly snapshotted Size modifier prefixes the item name.
- KDS never receives financial fields. Customer Display never receives hidden private fields merely to omit them visually.
- Broadcasts are hints, not state. Every client refetches authoritative data and remains correct after a missed message or reconnect.
- UI-only state such as the selected tab, search, fullscreen state, cart, payment step, and open cashier dialog survives relevant partial reloads.

## Affected areas

Expected production changes, subject to reuse discovered during implementation:

- `app/Actions/Orders/**` for the locked lifecycle transition and shared operational projections.
- `app/Events/**` for compact after-commit operational signals and the separate privacy-minimal Customer Display invalidation signal.
- `app/Http/Controllers/**` and a Form Request for KDS, Customer Display, POS Ready data, and transition validation.
- `routes/web.php` and `routes/channels.php` for protected page/mutation routes and private customer-display channel authorization.
- `app/Http/Middleware/HandleInertiaRequests.php` for the intentionally minimal Customer Display shared-prop boundary.
- `resources/js/pages/workspaces/**`, `resources/js/components/**`, `resources/js/hooks/**`, and shared TypeScript types/helpers for KDS, Customer Display, POS Ready, and realtime refresh.
- Wayfinder-generated bindings for new named controller routes.
- Focused Pest, PostgreSQL concurrency, and frontend tests.
- After verification only: `context/06-realtime-contracts.md`, `context/09-ui-registry.md`, and `context/13-progress-tracker.md`.

## Database and authorization impact

- No migration, table, enum, seeder, or dependency change is planned.
- Existing database checks and the unique Kitchen ticket per order remain authoritative.
- Transition code will use a database transaction with a stable lock order over the branch/session, order, and kitchen ticket records to avoid races and deadlocks.
- Full KDS mutations require `kitchen.access`; Customer Display launch requires `customer_display.launch`; POS Done requires `pos.access` plus a current Ready order in the active branch/session. Existing route branch middleware remains in force.

## Implementation plan

1. Confirm package APIs with Laravel Boost documentation and read every path-matched `.ai/rules` file before the first production edit.
2. Create the shared kitchen-board projection from the current open store session, with eager-loaded immutable item/modifier snapshots, full counters, a bounded Done list, and explicitly separate `standard_modifiers`, `instructions`, and `note` fields.
3. Implement the transactional lifecycle action and validated controller endpoint. Lock records, validate branch/session/order/ticket consistency, enforce the role-specific matrix, make identical replays no-ops, update both status columns and order completion/version fields, and dispatch exactly one post-commit event for a real change.
4. Add compact private broadcasts and channel authorization for Kitchen, POS, and Customer Display. Operational payloads contain only the IDs/status/version/timestamps needed by Kitchen/POS; Customer Display receives a separate signal containing no order identifier, status, financial, or customer detail.
5. Replace the generic Kitchen placeholder with the standalone-aligned KDS page and reusable ticket/status components. Add tabs, search, counts, cards, controls, footer, responsive density, empty/closed states, and robust fullscreen entry/exit.
6. Add the privacy-minimal Customer Display controller/page/layout, Preparing mapping, Ready mapping, clock, pulse, responsive grids, empty/closed states, same-tab Exit, and authoritative realtime refresh.
7. Add the POS Ready projection to the cashier workspace and render its bell, bottom-left queue, detail dialog, and Ready → Done action outside the cashier cart subtree so partial reloads cannot reset active work.
8. Consolidate the current POS Echo behavior into a reusable branch realtime-refetch hook where this can be done without changing semantics. Debounce bursts, coalesce in-flight reloads, clean up on unmount, and refetch after reconnect.
9. Generate/update Wayfinder bindings, apply Tailwind v4 styles consistent with the standalone and existing shell, and verify keyboard/touch/focus/overflow behavior.
10. Add focused Pest, frontend, and PostgreSQL concurrency coverage; rerun the narrowest affected test after each change.
11. Run formatting, static analysis, frontend checks, production build, focused suites, and the full test suite once. Perform three-window MAIN/POS/KDS/Customer Display QA, responsive checks, reconnect tests, and rendered side-by-side comparison with the standalone.
12. Update only the specified context documents after verification, audit the diff/artifacts/privacy contract, commit with a Phase 8/9 feature message, push `origin/feature/kitchen-display`, and do not open a PR.

## Test and verification contract

### Backend and database

- Route/page access for authorized and unauthorized roles, branch isolation, current-session filtering, and Store closed behavior.
- Pay Now and Pay Later each create one ticket; Pay Later settlement does not duplicate it.
- Every valid forward move and one-step rollback; invalid skips backward; POS may only perform Ready → Done.
- Duplicate/replayed targets are successful no-ops with no second version increment/event.
- Transaction rollback on mismatched order/ticket state, wrong branch/session, closed session, invalid state, or authorization failure.
- Two PostgreSQL workers racing the same transition produce one durable state/version/event effect and no inconsistent order/ticket pair.
- KDS projections contain snapshot names, Size prefix, standard modifiers, instructions, and note, and contain no financial fields.
- Customer Display props contain order numbers/status group only and contain no staff, customer, item, table, financial, or internal order identifiers.
- Query-count assertions or equivalent coverage prevent N+1 regressions and validate the Done bound/full counts.

### Frontend and realtime

- Tab/count/search/filter helpers, Customer Display grouping, transition-button matrix, Ready removal, empty/closed states, and responsive class/source contracts.
- One subscription per mounted channel, cleanup on unmount, burst debouncing, in-flight coalescing, reconnect refetch, and partial-reload preservation.
- POS Ready detail/Done flow leaves cart, payment, dialogs, selected tab, search, and fullscreen state intact.
- Fullscreen change/Escape handling and same-tab Customer Display Exit behavior.

### Required commands

- Narrow focused Pest files and frontend tests throughout implementation.
- `vendor/bin/pint --dirty --format agent` after PHP changes.
- `vendor/bin/phpstan analyse --no-progress --memory-limit=1G`.
- `npm run check:frontend`, `npm run types:check`, and `npm run build`.
- `php -d memory_limit=1G artisan test --compact -d memory_limit=1G` once after focused suites pass.
- `git diff --check` and an artifact/debug/privacy audit before commit.

## Manual QA and standalone comparison

- Run three simultaneous views: MAIN cashier POS, KDS, and Customer Display. Exercise Pay Now, Pay Later, MAIN/QAVE branch switching, Kitchen/Preparing/Ready/Done, POS Done, forward jump, one-step rollback, duplicate click, closed-session rejection, network disconnect/reconnect, and page refresh.
- Verify KDS at 360, 390, 430, tablet, desktop, and wide fullscreen widths: no horizontal overflow, no card-internal scrolling, correct column density, stable tab/search state, touch-safe controls, and Escape recovery.
- Verify Customer Display contains no employee shell/private data, maps Kitchen into Preparing, removes Done, uses readable two-column/stacked number grids, and shows truthful empty/closed states.
- Render the implementation and the decoded standalone side by side. Compare structure, spacing, typography, colors, card density, state emphasis, footer, Ready queue, detail dialog, and responsive behavior; record only intentional security/privacy/framework divergences.

## User guide and business/privacy impact

- Kitchen staff open Kitchen, filter tickets, and select an allowed lifecycle state. Fullscreen provides the dense production board; Exit full screen or Escape restores the workspace.
- Authorized staff launch Customer Display from KDS in the same tab/window and use Exit to return. Customers see only order numbers under Preparing or Ready.
- Cashiers see a Ready count/queue without losing their current cart. Opening a Ready order shows its authorized fulfillment detail; Mark as done removes it from every active surface.
- The feature reduces verbal coordination while ensuring customer-facing screens and kitchen staff never receive financial or unnecessary personal data.

## Acceptance checks

- The complete lifecycle is synchronized across all three surfaces without refresh and self-heals after reconnect.
- Simultaneous/repeated actions cannot duplicate tickets, broadcasts, completion effects, or corrupt status/version state.
- KDS, Customer Display, and POS Ready match the standalone's observable flow and responsive layout except for documented privacy/security improvements.
- Store closed is explicit and blocks lifecycle mutations.
- Focused/full automated verification and manual QA pass; required context files are accurate; the branch is committed and pushed; no PR is created.

## Risks and open decisions

- **Resolved:** the standalone permits forward jumps and only one-step rollback; that exact matrix is adopted.
- **Resolved:** Customer Display will not reuse the employee workspace chrome because it would leak staff identity; this is an intentional privacy divergence.
- **Resolved:** no Owner permission expansion, schema change, or Phase 12 edit workflow is included.
- **Implementation risk:** browser fullscreen permissions vary. The UI will report entry failure, retain a usable normal board, and synchronize state from `fullscreenchange` rather than assuming success.
- **Implementation risk:** lifecycle broadcasts can arrive out of order. Clients use them only as refresh triggers, while order version and locked database state remain authoritative.
