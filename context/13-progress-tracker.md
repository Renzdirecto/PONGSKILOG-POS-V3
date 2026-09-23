# PONGSKILOG POS V3 — Development Progress Tracker

**Purpose:** Development implementation tracking only.  
**Source:** `10-build-plan.md`

Check an item only when the corresponding implementation and required verification are complete.

---

## Phase 0 — Foundation Verification

- [x] Laravel 13 / PHP 8.4 verified
- [x] React 19 / Inertia 3 verified
- [x] TypeScript / Tailwind 4 / Vite 8 verified
- [x] PostgreSQL / Supabase connected
- [x] Redis configured
- [x] Laravel Reverb configured
- [x] Supabase Storage configured
- [x] Pest configured
- [x] Larastan / PHPStan configured
- [x] Pint configured
- [ ] CI pipeline verified
- [x] Baseline build/tests passing

---

## Phase 1 — Identity, RBAC & Branch Foundation

- [x] Phase 1A — Database + Eloquent model foundation
- [x] Phase 1B — RBAC + seeders
- [x] Phase 1C — Branch context + authorization
- [x] Phase 1D — Branch selector + role routing UI
- [x] Phase 1E — Security tests + final verification

- [x] Login
- [x] Logout / session handling
- [x] Users
- [x] Roles
- [x] Permissions
- [x] User role assignment
- [x] Staff branch assignments
- [x] Branch selection
- [x] Active branch context
- [x] Owner business-wide scope
- [x] Super Admin business-wide scope
- [x] Policies / Gates
- [x] Branch authorization tests

---

## Phase 2 — Branches & Store Sessions

- [x] Phase 2A — Store Session database + Eloquent foundation
- [x] Phase 2B — Open Store business operation
- [x] Phase 2C — Store Closed / Browse / Open Store UI
- [x] Phase 2D — Existing Store Session + shared store state
- [x] Phase 2E — Security / concurrency / final verification
- [x] Phase 2F — Branch management foundation + Customer QR store-state bridge

- [x] Branch management foundation
- [x] Branch status
- [x] Store Closed state
- [x] Browse mode
- [x] Backend read-only enforcement for Browse (current Phase 2 route surface; see scope below)
- [x] Open Store
- [x] Opening Cash
- [x] Opening Cashless
- [x] Existing Open Store detection
- [x] One active Store Session per branch constraint
- [x] Concurrent Open Store protection
- [x] Store state propagated to Customer QR

Phase 2E verification (2026-09-18):

- Reviewed the complete Phase 2 diff against `dev`, routes, middleware, authorization, action transactions, schema, shared props, and local seeder. No production-code defect found; added four missing security/read-only regressions and an opt-in PostgreSQL harness.
- `php tests/verify-store-session-postgres.php` uses explicit local PostgreSQL settings with `DB_URL=null`. Two independent cashier processes must both be observed waiting on the branch lock before the harness releases it. Both returned session `01a0b3cb-13d2-73a0-902f-9dcb3e1b58b6`; exactly one OPEN row existed, with the winning opener, timestamp, Cash `111.11`, and Cashless `222.22` unchanged. Both connections remained usable with no open transaction.
- PostgreSQL 18 verification used a temporary `phase2e_*` schema on `127.0.0.1:5432`, because the local app role lacks database-creation permission. Fresh migrations/RBAC seed, UUID/numeric(14,2) schema, partial unique index, constraint rejection, retained history, multiple CLOSED sessions, and independent branch OPEN sessions passed. The schema was removed; the normal local database's partial unique index was also verified read-only. Supabase was untouched.
- Targeted Phase 2 tests: 117 passed / 634 assertions. `composer test`: 245 passed / 994 assertions, Pint and PHPStan passed (process-only 1 GB memory override). `npm run check:frontend`, `npm run types:check`, and `npm run build` passed. Isolated SQLite fresh migration/RBAC seed passed with `DB_URL` cleared.
- Security, existing-session preservation, branch switching/state isolation, safe shared props, and LocalDevelopmentSeeder production guard/idempotency passed. Phase 2C/2D developer manual QA remains accepted; no user-facing behavior changed.
- Browse/closed-store scope: the current workspace is read-only and exposes no order, payment, inventory, expense, or other operational mutation endpoint/button. Open Store is the sole operational write and is intentionally allowed while CLOSED, subject to backend authorization. Account settings and branch selection are separate identity/context operations. Future operational routes still require Store OPEN enforcement; no general mutation middleware is claimed here.
- At the Phase 2E checkpoint, Branch management and Customer QR integration remained incomplete; Phase 2F below completes those foundations. Phase 3 was not started.

Phase 2F final verification (2026-09-18):

- Developer manual QA accepted as PASS: Owner can list MAIN/QAVE, create a temporary branch, edit core fields, and change status; Cashier direct access is denied; no Branch delete action exists. Customer QR OPEN/CLOSED/unavailable states passed on desktop and approximately 390px mobile. Browser automation was not retried.
- Branch management is limited to list/create/update of code, name, status, address, and contact, with backend policy/Form Request authorization. Status changes preserve Store Sessions. No advanced settings, table management, cashless/receipt configuration, or deletion were added.
- Public QR reuses `StoreState`: an active branch with an OPEN session shows STORE IS OPEN; otherwise it shows STORE IS CURRENTLY CLOSED. Availability is resolved from persisted state on each request/refresh. Public props are exactly `branch: { id, name, code }` and `store: { status }`, including for signed-in staff; no balances, StoreSession IDs, staff, permissions, reconciliation, or internal assignments are exposed.
- Targeted Branch tests: 29 passed / 228 assertions. Targeted Customer QR tests: 11 passed / 129 assertions. `composer test`: 285 passed / 1351 assertions, with Pint and PHPStan passing; no memory override was needed. `npm run check:frontend`, `npm run types:check`, and `npm run build` passed. The build reports a non-blocking optional `fontaine` warning and plugin timing diagnostics; dependencies were not changed.
- Reviewed `git diff dev...HEAD` and all uncommitted Phase 2F files for authorization, StoreSession reuse/concurrency, exact decimal money, branch isolation, current store state, public data exposure, secrets, and scope. No defect requiring behavior changes was found. Phase 2F adds no migration, dependency, machine-specific configuration, or Supabase change.
- Phase 2 is fully complete. Full Customer QR ordering remains Phase 10: no cart, order submission, customer account, payment, inventory, or Kitchen logic was added. Phase 3 and later-phase checkboxes remain untouched.

---

## Phase 3 — Catalog & Product Images

- [x] Phase 3A — Catalog database + Eloquent foundation
- [x] Phase 3B — Catalog rules + branch overrides + modifiers
- [x] Phase 3C — Product image pipeline
- [x] Phase 3D — Product management UI
- [x] Phase 3E — Cashier real catalog Browse
- [x] Phase 3F — Security / performance / final verification

- [x] Categories
- [x] Products
- [x] Product modifiers
- [x] Product image upload
- [x] Branch product overrides
- [x] Branch price override
- [x] Branch availability
- [x] Low-stock threshold
- [x] Optimized image variants
- [x] Product image fallback
- [x] Lazy-loading / image performance

Phase 3A verification (2026-09-18):

- Added six catalog tables, five UUID Eloquent models and factories, Branch relationships, and the `single`/`multiple` modifier selection enum. Prices use `numeric(14,2)` with `decimal:2` string casts, matching StoreSession; no authoritative floats.
- Database constraints enforce non-negative prices/thresholds, valid selection bounds/types, unique branch/product and product/modifier mappings, and restrictive foreign keys. Indexes cover active/sorted categories, category/active products, branch availability, sorted modifier options, and reverse relationships.
- Targeted `CatalogFoundationTest`: 43 passed / 121 assertions. `composer test`: 328 passed / 1472 assertions; Pint and PHPStan passed with a temporary process-only 1 GB memory override after PHPStan reached the local 128 MB limit. No machine-specific configuration was committed.
- Additive migration passed on local PostgreSQL at `127.0.0.1:5432`. Read-only metadata queries verified all three `numeric(14,2)` columns, both mapping uniqueness constraints, seven CHECK constraints, six restrictive foreign keys, and lookup indexes. Isolated SQLite in-memory fresh migration passed with `DB_URL` explicitly cleared. Supabase was untouched.
- Scope is schema/model foundation only. Main Phase 3 behavior checkboxes remain incomplete; catalog actions, effective pricing/availability, image processing, management UI, cashier Browse, and inventory quantities are not implemented in this batch.

Phase 3B verification (2026-09-18):

- Added explicit Category, Product, Modifier Group, and Modifier Option create/update actions, BranchProduct upsert, and transactional Product Modifier Group sync. Every mutation uses the existing `products.manage` permission through a Gate that rechecks persisted user activity and permission; seeded Owner/Super Admin pass, while normal staff, inactive users, and revoked permissions are rejected.
- Monetary input must be an unsigned decimal string with up to 12 integer digits and 2 decimal places; floats, malformed values, negatives, and overflow are rejected. Product updates preserve the existing image path. Branch overrides remain unique per branch/product and configure inventory tracking/threshold only, without inventory balances.
- `BranchCatalog` returns the requested branch's non-null override price or the global default as an exact decimal string. Availability requires an active Category and Product and no unavailable override for that branch. It rechecks persisted state, keeps branches isolated, and excludes Store Session state.
- Modifier configuration validates the selection enum and bounds, option group membership and price, and all submitted group IDs. Sync deduplicates mappings, accepts an empty list, preserves other products, and rolls back failed replacement writes.
- Targeted Phase 3B tests: 220 passed / 1018 assertions. Combined Phase 3A + 3B catalog tests: 263 passed / 1139 assertions. `composer test`: 548 passed / 2490 assertions, with Pint and PHPStan passing. Tests used isolated SQLite in memory with `DB_URL` explicitly cleared. No migrations, dependencies, frontend files, or Supabase changes.
- Only Phase 3B and completed branch override/price/availability/threshold configuration items are checked. Broader Categories, Products, and Product modifiers checklist items remain conservative pending their management integration. Phase 3C–3F, images, cashier catalog Browse, and inventory quantities remain unimplemented.

Phase 3C verification (2026-09-18):

- Added synchronous `ReplaceProductImage` and `RemoveProductImage` actions using the existing persisted-state `products.manage` Gate. Owner/Super Admin pass; staff, inactive users, revoked permissions, and missing Products are rejected. No endpoint or frontend was added.
- Native GD supports JPEG, PNG, and WebP. Uploads require content/MIME validation, at most 8 MB and 6000 x 6000 dimensions, a safe available-memory budget, and successful decoding. Exactly two WebP variants use quality 84: card within 480 x 480 and detail within 1200 x 1200, preserving aspect ratio and transparency without cropping/upscaling. Re-encoding removes source metadata; processing creates no local temporary files.
- Existing private `s3` storage holds `catalog/products/{product_uuid}/{asset_uuid}/source.{jpg|png|webp}`, `card.webp`, and `detail.webp`. `products.image_path` stores only the canonical detail key. `ProductImages` derives application paths and temporary URLs (five-minute default), returns null for absent images, and refuses unsafe/foreign paths. No source URL is exposed.
- Replacement checks each write and all three objects' existence before locking/reloading the Product and saving its new reference. Old cleanup runs after commit; failures before commit clean the new asset best-effort and propagate the primary error. Outer transaction rollbacks also clean uncommitted assets. Removal clears the DB reference before cleanup. Cleanup failures log only Product ID and exception type and do not undo valid DB state.
- Targeted `ProductImagesTest`: 63 passed / 260 assertions. Combined Phase 3A–3C catalog tests: 326 passed / 1399 assertions. `composer test`: 611 passed / 2750 assertions, with Pint and PHPStan passing. The standalone PHPStan run used a process-only 1 GB limit; final `composer test` passed with default settings. Tests use SQLite in memory, fake storage, and an offline real-S3-adapter signing check with dummy credentials.
- Live Supabase Storage smoke skipped: local configuration appears development-oriented, but the bucket's development-only status was not independently confirmed. No Supabase PostgreSQL or Storage writes were performed. No migration, dependency, filesystem configuration, or machine-specific setting changed.
- Only Phase 3C, Product image upload, and Optimized image variants were checked. Image fallback, frontend lazy loading/performance, and Phase 3D–3F remain incomplete.

---

Phase 3D implementation and verification (2026-09-18):

- Added Products, Categories, and Modifiers management pages and permission-protected routes, linked from the management workspace. Existing catalog actions remain authoritative; no schema or dependency changes.
- Products support create/edit, default decimal price, category, active/inactive status, and atomic modifier-group assignment. Search, category/status filters, and 24-item pagination bound the product listing; relationships are eager-loaded.
- Branch cards distinguish overrides from inherited default prices. Per-branch forms save price/availability, accept zero prices, restore default pricing, and preserve inventory settings and other branches. Product/category inactivity remains authoritative.
- Image upload, replacement, and removal use the existing private-storage pipeline. Cards receive only signed optimized variant URLs, reserve image space, lazy-load, and provide a missing/broken-image fallback. Storage upload failures return a recoverable field error.
- Added 25 management endpoint tests covering allowed/denied access, validation, missing records, atomic rollback, pagination, bounded queries, price inheritance/isolation, category and modifier edits, and the image lifecycle. Final combined management, catalog business rules, branch catalog, product images, and workspace-routing verification passed: 328 tests / 1696 assertions.
- Developer manual browser QA passed, as confirmed by the developer. The real management cards and image dialog use `ProductImage`, which renders the `No image available` placeholder for missing or failed images; the management image fallback is complete.
- Final full verification passed: `php artisan test --compact` — 636 tests / 3081 assertions; `vendor/bin/pint --format agent`; `vendor/bin/phpstan analyse --no-progress` with the default memory limit; `npm run check:frontend`; `npm run types:check`; and `npm run build`. The build retains its non-blocking optional `fontaine` warning and plugin timing diagnostics. No regression or dependency change.
- Scope review confirmed product/category/modifier management, product modifier assignment, image upload/replace/remove UI, branch overrides, search/filter/pagination, Owner/Super Admin access through `products.manage`, and workspace navigation only. No cashier catalog, cart/order/payment logic, inventory quantities, Customer QR ordering, destructive Product/Category deletion, or unrelated UI redesign was added.
- Phase 3E and Phase 3F remain unchecked. The overall lazy-loading/image-performance checklist remains unchecked pending the cashier catalog in Phase 3E, despite management cards already using optimized variants and lazy loading. Automated tests use isolated SQLite and fake storage; no live database or storage writes were performed by this finalization.

---

Phase 3E implementation and final verification (2026-09-18):

- Replaced the Cashier Browse placeholder in the existing workspace with the real active branch catalog. Browse is accessible with a CLOSED or OPEN Store Session; existing Open Store behavior remains intact. No cart, order, payment, inventory balance, Customer QR ordering, or Kitchen integration was added.
- `BranchCatalog::browse()` bulk-loads active Categories containing active Products, with only the selected branch's overrides and an active-assigned-modifier existence summary. Categories sort by sort order/name; products sort by name. The catalog read uses at most three queries at both 1 and 30 products, without calling the per-product resolvers.
- The server returns exact decimal effective prices (non-null override, including zero, otherwise default), branch availability independent of Store Session state, and a lean explicit projection. Branch-unavailable Products stay visible; inactive Products/Categories are excluded. Existing authentication, active-account, `pos.access`, cashier-role, and branch-context checks protect the workspace; unavailable branches receive an empty catalog.
- Added local name search, category buttons, empty/no-match states, availability labels, and an active modifier-group summary. Reused `ProductImage` for signed five-minute `card.webp` URLs, lazy loading, reserved aspect ratio/dimensions, and missing/broken-image fallback. No raw image path, source/detail URL, inventory configuration, or management fields are included in the catalog projection.
- Final verification rerun after UI corrections passed: focused `CashierCatalogTest` 26 tests / 220 assertions; full `php artisan test --compact` 673 tests / 3469 assertions; Pint; PHPStan with a process-only `--memory-limit=1G`; `npm run check:frontend`; `npm run types:check`; and `npm run build`. The earlier combined catalog/Cashier Store run passed 418 tests / 2324 assertions. No regression, dependency change, or machine configuration change.
- UI rules re-check passed for the Phase 3E Browse changes after comparison with restored `08-ui-rules.md`, `09-ui-registry.md`, the decoded `design/pos.html` reference, existing Cashier components, and `14-coding-standards.md`. Corrections make category buttons grow/wrap long labels, improve fallback text contrast/alignment, use a compact 15px Browse heading and 20px section gaps, and explicitly label READ-ONLY / STORE CLOSED or STORE OPEN. Search remains 48px high and category/reset buttons at least 44px; responsive cards retain readable labels, 3:2 image space, explicit dimensions, and no added animation or component library.
- Developer manual QA and responsive QA are accepted as PASS from the finalization request, covering CLOSED/OPEN Browse, MAIN/QAVE isolation, filters, images/fallback, and mobile/tablet/desktop behavior. Source-level responsive review also checked the 360/390/430px breakpoints and wrapping. A fresh automated visual pass after the small corrections could not run: browser tooling still reports `Unable to load browser request-header policy`. No automated visual pass is claimed.
- Pre-existing shell exception: the current workspace uses a top header and Instrument Sans rather than the frozen dark sidebar/Poppins direction. This is recorded explicitly, not claimed as full-workspace visual compliance; the requested Phase 3E review does not redesign the shared workspace shell.
- Restored `08-ui-rules.md` is the frozen Batch 3 file referenced by the context index and coding standards, was absent at HEAD, and is included unchanged. Its intentional Markdown hard break on line 3 produces a staged whitespace warning; other changed files pass `git diff --check`. The build retains its non-blocking optional `fontaine` warning and plugin timing diagnostics. Tests used isolated SQLite and fake storage; no live database/storage writes were performed.
- Phase 3E and Lazy-loading / image performance are complete: the grid uses only optimized signed `card.webp` URLs, lazy loading, stable image dimensions, and missing/broken-image fallback. No ordering mutations, cart/quantity/order-type controls, payments, inventory quantities/deduction, Kitchen workflow, Customer QR ordering, or Phase 3F work was added. Phase 3F remains unchecked and Phase 3 is not marked complete.

