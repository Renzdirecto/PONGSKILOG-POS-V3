# Feature 14 — Store Purchases / Expenses

**Status:** Implementation complete — USER MANUAL QA pending
**Branch:** `feature/store-expenses`
**Starting commit:** `31626e4748f8bcbbe29eba46cf9fb4ec8c8d805f`

## Canonical context read

- `context/00-context-index.md` through `context/15-library-docs.md`, with the Phase 14 Store Session, expense, inventory, receipt, authorization, audit, realtime, QA, deployment, and coding-standard requirements reviewed.
- Decoded `context/design/pos.html` bundled `__bundler/template`, including the compact white top bar, 44px controls, Live connection treatment, modal conventions, camera/file capture fallback, responsive breakpoints, and POS-state-preserving dialog behavior.
- Scoped `.ai/rules` covering app code, Cashier POS parity, controllers/actions/hooks, React pages/components, and local database preservation.
- Existing Phase 13 foundations: canonical `AuditRecorder`, append-only audit log and private audit realtime; branch inventory ledger and `ApplyInventoryMovement`; Store Session shared-lock conventions; payment invoice proof private-storage patterns; branch-scoped realtime refetch helpers; typed Wayfinder routes; and the existing interactive Store Open control plus read-only `StoreSessionDetailsDialog`.

## Approved decisions

- Store Purchases / Expenses belong to the current open Store Session and are entered from the existing top Cashier `STORE OPEN` control. No new primary navigation destination is added.
- The existing Store Session dialog becomes the reusable session surface: current-session overview, server-authoritative expense totals, bounded newest-first expense history, read-only detail, and nested Add Expense / Purchase flow. Phase 15 will extend this same surface with reconciliation and Close Store.
- Phase 14 is create-and-read only. Committed expenses and receipts are historical evidence; no edit, delete, approval, vendor/payables, accounting-category, or all-history Cashier workflow is introduced.
- One optional restock item is supported per expense through `store_session_expense_items`, enforced by a unique expense foreign key. A normal expense never mutates inventory.
- A client-generated UUID idempotency key plus persisted canonical intent hash provides exact replay and changed-intent conflict. Receipt bytes/path are excluded from the public intent but receipt presence and stable normalized upload metadata are incorporated so retries cannot silently change intent.
- Receipt storage follows the existing payment-invoice proof security posture on the configured private disk: safe raster formats only, size/dimension validation, branch-authorized streaming with `private, no-store`, no raw path/public URL, and cleanup when storage or the database transaction fails.
- The expense write uses the current Store Session shared serialization boundary before expense/product/inventory locks. Future Phase 15 Close Store will take the same Session boundary exclusively.
- The event contract is `.store.expense_recorded` on a private branch Store Session/operational channel, emitted only after commit. Clients treat it as compact invalidation and refetch authoritative session data. Restock uses the existing inventory/catalog events from `ApplyInventoryMovement`; no duplicate inventory event is added.
- Financial totals are SQL/server aggregates over the complete current session using exact decimals. Cash and Cashless remain distinct inputs for Phase 15; Phase 14 does not calculate closing balances or variances.
- Offline expense submission is blocked, including non-restock expenses. Visible confirmed history may remain while disconnected, but no write is queued or reported as successful.

## Scope

Included:

- Additive expense and expense-item schema, models, factories, relationships, constraints, indexes, and inventory-movement foreign linkage.
- Current-session projection with opening context, Cash/Cashless/total expense aggregates, bounded/paginated newest-first history, eligible tracked products, and read-only expense detail.
- Authorized, idempotent, atomic expense creation with optional private receipt and optional one-product restock through `ApplyInventoryMovement`.
- Canonical audit record and compact branch-scoped after-commit realtime invalidation; authoritative same-branch modal refresh and reconnect refetch.
- Responsive Store Session, Add Expense / Purchase, and Expense Detail dialog/sheet UI that preserves cart, loaded QR, order information, and payment state.
- Focused Pest/frontend tests, isolated PostgreSQL concurrency and migration verification, required quality gates, actual Phase 14 context updates, commit, and push.

Excluded:

- Store Close, expected/actual closing balances, reconciliation, variance, QR archival on close, and Phase 15 UI/actions.
- Owner expense management/history/reports, expense edit/delete/correction/approval, vendors/payables, full accounting, categories, and multi-line procurement.
- Fake Order/Payment/refund representations, Customer/Kitchen/QR behavior changes beyond existing inventory invalidation, dependency changes, Supabase mutation, broad browser QA, pull request creation, or destructive normal-local-database reset.

