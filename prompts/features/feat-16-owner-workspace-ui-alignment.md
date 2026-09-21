# Feature 16 Slice — Owner Workspace UI Alignment

**Status:** Implemented and verified; Phase 16 remains incomplete
**Branch:** `feature/owner-workspace-ui`
**Build-plan owner:** Phase 16 — Owner Workspace (partial pre-Phase 8/9 refinement only)

## Objective

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