---

Phase 3F final verification (2026-09-18):

- Pre-flight passed on `feature/catalog-product-images` at `ae0860b6df0152c6b01033c2b70802203109a496` with a clean working tree. Freshly fetched `origin/dev` matched local `dev`; the branch was zero behind and six commits ahead. Restored `08-ui-rules.md` is present and tracked. Reviewed all 57 files in the complete `git diff dev...HEAD` against the frozen context, including Phase 3A–3E and the decoded Owner/POS design references. No security, data-integrity, or functional production defect requiring a fix was found; production code, migrations, dependencies, and configuration remain unchanged.
- Authorization passed: management routes/actions require active authentication and `products.manage`; Owner/Super Admin succeed, while Cashier, Kitchen Staff, Cashier + Kitchen, inactive users, revoked-permission users, and guests are denied. Added the missing Kitchen/combined-role and inactive/revoked Super Admin direct-route cases. Cashier Browse requires a cashier-capable role, `pos.access`, and valid assigned branch context; forged/revoked scope and Kitchen-only access are rejected server-side.
- Branch isolation and exact money passed: MAIN changes do not affect QAVE with existing or absent overrides, including tracking/threshold settings. Non-null overrides, including `0.00`, take precedence over the default; inactive Products/Categories remain unavailable regardless of branch enablement or Store Session state. All authoritative prices use `decimal:2` strings backed by `numeric(14,2)`; malformed, negative, excess-precision, overflow, and float inputs are rejected. Frontend money stays string-valued through submission; numeric conversion is display-only. Uniqueness, restrictive relationships, modifier bounds, retained inactive records, image-path preservation, and empty modifier sync passed. Added direct DELETE regressions proving Products/Categories cannot be destroyed through management routes.
- Image pipeline/failure coverage passed: JPEG/PNG/WebP, inclusive 8 MB/6000-pixel limits, GD decoding and memory checks, rejection of unsafe/corrupt uploads, aspect ratio/transparency, and no upscaling. Card/detail bounds remain 480/1200 pixels. Random asset UUID keys exclude client filenames; the database retains only the detail object key. Existing fault injection verifies old-image preservation for processing, first/later writes, existence checks, DB failures, and outer rollback; successful replacement/removal cleans afterward, and cleanup failures preserve committed state with sanitized logs. No duplicate fault-injection tests were needed.
- Private-data contracts passed. Strengthened strict Inertia product projections for management and a populated Cashier product with image, zero override, inventory configuration, and modifiers. Cashier receives only operational fields and an active-assigned-group boolean; groups/options, raw paths, source/detail URLs, default/override internals, and inventory/admin settings are absent. Management exposes its intended editable fields and card URL, without source data or storage secrets. S3 adapter writes default to private; signing uses five-minute variant URLs without a storage request. Live bucket policy was not independently inspected.
- CLOSED and OPEN Browse GET regressions detect no database writes and preserve Store Sessions/catalog configuration. Open Store remains the separate intentional mutation. Routes and UI contain no cart, quantity/order-type selection, order, payment, inventory quantity/deduction, Kitchen, or Customer QR ordering implementation.
- Performance passed: expanded the existing query regression to 1, 30, and 100 Products, each with a category, image key, two branch overrides, and an assigned active modifier group. Every fixture stays at most three catalog queries, signs one `card.webp` URL per returned Product, and ignores the other branch's disablement. Management's query count remains constant as Products/branches grow, with eager relationships and 24-item pagination. Both grids use optimized `card.webp`, lazy loading, explicit dimensions/reserved 3:2 space, and missing/broken-image fallback; no source/detail image download is required by a grid. This is query/asset-contract evidence, not a measured live-network latency or CDN/cache claim.
- Read-only local PostgreSQL 18.4 metadata verification at `127.0.0.1:5432` confirmed all three `numeric(14,2)` columns, two mapping uniqueness constraints, seven CHECK constraints, six `ON DELETE RESTRICT` foreign keys, and expected lookup indexes. Isolated SQLite in-memory `migrate:fresh --database=sqlite --no-interaction` passed with process-only `DB_URL=null` explicitly clearing the URL and `DB_DATABASE=:memory:`. The normal developer database was not migrated/reset; Supabase was untouched.
- Final targeted catalog/image/management/Cashier/Store tests: 480 passed / 2758 assertions. Full `php artisan test --compact`: 680 passed / 3616 assertions, up from 673 / 3469. Seven additional dataset cases cover missing endpoint/retention/100-product checks; existing exposure/performance tests were strengthened. Pint, PHPStan (`--memory-limit=1G`, process-only), `npm run check:frontend` (74 files), `npm run types:check`, and `npm run build` passed. Complete diff review and secret-pattern scan found no environment files, credentials, real signed URLs, machine paths, temporary images, dumps, or dependency additions.
- Existing Phase 3D/3E developer manual and responsive QA remains accepted; no user-facing behavior changed and no browser automation pass is claimed. Source review confirms functional controls, validation/loading states, scrollable dialogs, readable availability/prices, and explicit CLOSED/OPEN read-only indicators. Non-blocking UI polish remains: top-header/Instrument Sans versus frozen sidebar/Poppins, larger management headings/card density, and management pagination/shared dialog-close targets below the 44px direction. These were not cosmetically redesigned. Image optimization remains synchronous on management upload; live storage delivery, CDN/cache behavior, and automatic renewal of five-minute image URLs remain unverified/unimplemented. The optional `fontaine` warning and plugin timing diagnostics remain non-blocking.
- All three frozen Phase 3 exit criteria are satisfied within the accepted manual QA and stated performance evidence: server-enforced branch price/availability; resilient missing/broken-image Browse; and bounded queries with optimized, lazy-loaded card images. **Phase 3 is complete.** Phase 4 and later checkboxes remain untouched.

---

## Phase 4 — Inventory Foundation

- [x] Phase 4A — Inventory foundation + core mutation rules
- [x] Phase 4B — Inventory operations UI + stock state integration
- [x] Phase 4C — Concurrency / security / final verification

- [x] Branch inventory balance
- [x] Inventory movement ledger
- [x] Low-stock state
- [x] Out-of-stock state
- [x] Manual adjustment
- [x] Negative stock protection
- [x] Concurrency-safe stock updates
- [x] Inventory branch-isolation tests

Phase 4A verification (2026-09-19):

- Added UUID `BranchInventory` and `InventoryMovement` models/factories and an additive migration. Quantities and versions use signed BIGINT whole units, with zero balance/version defaults, unique branch/product balances, non-negative balance/version CHECKs, nonzero movement CHECK, all eight frozen movement types, and restrictive existing foreign keys. Future reference UUIDs are nullable/indexed without premature foreign keys. `branch_products.low_stock_threshold` remains the sole threshold configuration; inventory holds stock state only.
- `ApplyInventoryMovement` reloads persisted inputs, locks branch/product tracking configuration, initializes missing balances with `insertOrIgnore` plus the unique pair, locks the balance `FOR UPDATE`, rejects insufficient stock/zero deltas/integer overflow, increments version once, and appends the ledger in one transaction. Failed writes and outer workflow rollback preserve both balance and history. This internal primitive has no HTTP endpoint; later callers must authorize their own workflow.
- Phase 4A tests: 47 passed / 200 assertions, including schema constraints, enum/relationships, first-row initialization, MAIN/QAVE and product isolation, stale tracking configuration, retained ledger history, negative/last-unit stock, rollback fault injection, future references, and integer limits. Relevant Phase 3 catalog tests: 395 passed / 2265 assertions. Full `php artisan test --compact`: 727 passed / 3816 assertions. Pint and PHPStan passed; PHPStan used process-only `--memory-limit=1G`.
- Local PostgreSQL at `127.0.0.1:5432` accepted only the additive inventory migration. Read-only metadata verified UUID/BIGINT types/defaults, unique branch/product constraint, four CHECK constraints, five `ON DELETE RESTRICT` foreign keys, and all expected indexes. Isolated SQLite in-memory fresh migration smoke passed with process-only `DB_URL=null` explicitly clearing the URL. The normal local database was not reset; Supabase was untouched.
- No frontend, routes/controllers, manual adjustment flow, stock-state integration, orders/payments, purchases, or transfers were added. Low/out-of-stock state, manual adjustment, final concurrency, and inventory branch-isolation completion remain unchecked. Independent-process PostgreSQL race verification remains Phase 4C; the sequential/transactional tests do not claim that verification. Phase 4B was not started.

---

Phase 4B implementation and verification (2026-09-19):

- Added a read-only `InventoryState` resolver: untracked balances are non-authoritative with null on-hand; missing tracked balances read as zero without creating rows; zero/below is out of stock; positive stock at/below the branch-product threshold is low stock. Threshold configuration remains solely in `branch_products`.
- Added `AdjustInventory`, authorized by the existing `inventory.manage` permission with persisted active-user/permission checks, and protected list/history/adjustment endpoints. Signed nonzero integer deltas and a required reason of at most 1000 characters delegate to `ApplyInventoryMovement`; actor, reason, and manual-adjustment type are appended atomically. Overdraw and unauthorized requests leave balances/history unchanged.
- Inventory management provides an explicit branch selector (including retained branches), search/status filters, 24-product pagination, optimized lazy card images/fallback, adjustment preview/validation, and on-demand history with 30-movement pagination, newest first, readable movement labels, actor, and reason. Current business-wide branch context supplies the default. Product management retains inventory configuration ownership.
- Cashier Browse receives only `stock_status` in addition to its existing safe projection. Server-side operational availability combines catalog rules with positive stock for tracked products. Low stock stays available; out-of-stock products remain visible with an explicit label. CLOSED/OPEN Browse remains read-only, and Cashier receives no inventory-management rights or exact balance, threshold, version, or history.
- Final targeted Phase 4A + 4B and Cashier/catalog regression run: 168 passed / 1274 assertions. Catalog queries remain at most four at 1, 30, and 100 products; management query counts remain constant with pagination and no per-card history query. Image URLs are generated only for the current page. Full `php artisan test --compact -d memory_limit=1G`: 804 passed / 4609 assertions. The first default-memory run passed 797/798 before an existing image-storage failure test reached the 128 MB process memory guard; the process-only increase resolved it, without changing application/test logic or machine configuration.
- Pint (`--dirty --format agent`), PHPStan (process-only `--memory-limit=1G`), frontend lint, TypeScript, production build, and whitespace checks passed. The existing optional `fontaine` warning and build plugin timing diagnostics remain non-blocking. No migration, dependency, or Supabase change.
- Live local Chrome QA passed Owner inventory/search/filter/branch flows, +10 persistence after refresh, -3 deduction, overdraw rejection, ledger ordering/user/reason, low/out-of-stock states, and MAIN/QAVE independence. Cashier checks passed low-stock availability, visible OUT OF STOCK, unaffected untracked products, OPEN MAIN/CLOSED QAVE Browse, branch switching, and direct inventory access returning 403. Visual checks passed at approximately 390px, tablet, and desktop without horizontal overflow. Intermittent browser-control failures were recovered with a fresh tab; no failed interaction was counted as a pass.
- Local QA cleanup restored Rice in MAIN to its original untracked behavior with no threshold and zero stock. Four clearly labeled QA movements remain in the append-only ledger; QAVE configuration, balances, and history were untouched.
- Phase 4B is complete. Phase 4C, final concurrency-safe-stock and inventory branch-isolation completion boxes remain unchecked for independent PostgreSQL race/security verification. Phase 4 is not complete; no order/payment/edit/void/purchase/transfer inventory workflow or Phase 5 work was started.

Phase 4C final verification (2026-09-19):

- Pre-flight confirmed clean `feature/inventory-foundation` at `90bd60702cb6eb670a47d64e9f8acfe12e3c9374`, containing Phase 4B and two commits ahead of both `dev` and freshly fetched `origin/dev`. Reviewed the full Phase 4 diff, mutation paths, authorization, query scope, UI source, and secret/artifact patterns. No production defect or production-code change; added the opt-in PostgreSQL harness and three direct ledger-mutation regression cases only.
- `php tests/verify-inventory-postgres.php` passed on local PostgreSQL 18.4 using an isolated random schema and explicitly cleared `DB_URL`. Two independent application/database processes were observed in PostgreSQL lock waits before release: one at the stock row and one at its branch-product configuration lock. Starting at stock 1/version 1, exactly one deduction committed and one returned insufficient stock; final stock 0/version 2, one new deduction, two total movements including initialization, and the original movement unchanged. Every worker proved a usable connection, Laravel transaction level zero, and no PDO transaction.
- Concurrent first-balance creation started with no balance and two overlapping configuration-lock requests. Both +5/+3 actions committed through the existing configuration lock, `insertOrIgnore`, unique pair, and balance lock: exactly one balance, stock 8/version 2, two movements, no unique-constraint leak. This verifies the real serialized action flow, not simultaneous insert execution after bypassing its configuration lock.
- Local PostgreSQL branch isolation passed: MAIN 10/QAVE 4 became MAIN 7/QAVE 4 after MAIN -3, with versions 2/1 and movement counts 2/1. A further QAVE +3 committed while an independent MAIN -1 process remained blocked on MAIN stock; after release the final MAIN/QAVE balances were 6/7, versions 3/2, and movement counts 3/2. Ledger sums and actor/reason/timestamp traceability matched every balance.
- Existing `ApplyInventoryMovementTest`/`InventoryOperationsTest` coverage reverified stock 2 rejecting -3 with unchanged balance/version/history, ledger-insertion failure rollback for new/existing balances, balance-write failure, and outer-transaction rollback. No duplicate fault tests were added. Source/route review found no ledger update/delete action or UI control; new PUT/PATCH/DELETE requests reject collection/item history and adjustment replacement while preserving the original ledger/balance.
- Authorization passed on inventory GET, history GET, and adjustment POST: active Owner/Super Admin allowed; Cashier, Kitchen, combined role, inactive user, revoked permission, and guest denied. Manual adjustments preserve authenticated actor, reason, branch, product, signed delta, and prior history. `CashierInventoryTest`, `CashierCatalogTest`, and `InventoryStateTest` passed branch switching, forged/stale-state protection, exact safe projection, untracked/missing/zero/threshold boundaries, and catalog-plus-stock availability. Low stock remains available; exact balances/thresholds/versions/history stay private; CLOSED and OPEN Browse remain read-only.
- Query regressions passed at 1/30/100 products with at most four Cashier catalog queries. Management remains bounded with eager-loaded category/configuration/balance, 24-product pages, 30-movement history pages, no per-card history queries, and card URLs generated only for returned products. UI source review confirms functional controls, explicit tracked/low/out states, adjustment submission/error protection, and responsive layouts. Accepted Phase 4B browser QA stands; no user-facing behavior changed or repeat browser pass is claimed.
- Read-only normal-local-PostgreSQL metadata confirmed UUID/BIGINT types, zero balance/version defaults, unique branch/product balance, four CHECK constraints, five restrictive foreign keys, and expected indexes. Fresh isolated PostgreSQL migrations and SQLite in-memory `migrate:fresh --database=sqlite --no-interaction` passed with `DB_URL=null`. Harness cleanup confirmed every created schema was removed, and a separate metadata query found no remaining `phase4c_*` schemas. Normal developer data was not reset or mutated; Supabase was untouched.
- Final targeted Phase 4/Cashier/catalog tests: **171 passed / 1289 assertions**. Full `php -d memory_limit=1G artisan test --compact -d memory_limit=1G`: **807 passed / 4624 assertions** (baseline 804/4609). The initially requested command with `-d` only before Artisan hit the already documented image-processing memory guard (806/807 passed); Collision launches a separate Pest process without inheriting that CLI setting. Passing `-d memory_limit=1G` to Pest resolved it without code/configuration changes. Pint, PHPStan `--memory-limit=1G`, frontend lint (79 files), TypeScript, production build, and whitespace/secret checks passed. Existing optional `fontaine` and plugin-timing warnings remain non-blocking.
- All seven Phase 4 exit criteria are verified by the evidence above: branch independence; traceable movement per mutation; safe concurrent last-unit deduction; negative-stock protection; authorized/traceable manual adjustment; server-derived low/out-of-stock state; and Cashier availability respecting tracked stock. **Phase 4 is COMPLETE.** No Phase 5 or later workflow/checklist was started. No Phase 4 blocker remains; verification is local, with accepted prior browser QA and no claim of hosted-environment or live-network performance testing.

---

## Phase 5 — Core POS Order Flow

**Phase 5 is COMPLETE.** Final independent acceptance passed on 2026-09-19; the final acceptance record below supersedes all earlier pending-acceptance and remaining-QA statements in this phase. User manual QA passed. Phase 6 and Phase 7 have not started.