## Architecture and security invariants

- Every read/write derives the active Branch from `ActiveBranchContext`; client Branch or Store Session IDs are never authoritative.
- Mutation requires an authenticated, active, branch-assigned Cashier or Cashier+Kitchen user with `store_expenses.manage`, an active Branch, and the current OPEN Store Session. Kitchen-only, Owner, unassigned users, wrong Branch, closed/stale Session, and tampered identifiers are denied server-side.
- The write transaction reauthorizes persisted user/branch state, takes the Store Session shared lock, resolves exact replay or conflict, creates expense/item, applies optional inventory movement, records audit, and commits as one unit. Any expense-item, balance, movement, or audit failure rolls back all database effects and emits no success event.
- Receipt storage failure creates no expense. If a stored object is followed by transaction failure, the new object is removed. A committed receipt is retained and streamed only after permission plus Branch checks.
- Idempotency guarantees one expense, one optional item/restock, one audit record, and one logical expense success event for an exact retry; changed normalized intent returns HTTP 409.
- Product eligibility is revalidated in the transaction: active catalog product, current Branch configuration, inventory tracking enabled, positive bounded integer quantity. Product/BranchInventory locks follow the established deterministic inventory order.
- Historical parents use restrictive foreign keys. Amount has an exact positive `numeric(14,2)` check; payment source is constrained to `cash|cashless`; quantity is positive; one item per expense and Branch-scoped idempotency uniqueness are database enforced.
- Realtime payload contains only event ID, Branch ID, occurred-at time, expense ID, Store Session ID, payment source, amount, and inventory-linked boolean. It omits note, receipt metadata/path, balances, variance, and audit detail.

## Affected files

Expected new files:

- `database/migrations/*_create_store_session_expenses.php`
- `database/migrations/*_create_store_session_expense_items_and_link_inventory_movements.php`
- `app/Enums/StoreExpensePaymentSource.php`
- `app/Models/StoreSessionExpense.php`
- `app/Models/StoreSessionExpenseItem.php`
- `database/factories/StoreSessionExpenseFactory.php`
- `database/factories/StoreSessionExpenseItemFactory.php`
- `app/Actions/StoreSessions/RecordStoreSessionExpense.php`
- `app/Support/CurrentStoreSessionExpenses.php`
- `app/Http/Requests/StoreSessionExpenseRequest.php`
- `app/Http/Controllers/StoreSessionExpenseController.php`
- `app/Http/Controllers/StoreSessionExpenseReceiptController.php`
- `app/Events/StoreExpenseRecorded.php`
- `resources/js/hooks/use-store-expense-realtime.ts`
- `resources/js/components/store-session-expense-form.tsx`
- `resources/js/components/store-session-expense-detail.tsx`
- `resources/js/lib/store-session-expense.ts`
- `resources/js/types/store-session-expense.ts`
- `tests/Feature/StoreSessionExpenseTest.php`
- `tests/store-session-expense-ui.test.ts`
- `tests/verify-store-expenses-postgres.php`

Expected existing-file changes:

- `database/migrations/2026_09_18_174624_create_inventory_tables.php` only if isolated fresh-schema compatibility requires the reserved expense-link column to gain its foreign key in a later additive migration; the historical migration otherwise remains untouched.
- `app/Models/{Branch,StoreSession,Product,User,InventoryMovement}.php`
- `app/Actions/Inventory/ApplyInventoryMovement.php` only for any missing typed relationship enforcement while preserving its existing mutation/event behavior.
- `app/Http/Controllers/CurrentStoreSessionController.php`
- `resources/js/components/store-session-details-dialog.tsx`
- `resources/js/layouts/workspace-layout.tsx`
- `resources/js/lib/store-session.ts`
- `resources/js/types/index.d.ts` or the existing shared type location
- `routes/web.php`, `routes/channels.php`, and generated `resources/js/actions/**` / `resources/js/routes/**`
- Focused existing inventory, Store Session, audit, realtime, route, and UI tests where regression assertions belong.
- `context/05-database-data-model.md`, `context/06-realtime-contracts.md`, `context/07-security-rbac.md`, `context/08-ui-rules.md`, `context/09-ui-registry.md`, `context/11-testing-qa.md`, and `context/13-progress-tracker.md`, limited to actual implemented Phase 14 behavior.

Exact filenames may be adjusted to match sibling naming conventions discovered during implementation, without changing the architecture or scope.

## Data and authorization changes

