# Feature 13 — Void & Audit

**Status:** Implemented
**Branch:** `feature/void-audit`
**Starting commit:** `172d285f831e627bef9c854410d84ec983846a7a`

## Canonical context read

- `context/00-context-index.md` through `context/15-library-docs.md`, with Phase 13, audit, security, database, realtime, QA, UI, and coding-standard requirements reviewed.
- Decoded `context/design/pos.html` and `context/design/PONGSKILOG-OWNER.html` bundled `__bundler/template` sources.
- Scoped `.ai/rules` covering app, Actions/Orders, controllers, React pages/components, hooks, and Kitchen workspace.
- Current Phase 12 implementation: committed-order editing, append-only payments/inventory movement ledger, audit logs for edits/proofs, realtime invalidations, transaction history, and the Owner/Super Admin shell.

## Approved decisions

- Void authorization uses one global four-digit approval PIN configured by an authenticated active Super Admin and stored only as a password hash.
- Void has strict two-person control: `initiated_by_user_id` and `authorized_by_user_id` must identify different authenticated users. A dual-role user cannot authorize their own Void.
- The initiating user must be an active assigned cashier or cashier+kitchen operator with POS access to the active branch; the authorizer is the active Super Admin who most recently configured the global PIN. Possession of that PIN intentionally represents delegated approval authority.
- No hardcoded prototype PIN, plaintext credential storage, credential logging, secret audit metadata, or secret realtime payload.
- One append-only void record per Order is the authoritative Void event; payment, OrderItem, KitchenTicket, prior inventory movement, and adjustment history are retained.
- Audit is centralized through one explicit recorder and is mandatory/atomic for current business-critical and security-sensitive mutations.

## Scope

Included:

- Cashier Transaction History Void flow, eligibility states, secure confirmation, idempotency/stale protection, and VOIDED history/detail/receipt states.
- Net inventory restoration from the Order's existing `sale`, `pay_later_commit`, and `order_edit_delta` movements.
- Compact post-commit Void invalidations for POS, Kitchen, Customer Display, and QR tracking.
- Super Admin-only Audit Trail and Void Orders read surfaces with server-side pagination, filters, details, and Owner/Cashier/Kitchen denial.
- Complete audit coverage pass for existing in-scope state-changing business/security actions.
- Additive migrations, focused feature/frontend tests, isolated PostgreSQL concurrency harness, and required source-of-truth context updates.

Excluded:

- Phase 14 purchases/expenses, Phase 15 Store Close/reconciliation, full Phase 16 Owner Transactions/Reports, and all other Phase 18 surfaces.
- Refund payments, payment rewrites/deletes, Kitchen ticket replacement/deletion, order identifier reallocation, historical audit backfill, dependency changes, Supabase changes, and a pull request.

## Architecture and security invariants

- Void is allowed only for a committed active Order in the initiator's active branch and current open Store Session; drafts, submitted/archived QR orders, already-voided Orders, foreign-branch records, and prior/closed-session Orders are denied.
- The Void action takes the existing lock order: open StoreSession shared lock → Order exclusive lock → KitchenTicket where needed → sorted tracked product/BranchInventory rows.
- The same database transaction validates initiator/authorizer/state/version/idempotency, restores inventory, creates the void, transitions the Order, increments version, and records Audit. A failure rolls back all of it.
- Same idempotency key plus same canonical payload returns the original success; a changed payload returns 409. Concurrent Void attempts have one winner.
- Void restoration reverses only the aggregate negative consumption from the authoritative Order movement history and appends positive `void_restore` rows. It never uses current Order Item quantities as the source of truth.
- Audit is read-only and append-only in normal application behavior. Before/after/metadata use purpose-built, redacted snapshots—not arbitrary model serialization.
- Realtime is after commit, compact, branch- or order-scoped, and contains no reason text, credentials, payment data, audit JSON, or authorizer identity on normal operational channels.

## Affected areas

- Database: additive `order_voids` migration, audit-list indexes, relationships/factories, and Order/Audit projections.
- Domain: `VoidOrder`, a canonical `AuditRecorder`, authorization/eligibility helpers, inventory movement restoration, and existing mutation actions migrated to recorder use.
- HTTP: dedicated Void Form Request/controller/route/throttle; protected Audit Trail and Void Orders controllers/routes.
- Events/projections: compact `order.voided` event and authoritative refetch integrations for POS/History, KDS, Customer Display, and customer tracking; receipt/tracking/history projections handle terminal Void state.
- UI: existing Transaction History Void control/modal; management shell navigation; Super Admin Audit Trail and Void Orders pages/detail sheets, following decoded standalone structure and responsive behavior.
- Tests: focused Pest feature coverage, frontend contract tests, and `tests/verify-void-audit-postgres.php` using isolated temporary PostgreSQL schemas.
- Context: update the required Phase 13 source documents without marking Phase 13 or full Phase 18 complete.

## Data and authorization design