- [x] Dine In / Take Out
- [x] Product browser
- [x] Search / categories
- [x] Product customization
- [x] Modifier handling
- [x] Cart
- [x] Notes
- [x] Optional branch-valid table handling for Dine In and Take Out
- [x] Order Information
- [x] Order numbering
- [x] Server-side total calculation
- [x] Historical order snapshots

Phase 5 implementation verification (2026-09-19):

- These checks record implemented behavior and passing automated verification. **Final Phase 5 acceptance remains pending the separate final QA/audit.** Phase 6 and later checklists are unchanged; no PR is opened by this implementation.
- Added the frozen UUID `branch_tables`, `orders`, `order_items`, and `order_item_modifiers` foundation, typed enums/models/factories, exact decimal casts, branch/order uniqueness, lookup indexes, nonnegative money/positive quantity/version constraints, restrictive historical-parent foreign keys, and nullable catalog references that retain snapshots after catalog deletion. The guarded, idempotent local/testing seeder adds MAIN/QAVE tables without seeding orders; its production guard remains covered.
- `CreatePosDraftOrder` reloads and authorizes the persisted cashier/combined role, active assignment, permission, and active branch; locks the branch and checks/locks an OPEN Store Session inside the transaction. Current validation, which supersedes the earlier Phase 5 requirement described here, accepts an optional customer/order label and optional active current-branch table for either order type. It also validates active products/categories/branch availability, assigned active modifier groups/options, duplicate selections, single/min/max selection rules, notes, and strict positive integer quantities. Failed validation or insertion rolls back all draft rows.
- Server-authoritative pricing uses current branch overrides and integer cents with numeric(14,2) overflow guards; line totals include modifier deltas per product quantity. Historical product/group/option names, base prices, deltas, quantities, notes, and totals are persisted. Order numbers use `YYMMDD-` plus eight random uppercase characters, protected by branch-scoped uniqueness and at most five retries of only the expected order-number conflict; nested transactions provide a PostgreSQL savepoint. Collision/exhaustion tests pass on SQLite; a PostgreSQL order-collision concurrency audit remains for final QA.
- Stock is freshly read and quantities are aggregated across configurations of the same product. Missing/zero/insufficient tracked stock rejects drafts; untracked products stay independent of balances. Drafts remain `draft` / `unpaid` / `not_sent`, with null payment term, Store Session ownership, and commitment timestamp. No deduction, reservation, inventory movement, payment, Kitchen ticket, broadcast, or operational session mutation is added. Future commitment must revalidate stock.
- Reviewed the decoded standalone `context/design/pos.html` template and aligned the Phase 5 UI with Poppins, compact product cards, near-black headers/actions, red prices/quantities, Dine In/Take Out colors, orange notes, desktop side cart, mobile floating cart, and full-height mobile customization. Reused the existing catalog search/categories/images, dialogs, and Wayfinder/Inertia forms. Cart add/edit/remove, empty state, order information, processing protection, preserved-cart validation errors, and persisted summary are implemented; payment controls are visibly disabled.
- Focused Phase 5 tests: **98 passed / 410 assertions**. Store/catalog/inventory regression selection: **173 passed / 1168 assertions**. Full `php -d memory_limit=1G artisan test --compact -d memory_limit=1G`: **905 passed / 5042 assertions**, up from 807/4624. Coverage includes authorization, branch isolation, stale state, exact totals/overflow, historical snapshots, schema constraints, bounded collisions, transactional rollback, and repeated drafts without stock reservation. At 1/30/100 products, customization catalog reads stay at most six queries and draft SELECTs at most eighteen; the existing non-customization catalog stays at most four queries.
- Pint (`--dirty --format agent`), PHPStan (`--memory-limit=1G`), frontend lint (85 files), TypeScript, and production build passed. Existing optional fontaine and build-plugin timing warnings remain non-blocking. No dependency or Supabase change.
- Local PostgreSQL 18.4 additive migration passed. Metadata confirmed all five numeric(14,2) columns, fourteen CHECK constraints, eight restrictive and two null-on-delete foreign keys, unique keys, and expected indexes. SQLite in-memory fresh migrations passed with `DB_URL=null`. The existing isolated PostgreSQL harness migrated every table, passed its inventory concurrency regressions, removed its random schema, and left normal local data intact; no normal-database reset occurred.
- Historical Phase 5 Chrome QA covered CLOSED QAVE read-only Browse/no order start; OPEN MAIN order-type requirement, search/category filtering, quantity/notes, add/edit/remove/empty cart, MAIN-only active table choices, Dine In Table 3 and Take Out drafts, snapshot-based summaries, and disabled payment controls. The Take Out label requirement tested at that time was superseded in Phase 6: labels are now optional for both order types. Read-only database checks confirmed draft defaults and unchanged Rice inventory quantity/version/timestamp/movement history. POS visual/overflow checks passed at 360px, 390px, 430px, 820px tablet, and 1440px desktop; the temporary viewport override was reset.
- Remaining manual QA: required/multiple modifier interactions and modifier summary rendering, unavailable/out-of-stock product clicks, stale stock/store changes while a cart is open, and broad populated-catalog/long-content visual checks. The local catalog has only one untracked product without modifiers; automated server cases pass, but these live scenarios are not claimed. Browser URL policy blocked directly rendering the standalone local `pos.html`; source-based reference comparison and live application visual checks were completed, not a rendered side-by-side comparison. Final QA must independently review these gaps and acceptance.

Phase 5 standalone parity correction (2026-09-19):

- Supersedes the earlier Phase 5 visual-parity claim above. The mismatch came from retaining the generic workspace header, wrapped Menu/catalog card, detached cart and successful-create redirect to `workspaces/order-summary`; matching individual colors did not reproduce the standalone composition or flow.
- Extracted the `<script type="__bundler/template">` payload with a DOTALL regular expression and decoded its JSON string with Python `json.loads`, including escaped markup. Wrote only temporary decoded-reference and element-mapping files outside the repository. Inspected the actual markup and responsive style computations (94px rail, 60/66px top bar, 336/382px cart, 768/1024px breakpoints, 420px gate and 860px product dialog). The standalone artifact and bundler runtime were not modified or copied into application code.
- Rebuilt the Cashier shell with the real logo, near-black left rail, selected POS navigation, compact top bar, real branch/profile data, compact mobile branch switcher and floating mobile navigation. New Order is in the top bar and immediately opens the stacked Dine in / Take out gate. Unimplemented Dashboard, QR Orders and Transaction History remain explicitly disabled; no fake counts, Kitchen state or notifications.
- POS is a full-height integrated product/cart split with independently scrolling product and cart bodies. Category controls scroll horizontally, show real counts and sit beside (or above on narrow screens) compact search with clear/reset. Cards use stable 3:2 media, category icon fallbacks, real branch prices, unavailable/low-stock state and in-cart counts. No menu images were processed. Catalog descriptions are now exposed for customization.
- Cart uses Current order context, red quantity/amounts, modifier details, orange notes, edit/remove, empty state, Dine In / Take Out segments, item/subtotal/total hierarchy and the reference Save-left / Pay-right placement. Superseded by the payment follow-up below: switching an editable cart preserves the optional table, and both order types accept an optional label. New Order with an existing cart opens the type gate first, then confirms clearing items before applying the chosen type.
- Customization now follows the reference Back/title/Close header, media/monogram, red price, availability/description, quantity-first controls, required group badges, single/multiple options, deltas, special instructions and fixed Add/Update amount footer with Remove when editing. It is fullscreen on mobile and two columns on wide screens.
- Order Information is an in-place modal (mobile bottom sheet), with a serving-type banner, customer label, current-branch table choices, summary hint, Proceed and Cancel. POST uses the unchanged `CreatePosDraftOrder`, flashes only serialized persisted summary fields and redirects to `workspaces.cashier`. The same modal displays the returned order number/items/modifiers/totals; the cart then uses persisted snapshot rows rather than matching them to client rows by index. Initial flash data also initializes the modal after an asset-version remount. The protected direct show route remains recovery-only; normal flow does not navigate to a summary page.
- Intentional Phase 5 differences: an extra Order information control creates only a draft while Save/Pay remain disabled with an explanation. Persisted snapshots are read-only (new work starts through New Order); no draft-update endpoint or operational commitment was added. The standalone's short mock numbers, prices, table data, role switcher, payment/Kitchen/notification states and images are not substituted for real application data. Superseded by the payment follow-up below: tables are optional for both order types and validated when selected. Payment remains prepared for activation within this modal workflow in Phase 6, with no payment route/page or fake commit introduced.
- Historical Phase 5 Chrome comparison used the actual unmodified standalone served temporarily on loopback, not only its source. It covered the rail/top bar, product split/cards, gate, customization and Order Information at 360px, 390px, 430px, 820px tablet and 1440px desktop without document overflow. The Take Out label requirement checked then was superseded in Phase 6; labels are now optional for both order types. MAIN was restored and viewport overrides were removed.
- Local QA drafts retained: `260919-KL8CXJC2` (Take Out, PHP 20.00) and `260919-IOIZ507L` (Dine In Table 2, PHP 40.00), clearly labeled as Phase 5 parity QA. The first browser attempt exposed an asset-version refresh edge case, which was corrected; the subsequent successful save showed the persisted summary modal at the unchanged POS URL. Read-only database verification confirmed Rice stock remained 0, version 4, timestamp `2026-09-19 08:06:16`, with draft/unpaid/not_sent states and no stock deduction.
- Automated verification: focused POS/order foundation plus Cashier store/catalog/inventory regression **171 passed / 1180 assertions**; full `php -d memory_limit=1G artisan test --compact -d memory_limit=1G` **906 passed / 5107 assertions**. Tests now assert redirect to POS, persisted Inertia flash values and one-time consumption, retained direct-read security/snapshots, and catalog description fields. Existing missing-type, CLOSED-store, invalid-table/Take-Out-label, stock, modifier, exact-money, branch-isolation and absent-payment/Kitchen regressions still pass. Pint, PHPStan with 1G, frontend lint, TypeScript and production build pass; existing optional fontaine/build timing notices remain.
- Remaining separate final QA: live modifier selection and unavailable/stale catalog/store cases (the local catalog has no assigned active modifier groups), long-content stress, PostgreSQL order-number concurrency audit, and independent final acceptance. This correction is close structural/interaction parity, not a claim of pixel-perfect 1:1. **Phase 5 FINAL acceptance remains pending. Phase 6 has not started.**

---

### Phase 5 POS UX and sizing correction (2026-09-19)

This evidence supersedes the earlier New Order / separate Order Information cart step and disabled entry-button exceptions. **Phase 5 FINAL ACCEPTANCE remains pending user manual QA and the separate final QA. Phase 6 and Phase 7 have not started.**

- Re-extracted the standalone's `__bundler/template` JSON into a temporary file outside the repository before editing. Audited its markup and responsive style calculations against React. Recorded the permanent standalone source-of-truth and phase-boundary rule through Boost in `.ai/rules/js.md`. No standalone artifact, dependency, backend action, route, schema, or financial workflow was changed.
- Automatic Select order type gate appears on operational POS entry with no local context. Products remain disabled until an explicit choice. Clear/cancel returns to the gate. User/branch-keyed Inertia remembered state and preserved POS navigation retain existing carts; MAIN → CLOSED QAVE → MAIN also retained the local MAIN cart during browser QA. The gate contains neutral em-dash Kitchen status placeholders, with no Kitchen query or invented counts.
- Removed the top New Order button and the extra Order Information cart button/helper. Added a compact real StoreSession status pill, empty Notifications popover with no badge, and standalone-style profile control using the real user's name/initials/role and existing logout route. No employee ID, Settings action, notification infrastructure, or fake records were introduced.
- Cart uses compact metadata, 42px media, red quantity/amount, configured product-name size tags, amber modifiers/notes, and working quantity/edit/remove controls. Superseded by the payment follow-up below: Dine/Take changes preserve the optional selected table. Changing type after saving a snapshot returns to the retained local cart; the existing persisted snapshot stays immutable and the next Proceed revalidates a new draft.
- Save · pay later opens Order information in place. Proceed calls only the existing Phase 5 draft endpoint, including optional active-branch table/label, availability, stock, modifier, exact-money and snapshot validation. The resulting modal explicitly disables Activate Pay Later. Pay now opens the standalone-shaped Payment modal with customer/table/summary on the left and Cash/Cashless/Split, editable preview values, keypad, exact amount, and reconciliation on the right. Confirm payment is disabled and has no submission handler. Neither entry navigates to a summary/payment page.
- Product descriptions use the real description or “No description available.” Payment previews use integer cents locally. Real server totals remain authoritative for persisted drafts.

Exact sizing audit (pixels unless stated):

| Element | Decoded standalone | Before correction | After correction |
| --- | --- | --- | --- |
| App / rail / top bar | 100dvh / 94 / 60 mobile, 66 tablet+ | Same | Retained |
| Toolbar padding / gap | 10 vertical, 12 horizontal / 10 | Same | Retained |
| Category height / font / horizontal padding | 46 / 13.5 / 14 | 44 / 12 / 12 | 46 / 13.5 / 14 |
| Search surface height / font | 46 / 16 | 46 / 16 | Retained; 44px clear target |
| Search width at 1024 / 1300+ | 210 / 270 | Mixed breakpoint units caused 210 at 1440 | 210 / 270 using consistently ordered px breakpoints |
| Product minimum width | Mobile two columns; 150 below 1024, 156 below 1400, 168 above | Intended same, mixed breakpoint units | Same values with consistent breakpoint ordering |
| Grid gap / card padding / name and price / media ratio | 8 mobile, 10 tablet+ / 10 / 14 / 3:2 | Same | Retained |
| Cart width | 336 below 1300, 382 above | Same | Retained and measured |
| Cart context heading | 26 for mock short order number | 26 for “New order” | 16 for compact real context; deliberate no-giant-heading override |
| Cart thumbnail / row padding / row gap | 42 square / 11 vertical, 13 horizontal / 9 | 42 / 12 each side / no explicit row gap | 42 / 11,13 / 9 |
| Quantity / name / modifiers / note / line amount | 13 / 14 / 11.5 / 11 / 14 | 14 / 14 / 11 / 11 / 14 | 13 / 14 / 11.5 / 11 / 14 |
| Quantity controls / value | Desktop 44 square, mobile 46; value 40/42 wide | Missing in cart | 46 square; value 42×46, all widths |
| Edit / remove | 44 desktop, 46 mobile | 44 | 46 |
| Dine/Take wrapper / segment height / font | 3 padding and gap / 42 / 13 | 4 padding, no gap / 44 / 12 | 3 / 42 / 13 |
| Count and subtotal / Total label / Total amount | 12.5 / 15 / 27 | 11 / 14 / 28 | 12.5 / 15 / 27; total remains red |
| Save / Pay height and font | 48 and 13.5 / 48 and 15.5 | 48 and 12 / 48 and 14; disabled | 48 and 13.5 / 48 and 15.5; open their modals |
| Notification button / radius | 44 square / 12 | Absent | 44 / 12 |
| Profile button / avatar / popover avatar | 44 high / 32 / 44 | Noninteractive 36px avatar | 44 / 32 / 44; dark circles, real initials |
| Product modal width, 768–899 / 900+ | 460 / 860 | 860 at both, potentially wider than viewport | 460 / 860, constrained to viewport minus 40; mobile fullscreen |
| Product name / price / description | 22 / 20 / 12.5, line-height 1.6 | 22 / 20 / optional 12, line-height 20px | 22 / 20 / 12.5, line-height 1.6, fallback included |
| Payment modal width, 768–899 / 900+ | 520 / 1020 | Absent | 520 / 1020, constrained to viewport minus 40; mobile fullscreen |
| Product/payment modal maximum height, tablet+ | min(92dvh,940px) | 92dvh | min(92dvh,940px) |

- Live Chrome QA covered 820×1180 and 1024×768 tablets, 390×844 and 430×932 mobile, and 1440×900 desktop. Verified compact two-column tablet-820 / three-column tablet-1024 / five-column desktop products, 336/382px carts, 46px cart controls, compact top bar, no horizontal document overflow, mobile floating cart/fullscreen sheets, product descriptions/fallbacks, gate/reset, quantity changes, editing/removal, type changes, profile/notification popovers, both modal entry flows, local Cashless/Split preview reconciliation and disabled commits. The tablet-820 product modal now measures 460px rather than the previous viewport-wide single-column dialog. Temporary viewport override was reset after QA.
- CLOSED QAVE shows Browse/Open Store, read-only products, real CLOSED pill, and no operational gate. MAIN restored afterward. The local catalog now contains 53 products, allowing populated-grid checks; unconfigured prices/images remain real stored data/fallbacks. No live modifier setup or inventory configuration was fabricated.
- Retained exactly one new QA draft from this correction: `260919-X0DKFUUF`, Dine In / Table 2, PHP 20.00, label `Phase 5 density QA - draft only`. Read-only verification found `draft / unpaid / not_sent`, null payment term and commit timestamp. Inventory remained quantity 0, version 4, updated `2026-09-19 08:06:16`; the four existing inventory movements were unchanged. No Payment or Kitchen implementation was added.
- Automated checks: focused POS/order foundation **100 passed / 512 assertions**; selected Store/Catalog/Inventory regressions **222 passed / 1671 assertions**; full `php -d memory_limit=1G artisan test --compact -d memory_limit=1G` **908 passed / 5155 assertions**. Added two meaningful description-present/null response-contract cases for real profile/store/table/catalog props and no order/inventory side effects. Existing security, validation, snapshots and no-operational-effects cases remain passing. Pint, PHPStan `--memory-limit=1G`, frontend lint, TypeScript, production build and diff whitespace checks passed. Existing optional fontaine/plugin-timing build notices remain.
- Intentional differences: real UUID-backed order context and server snapshots replace mock numbers; saved snapshots remain immutable; operational payment/Pay Later, Kitchen data, notification data, and future navigation stay unavailable. Touch controls use the mobile 46px sizes on tablet/desktop too; the cart context heading is intentionally compact and total stays red. No global zoom/transform sizing was introduced. Independent final QA still owns live modifier/stale-stock/store stress, long-content stress, PostgreSQL order-number concurrency and user acceptance.