- `store_session_expenses`: UUID primary key; restrictive Branch, Store Session, and actor foreign keys; description; positive exact amount; constrained payment source; nullable note; private receipt disk/path plus safe name/MIME/size metadata as needed; UUID idempotency key; SHA-256 intent hash; timestamps; unique `(branch_id, idempotency_key)`; indexes for `(store_session_id, created_at)`, `(store_session_id, payment_source)`, `(branch_id, created_at)`, and actor only if justified by the audit/detail query.
- `store_session_expense_items`: UUID primary key; restrictive unique expense foreign key; restrictive Product foreign key; positive bigint quantity; timestamps; Product index only if not already covered by the chosen composite/index layout.
- `inventory_movements.store_session_expense_id`: replace the reserved nullable UUID-only link with a restrictive foreign key once the expense table exists; retain nullability for all non-expense movement types.
- Model relationships expose Branch/Session/actor/item/product/movement without N+1 queries. Normal application behavior has no update/delete path for expense history.
- Permission remains the seeded `store_expenses.manage`; no new role or permission is created. Controller middleware and action-level persisted authorization both enforce it.

## Workflow and failure behavior

1. Cashier selects the existing `STORE OPEN` control. The current-session dialog opens immediately with a loading state and fetches the authoritative projection without changing POS state.
2. The dialog shows LIVE/STORE OPEN, Branch, opened time/by, opening balances, Cash/Cashless/total expenses, the bounded current-session list, and `+ Add Expense / Purchase` only when authorized and online.
3. The form collects description, exact amount string, Cash/Cashless source, optional note and receipt, plus an explicit Restock Inventory toggle. Enabling restock reveals only eligible current-Branch tracked products and positive integer quantity with the stock-increase warning.
4. Submission uses a stable LAN-safe client UUID for retries, disables duplicate submits, sends multipart data through a generated Wayfinder route, and keeps the attempt stable after ambiguous failure. Offline submission is rejected before dispatch with actionable copy.
5. The backend stores/validates the optional private receipt, then in one transaction reauthorizes context, locks the OPEN Session shared, handles replay/conflict, validates product eligibility, creates expense/item, invokes `ApplyInventoryMovement(StorePurchaseRestock, +quantity, ..., expenseId)`, writes canonical audit, and commits.
6. After commit, `store.expense_recorded`, existing audit invalidation, and—when restock-linked—existing inventory/catalog invalidations are published once. The open dialog debounces/coalesces and refetches authoritative data; reconnect also refetches.
7. Selecting a row opens a read-only detail view with safe financial/session/actor/restock/receipt information. Receipt viewing streams through the protected route; no storage path is exposed.
8. Closing the nested form/detail returns to the session overview; closing the session surface returns to the unchanged cart/order/payment/loaded-QR state.

User-visible errors remain actionable: stale/closed Session, invalid amount, invalid tracked product, offline connection, private storage failure, authorization, and changed idempotent intent. Raw exceptions are never shown.

## Exact implementation plan

1. Read the mapped Laravel/security/validation/routing/migration/event/query and Pest rule files; verify version-specific Laravel 13, Inertia 3, Wayfinder, broadcasting, validation, uploaded-image, and transaction APIs through project documentation tooling or installed source.
2. Add the two additive tables, constraints/indexes, and the deferred restrictive inventory-movement foreign key; add models, factories, relationships, payment-source enum, and exact decimal casts consistent with sibling models.
3. Build the current-session expense query/projection with SQL totals, bounded/paginated newest-first rows, safe detail projection, eligible tracked-product data, and strict active-Branch/current-Session scoping.
4. Implement the Form Request/controller/action with dual-layer authorization, stable normalization/hash, exact replay/conflict, shared Session lock, receipt lifecycle, one optional item, `ApplyInventoryMovement`, canonical audit, rollback guarantees, and after-commit event dispatch.
5. Add protected current-session list/create/detail/receipt routes and private branch channel authorization; regenerate Wayfinder functions with form helpers and verify route signatures.
6. Extend the existing Store Session dialog into a Phase-15-ready overview/list shell; add responsive form and read-only detail views, exact string money handling, searchable eligible-product selector, camera/file input fallback, 44px controls, offline/processing/error states, and POS state preservation.
7. Add the expense realtime hook using the existing branch refresh/reconnect helpers, compact invalidation, coalescing/debounce, and authoritative refetch. Reuse inventory and audit realtime with no duplicates.
8. Add focused Pest coverage for normal Cash/Cashless expense, totals, restock/no-restock, authorization and isolation, stale/closed Session, validation, idempotency, audit, receipt security/cleanup, rollback injection, and after-commit events; add frontend contract/state-preservation tests and an isolated PostgreSQL concurrency/migration harness.
9. Compare implemented source side-by-side with the decoded POS standalone for header/control/dialog hierarchy, spacing, typography, actions, mobile sheet/modal behavior, and capture fallback; resolve major structural differences without claiming user visual acceptance.
10. Run focused Phase 14, inventory, Store Session, audit, and realtime tests; isolated SQLite/PostgreSQL migration fresh/up/down/reapply; PostgreSQL concurrency harness; Pint dirty formatting; PHPStan; frontend check; TypeScript; production build; and `git diff --check`.
11. Update only actual Phase 14 context/progress, review the full intended diff/status, commit as `feat: add store session purchases and expenses`, push `feature/store-expenses`, leave USER MANUAL QA pending, and open no PR.

