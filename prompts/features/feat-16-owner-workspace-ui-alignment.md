# Feature 16 Slice — Owner Workspace UI Alignment

**Status:** Baseline alignment implemented at `33d707f`; manual-QA refinement batch implemented and verified; Phase 16 remains incomplete
**Branch:** `feature/owner-workspace-ui`
**Build-plan owner:** Phase 16 — Owner Workspace (partial pre-Phase 8/9 refinement only)

## Manual-QA Refinement Batch - Blueprint

### What we are building

Refine the already-implemented Owner Workspace alignment without restarting it: add truthful top-bar notification/account controls; expose Add Product, Add Category, and Add Group quick actions; make Products, Categories, and Inventory filtering reactive and server-authoritative; provide tile/list presentation modes; align Add/Edit Product around reusable Groups with inline Group/Option creation; add stable category icons; make Inventory branch behavior follow the global Owner scope and open movement history in a responsive modal; and update only the POS surfaces needed to keep unavailable products visible but non-orderable and to render explicitly marked Size selections as a product-name prefix.

### Language agreed by the supplied brief

- **Groups:** user-facing name for the existing reusable `modifier_groups` / `modifier_options` domain. Internal PHP, relationship, route, and table names may remain unchanged.
- **Unavailable product:** a product/category/branch/stock combination that remains visible in the POS catalog projection but has `is_available = false`, cannot open customization or enter the cart, and is still rejected by authoritative order validation.
- **Size Group:** a reusable Group with an explicit persisted semantic role of `size`; no product-name or category-name inference is permitted.
- **All Branches:** business-wide Owner scope with no fabricated aggregate stock. Product branch configuration may show all authorized branches; Inventory requires an explicit local branch selection before quantities are queried.
- **History modal:** the normal Inventory-list interaction loads real paginated branch/product movements into a responsive dialog/bottom sheet; the existing deep-link route may remain as a fallback.

### Decisions made

1. Add nullable `categories.icon_key` and nullable `modifier_groups.semantic_role` columns in one additive, reversible migration. Validate both against application-owned allowlists; existing rows use generic/no-semantic fallbacks.
2. Use `semantic_role = size` as the only current special Group role. Carry that role through the catalog projection and order modifier snapshots via a nullable semantic snapshot so persisted receipts and summaries do not change when a Group is edited later.
3. Centralize operational line-name formatting in shared backend/frontend helpers. A selected active Size option yields structured display parts (`prefix`, canonical product name, combined accessible label); non-Size options remain modifier detail rows and never alter the canonical product name.
4. Inline Group creation uses the existing Group and Option actions/domain inside one validated, authorized transaction, then attaches the new Group to the current Product save. Existing Group selection continues through the current product/group pivot. No duplicate Group subsystem or hard delete is introduced.
5. Product create/edit remains one modal system. Branch fields are derived from the server-authorized global branch context: one configuration for a selected branch, all authorized branch configurations for All Branches.
6. Reactive filters use debounced Inertia GET visits with query replacement, scroll/state preservation, partial reloads where safe, and server-side pagination/filtering. Tile/list is client presentation state and is remembered locally without refetching.
7. Category icons use a curated Lucide-backed key map shared by Owner and POS-ready presentation. Arbitrary SVG/JSX input is never accepted or persisted.
8. Inventory history keeps its existing protected route as the authoritative data source. The list opens it as modal data with page/load-more navigation; direct navigation still renders the existing fallback page.
9. The notification control opens an explanatory "coming later" popover without a count. The account control shows real identity, role, branch/business scope, only existing authorized routes, and logout.
10. Disabled product management cards use an explicit red border/background/status treatment while preserving Edit and Enable. POS unavailable cards remain readable and visible with an Unavailable badge/overlay and disabled interaction.

### Scope

- **Included:** every item in the attached manual-QA refinement brief for Owner top bar, Products/Categories/Groups, Product Add/Edit and inline Group editing, reactive filters, tile/list views, disabled Product presentation, branch-scoped configuration, Category icons, Size naming, Inventory filtering/scope, Adjust Stock styling, Inventory History modal, the narrowly required POS availability/naming surfaces, focused tests, verification, manual QA, context updates, commit, and push.
- **Excluded:** Kitchen/KDS, Customer Display, full Customer QR ordering, Transaction History, Close Store, Dashboard completion, Reports, Staff, full Settings, branch analytics, real notifications, global search, profile editing without an existing route, destructive Group/Option deletion, PR creation, and Phase 16 completion.