---

### Phase 5 payment / Order Information follow-up (2026-09-19)

This evidence supersedes the prior mandatory Dine In / prohibited Take Out table rules and the 820px single-column payment layout. **Phase 5 FINAL ACCEPTANCE remains pending user manual QA. Phase 6 and Phase 7 have not started.**

- Branch table selection and the customer/order label are optional for both Dine In and Take Out. If selected, a table must be active and belong to the current branch. Request validation accepts nullable UUIDs; the draft action checks any provided table and persists it for either type. Null/omitted tables work even when a branch has no tables; direct action callers also normalize a blank selection to null.
- Both Order Information / Save Pay Later and Pay Now use the same optional active-branch table chips, selected highlight, toggle deselection and No table choice. Type switching retains the table, submission no longer discards Take Out tables, and Proceed is available without configured tables. Saved snapshots remain immutable. Pay Later activation stays disabled with a Phase 7 explanation.
- Pay Now mirrors the decoded standalone summary's bordered compact rows, quantity/product/detail/amount alignment, row padding and subtle separators. Quantity and amount remain red, product names black, modifier details muted and notes amber. Reviewed the actual rendered unmodified standalone on loopback as well as its decoded markup, including order context, banner, customer/tables, tabs, keypad, quick amounts and reconciliation. The standalone's single-column layout below 900px is deliberately overridden from 768px to satisfy the requested 820px two-panel layout.
- Exact / PHP50 / PHP100 / PHP500 / PHP1,000 share one compact wrapping row. Cash shortcuts always target Cash received; Split Exact subtracts Cashless and then selects cash for the keypad. Cashless-only hides these shortcuts. BigInt cents provide exact received/remaining/change previews. The redundant Payment preview heading is removed. Change uses a dark surface with a 10px uppercase label and 28px bold white value, including zero; Remaining is bold red for underpayment.
- Live Chrome app QA passed at 820x1180, 1024x768, 390x844 and 1440x900. At both tablet widths the normal right panel measured 581px client height / 581px scroll height with overflow visible and all controls inside the modal. The two-panel modal was about 634px tall including borders/header; at desktop it was capped at 1020px wide. At 390px, mobile scrolling exposed every control, cash shortcuts fit cleanly and the modal had equal client/scroll widths (388px inside its borders), with no horizontal overflow. Verified Cash/Cashless/Split, denominations, exact, keypad/delete, underpayment/zero change, prominent positive change and disabled confirmation.
- Live MAIN currently has three active tables; all three were shown for both types in both editable modals. Verified Table 3 survives Dine In to Take Out and can be deselected. A temporary isolated fixture using the actual React payment component supplied five tables, modifier/note rows and a PHP235 total without database writes. Tables 1-5 plus No table wrapped cleanly at 820px, and all five remained visible for Take Out. A 20-row stress case at 1024x768 measured left 653px visible / 1874px content and right 653px visible / 653px content: only the left scrolled. Temporary fixture/reference files were removed after QA.
- Exact PHP235 -> received PHP235 / remaining PHP0 / change PHP0; PHP500 -> change PHP265; PHP100 -> remaining PHP135 / change PHP0 all passed in the browser fixture and automated money tests. Live app Split PHP500 with PHP200 Cashless -> Exact Cash PHP300, and keypad adjustment to PHP300.05 -> PHP0.05 change passed. Fractional and large-value tests also passed without floating-point drift.
- Automated verification: focused POS/order foundation **110 passed / 565 assertions**; Store/Catalog/Inventory name-filtered regressions **596 passed / 3689 assertions**; full `php -d memory_limit=1G artisan test --compact -d memory_limit=1G` **918 passed / 5208 assertions**. Three Node exact-money tests passed (`node --test --experimental-strip-types tests/pos-money.test.ts`). Pint, PHPStan `--memory-limit=1G`, frontend lint, TypeScript, production build and diff whitespace checks passed. Existing optional fontaine/build timing notices and Node's experimental type-stripping notice are non-blocking.
- Backend authorization, active branch/OPEN Store Session checks, server prices, inventory availability, exact totals, immutable snapshots and rollback protections remain covered. No new live QA drafts were saved in this follow-up. Confirm Payment has no submit handler and remains disabled; no Payment rows, paid state, stock deductions, Kitchen tickets, payment broadcasts or operational Pay Later commits were introduced. No dependencies, schema, routes, Phase 6/7 checklist states or PR were added.

---

### Phase 5 final independent acceptance (2026-09-19)

- Reviewed the entire `origin/dev...HEAD` implementation, starting from `25225d7b05150ca310859db2ab426beea2bee501` on `feature/core-pos-order-flow`, with an initially clean tree and refreshed origin. The branch was ahead of, and not behind, `origin/dev`. Re-read the frozen business/security/data/UI/build/QA context and the decoded standalone POS markup. The standalone remains the visual/interaction authority; backend rules remain authoritative for integrity. User manual QA is accepted; no pixel-perfect claim or redesign is made.
- Found one production defect during isolated long-content browser QA: an unbroken saved customer/order label could overflow the Payment summary. Added only `wrap-anywhere` to that label. No backend production defect was found. Clarified the user-flow/UI documents: both order types allow no table, a selected table survives a type switch, and clicking it again clears it without a separate No table chip.
- Direct-request tests pass for active assigned Cashier and Cashier + Kitchen with an active branch and OPEN Store Session. Guest, inactive account, Kitchen-only, Owner/Super Admin without cashier access, unassigned/inactive assignments, inactive/temporarily closed branches, CLOSED/stale store state, revoked permissions and forged branch context are denied. Authorization is rechecked against persisted state inside the draft action.
- Optional-table and optional-label regressions pass for Dine In and Take Out: null/omitted/blank selections, active current-branch selection and persistence, and inactive/foreign-table rejection. Tests use isolated fixtures, independent of developer Table 4/Table 5 data. Browser interaction verified selection survives Dine In to Take Out, toggle deselection, and optional choices in both modal flows.
- Server validation rejects inactive products/categories, unavailable branch products, missing/zero/insufficient tracked balances, aggregated excess quantity, stale stock reductions, forged product IDs and invalid quantities. Untracked products, sufficient low stock and zero-price branch overrides pass. Drafts leave inventory quantity, version, timestamps and movements unchanged.
- Required/min/max/single/multiple modifier rules, inactive groups/options, wrong or unassigned groups/options, duplicates and forged client prices are covered. Browser selection of two PHP20 modifiers on a PHP95 product at quantity two produced PHP270 with labels and notes in the cart/payment summary. The PHP95 + PHP20, quantity-two backend case persists PHP230. Persisted summary tests retain original product/group/option names, prices/deltas, notes, line total, subtotal and total after catalog changes.
- Backend decimal-string/integer-cent calculations and overflow guards pass. Three retained Node money tests cover the requested PHP235 Exact/PHP500/PHP100 examples, PHP500 split with PHP200 cashless and PHP300 exact cash, fractions and large values. Browser PHP235 reconciliation produced received235/remaining0/change0, change265 for500, and remaining135/change0 for100. Cashless-only hides cash shortcuts; Confirm payment remains disabled.
- Added the opt-in `tests/verify-pos-postgres.php` harness using literal loopback PostgreSQL only, explicitly cleared DB_URL, random isolated schema, fresh migrations and committed test fixtures. Four independent worker connections were observed simultaneously waiting on the branch lock, then created 20 distinct persisted order numbers; every worker connection remained usable with no open transaction. Source uses random order numbers, never MAX + 1.
- The PostgreSQL harness forced a real branch/order unique violation (23505), then a successful second generator result through the existing savepoint. Unrelated unique (23505) and CHECK (23514) failures each propagated after exactly one generator call with no partial order and a reusable connection. All 21 successful drafts retained the Phase 5 state and unchanged inventory. The random schema was removed; a final read-only namespace query found no remaining `phase5_*` schemas.
- Query regression at 1/30/100 products and cart lines passes: plain catalog exactly four queries, customization catalog at most six, and draft reads at most eighteen SELECTs. Product/modifier/inventory loading remains bounded; no speculative optimization was needed.
- Local PostgreSQL read-only metadata and isolated fresh-schema assertions confirm UUID IDs, numeric(14,2) money, quantity/money/version CHECKs, branch/order uniqueness, lookup indexes, restrictive historical FKs, null-on-delete catalog FKs and version default1. Fresh PostgreSQL and SQLite in-memory migrations passed with DB_URL explicitly cleared. No normal-database reset, developer-data write or Supabase access occurred during final acceptance.
- Responsive browser fixtures rendered the actual production Cart, customization, Order Information and Payment components with 20 lines, 12 long modifiers per line, long product/group/option names, long notes and a 150-character unbroken label. All 25 mode/viewport combinations passed at 390x844, 430x932, 820x1180, 1024x768 and 1440x900 after the wrapping fix, with no horizontal overflow. Actions remain reachable and the cart/modal bodies scroll as needed. Normal tablet payment controls measured 581px visible/581px content. At 1024px under extreme summary content, left measured 653px visible/22414px content while right remained 653px/653px; Confirm payment stayed inside the modal. Temporary fixture/reference files were removed.
- Standalone regression review retains the 94px rail, 60/66px top bar, real STORE OPEN/profile/logout, notification and Kitchen placeholders, automatic type gate, compact catalog/cards, integrated cart, customization description/fallback, in-place Save/Pay modal sequence, black/red summary, cash denominations and prominent change. No separate summary/payment page enters the normal flow. User manual acceptance remains passed.
- Phase boundary is intact: drafts are `source=pos`, `commercial_status=draft`, `payment_status=unpaid`, `kitchen_status=not_sent`, with null payment term, commitment timestamp and Store Session ownership. No payment creation, paid transition, inventory deduction/reservation, Kitchen ticket, operational Pay Later activation or payment/Kitchen broadcast is implemented. Both final operational buttons remain disabled.
- Final checks passed: focused POS/order foundation **110 tests / 577 assertions**; Store/Cashier/Catalog/Inventory/Branch/LocalDevelopmentSeeder regression selection **734 tests / 4472 assertions**; exact requested full suite **918 tests / 5220 assertions**; Node exact-money **3 tests**; Pint `--dirty --format agent`; PHPStan `--memory-limit=1G` (zero errors); frontend lint (89 files); TypeScript; production build; and diff whitespace checks. Existing optional fontaine/build-plugin timing notices and Node experimental type-stripping notice are non-blocking. Harness-only assertion assumptions about PostgreSQL default formatting and Eloquent timestamp serialization were corrected and rerun successfully; no unresolved failure remains.
- Full-branch secret/artifact review found no credentials, environment files/backups, local absolute paths, screenshots, decoded standalone files, temporary frontend fixtures, debug logging, generated junk or dependency changes in the intended diff. The retained PostgreSQL harness is deliberate verification coverage, not production/mock seed data. **Phase 5 is COMPLETE and ready for PR review.** No PR was created; Phase 6/7 checklists remain untouched.

---

## Phase 6 — Pay Now

- [x] Cash
- [x] Cashless
- [x] Split
- [x] Payment modal
- [x] Amount received / change
- [x] Underpayment validation
- [x] Payment idempotency
- [x] Atomic payment transaction
- [x] Inventory deduction
- [x] Kitchen ticket creation
- [x] Payment success state
- [x] Receipt
- [x] Duplicate-submit tests

### Phase 6 implementation verification — 2026-09-19

**Implementation checks pass. User manual QA and the separate FINAL Phase 6 QA/AUDIT remain pending. This is not final Phase 6 acceptance.** This section supersedes earlier historical notes that Phase 6 has not started. Phase 7 remains untouched; no PR is created.

- Added UUID Payments and Kitchen tickets, exact `numeric(14,2)` money checks, deterministic unique payment leg keys, unique Kitchen order, indexes and restrictive historical FKs. Lean models/factories reuse the existing Kitchen status enum; Split persists cash + cashless, never a third method.
- `PayNowOrder` owns the outer transaction: serialize the root key, lock/re-authorize the branch, recover an authorized replay, lock the OPEN Store Session and existing draft or reuse Phase 5 draft creation, validate exact tender, insert Payment legs, revalidate current availability, aggregate/sort tracked products, apply existing Sale inventory movements, create the Kitchen ticket and transition the Order to active/paid/immediate/kitchen with committed timestamp and incremented version. Existing draft prices remain snapshots. No completed state, reservation subsystem or Pay Later operation was added.
- Stable UUID attempt plus immutable retry payload on ambiguous responses; replay checks branch/cashier/order/cart/tender and never repeats operational effects. PostgreSQL root advisory locking also prevents a cross-branch Cash/Cashless race from claiming separate legs under one root. Only the expected Payment unique-key exception is recovered after rollback; unrelated failures propagate.
- Cash 235/235 gives zero change; 235/500 gives 265 change; underpayment rejects. Cashless applies the total with null received/change. Split applies only the exact remaining Cash due, with excess tender returned as change. Legitimate zero-total Cash/Cashless operations retain one traceable zero Payment and normal operational effects; zero-valued Split legs reject.
- Direct route coverage verifies Cashier and Cashier + Kitchen authorization, current active branch assignment, OPEN session, revoked/inactive/management-only/guest denial, foreign branch/order/table rejection, forged monetary/state inputs and immutable snapshots. Receipt data is returned through the authorized payment response with `Cache-Control: no-store`; no public receipt endpoint or Receipt table exists.
- `order.committed` and `kitchen.ticket_created` use the frozen private branch channels and dispatch after commit. Event rollback/replay behavior and channel authorization pass; KDS realtime consumption remains Phase 8.
- Focused Pay Now tests: **81 passed / 657 assertions**. Full suite including Phase 5, Store Session, catalog, inventory, authorization, branch isolation and exact money: **999 passed / 5,877 assertions**. Frontend exact-money tests: **4 passed**. Pint, PHPStan (zero errors), frontend lint (90 files), TypeScript and production build pass. Build retains the existing optional fontaine notice; no dependencies changed.
- Normal local PostgreSQL received only the additive migration. Metadata checks verified UUIDs, `numeric(14,2)`, nullable money checks, method/status checks, unique constraints, indexes and restrictive FKs. Isolated PostgreSQL fresh migrations and SQLite `:memory:` migrations with `DB_URL=null` pass. Supabase was not accessed; normal local data was not reset.
- Real PostgreSQL independent-process harness proves overlapping workers for Cash duplicate, Split duplicate and last-unit races: one operational commit, exact Payment row count, stock/movement/ticket once, same duplicate result, and one insufficient-stock loser with no orphan draft. Cross-branch root reuse produces one winner and one conflict. A real Kitchen CHECK failure rolls back new draft, Payments, inventory and ticket. Worker connections remain usable at transaction level zero; temporary schemas are removed.
- Inventory test verifies tracked quantity 10 → 8 and version 3 → 4 across two lines of the same product, one Sale movement with delta -2, Order/actor association, and no movement for untracked products. Each paid Order creates exactly one initial `kitchen` ticket; replay creates none.
- Real browser QA used a clearly labelled isolated PostgreSQL fixture: exact Cash, Cash with change, disabled underpayment, Cashless Take Out, Split, success, receipt and New Order. A test-only middleware returned 503 after a successful Split commit: the cart stayed intact, fields locked, Retry sent the identical key/payload and recovered the original paid Order. Final fixture state: **5 paid Orders, 6 Payment rows, 5 Kitchen tickets, 5 Sale movements, stock 100 → 92**; the fixture schema was removed without changing normal inventory history.
- Responsive checks at **820, 1024, 390, 430 and 1440px** found no horizontal overflow. Normal tablet Payment right panel had equal client/scroll height, visible quick values, compact keypad, prominent Change and reachable Confirm; mobile uses vertical scrolling. Receipt wraps the real order number and displays persisted branch/date/cashier/items/tender.
- Decoded and served the unmodified `context/design/pos.html` and compared rendered Payment and paid-success states. Preserved the manually approved Phase 5 Payment dimensions and black/red summary; paid state follows the checkmark, red order number, black PAID pill, bordered summary rows and New order/View receipt controls. Real order numbers, persisted data and Split breakdown differ from the mock. This is close structural/flow parity, not a pixel-perfect 1:1 claim.
- Lightweight 80mm print CSS and Print receipt action are present. Browser print invocation was exercised, but native print-preview contents and physical printer output could not be inspected by the browser tool and remain manual QA. No PDF package, gateway, history workspace or KDS UI was added.

### Phase 6 manual-QA follow-up fixes — 2026-09-19

**Follow-up implementation verification passes. User manual QA and the separate FINAL Phase 6 QA/AUDIT remain pending. This is not final Phase 6 acceptance. Phase 7 remains untouched.**