## Testing and verification

- Automated PHP: normal and Cashless expense effects; exact totals excluding prior Session/other Branch; one optional restock; no implicit restock; authorization matrix; current-Session tampering; money/quantity/product/image validation; same-key replay and changed-intent 409; all rollback points; audit identity/metadata/redaction; receipt streaming and cleanup; after-commit event behavior; no fake Payment row.
- Automated frontend: top control opens without navigation; loading/empty/list/detail/form states; fields/toggle/product selection; duplicate-submit block; offline copy; compact realtime invalidation/refetch/reconnect; cart/order/payment/loaded-QR state preservation; no Phase 15 action.
- PostgreSQL: duplicate/competing same-key requests, same-product concurrent restocks without lost updates, different-product restocks without unnecessary deadlock, Session shared-lock compatibility with future exclusive Close Store, constraint enforcement, and migration fresh/down/reapply in a disposable schema.
- Regression: focused `ApplyInventoryMovement`, current Store Session, audit, catalog realtime, and Store Session opening tests.
- Static/build: `vendor/bin/pint --dirty --format agent`, `vendor/bin/phpstan analyse --no-progress --memory-limit=1G`, `npm run check:frontend`, `npm run types:check`, `npm run build`, and `git diff --check`.
- Manual: user validates final visual/interaction behavior at 360/390/430px, tablet, and desktop. Agent reports source parity review only and final status `READY FOR USER MANUAL QA` if every automated gate passes.
- Operations: confirm the normal local development database was not reset/refreshed/wiped; Supabase was untouched; receipt disk configuration remains private; rollback preserves committed historical receipts/expenses according to deployment policy.

## User-guide, business, privacy, and policy impact

- Cashiers gain an in-session operating-expense workflow from the existing top Store Open control. Kitchen-only and management roles do not receive the operational mutation merely through broader dashboards.
- Cash/Cashless classification becomes authoritative input for future reconciliation; optional restock synchronizes the financial record and stock ledger atomically.
- Receipts are private branch-scoped evidence and may contain sensitive vendor/transaction information; they are never public assets or broadcast payloads.
- Context updates document actual schema, event, authorization, UI entry point, tests, and progress. Phase 15, 16, 17, and the full Phase 18 workspace remain incomplete.

## Acceptance checks

- One successful normal expense creates exactly one expense, no item/movement/stock change, one audit record, and one logical expense invalidation.
- One successful restock creates exactly one expense/item/movement, increases current Branch inventory exactly once, ties the movement to the expense, records one audit, and emits existing inventory/catalog invalidations after commit.
- Exact retry returns the same expense with no duplicate financial, inventory, audit, receipt, or realtime effect; changed intent conflicts.
- Cash, Cashless, and total aggregates are exact server-side totals for the whole current Session only.
- Every unauthorized/wrong-Branch/stale/closed/tampered case fails without leaking records or receipt paths.
- Receipt validation/storage/viewing/cleanup matches existing private proof protections and sets `Cache-Control: private, no-store`.
- Same-Branch authorized Cashiers refresh an open dialog after the compact event; other Branches cannot subscribe/read; reconnect refetches without request storms.
- Opening/closing the Store Session surface preserves POS cart/order/payment/loaded-QR state and no new primary navigation item exists.
- All focused automated/static/migration/concurrency gates pass, the branch is committed and pushed, no PR is opened, the working tree is clean, the normal local database was not reset, and final visual acceptance remains `USER MANUAL QA`.

## Risks and open decisions

None. The supplied Phase 14 brief resolves the product, schema, authorization, locking, receipt, realtime, UI entry-point, verification, commit, and deployment-safety decisions needed for implementation.