- `order_voids`: UUID primary key, branch, Store Session, unique Order, initiator, authorizer, stable reason code and human-readable label, nullable required-for-Other note, `super_admin_pin` method, timestamps, and useful list/detail indexes.
- `void_authorization_settings`: one globally unique row with only the PIN hash, configuring Super Admin, and configuration timestamp. No plaintext PIN column exists.
- Orders retain their identity/history and transition to `commercial_status=voided`, `voided_at`, and a new version. Payment status remains historical truth (paid/unpaid/partial), while operational projections distinguish it from `VOIDED`.
- Audit indexes support `(branch_id, created_at)`, `(user_id, created_at)`, `(module, created_at)`, `(action, created_at)`, and `(auditable_type, auditable_id, created_at)` without redundant speculative indexes.
- Audit Trail and Void Orders use Super Admin-only backend authorization without requiring POS active-branch context; Owner is explicitly denied their dedicated pages.

## Workflow and failure behavior

1. The cashier opens a current-session eligible transaction and sees the standalone-parity Void modal.
2. The cashier selects a mandatory stable reason; `Other` requires meaningful free text; the global four-digit approval PIN is entered for delegated authorization by its configuring Super Admin.
3. The request carries UUID idempotency key and expected Order version; the UI never queues an offline Void and shows generic authorization failure wording.
4. The action authenticates both actors, locks the session and Order, validates scope/status/version/unique void, aggregates eligible prior movement quantities, locks tracked inventory deterministically, appends restoration movements, writes void + audit, and commits.
5. After commit, compact invalidations prompt authoritative refetch. KDS hides the ticket through commercial state rather than false lifecycle transition; Customer Display removes it; QR tracking resolves to a customer-safe terminal state; normal Cashier/customer receipt access is denied after Void while payment history remains protected internally.

## Exact implementation plan

1. Inspect/update the generated Wayfinder surface and add additive schema/models/factories/relationships for void records and non-redundant Audit indexes.
2. Implement the typed AuditRecorder, migrate existing edit/proof writes, and add atomic audit calls to every current business/security mutation; publish the resulting audited/not-audited endpoint matrix.
3. Build the Void request/controller/action with Form Request validation, throttle, global hashed-PIN authorization, distinct initiator/authorizer attribution, idempotency hash, session/order/version locking, aggregate ledger restoration, void/audit persistence, and post-commit events.
4. Extend Transaction History/detail/receipt/tracking/KDS/display queries and projections so voided records are retained and operationally excluded correctly.
5. Add Super Admin Audit Trail and Void Orders controllers, paginated query objects/projections, protected routes, management-shell navigation, and responsive standalone-parity React pages/detail views.
6. Wire cashier modal/form state, eligibility explanations, changed/stale handling, local stable UUID generation, protected route functions, and targeted realtime refetch behavior.
7. Add focused Pest, frontend, and PostgreSQL concurrency coverage; validate migration fresh/rollback/reapply on isolated SQLite/PostgreSQL; update canonical Phase 13 context documents while leaving final/manual acceptance unchecked.
8. Run focused tests and static/build gates, review diff, commit to `feature/void-audit`, push it, and do not open a PR.

## Testing and verification

- Void matrix: eligible payment/Pay Later/partial/edited/QR-origin orders; inventory tracked/untracked/repeated products; wrong/missing PIN and ineligible PIN-owner denial; stale version; initiator/authorizer distinction; idempotent replay/conflict; retained payments/adjustments/tickets.
- Audit matrix: row data/redaction/rollback for every audited existing mutation, no audit for read-only endpoints, append-only routes, protected Audit Trail filters/pagination/details, and all denied roles.
- Void Orders matrix: business-wide lists/filter/search/detail/payment/inventory/audit linkage plus role denial.
- Concurrency: duplicate void, void versus edit/settlement/kitchen/invoice proof/session boundary, ordered product locking, exact replay/conflict, and unrelated Orders.
- Focused PHP/frontend suites, Pint, PHPStan, frontend check, TypeScript, production build, `git diff --check`, isolated migration cycles, and targeted browser checks only. User manual QA remains required.

## Documentation, policy, and user-guide impact

- Update contexts 02, 03, 05, 06, 07, 08, 09, 11, and 13 with the accepted global hashed-PIN model, initiator/authorizer distinction, Void record, net restoration, protected management surfaces, audit policy/matrix, and accepted manual QA.
- Do not mark Phase 13 complete or present the two Super Admin pages as full Phase 18.

## Acceptance checks

- Exactly one durable Void/audit/restoration effect exists for a successful attempt; no historical financial/inventory/Kitchen data is overwritten or deleted.
- The active PIN-owning Super Admin is attributed as authorizer; the operational initiator is distinct; wrong/missing PIN and ineligible PIN-owner cases fail safely and generically.
- Void correctly neutralizes the Order's ledger effect after repeated edits; payments and lower-total adjustments remain historically accurate.
- Voided Orders are absent from Cashier History and receipt surfaces while protected Super Admin history and customer-safe operational surfaces remain truthful, with no sensitive realtime leakage.
- Super Admin can audit all branches through paginated protected pages; Owner and operational roles cannot.
- All required automated/static/migration gates pass, the branch is pushed, no PR exists, and manual QA remains explicitly pending.

## Risks and open decisions

None. The two-person approval policy is explicitly approved.