- New POS order types now reserve a real persisted empty draft and a branch-serialized numeric operational number before cart interaction. Cart, Payment, paid-success and receipt retain that same short number; the immutable `BRANCH-YYMMDD-number` reference is secondary audit metadata. Per-branch counters use row locks, skip historical numeric collisions, allow abandoned-number gaps, and never rewrite legacy random order numbers or backfill their nullable references.
- Product customization receives exact on-hand only for inventory-tracked products in the active branch and renders in-stock, low-stock and zero-stock quantities. Untracked products show `Available` without a number. Threshold, version, history and other-branch balances remain outside the Cashier projection.
- Receipt now has its own Back header, compact 80mm print hierarchy, complete persisted order/item/tender details, and explicit Print receipt / Show QR / New order actions. The historical Show QR placeholder is superseded by the Phase 10 signed digital receipt handoff below. Cashless and Split show an invoice dash placeholder; real invoice capture remains recommended Phase 12 work and Phase 12 is not started.
- Focused order/payment/catalog verification passed **221 tests / 1,592 assertions**; the final full suite passed **1,002 tests / 6,000 assertions**. Six frontend money/identity/stock-label tests, Pint, PHPStan with 1G, frontend lint (91 files), TypeScript, production build and diff whitespace checks pass. The existing optional fontaine and build-plugin timing notices, plus Node's experimental type-stripping notice, remain non-blocking.
- Fresh SQLite in-memory migration and isolated PostgreSQL migrations pass. The updated POS PostgreSQL harness observed four independent workers allocate exactly 1001–1020, preserved a legacy collision, and left every connection reusable. Pay Now duplicate Cash/Split, last-unit, cross-branch root, rollback and after-commit event races still pass; the inventory concurrency harness also passes. Every random schema was removed. The additive migration was applied to the normal local PostgreSQL database without a reset.
- Live component QA at 390, 820 and 1024px verified the receipt Back header, order/reference hierarchy, long item/modifier/note wrapping, full Split tender and invoice placeholder, fixed reachable actions and no visible horizontal overflow. That historical placeholder has since been replaced by the Phase 10 signed digital receipt handoff below. Temporary QA route/component files were removed and the browser viewport restored. Native print preview and physical 80mm printer output remain manual QA.

### Phase 6 final independent acceptance — 2026-09-20

**Phase 6 is COMPLETE.** The complete `origin/dev...HEAD` branch is accepted as PR ready. Phase 7 remains untouched and no PR was created.

- The audit found and fixed one blocking production regression: React Strict Mode cleanup discarded a successful reservation response, leaving the order-type gate open at `Preparing order…`. Reservation completion now survives Strict Mode replay. Live Chrome verified Dine In leaves the gate, shows stable numeric `#1003`, and refresh plus missing remembered client reservation state recover the same persisted UUID, number and immutable full reference without advancing the counter.
- Customer/order label and active-branch table are optional for both order types in rules, flows, UI guidance and validation. Selecting Table 2 auto-fills `Table 2`; identical label/table display is deduplicated; deselecting removes only an auto-derived label and preserves unrelated custom text. Inactive and foreign tables remain rejected.
- The isolated PostgreSQL harness observed independent overlapping processes for same-cashier Dine In/Take Out reservation reuse, two-cashier distinct `#1001/#1002` reservations, independent branches each starting at `#1001`, duplicate Cash and Split, last-unit contention, reversed multi-product carts, cross-branch root-key conflict, and Kitchen failure rollback. It verified stable identity, one counter advance where applicable, no deadlocks or partial effects, reusable connections, after-commit-only events, and complete random-schema cleanup.
- Cash, Cashless, Split, zero-total, underpayment, replay/different-key rejection and simulated lost-response recovery retain exact persisted money semantics. Inventory deducts once with aggregate per-product Sale movements; untracked products create no balance mutation. Each successful Pay Now creates one Kitchen ticket and replay creates none. Order number/reference remain unchanged from reservation through draft, payment, Kitchen and receipt; legacy random identifiers remain untouched.
- Receipt retains the approved hierarchy, top Back action, persisted item/modifier/note and tender projection, short Order Number, secondary full Reference, Cashless/Split `Invoice: —`, and no Cash invoice placeholder. Show QR now uses the Phase 10 signed digital receipt handoff documented below. Print DOM/CSS hides application chrome/actions and provides compact black 80mm content; native print preview and physical-printer output remain a non-blocking manual/staging check because the browser tool cannot inspect them.
- Live Payment QA passed table/label interaction, Exact cash and reachable confirmation without submitting a payment. Responsive measurements at 390, 430, 820, 1024 and 1440px showed document and dialog `scrollWidth === clientWidth`; normal Payment content stayed within the dialog. The previously accepted long-content component stress and standalone comparison remain valid; no redesign was introduced and the temporary viewport override was reset.
- Query regressions pass at 1/30/100 Products: Cashier catalog remains at most four queries, Phase 5 customization/draft loading remains bounded, and new Pay Now coverage stays at 34 SELECTs with at most four Product and two OrderItem reads as a 1/30/100-item cart grows. Reservation refresh reuses the persisted empty order rather than adding Orders or counter increments.
- Fresh SQLite in-memory migration and isolated PostgreSQL migration/metadata checks pass for UUIDs, `numeric(14,2)`, checks, idempotency/Kitchen/reference uniqueness, branch counters, indexes and restrictive history FKs. Normal local PostgreSQL was not reset, Supabase was not accessed, and all temporary schemas were removed.
- Final focused Laravel verification: **341 tests / 2,262 assertions**. Image safety verification: **105 tests / 878 assertions**. Frontend money/order/table/stock helpers: **8 tests**. The clean accepted full-suite command `php -d memory_limit=1G artisan test --compact -d memory_limit=1G` passed in a fresh process: **1,010 tests / 6,040 assertions**. The prior accumulated-process memory concern is closed without weakening the production image guard; CI already supplies the same 1G limit.
- Pint, PHPStan with 1G (zero errors), frontend lint (91 files), TypeScript, production build and diff whitespace checks pass. Only the existing optional fontaine/build timing and Node experimental type-stripping notices remain non-blocking. Full diff review found no committed credentials, environment backups, screenshots, decoded standalone files, browser fixtures, debug dumps, absolute local paths, QA schemas or unnecessary dependencies.

---

### Pre-Phase 7 local POS QA preparation — 2026-09-20

- Local-only menu seed data now uses non-zero deterministic QA prices, configures seeded Products for tracked inventory in MAIN/QAVE, and initializes missing balances through manual-adjustment ledger movements at MAIN 50 / QAVE 30 without refilling existing balances on repeat runs.
- The existing Open Store opening Cash/Cashless form was reverified without a production UI redesign. Local Store Sessions were prepared CLOSED for manual Open Store QA; Phase 15 reconciliation and every Phase 7 item remain untouched.

### Pre-Phase 7 Store Session and invoice placeholder refinement — 2026-09-20

- The STORE OPEN indicator now exposes authorized, read-only current opening details through an on-demand, branch-scoped endpoint; opening balances remain absent from shared `storeContext` and realtime events.
- The paid-success screen for Cashless and the Cashless leg of Split now shows a standalone-aligned, explicitly deferred Invoice camera control beside View receipt; Cash does not show it. Actual camera/upload/storage/viewing is deferred to Phase 12; Phase 7 remains untouched.

---

## Phase 7 — Pay Later

- [x] Save as UNPAID / PAY LATER
- [x] Immediate inventory deduction
- [x] Immediate Kitchen ticket
- [x] Transaction History entry
- [x] Later payment settlement
- [x] Prevent second inventory deduction
- [x] Prevent duplicate Kitchen ticket
- [x] Pay Later idempotency tests

Phase 7 implementation verification (2026-09-20):

- Added an idempotent Pay Later activation boundary that atomically commits the existing POS draft as `UNPAID / PAY LATER`, applies one aggregated `pay_later_commit` movement per tracked product, creates one Kitchen ticket, and emits the existing order/Kitchen events only after commit. Later settlement is a separate idempotent backend operation that writes exact Cash, Cashless, or Split payment legs and marks the order paid without repeating inventory or Kitchen effects.
- Authorization, persisted active-user/branch access, current OPEN Store Session, branch/order/table ownership, draft eligibility, server-owned fields, current product/category/branch availability, tracked stock, snapshot prices, replay identity, and rollback behavior are covered. The additive UUID activation key migration passed local PostgreSQL and isolated SQLite fresh migration checks.
- Focused order/payment/inventory/session coverage passed at 364 tests / 2,532 assertions. The full suite passed at 1,081 tests / 6,553 assertions. The isolated PostgreSQL harness passed same-key replay, competing activation keys, last-unit stock, repeated-line aggregation, reversed product ordering, Kitchen rollback, duplicate Cash/Split settlement, after-commit event replay, and schema cleanup.
- Frontend verification passed 13 Node tests, frontend lint, TypeScript, PHPStan with a 1 GB process limit, Pint, and the production build. Browser QA passed Take Out with no label/table, Dine In with table only, Take Out with a custom label, tracked-stock refresh, clean New order state, and responsive widths 390 / 430 / 820 / 1024 / 1440 with no horizontal overflow or browser console errors.
- This checkpoint does not add the Phase 8 KDS or the Phase 12 Transaction History interface. User manual acceptance and the separate final implementation audit remain pending; this note is not a production-readiness approval.

Phase 7 manual-QA flow-parity correction (2026-09-20):

- Manual QA found and corrected a standalone flow-parity issue: Pay Later now requires one cashier confirmation, `Order Information → Proceed → committed Pay Later success`. Proceed commits the current reserved cart atomically instead of first exposing a saved-draft activation step.
- Existing draft and recovery support remains safely committable through the backend, but is no longer presented as an extra cashier-facing activation step. Phase 7 final acceptance remains pending; Phase 8 is not started.

### Phase 7 final independent acceptance — 2026-09-20

**Phase 7 is COMPLETE.** The complete `origin/dev...HEAD` branch is accepted as PR ready. Phase 8 and the Phase 12 Transaction History UI have not started, and no PR was created.

- The independent audit found and fixed one blocking retry defect: an already committed Pay Later activation accepted a reused idempotency key even when the supplied local-cart payload changed. Exact-key recovery now compares the committed snapshots against order type, normalized customer/table, products, quantities, notes, and modifier option IDs; changed intent returns HTTP 409 without repeating inventory, Kitchen, payment, or event effects. Existing draft recovery remains snapshot-priced and does not compare against mutable catalog prices.
- Manual application QA passed the standalone-aligned single confirmation flow, `Save Pay Later → Order Information → Proceed → Saved as Pay Later`, with optional customer and table. The committed local order retained numeric `#1022` and immutable `MAIN-260920-1022`, persisted `active / unpaid / pay_later / kitchen`, created zero Payments, one Kitchen ticket, and one `pay_later_commit` inventory movement; tracked Tapsilog stock changed exactly once from 47 to 45 for quantity two. Automatic reservation then produced sequential `#1023`; the 20-item stress cart was not committed.
- The decoded standalone reference was rendered and compared directly. It confirms the same one-step confirmation and success-state structure; the production UI intentionally improves operational truth with explicit `UNPAID` and `PAY LATER` badges, the immutable reference, and the project rule that customer/table remain optional.
- Activation and settlement remain separate atomic boundaries. Pay Later commit reauthorizes current persisted access and OPEN Store Session, validates current availability and stock, preserves historical item/modifier/price snapshots, aggregates and sorts tracked products, deducts inventory once, creates exactly one Kitchen ticket, commits without a Payment, and dispatches branch-scoped order/Kitchen events only after commit. Settlement accepts only eligible Pay Later orders, remains bound to the original Store Session, writes exact Cash, Cashless, or two-leg Split Payments, and never repeats inventory or Kitchen effects.
- SQLite fresh migration and every isolated PostgreSQL harness passed. Independent overlapping processes verified stable reservation identity, per-branch numbering, duplicate activation replay, competing-key rejection, changed-payload rejection, last-unit single-winner behavior, repeated-line aggregation, reversed product ordering without deadlock, controlled Kitchen rollback without partial effects, duplicate Cash/Cashless/Split settlement, changed financial-payload rejection, after-commit-only events, branch isolation, and complete random-schema cleanup.
- Pay Now regression remains accepted: Cash, Cashless, Split, last-unit contention, duplicate submission, reverse-order locking, cross-branch root-key conflict, rollback, immutable order identity, exact Payment legs, single inventory/Kitchen effects, and after-commit events all passed. Transaction History data is ready through persisted Orders, item/modifier snapshots, Payments, creator, Store Session, statuses, timestamps, and immutable reference; only the Phase 12 History interface remains deferred.
- Authorization and privacy checks cover Cashier and combined-role success; guest, inactive user, revoked permission, management-only role, foreign branch/order/table, stale session, invalid state, and forged server-owned fields remain denied. Kitchen events retain operational data only and expose no tender amounts or other financial payload.
- Query regressions remain bounded at 1/30/100 cart lines, with repeated products aggregated into one movement. Responsive live-application and standalone checks passed at 390, 430, 820, 1024, and 1440px with no document/dialog horizontal overflow; the 20-item, long-customer-label, long-note stress case kept the final Proceed action reachable. The read-only Store Open details modal and Cashless/Split invoice placeholder behavior also passed, while Cash and Pay Later correctly show no invoice control.
- Final verification passed: focused Laravel matrix **413 tests / 2,565 assertions**; frontend helpers **13 tests**; full `php -d memory_limit=1G artisan test --compact -d memory_limit=1G` **1,088 tests / 6,638 assertions**. Pint, PHPStan with zero errors, frontend lint, TypeScript, production build, fresh migrations, browser logs, whitespace, and secret/artifact review passed. The existing optional `fontaine`, build timing, and Node experimental type-stripping notices remain non-blocking; no dependency, production reset, Supabase, KDS, Close Store, or Phase 12 UI change was made.

---

## Phase 8 — Kitchen / KDS

- [x] Kitchen board
- [x] KITCHEN state
- [x] PREPARING state
- [x] READY state
- [x] DONE state
- [x] Lifecycle validation
- [x] Fullscreen mode
- [x] Realtime Kitchen updates
- [ ] Order-edit Kitchen updates
- [x] Branch isolation
- [x] No financial data exposure

**Phase 8 operational KDS is COMPLETE.** Committed Pay Now and Pay Later orders
enter the current OPEN Store Session board exactly once. The server-authoritative
Kitchen → Preparing → Ready → Done transition locks Order and Kitchen ticket,
keeps their statuses synchronized, permits forward jumps and one-step rollback,
and treats duplicate targets as no-ops. The separate Phase 12 committed-order
editing workflow remains deferred, so its future `kitchen.order_updated` item is
intentionally unchecked and does not block this approved slice.

- KDS matches the approved standalone interaction and hierarchy in normal and fullscreen modes: filters/counts, search, lifecycle cards, immutable preparation details, responsive density, summary footer, and Customer Display launch. Done history is capped while counts remain truthful.
- Kitchen props contain no totals, tender, payment method, change, settlement, or other financial fields. Route authorization, active branch, current OPEN session, closed/stale session, foreign branch/order, mismatched Order/ticket state, and cashier-only Ready → Done restrictions are enforced server-side.
- Compact private branch signals trigger debounced/coalesced authoritative partial reloads and reconnect refetch. Query-count regression coverage remains bounded with 20 tickets, and isolated PostgreSQL workers proved overlapping Ready transitions serialize to one version increment without split Order/ticket state.

---

## Phase 9 — Customer Display

- [x] Preparing order numbers
- [x] Ready order numbers
- [x] Branch-scoped display
- [x] Realtime updates
- [x] Reconnect/refetch
- [x] Safe public payload

**Phase 9 Customer Display is COMPLETE.** The dedicated full-canvas surface maps
Kitchen and Preparing into Preparing, shows Ready separately, removes Done, and
exposes order numbers only. Shared employee/auth/profile data is omitted before
serialization. Its private branch event contains only event identity, branch,
and time; the client refetches the minimal authoritative projection.

- The POS Ready bell/list, lower-left queue, detail dialog, and cashier Ready → Done action are integrated outside the cart tree, preserving order type, cart, product/payment dialogs, and remembered cashier state across realtime refresh.
- Live multi-view QA verified KDS lifecycle changes propagating to Customer Display without reload and the POS Ready surface. Responsive checks passed without horizontal overflow at phone, tablet, and desktop widths; KDS grid density progressed from one to three columns in normal mode, and Customer Display stacked on phones while retaining two columns on larger screens.

---

### Phase 8/9 post-merge responsiveness refinement