### System invariants

- Authorization, role/permission checks, business-wide scope, branch context, exact decimal pricing, image storage, Group validation, inventory locking/ledger writes, payment/order validation, and stale-cart rejection remain server-authoritative.
- Disabled or unavailable products remain visible only in the intended menu projection and can never be committed through stale client state.
- Inventory remains branch-specific; All Branches never sums quantities.
- Inventory adjustments continue exclusively through `AdjustInventory` and `ApplyInventoryMovement`; reasons stay free text and movements stay append-only.
- Product and category inactivity, branch availability, and out-of-stock state continue to feed one availability contract.
- Order snapshots remain historically stable even after product, Group, option, icon, or semantic-role edits.

### Affected areas

Expected production areas (exact final diff depends on reuse):

- Migration/model/action/request/controller changes for `Category`, `ModifierGroup`, Group/Option inline creation, Product save orchestration, catalog projection, Inventory filtering/history, and order display snapshots.
- `resources/js/components/owner-workspace-shell.tsx`, shared Owner/catalog controls, Product forms, Inventory dialog/history, category icon map/picker, and Product/Category/Groups/Inventory pages.
- Narrow POS changes in catalog/product dialog/cart/payment/order-information/Pay Later/receipt presentation plus shared typed display-name helpers.
- Wayfinder-generated route bindings only if a new route is required; prefer current named routes and controller actions.
- Focused Pest and frontend tests covering schema validation, projections, mutations, branch isolation, filtering, availability, formatting, and responsive UI contracts.

### Data and authorization changes

- Add nullable/default-safe `categories.icon_key` and `modifier_groups.semantic_role` columns with reversible PostgreSQL- and SQLite-compatible migration operations.
- Add a nullable Size semantic snapshot to `order_item_modifiers` only if the existing snapshot fields cannot preserve the naming contract without consulting mutable catalog data; this is the preferred historical-integrity design.
- Keep existing `products.manage` and `inventory.manage` gates and current business-wide branch authorization. Inline creation and auto-attach execute inside the same authorized product workflow and transaction.
- Validate icon keys and semantic roles with allowlists. Validate Group selection type/min/max and option name/price/sort/active state through existing actions; do not weaken current validation.

### Workflow and failure states

- Inline Group create validates the complete nested Group and Options payload, creates all records atomically, attaches the Group to the Product, and rolls back the entire inline operation on any failure. Validation errors stay in the Product modal.
- Existing Group attachment remains idempotent through pivot sync. Removing a Group from a Product removes only the assignment.
- Reactive filters cancel/replace stale visits where supported and show subtle localized progress without blanking the page.
- Inventory modal requests are branch/product bound and preserve pagination. Unauthorized or mismatched branch/product requests remain rejected by the controller/policy boundary.
- Unavailable Product clicks are no-ops with explanatory UI; stale submitted IDs remain rejected by `BranchCatalog`/order creation.

### How to build it

1. Confirm installed package APIs, then read all applicable `.ai/rules`, Laravel/Inertia/Wayfinder/Tailwind guidance, sibling controllers/actions/components, and the decoded Owner/POS standalone sources.
2. Add the smallest reversible schema/model/factory changes for category icon keys, Group semantic roles, and immutable order semantic snapshots; run migration smoke/up/down checks.
3. Extend category and Group validation/actions/controllers and create a transactional Product-save orchestration for inline Groups/Options plus existing Group attachment.
4. Refactor catalog projections so Owner Product modals receive only the branch configurations allowed by global scope, while POS receives visible unavailable products with one explicit availability contract and Group semantic metadata.
5. Add shared operational display-name builders and apply them to POS customization/cart/order information/payment/Pay Later/receipt outputs without changing canonical product names or non-Size Group presentation.
6. Refine the Owner shell top-bar notification/account menus and Product-management quick actions using only real routes/actions.
7. Rebuild Product Add/Edit around the standalone dialog/bottom-sheet structure, including details, image, Options, existing/new Groups, branch fields, and disabled-card styling.
8. Rename user-facing Modifiers to Groups and align the central library, Group fields, Options rows, active states, pricing, min/max, selection type, and semantic Size marker.
9. Add Category reactive filtering, tile/list display, standalone-style modal, icon picker, fallback icon, and existing sort/active/count behavior.
10. Add Product reactive filters and local tile/list preference without presentation-only backend visits.
11. Make Inventory filters reactive; derive selected-branch versus All-Branches behavior from the global context; retain free-text adjustment reasons and authoritative actions.
12. Adapt Inventory History into a list-launched responsive modal/sheet with real branch/product context and paginated movements while retaining a safe deep-link fallback.
13. Add focused backend and frontend tests, run the requested full verification, perform responsive manual QA at 360/390/430/tablet/desktop, then update only `context/09-ui-registry.md` and `context/13-progress-tracker.md`.
14. Review for security, debug output, artifacts, and unrelated changes; commit `feat: refine owner catalog groups and inventory UX`, push to `origin/feature/owner-workspace-ui`, and do not open a PR.