- Critical lifecycle broadcasts send immediately after the outer commit with rescued transport errors. Compact private signals still trigger authoritative projections; operational coalescing is 35 ms and catalog debounce remains 160 ms.
- KDS uses concurrent, per-order JSON PATCH mutations with optimistic status/filter/count overlays, independent rollback, version reconciliation, and confirmed-only queued Ready audio. POS redirect compatibility and the approved responsive layout are preserved.
- Shared OPEN Store Session locking replaces the exclusive transition bottleneck. Order and KitchenTicket exclusive locks still enforce synchronization/idempotency. PostgreSQL verification proves same-order overlap, unrelated-order independence while one row is held, and an exclusive close boundary.
- Live QA created five labeled orders through Pay Later/Pay Now and submitted all five Preparing actions within 133 ms: five independent pending cards and five successes. A controlled temporary validation rejection rolled back only C; A/B/D/E remained Ready, one toast appeared, and exactly four PA SERVE playbacks followed four confirmations. Mixed transitions and Customer Display Preparing/Ready/Done behavior passed. A new ticket played one TING; reload/hydration did not replay it. Temporary instrumentation and fault injection were removed.
- Local instrumented measurements: queued Pay Later success to KDS frame 7.64 s (5.21 s to event); after refinement 0.72-2.10 s across Pay Later/Pay Now samples. Isolated confirmed Ready to POS frame improved from 3.37 s to 1.18 s. Five rapid clicks reached their next visual frames in 10-169 ms. Immediate events arrived before HTTP success; remaining variability includes the Windows single-worker PHP development server serializing HTTP requests and browser frame scheduling. A consistent sub-second result is not claimed for this environment.
- Release gate passed: focused Laravel 32 tests / 272 assertions; 16 focused Node behavioral/presentation tests; full Laravel suite 1,150 tests / 7,333 assertions (one complete run); isolated PostgreSQL concurrency harness; Pint; PHPStan (zero errors); frontend lint; TypeScript; production build; and diff whitespace checks. No migrations were added. Reconnect refresh and branch isolation have automated regression coverage.

---

## Phase 10 — Customer QR Ordering

**Implementation delivered; latest UI/UX direction manually accepted by the user.**
Release readiness is determined by the final audit gates below.

- [x] Branch QR entry and anonymous session authorization
- [x] Store Closed state - implemented; user manual QA accepted
- [x] Welcome/Menu parity - user manual QA accepted
- [x] Product customization - user manual QA accepted
- [x] Cart / Dine In / Take Out - user manual QA accepted
- [x] QR submission and exact submitted snapshot state
- [x] No stock deduction, Payment, or Kitchen ticket on initial submission
- [x] Anonymous QR session, hashed token, secure branch cookie, server expiry
- [x] One active order/session and idempotent submission recovery
- [x] Active tracking - server/event tested; user manual QA accepted
- [x] Receipt / Save receipt PNG - implemented; user manual QA accepted
- [x] Owned receipt authorization and exact 24-hour expiry
- [x] 30-minute Archived / Unclaimed scheduler and preserved history
- [x] Customer tracking security / branch isolation tests

## Phase 11 — QR Orders Staff Flow

**Implementation delivered; latest UI/UX direction manually accepted by the user.** No Phase 12+ work is marked complete.

- [x] Active QR queue / search / detail - user manual QA accepted
- [x] Realtime arrival and claim removal - automated contracts pass; user manual QA accepted
- [x] LOAD preserves the existing Order, provisional QR number, snapshots and source without allocating official identity
- [x] Branch-safe authorized LOAD with no Payment/stock/Kitchen side effects
- [x] Duplicate LOAD protection verified with PostgreSQL workers
- [x] Active cashier cart preserved by a clean-cart LOAD guard
- [x] Archive/Delete confirmation - user manual QA accepted
- [x] Archive preserves history and rejects claimed/committed/inconsistent orders
- [x] Pay Now after LOAD reuses the existing atomic payment action
- [x] Pay Later after LOAD and subsequent settlement preserve single stock/ticket effects
- [x] Size/Instructions/notes survive through normal KDS and customer tracking
- [x] Owner branch QR display/view/copy - user manual QA accepted
- [x] iPad Mini/sidebar adjustment - sidebar from 768px, phone-only floating dock, two-column normal Kitchen at tablet widths; source regression passes, user manual QA accepted

### Phase 10/11 implementation and verification - 2026-09-22

- One anonymous branch-bound cookie session and the existing Order aggregate;
  one shared server snapshot builder reused by POS/QR, no parallel payment/inventory/Kitchen engine.
- LOAD only claims. Existing Pay Now/Pay Later commit the same Order ID with newly allocated official identity and exact
  submitted prices. Submit/LOAD/archive have zero operational effects; commitment
  deducts once and creates one ticket; Pay Later settlement creates payments only.
- Staff queue has bounded eager-loaded projections, search, detail, realtime badge,
  claim invalidation and retained archive history. An active cashier cart blocks LOAD.
  Claimed QR snapshots are read-only in the persisted-order POS path.
- Public tracking and receipt enforce cookie ownership; private customer channels
  never expose staff data. Receipt expires after payment + 24 hours. Start new order
  is explicit after Done/archive. Public catalog contains availability labels, not quantities.
- Reference-defined customer screens and legal/social surfaces are implemented;
  Pay Later's payment-pending status is truthful even after Kitchen commit.
  Source alignment is not a substitute for final user visual acceptance.
- Realtime is immediate after commit, rescued on transport failure, with 35ms
  coalescing and authoritative reconnect refresh. Reverb must be running; Laravel's
  scheduler must run for the every-minute stale-order archive task.
- PostgreSQL harness: all six required scenarios passed using independent workers
  and observed database lock waits, plus both Store Close and branch-state boundaries.
  Temporary schema removed. SQLite and PostgreSQL fresh/rollback/reapply passed.
- Local feature migration applied without resetting existing data. No dependency
  changes, fake public tracking data, prototype assets, or browser QA files added.
- Initial full release gate exposed nine SQLite Order CHECK-constraint regressions
  caused by a foreign-key table rebuild. The QR migration now uses a native nullable
  inline REFERENCES addition on SQLite; original checks survive up/down/up. The
  affected Order foundation and QR tests passed after correction.
- Final full Laravel release gate passed: **1,197 tests / 7,733 assertions**.
  Focused Customer QR coverage passed **44 tests / 355 assertions**; focused
  frontend coverage passed **29 tests**, including the tablet navigation/grid rule.
  Pint, PHPStan (zero errors), frontend lint (123 files), TypeScript, production
  build, and whitespace checks passed. PostgreSQL concurrency and isolated
  fresh/rollback/reapply checks passed after the SQLite correction.
  The existing optional fontaine and build timing notices remain non-blocking.

Manual QA checklist: scan the Owner branch QR; confirm Closed/Welcome/legal/menu;
customize Size/Instructions/notes; edit/remove cart lines; choose Dine In/Take Out;
submit twice and retain one number; inspect two same-branch cashier queues; confirm
active-cart LOAD guard and single-claim winner; complete Pay Now and Pay Later;
advance normal KDS and verify customer tracking; settle Pay Later without extra
stock/tickets; save the owned paid receipt and verify expiry; leave an untouched
submission for 30 minutes with scheduler running; confirm archive and Start new order;
disconnect/reconnect during browse/tracking; test foreign branch/session denial;
inspect 360/390/430px phones, iPad Mini 768/1024px sidebar, tablet and desktop.

---

## Phase 12 — Transaction History & Editing

- [x] Transaction list
- [x] Search
- [x] Filters
- [x] Transaction detail
- [x] Pay Later settlement from history
- [x] Edit committed order
- [x] Inventory delta calculation
- [x] Inventory compensating movement
- [x] Higher-total delta payment
- [x] Higher-total Pay Later delta
- [x] Lower-total correction
- [x] Receipt actions
- [x] Edit audit trail
- [x] Kitchen update after relevant edit
- [x] Cashless / Split invoice proof capture
- [x] Camera / image upload for invoice proof
- [x] Persist invoice proof against payment/transaction
- [x] View invoice proof in transaction detail
- [x] Replace/remove invoice proof with authorization

The Phase 6 placeholder is now replaced by Phase 12 proof management. The proof attaches to the Cashless Payment row: Cash-only payments have none, while Split attaches it only to the Cashless leg. Camera or file upload is supported, images remain private and authorization-gated, and proofs are manual evidence rather than payment-gateway verification.

**Phase 12 is complete on `feature/transaction-history`.** The cashier-only, branch-scoped History workspace, versioned committed-order editing, net inventory deltas, append-only reconciliation, balance settlement, grouped Payment attempts, private Cashless invoice proofs, canonical edit/proof audits, and compact POS/Kitchen invalidations are implemented. Void remains disabled for Phase 13. User manual device/visual QA and the final implementation audit are accepted.

---

## Phase 13 — Void & Audit

- [x] Void authorization
- [x] Void reason
- [x] Re-auth / protected confirmation
- [x] Compensating inventory restoration
- [x] Original order retained
- [x] Payment history retained
- [x] Audit Trail
- [x] Super Admin Void Orders
- [x] Void authorization tests

Phase 13 final release-gate implementation (2026-09-23):

- The accepted approval model is one global four-digit PIN configured by an authenticated active Super Admin, stored only as a hash. The configuring Super Admin is the authorizer; the active assigned Cashier/Cashier+Kitchen operator is the distinct initiator. Wrong/missing PIN, inactive or no-longer-Super-Admin PIN owner, and self-authorization fail safely. PostgreSQL advisory serialization protects concurrent first-time and replacement configuration while the database unique scope preserves one global row.
- Void follows the Store Session shared → Order exclusive → remaining locks order in one transaction. It retains the complete Order/Payment/Kitchen history, appends one OrderVoid and canonical audit, increments version, and restores only the net negative `sale`, `pay_later_commit`, and `order_edit_delta` ledger effect through sorted `void_restore` movements. Exact retry is side-effect-free and changed intent conflicts.
- Normal Cashier History/detail and every receipt path deny voided Orders. KDS, Customer Display, and Customer QR tracking refetch authoritative safe state through compact after-commit invalidations. Audit Trail and Void Orders remain read-only, 30-per-page, Super Admin-only registers; the private audit channel applies the same permission boundary.
- The canonical recorder now drives committed-edit and invoice-proof audits and recursively redacts PIN/password/secret/token/credential keys. Frozen current audit obligations now cover successful login, Store Open/opening balances, Pay Now, Pay Later, settlement, edit/correction context, Void, PIN changes, manual inventory adjustment, invoice-proof changes, Branch changes, and implemented Branch QR/receipt settings. Audit models reject normal update/delete operations. Catalog Product/Category/Group/Option/image and branch-product configuration mutations are explicitly deferred audit expansion because the frozen audit list does not require catalog changes; no future Phase 14/15/17 entries were fabricated.
- Isolated PostgreSQL verification covers same-key and competing Void, Void versus edit/settlement/Kitchen, reverse Product restoration order, the future Store Close Session boundary, concurrent PIN changes, and fresh/rollback/reapply schema cleanup. Focused failure injection verifies inventory restoration, OrderVoid creation, Order update, and audit failure each roll back all effects and emit no success invalidation.
- `cashier@gmail.com` remains `cashier_kitchen` on MAIN. Local/testing seeders retain their production guards and idempotent, preservation-first behavior; no Void PIN is seeded. User manual UI acceptance remains the visual authority, with final source review performed against the decoded standalone templates rather than a new broad browser pass.

**Phase 13 is complete.** This does not mark Phase 14, Phase 15, Phase 17, or the full Phase 18 workspace complete.

---

## Phase 14 — Store Purchases / Expenses

- [x] Current Store Session expense list
- [x] Add Store Purchase / Expense
- [x] Description
- [x] Amount
- [x] Cash payment source
- [x] Cashless payment source
- [x] Note / reason
- [x] Optional receipt image
- [x] Optional inventory product link
- [x] Optional quantity
- [x] Restock inventory movement
- [x] Cash closing-balance effect
- [x] Cashless closing-balance effect
- [x] Store Session/user trace

Phase 14 implementation completed on `feature/store-expenses` (2026-09-23). Cashier Store Purchases / Expenses now open from the existing `LIVE / STORE OPEN` Current Store Session control, not a new navigation destination. The reusable session dialog provides exact server totals, newest-first current-session history, read-only detail, and an offline-safe add flow with optional private receipt and one explicit tracked-Product restock. Cash and Cashless classification is persisted separately for future Phase 15 expected-balance calculations; Phase 15 Close Store/reconciliation remains unimplemented.

The write derives the active Branch and OPEN Store Session, takes the shared Session boundary, uses stable UUID/hash idempotency, reauthorizes and locks inventory inputs, calls `ApplyInventoryMovement`, appends canonical Audit evidence, cleans failed uploads, and emits compact rescued after-commit realtime invalidation. Focused Laravel, frontend, isolated migration, and independent-worker PostgreSQL integrity/concurrency gates passed. Standalone parity and responsive behavior were source-reviewed; final device/visual acceptance is USER MANUAL QA. Phase 15, Phase 16 full workspace, Phase 17, and Phase 18 remain incomplete.

Delivery verification: the focused Store Session/inventory/Audit/realtime regression slice passed **245 tests / 1,473 assertions**, followed by **26 tests / 145 assertions** after the PHPStan-driven projection typing correction. The complete frontend behavior suite passed **75 tests**. Pint, PHPStan with zero errors, frontend lint with zero warnings across 135 files, TypeScript, production build, and whitespace checks passed. Disposable SQLite and isolated PostgreSQL fresh/rollback/reapply passed; PostgreSQL independent workers passed exact replay, changed-intent conflict, same-Product and different-Product restocks, and the exclusive future Close Store boundary. The temporary databases/schemas were removed. **NORMAL LOCAL DEVELOPMENT DB WAS NOT RESET.** No Supabase access/reset, dependency change, Phase 15 action, broad browser sweep, or PR was performed. Status: **READY FOR USER MANUAL QA**.

Final release-gate audit completed on 2026-09-23 with USER MANUAL QA accepted. The audit corrected two blocking edge cases: expense writes now depend on real browser connectivity rather than Echo/Reverb connection state, and optional restock quantities are capped at 1,000,000 on both client and server. Inactive user/branch-assignment denials and an independent-worker concurrent Cash/Cashless projection race were added to the regression coverage. The complete final gates passed: **1,319 Laravel tests / 8,710 assertions** and **76 frontend tests**, with zero failures, errors, or skips; Pint, PHPStan, frontend lint, TypeScript, production build, and whitespace checks also passed. Isolated SQLite and PostgreSQL fresh/rollback/reapply passed, including exact replay, changed-intent conflict, same-Product and different-Product restocks, exact concurrent Cash/Cashless totals, and the exclusive Close Store boundary. All temporary databases/schemas were removed. **NORMAL LOCAL DEVELOPMENT DB WAS NOT RESET.** Supabase was not accessed or reset, no dependency or Phase 15 change was made, and no PR was opened. Status: **READY FOR PR**.

---

## Phase 15 — Close Store & Reconciliation

- [x] Close Store entry point
- [x] Block unresolved UNPAID / PAY LATER
- [x] Block Kitchen non-DONE orders
- [x] Allow unclaimed QR without blocking
- [x] Opening Cash summary
- [x] Opening Cashless summary
- [x] Cash sales
- [x] Cashless sales
- [x] Split breakdown
- [x] Store purchases/expenses summary
- [x] Relevant adjustments / void effects
- [x] Expected Closing Cash
- [x] Expected Closing Cashless
- [x] Closing Cash input
- [x] Closing Cashless input
- [x] Cash variance
- [x] Cashless variance
- [x] Shortage blocks normal close
- [x] Overage requires note
- [x] Archive remaining unclaimed QR
- [x] Atomic Store Close transaction
- [x] Store Close audit
- [x] `store.closed` broadcast after commit
- [x] Customer QR switches to Store Closed
- [x] Concurrent Close Store protection

- [x] Phase 15 implementation complete (focused, frontend, static and isolated PostgreSQL gates)
- [ ] Standalone parity side-by-side review and device/visual acceptance (USER MANUAL QA)

Phase 15 implementation on `feature/close-store` (2026-09-23). Close Store extends the existing `LIVE / STORE OPEN` Current Store Session dialog: a Close Store danger section below Purchases & Expenses opens Review & reconcile → final confirmation → Store Closed, with no new navigation page. The decoded `context/design/pos.html` bundle contains no Close Store prototype, so the flow reuses the accepted Phase 14 dialog shell and the standalone's white/black/red/amber/green token language; the Phase 14 sections are unchanged.

**Intentional reconciliation decisions**

1. `StoreSessionReconciliation` is the single authority for the read-only preview and the final close. Expected = Opening + Payment rows − Store Expenses − corrections on non-voided Orders − all payments of voided Orders, computed separately for Cash and Cashless in integer cents. Cash impact is `Payment.amount`, never tendered cash; Split legs count once and the split breakdown is explanatory only; a restock expense has one financial effect.
2. `variance = actual − expected` everywhere (backend, DB, UI, Audit). Negative is a shortage, positive an overage.
3. Cash and Cashless are independent: a shortage in either blocks close with no note override and no cross-channel offset. An overage requires a trimmed explanation of at least 5 characters; exact needs no note.
4. Any committed, non-voided current-session Order with authoritative outstanding > 0 blocks close (unpaid/partial Pay Later and higher-total Balance Due, regardless of `payment_term`). Committed Kitchen/Preparing/Ready blocks; Done and voided do not.
5. Voided Orders reverse every payment they collected, so an earlier lower-total correction on the same Order is excluded from the corrections line and never subtracted twice.
6. New lower-total corrections persist `cash_amount`/`cashless_amount`. Single-method Orders are attributed automatically; mixed-method edits require the Cashier's explicit Cash portion in the existing Adjustment to return confirmation.
7. Historical mixed-method corrections without a source block Close Store until a write-once, audited allocation is recorded from the blocker card. Nothing is guessed (no Cash default, no proportional split).
8. A loaded (claimed) uncommitted QR order blocks close until payment or Cancel LOAD; Close never clears `loaded_by_user_id`. Submitted unclaimed QR orders are informational and archived with `store_closed` at the close timestamp.
9. Expected balances keep exact signed math and may be negative (the former `expected_* >= 0` CHECK was removed); the UI surfaces a warning. Actual Closing Cash/Cashless remain non-negative.
10. The close holds idempotency advisory → Branch → OPEN Store Session exclusively → unclaimed QR Orders, recomputes everything inside that boundary, and never trusts client totals. Exact retry recovers the same close; changed retry or a different key after close returns 409.
11. The local POS cart is untouched until the server confirms the close; the confirmation warns that an unsent cart on the device will be cleared, and Store state reloads only after success (or a truthful already-closed response).

**Verification**: focused Phase 15 + adjacent Store Session, expense, payment, settlement, edit, Void, Kitchen, QR, Customer Display, Audit and realtime slice **537 tests / 4,107 assertions**; frontend **87 tests**; Pint, PHPStan (zero errors), frontend lint, TypeScript, production build and `git diff --check` passed. Disposable SQLite up/down/reapply passed for both Phase 15 migrations. The isolated PostgreSQL harness `tests/verify-close-store-postgres.php` passed fresh/rollback/reapply (constraints and session-correction index), PostgreSQL SQL exactness and allocation, A Close vs Close, close-wins rejection of Pay Now, Pay Later, edit, Void, Kitchen, Expense and QR restore/load, write-first inclusion of settlement, correction, Void, Done and Expense (Pay Now/Pay Later correctly re-block), J 10-order QR archival rollback, and K concurrent Cash/Cashless/Split payments closing at zero variance; every temporary schema was removed. Phase 12, 13 and 14 harnesses were rerun after their fixed rollback step counts were made relative to their own migrations; the QR harness still uses pre-Phase-12 fixed step counts and was not rerun. Responsive layout was source-reviewed for 360/390/430/tablet/desktop (confirmation footer and variance labels were corrected for 360px); no browser sweep was performed. **NORMAL LOCAL DEVELOPMENT DB WAS NOT RESET**: it received only the two additive `php artisan migrate` runs, and no Store Session was closed or edited. Supabase was not accessed. The full Laravel suite is reserved for FINAL QA after USER MANUAL QA. Status: **READY FOR USER MANUAL QA**.

**Follow-up UI/UX refinements (USER MANUAL QA driven, 2026-09-23):** standalone-style Store Session close button without mouse focus ring; Pre-close checks shown only while a blocker remains; session purchases collapsible dropdown below Actual closing count; redesigned per-channel final confirmation; cached Store Session reopen with layout-preserving skeleton; modal widened 512px → 614px; Customer QR shows Store Closed instead of stale uncommitted-order tracking and refetches when a sleeping phone becomes visible.

**Store Session inventory adjustments:** Adjust inventory sits beside Add expense / purchase in the same dialog. Complimentary, Wastage, Damaged, Staff meal and Other (explanation required) deduct stock through `ApplyInventoryMovement` (`manual_adjustment`, attributed via `store_session_inventory_adjustments`) under the shared Session boundary, with idempotency, one `inventory / store_session.inventory_adjusted` Audit and existing inventory/catalog realtime. It creates no Store Expense or Payment and never changes reconciliation. Verified by focused Pest, frontend tests and PostgreSQL scenario L (concurrent last-unit deductions). Status: USER MANUAL QA.

**Final QA (2026-09-23):** the complete `origin/dev...feature/close-store` change set was re-audited from source. Fixes: the closing Cashier's client now records the Store Session it is closing before sending the request, because the `store.closed` broadcast can arrive before the HTTP response and previously dismissed the pending Store Closed summary; Add expense / Adjust inventory / expense detail fall back to the overview load message when the cached session is discarded (404/403/401/419) instead of rendering an empty dialog; the desktop Store Closed header keeps long Branch names beside the illustration. Regression coverage was added for inventory-adjustment failure injection (adjustment record and Audit write roll back stock, movement and attribution with no realtime), the inventory-adjustment role matrix (Cashier+Kitchen allowed; guest, inactive, inactive-assignment and other-Branch denied), and backend rejection of Pay Later, settlement and inventory adjustment after a real close. Stale PostgreSQL harness assumptions were corrected without production changes: the QR harness rollback steps are now relative to its own migrations, and the Phase 5/6 harnesses accept the documented `BRANCH-MMDDYY-####` reference. Gates: **1,408 Laravel tests / 9,419 assertions** and **100 frontend tests**, zero failures; Pint, PHPStan, frontend lint, TypeScript, production build and `git diff --check` passed; disposable SQLite fresh/rollback/reapply passed; all 11 isolated PostgreSQL harnesses (Close Store A–L, Store Expenses, Transaction History, Void/Audit, QR, inventory, Kitchen, Pay Later, Pay Now, POS, Store Session) passed and removed their schemas. **NORMAL LOCAL DEVELOPMENT DB WAS NOT RESET.** No browser/device QA was performed in this audit. Status: **READY FOR PR**.

---

## Phase 16 — Owner Workspace

Owner workspace UI alignment slice (2026-09-21):

- Aligned the protected Owner/Super Admin management shell, Products/Categories/Modifiers, Inventory/Movement History, Branch Management, and the existing workspace entry state to the decoded `context/design/PONGSKILOG-OWNER.html` reference. The responsive shell uses the 248px desktop sidebar, 96px tablet rail, and mobile top bar/bottom dock/More pattern; unavailable later-phase navigation remains disabled and labeled.
- Preserved the real product, image, modifier, branch override, inventory adjustment/history, branch QR, Store Session state, branch context, and backend authorization behavior. Added selected-branch stock presentation/filtering to Products and full-dataset branch inventory summary/category filtering with real balance update timestamps; no schema or dependency change.
- Live local browser QA covered Dashboard, Products, Categories, Inventory, and Branch Management at desktop, tablet-rail, and mobile-dock widths. The pass caught and corrected desktop rail overlap and the mobile active-More contrast issue. Final pages had no document horizontal overflow and recent browser logs contained no application error.
- Focused catalog/inventory/branch/RBAC verification passed: **404 tests / 2,665 assertions**. Full Laravel verification passed: **1,090 tests / 6,702 assertions**. Pint, PHPStan with zero errors, frontend lint, TypeScript, production build, and whitespace checks passed. The existing optional `fontaine` and build timing notices remain non-blocking.
- This was a visual alignment and existing-function refinement slice only. Phase 16 remains incomplete: no Phase 16 checkbox below is marked, and Transactions, Reports, Staff, full Settings, analytics, branch comparison, and Store Session summaries were not implemented.

### Owner catalog, Groups, and Inventory refinement — 2026-09-21

- Added truthful notification/account menus and Product/Category/Group quick actions; renamed user-facing Modifiers to Groups; made Product, Category, and Inventory filtering reactive and server-authoritative; and added local tile/list presentation where applicable.
- Product Add/Edit now uses one standalone-style editor with image, core fields, selected-branch stock truth, authorized branch configuration, reusable Group assignment cards, inline Group/Option creation, and a fixed action footer. All Branches never fabricates or sums inventory.
- Added allowlisted Category icon keys and an explicit nullable Group `size` semantic role. The semantic role is snapshotted on committed order modifiers, and shared backend/frontend display helpers prefix only explicitly selected Size options while preserving canonical Product names and historical output.
- POS catalog projections keep disabled/category-disabled/branch-unavailable/out-of-stock Products visible with an explicit reason while preventing customization/cart entry; authoritative order validation continues rejecting stale unavailable Products.
- Inventory now follows global Owner scope, requires an explicit local branch in All Branches, retains authoritative Adjust Stock actions and append-only movements, and opens real paginated movement history in a responsive modal with the protected deep-link fallback retained.
- Live Chrome QA covered the Owner menus and quick actions, Products/Categories/Groups, branch-specific and All Branches behavior, Adjust Stock/history, and the standalone Product editor. The editor was specifically checked at 360, 390, 430, 820, and 1440px: its header/footer remain fixed, its body scrolls independently, touch controls remain reachable, and no visible horizontal document/dialog overflow was found. A no-change live Product save completed successfully; temporary Group previews were cancelled and no QA catalog fixture remains.
- Additive migration smoke/rollback/reapply passed on isolated SQLite, and isolated PostgreSQL fresh/concurrency verification passed with temporary schemas removed. The normal local PostgreSQL database received only the additive migration; Supabase was not accessed or reset.
- Final verification passed: **1,094 Laravel tests / 6,823 assertions**, **14 frontend contract tests**, Pint, PHPStan with zero errors, frontend lint, TypeScript, production build, and whitespace checks. The existing optional `fontaine`, build timing, and Node experimental type-stripping notices remain non-blocking.
- Phase 16 remains incomplete. Transactions, Reports, Staff, full Settings, analytics, branch comparison, real notifications, and Store Session summaries remain deferred; no Phase 16 checkbox below is marked by this refinement.

### Realtime catalog synchronization and Instructions Groups follow-up — 2026-09-21

- Wired compact after-commit `inventory.changed`, `product.availability_changed`, and `product.branch_configuration_changed` broadcasts to the private branch inventory channel. Inventory movements and visible catalog mutations now notify only affected authorized branch clients; PostgreSQL/refetched catalog state remains authoritative.
- POS now maintains one Echo subscription for the active branch, debounces event bursts into an Inertia `catalog` partial reload, prevents overlapping reloads, and performs an authoritative reload after reconnect. Cart, order type, payment state, open Product dialog, selected options, and notes are preserved; an open dialog immediately blocks saving if its Product becomes unavailable.
- Extended reusable Groups with the explicit `instruction` semantic role. Owner Group and inline Product editors expose Standard options, Size, and Instructions. Instructions normalize to optional/multiple, enforce zero option prices on the server, render as POS chips without price/name effects, and remain separate from free-text notes.
- Committed order modifiers retain Group name, option name, semantic role, and zero price snapshots. Shared cart/payment/paid/receipt/order-summary presentation renders `Instructions:` independently, leaving Size and Standard behavior unchanged and ready for future KDS/Customer QR reuse without implementing either phase.
- Focused catalog/POS/inventory/payment regression passed at **429 tests / 3,977 assertions**; full Laravel verification passed at **1,110 tests / 6,971 assertions**. Frontend contract tests passed at **16 tests**. Pint, PHPStan with zero errors, frontend lint, TypeScript, production build, and whitespace checks passed.
- Live Chrome QA verified MAIN availability, Product enable/disable, price override, out-of-stock, and restock changes in an already-open POS without browser reload; a QAVE-only change did not alter MAIN. Original availability/price/role state and stock quantity were restored. Owner Instructions defaults and explanatory treatment were visually verified; 360/390/430/tablet/desktop checks found no document overflow and browser logs contained no application error.
- The browser automation bridge could focus but not activate the POS order-type buttons in this run, so live instruction-chip/cart interaction was not claimed; server snapshots/totals/rejections and client price-neutral/reconnect behavior are covered by focused automated tests. Phase 8 and Phase 16 remain incomplete.

### Phase 16A — Owner Sales & Store Session reporting — 2026-09-24

Branch `feature/owner-reporting` from `dev` at `62ec3a9`. No migration and no dependency change.

- **Real Reports page.** `GET /workspaces/reports` (`workspaces.reports`, `auth` + `permission:reports.view`, and `ReportsRequest` requires an active business-wide Owner or Super Admin). It is one read-only Inertia page (`workspaces/reports`) rendered in the Owner shell for Owner and the Super Admin shell for Super Admin. Owner → Reports is now enabled with an active state and appears as a Dashboard card. Super Admin → Owner → Reports is a live registry destination; the `super-admin.reports` placeholder route and content were removed. Owner Transactions stays disabled.
- **One projection.** `App\Support\StoreSessionSalesReport` resolves Manila business-date ranges, the global Branch scope and the Store Session filter, then projects the summary, daily rows, session rows and detail. `StoreSessionReconciliation` gained `flows()` (batched Payment / Split / Expense / correction / Void flows keyed by session, Branch-matched), `opening()` and `expected()`. `calculate()` and `correctionChannels()` now delegate to them, so Close Store, the Cashier Dashboard and reporting share one formula. `ExactMoney::signedCents()` parses persisted signed values.
- **Semantics:** see `02-business-rules.md` §37. Business date = Manila date the session opened; multiple sessions per date; cross-midnight sessions stay under the opening date. Net Sales = current `orders.total` of committed Active/Completed Orders (Pay Later included), separate from Cash/Cashless net collections. Split is informational. Corrections and Void reversals are shown separately with no double subtraction. Expenses are Store Session expenses only, and stock-only adjustments are excluded. CLOSED sessions use persisted snapshot/close values (legacy sessions fall back to records and show Not available for missing values). OPEN sessions are LIVE and provisional. Zero-activity sessions still appear.
- **Performance:** a constant number of queries regardless of session count (sessions + eager identities + one Order aggregate + batched flows; closed snapshots skip the live flows), bounded by the 31-day custom maximum. Index review found `orders(store_session_id, qr_sequence)` already leads with `store_session_id`, and payments / expenses / order_adjustments already have session indexes, so no migration was added. Realtime was not added: All Branches would need many subscriptions and Owner is not authorized on operational branch channels, so the page has a manual Refresh (no polling).
- **UI:** follows the decoded `PONGSKILOG-OWNER.html` Reports screen (toolbar card with segmented periods and a centered Business date, custom-range card, KPI grid, 20px panels, inset tiles, tables that become cards). Fake analytics (charts, product ranking, cashier performance, exports) were not reproduced. Responsive layout was source-reviewed for 360/390/430/tablet/1024/desktop; no browser sweep was performed.
- **Verification:** new `StoreSessionSalesReportTest` (35 tests) plus a batched-flow parity test in `StoreSessionReconciliationTest`. Focused backend regression **551 tests / 3,910 assertions**; frontend **120 tests** (new `reports-ui.test.ts`). Pint, PHPStan (0 errors), frontend lint, TypeScript, production build and `git diff --check` passed. The new isolated PostgreSQL harness `tests/verify-owner-reports-postgres.php` passed: index, same-day sessions with odd-cent Cash/Cashless/Split, correction + Void, Manila boundary, All Branches isolation, and batched-vs-single parity. The Close Store PostgreSQL harness (A–L) was rerun after the reconciliation refactor and passed. Both removed their schemas. **NORMAL LOCAL DEVELOPMENT DB WAS NOT RESET**, received no migration and had no rows written. The full Laravel suite is reserved for FINAL QA.
- **Known limitation:** the shared BranchSwitcher redirects to the role workspace after switching (existing behavior), so the Owner returns to Reports from the navigation after changing Branch scope.
- Status: **READY FOR USER MANUAL QA**. Phase 16 remains incomplete: Dashboard analytics, Transactions, Staff, full Settings, Branch comparison, product/payment-mix/cashier/kitchen reporting and exports remain deferred. Only Store Session summaries is checked below; Reports stays unchecked until the rest of the planned report scope exists.

- [ ] Dashboard
- [ ] All Branches scope
- [ ] Specific Branch scope
- [ ] Transactions
- [ ] Reports
- [ ] Products
- [ ] Inventory
- [ ] Staff
- [ ] Settings
- [ ] Branch comparison
- [x] Store Session summaries (Phase 16A; USER MANUAL QA pending)
- [ ] Owner blocked from Super Admin-only controls

---

## Phase 17 — Stock Transfers

- [ ] Create transfer
- [ ] Source branch
- [ ] Destination branch
- [ ] Product / quantity
- [ ] Requested
- [ ] Sent
- [ ] Received
- [ ] Completed
- [ ] Transfer-out movement
- [ ] Transfer-in movement
- [ ] No double receive
- [ ] Transfer history / audit

---

## Phase 18 — Super Admin Workspace

### Super Admin foundation, navigation, and Staff creation — 2026-09-24

Branch `feature/super-admin-foundation` from `dev` at `e927c5c`. No dependency change. One additive migration (follow-up request): nullable unique `users.employee_id`, required on new staff as `MMDDYY` + two digits, typed manually by the Super Admin; existing accounts keep it empty. A second additive migration adds nullable `users.avatar_path` for an optional Super Admin uploaded staff profile picture (private disk, authorized route), shown as a rounded-square holder in the Staff list.