### How to verify it

- **Focused automated:** Product management/business rules, cashier catalog/order rejection, Pay Now/Pay Later snapshots, inventory operations/state/adjustment/history, migration/schema/factory coverage, plus frontend display-name and interaction contract tests.
- **Full automated:** `php -d memory_limit=1G artisan test --compact -d memory_limit=1G`, `vendor/bin/pint --dirty --format agent`, `vendor/bin/phpstan analyse --no-progress --memory-limit=1G`, `npm run check:frontend`, `npm run types:check`, `npm run build`, and `git diff --check`.
- **Database:** additive migrate up/down/up on SQLite smoke and available local PostgreSQL without touching Supabase or unrelated data.
- **Manual:** notification/profile menus; all three quick actions; Product Add/Edit, existing/inline Groups and Options; branch-specific/All Branches config; reactive filters; tile/list; disabled red management card; Category icons; Inventory scope/adjustment/history; unavailable POS card; Size-prefixed naming across every current order surface; keyboard/touch behavior and zero horizontal overflow at all required widths.

### Context updates

- After verification only, update `context/09-ui-registry.md` and `context/13-progress-tracker.md` with Groups terminology, inline Group management, unavailable-but-visible availability, branch-scoped Product modal behavior, explicit Size naming, Category icon keys, and Inventory History modal UX.
- Do not mark Phase 16 complete.

### Risks and open decisions

- **Material open decisions:** None. The user brief fixes terminology, data-safety boundaries, responsive targets, and desired behavior. The explicit marker is implemented as a nullable allowlisted Group semantic role, and icon persistence as a nullable allowlisted key.
- **Primary risks:** preserving historical Size naming, avoiding N+1/query regressions while returning inactive/unavailable catalog rows, nested inline validation/error mapping, and ensuring reactive visits do not create stale UI races. These are covered by immutable snapshots, eager-loading/query tests, transactions, and focused navigation tests.

Blueprint ready.

## Prior Alignment Objective

Align the existing Owner-facing shell, Products/Categories/Modifiers, Inventory/Movement History, and Branch Management surfaces with `context/design/PONGSKILOG-OWNER.html` while preserving the application's stronger database-backed catalog, inventory, branch, authorization, image, and mutation behavior.

This slice must not complete or mark complete Phase 16. It must not add Kitchen/KDS, Customer Display, Reports, Staff management, full Settings, transaction history, branch comparison, Store Session reporting, or mock analytics/data.

## Canonical context read

- `context/01-project-overview.md`
- `context/04-architecture.md`
- `context/08-ui-rules.md`
- `context/09-ui-registry.md`
- `context/10-build-plan.md`
- `context/11-testing-qa.md`
- `context/13-progress-tracker.md`
- `context/14-coding-standards.md`
- `context/15-library-docs.md`
- `.ai/rules/index.md`
- `.ai/rules/app-js.md`
- `.ai/rules/app.md`
- `.ai/rules/js.md`
- `context/design/PONGSKILOG-OWNER.html`, including the decoded `__bundler/template` source

## Standalone observations that control presentation

- Desktop sidebar is 248px at wide widths; tablet uses a 96px dark rail; mobile uses a 60px top bar and fixed dark bottom navigation.
- Desktop top bar is 72px. The main content uses `#F7F7F7`, 18px/22px padding, white surfaces, `#E5E5E5`/`#ECECEC` borders, moderate 10–20px radii, and restrained shadows.
- Owner typography uses Poppins with compact hierarchy: 23px desktop page title, 14–16px item headings, 12–13.5px body/control text, and 10–11px uppercase metadata.
- Products uses Products/Categories segmented navigation, a compact search/category/status toolbar, 68px thumbnails, status/stock/option-group chips, two equally weighted actions, and a bottom-sheet modal on mobile.
- Inventory uses three server-truth summary cards, a compact filter bar, and a dense six-column desktop list that collapses to wrapped rows/cards below approximately 980px.
- Mobile does not retain the desktop sidebar/header composition; primary destinations remain discoverable through the bottom dock and an accessible More menu.

## Approved decisions encoded by the brief

1. **Standalone presentation, real backend behavior.** The standalone controls visual hierarchy and interaction patterns; Laravel remains authoritative for all data, validation, authorization, inventory, images, and branch state.
2. **Owner shell scope.** Apply the new dark shell to Owner management pages and keep the specialized Cashier POS shell unchanged. Super Admin users who access shared management routes retain their identity and authorization; no Owner-only permission is inferred from presentation.
3. **Navigation truthfulness.** Dashboard links to the existing protected Owner entry route. Products, Inventory, and Settings/Branch Management link to real routes when authorized. Transactions, Reports, and Staff remain visibly unavailable with a clear “Coming later” treatment. Do not show a fake global search, notifications, counts, or dead controls.
4. **Branch context.** Reuse the existing server-authorized `BranchSwitcher`. Product stock/status is shown and filterable only for the selected branch. In All Branches scope, product cards clearly state that a branch must be selected for stock detail; no cross-branch stock number is fabricated.
5. **Catalog organization.** Reproduce the standalone Products/Categories segmented treatment using real routes. Retain Modifiers as a third management tab because it is real, required functionality even though the standalone combines some option editing into its product modal.
6. **Product edit workflow.** Keep product details, image management, modifier assignments, and branch overrides available from the styled product edit/detail flow. Add quick Enable/Disable actions through the existing validated update route rather than inventing a second mutation path.
7. **Inventory summaries.** Produce summary counts on the server from the full branch-scoped query. Never derive aggregate counts from the paginated page. Reuse the same stock-state semantics for inventory and product filtering.
8. **Branch status versus Store Session.** Continue displaying both independently. Branch status edits must never open or close a Store Session.
9. **No dependencies or schema changes.** Existing React, Radix, Lucide, Inertia, Tailwind, Wayfinder, and Laravel facilities are sufficient.

## Scope

### Included

- Owner dark desktop sidebar, tablet rail, mobile top bar/bottom dock/More navigation, profile/logout area, real branch/business context, active-route treatment, content surfaces, and responsive behavior.
- Shared Owner page-header, tabs, filters, cards, tables/rows, badges, buttons, dialogs, empty states, focus states, and touch targets.
- Products cards with real image, category, default price, selected-branch stock status/quantity where applicable, active/disabled state, modifier-group count, Edit, and Enable/Disable.
- Existing add/edit details, image, modifiers, branch pricing/availability/inventory settings, and pagination integrated into the aligned design.
- Categories aligned as the same management area, with real count/status/edit/enable-disable behavior.
- Modifiers visually aligned and retained as a real third tab.
- Inventory summary counts, branch/category/search/status controls, compact responsive rows, quantity/status/threshold/real update time, Adjust Stock, and View History.
- Inventory movement history and adjustment dialog aligned with the Owner visual system.
- Branch Management cards, status/store-state badges, Add/Edit dialog, long-content handling, empty state, and real QR-page link.
- Focused backend projection/query changes needed for real stock/status/summary/update-time presentation.
- Focused tests, full verification, progress tracker note, UI registry imprint, commit, and push.

### Excluded

- Phase 8 Kitchen/KDS and Phase 9 Customer Display.
- Phase 12 Transaction History work and Phase 15 Close Store.
- Full Phase 16 Dashboard, Transactions, Reports, Staff, Settings, comparison analytics, Store Session summaries, notifications, or global search.
- New branch capabilities, deletion, operating hours, managers, analytics, performance metrics, or Store Session controls.
- Stock transfers and any mock/demo/static production data.

## Architecture and security invariants