- The obsolete `context/design/PONGSKILOG Super Admin (standalone).html` was deleted. No Super Admin standalone is authoritative; Super Admin UI is product-designed in the Owner/POS language (`08-ui-rules.md`, `09-ui-registry.md`).
- New `SuperAdminShell` with a collapsible four-section sidebar (Overview, Cashier + Kitchen, Owner, Control), driven by the permission-aware registry `resources/js/lib/super-admin-navigation.ts`. It has a tablet rail and a mobile dock, each with a collapsible drawer, and the Owner shell is now Owner-only. Operational pages keep their POS shell and gain a Control Center link for Super Admin.
- Real destinations link to existing pages. Notifications, Reports, and Access Control are protected Planned placeholders with no fake data or toggles. The Super Admin Dashboard is a Control Center landing with real quick links only. Settings reuses Branch Management under Control.
- Full operational parity for Super Admin, approved by the product owner, superseding the Phase 14/15 denials. It works through `User::hasCashierOperationsRole()` / `hasOperationalBranchAccess()` on the selected active Branch with no fabricated assignments. Store Session and business invariants are unchanged, actions are audited as the Super Admin, and Void keeps two-person approval. Owner gains no Cashier operations.
- Real Staff page (`access_control.manage`): list/search/filter and an atomic Add Staff flow. The Super Admin chooses the temporary password, which is hashed and never re-shown or audited. Operational roles require an active Branch; Owner and Super Admin are business-wide. The flow writes one `staff.created` Audit record. There is no invite email, forced password change, or self-service profile work.
- Automated gates: focused backend regression **1,148 tests / 8,466 assertions** (including the new SuperAdminWorkspaceTest and StaffManagementTest), frontend Node tests **113 passed**, Pint, PHPStan (zero errors), frontend lint (zero warnings), TypeScript, production build, and `git diff --check` all passed. Tests use in-memory SQLite; the normal local development database was not reset.
- Not complete: the Access Control matrix, Notifications service, Owner Reports, Super Admin analytics, editing or deactivating existing staff, and profile settings. Final visual and device acceptance is **USER MANUAL QA**. No checkbox below is marked by this slice.
- Final QA — 2026-09-24: **USER MANUAL QA: PASSED BY USER**. Engineering fixes: a Staff unique-index race now reports the actually violated field (email vs Employee ID) from the parsed constraint instead of the SQL message; staff avatars fall back to initials if the image fails to load; the avatar Remove control is named. Added regressions for avatar denial (Cashier, Kitchen, Cashier + Kitchen, guest, inactive Super Admin), spoofed-content uploads, duplicate races, and Super Admin Store expense, Store inventory adjustment, and `store-session` channel parity. Complete suite **1,513 tests / 10,127 assertions**, frontend **114 passed**; Pint, PHPStan, lint, TypeScript, build, and `git diff --check` passed. Disposable-schema PostgreSQL verified the two additive migrations (fresh, rollback, reapply, existing rows) and atomic Staff creation; the Void, Close Store, and Pay Now PostgreSQL harnesses passed. The normal local development database was not reset.

- [ ] Dashboard
- [ ] Audit Trail
- [ ] Void Orders
- [ ] Access Control
- [ ] Settings / system controls
- [ ] Cross-branch visibility
- [ ] Protected Super Admin authorization

---

## Phase 19 — Reporting & Performance Hardening

- [ ] Query/index review
- [ ] Pagination
- [ ] Report aggregation
- [ ] Cache safe read-heavy data
- [ ] Queue heavy exports
- [ ] Eliminate N+1 issues
- [ ] Realtime payload optimization
- [ ] Product image optimization verification
- [ ] Owner all-branch query optimization
- [ ] POS performance verification

---

## Phase 20 — Final Hardening

- [ ] Full RBAC review
- [ ] Full branch-isolation test pass
- [ ] Payment concurrency test pass
- [ ] Inventory race-condition test pass
- [ ] Store Open concurrency test pass
- [ ] Store Close reliability test pass
- [ ] QR archive test pass
- [ ] Realtime reconnect test pass
- [ ] 360px QA
- [ ] 390px QA
- [ ] 430px QA
- [ ] Tablet QA
- [ ] Desktop QA
- [ ] Staging validation
- [ ] Backup / restore verification
- [ ] Production health checks
- [ ] CI green
- [ ] Production readiness approved

---

# Completion Rule

A checkbox may be marked complete only when:

- Implementation is complete
- Relevant automated tests pass
- Authorization is correct
- Branch isolation is verified where relevant
- Data-integrity requirements are satisfied
- UI loading/error states are handled where relevant
- Responsive QA is completed where relevant
- Realtime behavior is verified where relevant

Do not check items merely because the standalone prototype already contains the UI.


### Phase 10/11 manual-QA refinement and architecture correction - 2026-09-22

Implementation refined on feature/qr-ordering, preserving the later LAN HTTP UUID fix at 3c4bde0. The user has manually accepted the latest Phase 10/11 UI/UX direction. Phase 12 and full Phase 16 remain incomplete.

- New QR submissions use a locked per-Store-Session counter and QR-01 display. Official short number/reference remain null through submit, LOAD and Cancel LOAD, and are assigned inside Pay Now/Pay Later's existing atomic commitment. New references are BRANCH-MMDDYY-#### from a separate branch/Manila-date counter. Direct POS reservation, snapshots and historical identifiers remain intact.
- Added owned Cancel Loaded Order and eligible Archived RESTORE, with renewed deadline, session-conflict checks and compact staff/customer invalidations. No Payment, stock or Kitchen effects occur on submit/load/cancel/archive/restore. Pay Now creates payment/stock/ticket once; Pay Later activation creates stock/ticket once; settlement creates only payment.
- Cashier hierarchy is Dashboard/POS/QR Orders/Kitchen/History/Display, with permission checks and disabled History. Queue cards use provisional identity, optional names, order-type badges, one elapsed clock and LOAD/VIEW/DELETE; archived cards expose VIEW/RESTORE. Normal refresh is removed. Pay Now/Pay Later accept the same temporary loaded-QR name/table edits at commitment. Fabricated Walk-in labels were removed from the affected KDS/Ready/POS surfaces.
- Customer welcome/social links, category icons, cart icon/count, scoped top success toast, Confirm Order, order-type colors, tracking icons/actions and timestamped timeline were refined. Active-order browsing remains read-only; terminal reset and unpaid receipt restrictions remain authoritative. Tracking and receipt include Stay connected, and receipt returns to tracking. Prior paid receipts remain authorized after a new Order until their own exact 24-hour expiry.
- Partial Owner Settings reuses Branch Management and adds typed Receipt settings. Branch QR has QR/History tabs, stable kiosk link/image, independent QR toggle, open/copy controls, date filtering and bounded activity. The kiosk code is initialized from branch code and remains stable on later business-code edits; old UUID entry redirects. Anonymous credentials remain encrypted HttpOnly cookies, never kiosk URL content. Visit records exclude IP/device metadata and deduplicate opens for two minutes.
- LOAD now shares the branch state boundary, locks the cashier claim boundary and Order exclusively, and keeps the Store Session shared lock. Post-LOAD navigation requests only loadedQr/qrWaitingCount, avoiding a full catalog projection. Temporary instrumentation measured the local PostgreSQL action at 16.35, 15.10, 11.31, 12.05 and 11.65 ms, 16 queries each, with null broadcast transport. Instrumentation was removed. The user's approximately 3-second observation is an end-to-end baseline; its exact transport/browser cause was not reproduced, so a measured end-to-end before/after improvement or guaranteed sub-second UI is not claimed.

Verification:

- Focused QR/direct POS/payment/foundation run: 207 tests / 1,521 assertions passed. Later focused QR/draft coverage: 153 tests / 1,116 assertions passed. Owner branch correction: 29 tests / 237 assertions passed. Customer Store State: 11 tests / 138 assertions passed. These overlap and must not be summed.
- Full Laravel suite ran ONCE: 1,213 tests, 7,856 assertions; 1,212 passed and one old branch-list QR URL assertion failed. The assertion was updated to the approved canonical kiosk URL; all 29 affected BranchManagement tests then passed. The full suite was not repeated, honoring the requested single full run.
- Frontend behavior tests: 43 passed. PHPStan: zero errors. Pint, frontend lint (124 files), TypeScript, production build and git diff --check passed. Existing optional fontaine/build timing and Node experimental warnings are non-blocking.
- PostgreSQL independent-worker harness passed duplicate submit/LOAD, concurrent provisional numbering, LOAD versus stale archive, Cancel LOAD versus Pay Now/Pay Later, concurrent official short/daily-reference allocation for both payment paths, restore versus new submission, restore versus Store Close, and branch/Store Close submission boundaries. Exactly-once stock/payment/ticket effects verified. All workers and temporary schemas cleaned up.
- SQLite and isolated PostgreSQL fresh/up/down/reapply passed with historical references preserved; SQLite's original Order CHECK constraints survived. Both additive migrations were applied to the local database without reset. No Supabase access/reset, dependency change, broad browser sweep or PR.

Known limits: pre-cutover QR Orders retain their already-issued official identity; archived legacy Orders with an official identity are intentionally not restorable. Downgrade refuses while null-identity provisional Orders exist, preserving their history. Unconfigured Facebook/Website links are disabled until configured. Receipt settings support a default brand logo and validated custom upload/replacement/removal with a visibility toggle; customer receipts save as PNG. Printer hardware, Phase 12 proof/history and the rest of Phase 16 remain deferred. No new end-to-end LOAD latency measurement is claimed.

Manual QA: open the permanent branch QR and verify legacy-link redirect; toggle QR OFF while store remains open; inspect welcome links/menu/category icons/cart/Confirm Order; submit and verify QR-01 without official identifiers; test existing-cart LOAD protection, Cancel LOAD, DELETE/RESTORE and conflicts; exercise Pay Now and Pay Later name/table edits and identifier transition; advance and roll back Kitchen stages while watching customer tracking; browse without adding another Order; settle Pay Later once; open/save a paid receipt and begin another Order; inspect receipt settings and dated QR history; disconnect/reconnect; check phone/tablet/desktop composition. The user accepted the latest UI/UX direction before this final audit.


### Phase 10/11 final audit and release gates - 2026-09-22

This record supersedes the earlier incomplete full-suite and pending-manual-QA reports. The user accepted the latest UI/UX direction; this audit did not redesign it or run broad browser visual QA. Phase 12, Close Store, and full Phase 16 remain incomplete; Owner Settings remains the partial Branch Management/Receipt slice.

- Audited all six feature commits and the complete 88-file feature diff from `dev`, starting at `30d64432ab44ccbefb72cc18a5b50286042f4931`. Refreshed origin: the starting branch was six commits ahead and zero behind `origin/dev`, and matched its remote feature branch.
- Corrected QR Pay Later metadata validation so an inactive original table can be replaced or cleared at commitment, matching Pay Now; retaining an inactive table is still rejected atomically. Disabled QR submissions now truthfully distinguish QR unavailability from Store Closed. Owner Orders Placed uses original creation time so restore cannot move historical counts to another date.
- The user's tablet report reproduced in browser logs as `crypto.randomUUID is not a function` on the Cashier IP/HTTP page. POS Add to cart, Pay Now and Pay Later now reuse the existing cryptographically random LAN-compatible UUID helper. Production assets were rebuilt, and the user was told to refresh the tablet. A live tablet retest is not claimed.
- Corrected the migration harness to roll back all four Phase 10/11 migrations, explicitly verify receipt-logo column removal/reapplication, and exercise the identity migration with historical rows. Added the missing overlapping same-cashier/two-LOAD case with exact winner, claim, identity and side-effect assertions.
- Identity architecture remains intact: per-Store-Session QR sequence only at submission; official short number and branch/Manila-date reference allocated in the shared Pay Now/Pay Later transaction; direct POS early reservation preserved. Submit/LOAD/cancel/archive/restore have no payment, stock or Kitchen effects. Snapshots are preserved; commitment and settlement remain exactly once.
- Anonymous cookie hashing/expiry/branch ownership, narrow tracking authorization, customer-safe projections, branch/RBAC enforcement, receipt settings/logo validation and cleanup, exact 24-hour receipt access including prior owned receipts, after-commit rescued realtime and reconnect refetch were reviewed. PNG captures only the receipt card with solid background, images and 2x resolution; export failures are caught by the UI.
- Focused QR suite: **72 passed / 607 assertions**. The first complete Laravel gate found seven stale exact Store payload assertions missing the accepted `is_open` field; those were corrected without weakening privacy checks. Focused Store-state suite then passed **11 tests / 138 assertions**. The required final complete rerun of `php artisan test --compact` passed **1,225 tests / 7,991 assertions; 0 failures, 0 errors, 0 skipped**.
- Complete frontend verification: **47 Node tests passed**, zero failures/skips; `npm run check:frontend` passed with no warnings/errors across **125 files**; `npm run types:check` passed; `npm run build` succeeded. `vendor/bin/pint --dirty --format agent` passed. `vendor/bin/phpstan analyse --no-progress --memory-limit=1G` passed with **zero errors**. Whitespace checks passed.
- SQLite fresh/up/down/reapply preserved historical identifiers and existing Order CHECK constraints. PostgreSQL fresh/up/down/reapply and **15 independent-worker concurrency scenarios** passed, covering duplicate submit/LOAD/commit, same-cashier claims, provisional numbering, LOAD/archive, cancel versus both commitment paths, both official-identity allocation paths, restore conflicts and Store/branch boundaries. Temporary schema cleanup passed. Only isolated local/test databases were used; Supabase was not accessed or reset.
- Queue/history pagination and eager loading avoid obvious N+1 queries. Catalog and Owner branch lists retain existing full-collection behavior; no speculative optimization or new latency claim. Existing localhost strings in UI are URL-parsing bases, not network destinations. No new secrets, environment files, debug instrumentation, screenshots, binaries or database credentials were found in the commit diff.
- Non-blocking limits remain: legacy officially numbered archived QR orders cannot restore; downgrade refuses while provisional rows exist; unconfigured social links remain disabled; printer hardware and later phases remain deferred. Reverb and the scheduler remain runtime requirements. Optional fontaine/build-timing and Node mock-timer notices are non-blocking. No PR was opened.


## Phase 10 correction: POS digital receipt QR handoff

POS paid receipt -> Show QR -> temporary signed public digital receipt. This replaces the Phase 6 placeholder for direct POS and Customer QR-origin paid Orders, including Pay Later after settlement. The cashier endpoint requires authentication, pos.access and authorized active-branch ownership, paid status and both official identifiers.

The relative signature authorizes only one receipt. BaconQrCode encodes the current request origin (including LAN IP/port) plus the signed path. Availability ends at the existing payment timestamp + 24 hours, never 24 hours from opening Show QR. Tampered paths/signatures return 403/404; expired receipts return 410. The public route is throttled and serves private/no-store responses without employee shared props or a Customer QR cookie. Existing session-owned Customer QR receipt authorization remains unchanged.

The standalone receipt-only page reuses the customer receipt card, persisted branch name/address/contact/footer/logo settings, official Order number/REF, item and payment snapshots, and receipt-card-only 2x PNG exporter. POS QR has loading, retry, expired and Back states. Phase 12 remains deferred. No migration or new dependency is required.

USER MANUAL QA REQUIRED: Open normal POS -> create Pay Now order -> View Receipt -> Show QR -> scan using a second phone/tablet -> confirm the public receipt opens without login, correct REF/items/payment/branding -> save PNG. Repeat for a loaded Customer QR Order and settled Pay Later Order. Final device/visual acceptance is pending; no broad browser QA was performed.

Verification: focused receipt/payment/Customer QR regression passed 206 tests / 1,747 assertions; focused frontend receipt/PNG passed 5 tests; full Laravel passed 1,240 tests / 8,117 assertions with zero failures/errors; complete Node frontend suite passed 51 tests. Pint, PHPStan, frontend lint (zero warnings), TypeScript, production build and diff check passed. An initial full-suite run overlapped the build removing a font CSS asset; the log confirmed that transient rendering failure, the affected 20-test file passed independently, and the full suite passed with assets stable. No payment/concurrency logic changed, so the PostgreSQL concurrency harness was not rerun. Status: READY FOR MANUAL QA; device scan/PNG visual acceptance remains pending.

## Phase 12 implementation delivery - 2026-09-22

Implemented cashier-only, active-branch Transaction History with server pagination/search/date/Kitchen/Payment/Order Type/Method filtering, full-dataset metrics, local Tiled/List preference, fresh detail reads, receipt print/share eligibility, and intentionally disabled Phase 13 Void. Historical committed Orders remain readable across Store Sessions; edits, balance settlement, and proof mutation require the Order's current OPEN Store Session.

Committed edits retain unchanged item/modifier/name/price snapshots, price new or reconfigured lines from the current catalog, aggregate deterministic tracked inventory deltas, append `order_edit_delta` movements, preserve the KitchenTicket and lifecycle timestamps, and use Order version plus idempotency replay. Same-total edits add no money row; higher totals become authoritative outstanding balance; lower paid totals append explicit adjustments while original Payments remain. Payment attempts have stable grouping/context, and invoice proofs attach only to real Cashless Payment rows in private storage with authorized camera/file add, view, replace, remove, cleanup, and audit.

Automated delivery gates passed: focused Phase 12 Pest tests, 180 adjacent Pay Now/Pay Later/Kitchen/receipt regression tests, 54 Node frontend tests, Pint, PHPStan (1 GB runner, zero errors), frontend lint (zero warnings), TypeScript, production build, PostgreSQL fresh/rollback/reapply and concurrency cases A-I. The separate user device/visual walkthrough and final implementation audit remain pending, so **Phase 12 is not marked complete**. Status: READY FOR USER MANUAL QA.

### Phase 12 final audit and release gates - 2026-09-22

User manual UI/UX QA was accepted before this audit; no broad browser pass or speculative redesign was performed. The complete `dev...HEAD` Phase 12 diff and the decoded `context/design/pos.html` bundle were source-reviewed. The audit corrected two integrity defects: payment-method filtering and display now use the persisted payment context rather than same-second timestamps, so an original Cash payment plus a later Cashless balance payment is not reported as Split; KDS prunes UPDATED IDs that no longer exist on its authoritative board while retaining the badge for tickets still present until reload.

The complete Laravel suite passed **1,255 tests / 8,267 assertions** with zero failures, errors, or skips. The complete frontend behavioral suite passed **62 tests**. Pint, PHPStan (zero errors), frontend lint (zero warnings), TypeScript, production build, whitespace checks, and the isolated local PostgreSQL Phase 12 migration/concurrency harness (fresh, rollback, reapply, cases A-I, schema cleanup) passed. No Supabase access/reset, Phase 13 Void implementation, dependency change, secrets/artifacts, or PR was created. **Phase 12 is COMPLETE and ready for PR.**