- Existing `products.manage`, `inventory.manage`, `settings.manage`, branch policies, active-user checks, and business-wide scope remain server-authoritative.
- Hidden or disabled UI is never treated as authorization.
- Product and inventory reads remain branch-correct; branch inventory must not leak between branches.
- Inventory mutations continue exclusively through `AdjustInventory` / `ApplyInventoryMovement`; no direct client-side balance mutation.
- Inventory history remains append-only.
- Product prices remain exact decimal strings; UI number conversion is display-only.
- Product images continue using signed optimized card variants and the existing missing/broken-image fallback.
- Existing Store Session state is read-only on Branch Management and remains independent of branch status.

## Affected areas

Expected production files (final diff may be smaller after reuse review):

- `resources/css/app.css`
- `resources/js/app.tsx`
- `resources/js/layouts/workspace-layout.tsx`
- `resources/js/components/branch-switcher.tsx`
- `resources/js/components/catalog-ui.tsx`
- `resources/js/components/product-forms.tsx`
- `resources/js/components/inventory-ui.tsx`
- `resources/js/components/inventory-adjustment-dialog.tsx`
- `resources/js/components/owner-workspace-shell.tsx` (new, only if extraction materially reduces duplication)
- `resources/js/components/owner-ui.tsx` (new shared Owner primitives, only if justified)
- `resources/js/pages/workspaces/show.tsx`
- `resources/js/pages/catalog/products.tsx`
- `resources/js/pages/catalog/categories.tsx`
- `resources/js/pages/catalog/modifiers.tsx`
- `resources/js/pages/inventory/index.tsx`
- `resources/js/pages/inventory/movements.tsx`
- `resources/js/pages/branches/index.tsx`
- `resources/js/types/catalog.ts`
- `resources/js/types/inventory.ts`
- `app/Http/Controllers/ProductController.php`
- `app/Http/Controllers/InventoryController.php`
- `app/Http/Requests/InventoryIndexRequest.php`
- `app/Support/InventoryState.php` or one narrowly scoped shared stock-query helper if needed to avoid duplicate status logic

Expected test/context files:

- `tests/Feature/ProductManagementTest.php`
- `tests/Feature/InventoryOperationsTest.php`
- `tests/Feature/BranchManagementTest.php` only if the server projection changes
- `tests/Feature/Phase1DWorkspaceRoutingTest.php` only if shared shell props/contracts change
- `context/09-ui-registry.md`
- `context/13-progress-tracker.md`

## Database and migration impact

- No migration.
- No new table, column, index, enum, or seed data.
- Read-model queries may add selected-branch inventory configuration/balance eager loads and server-side aggregate counts.
- Query-count regression coverage must remain bounded for paginated catalog and inventory lists.

## Implementation plan

1. Build the reusable Owner shell inside the existing workspace layout boundary, preserving the Cashier-specific shell. Match the standalone desktop/sidebar, tablet rail, mobile dock, top bar, branch context, active-route rules, profile/logout, and content background. Only real routes are links; deferred destinations are disabled and labelled.
2. Add small reusable Owner UI primitives only where they remove repeated page-header, segmented-tab, surface, badge, filter-control, empty-state, and dialog styling. Apply Poppins through an Owner-specific surface class rather than changing unrelated application workspaces.
3. Extend the Product listing read model with selected-branch stock state and modifier-group count, using `InventoryState` semantics and database-side status filtering before pagination. Keep All Branches behavior explicit and non-fabricated.
4. Rebuild Products around the standalone compact cards and controls. Preserve pagination, signed thumbnails, create/edit, image management, modifier assignment, branch overrides, and exact prices. Wire quick Enable/Disable through the existing Wayfinder update route with processing/error protection.
5. Rebuild Categories with the same segmented management navigation, compact rows, product counts, state badges, real edit, and validated enable/disable. Restyle Modifiers consistently without removing any group/option functionality.
6. Add branch-scoped inventory summary counts, category filtering, and real balance update timestamps on the server. Keep the existing status filtering, pagination, query bounds, and branch isolation.
7. Rebuild Inventory as standalone-style summary cards + compact filter toolbar + responsive dense table/list. Keep untracked products explicit, retain Adjust Stock and View History, and align the adjustment/history dialogs/pages.
8. Restyle Branch Management as a native Owner page with compact commercial cards, separate branch-status and Store OPEN/CLOSED badges, real Add/Edit/QR controls, robust empty and long-content states, and responsive bottom-sheet dialogs.
9. Add/update focused Pest coverage for new projections, full-dataset summary counts, status/category filters, All Branches behavior, branch isolation, authorization, and bounded queries. Reuse existing factories and endpoint patterns.
10. Run focused tests after each backend/test change, then run the requested full suite and frontend/PHP/repository checks. Correct all introduced failures.
11. Manually compare the live application with the decoded standalone at 360px, 390px, 430px, tablet, and desktop. Exercise empty, missing-image, disabled, tracked/untracked, low/out, no-branch, long-name/address/contact, dialogs, focus, and no-overflow states. Record any environment-limited checks honestly.
12. Imprint approved reusable Owner shell/component patterns into `context/09-ui-registry.md`. Add a dated pre-Phase 8/9 Owner UI alignment note to `context/13-progress-tracker.md` without checking Phase 16 complete.
13. Review the final diff for unrelated changes, secrets, debug code, and artifacts; commit as `feat: align owner products inventory and branches UI`; push to `origin/feature/owner-workspace-ui`; do not open or merge a PR.

## Testing and verification

### Focused automated coverage

- `php artisan test --compact tests/Feature/ProductManagementTest.php`
- `php artisan test --compact tests/Feature/CatalogBusinessRulesTest.php`
- `php artisan test --compact tests/Feature/InventoryOperationsTest.php`
- `php artisan test --compact tests/Feature/InventoryStateTest.php`
- `php artisan test --compact tests/Feature/ApplyInventoryMovementTest.php`
- `php artisan test --compact tests/Feature/BranchManagementTest.php`
- Relevant RBAC/workspace routing tests if shared contracts change.

### Full and static checks

- `php -d memory_limit=1G artisan test --compact -d memory_limit=1G`
- `vendor/bin/pint --dirty --format agent`
- `vendor/bin/phpstan analyse --no-progress --memory-limit=1G`
- `npm run check:frontend`
- `npm run types:check`
- `npm run build`
- `git diff --check`
- Working-tree review for debug code, secrets, generated artifacts, and local-only files.

### Manual UI checks

- Standalone and live app at 360px, 390px, 430px, tablet/rail, and desktop/sidebar widths.
- Keyboard tab order, visible focus, dialog close/focus behavior, labels, state text, and 44px touch targets.
- Products: normal/long names, missing image, disabled state, modifiers, selected branch stock, All Branches scope, add/edit/image/branch overrides, pagination and filters.
- Inventory: summary correctness, branch/category/search/status filters, tracked/untracked, low/out/in-stock, last update, adjustment failure/success, history, and pagination.
- Branches: no branches, long address/contact, every branch status, independent Store state, Add/Edit validation, and QR navigation.
- No horizontal document or dialog overflow at all target widths.

## Documentation impact

- Update `context/09-ui-registry.md` with the approved Owner workspace shell and management-surface patterns after visual QA.
- Update `context/13-progress-tracker.md` with an explicit “Owner Workspace UI Alignment — Products + Inventory + Branch Management” record before Phase 8/9.
- Do not check any complete Phase 16 item solely because of this refinement.
- No user guide, deployment, dependency, database-model, business-rule, or privacy-policy change is expected.

## Acceptance checks

- The Owner shell materially matches the decoded standalone across desktop, tablet, and mobile.
- Products/Categories/Modifiers form one coherent management area and all existing real operations remain reachable.
- Product stock/status is real and selected-branch scoped; no fake All Branches stock is shown.
- Inventory summaries come from full server-side branch data, not the current page.
- Inventory adjustment/history behavior and branch isolation are unchanged.
- Branch status and Store Session state remain visibly and behaviorally distinct.
- Deferred Owner modules are not implemented and no visible control is dead or misleading.
- Focused tests, full suite, lint, typecheck, build, Pint, PHPStan, and `git diff --check` pass.
- Phase 16 remains pending.
- One commit is pushed to `feature/owner-workspace-ui`; no PR is opened.

## Risks and open decisions

- Material open decisions: None. The supplied brief resolves scope, source-of-truth priority, navigation truthfulness, responsive targets, authorization, and completion/reporting requirements.
- Implementation risk: stock-state joins and aggregate counts could regress query bounds. Mitigate with shared semantics, eager loading, database-side aggregates, and focused query-count tests.
- Visual risk: shared management routes are available to both Owner and Super Admin. The shell must preserve the authenticated role label and permissions without exposing Super Admin-only controls to Owner.
