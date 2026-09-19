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

- [x] Dine In / Take Out
- [x] Product browser
- [x] Search / categories
- [x] Product customization
- [x] Modifier handling
- [x] Cart
- [x] Notes
- [x] Branch-valid table handling
- [x] Order Information
- [x] Order numbering
- [x] Server-side total calculation
- [x] Historical order snapshots

Phase 5 implementation verification (2026-09-19):

- These checks record implemented behavior and passing automated verification. **Final Phase 5 acceptance remains pending the separate final QA/audit.** Phase 6 and later checklists are unchanged; no PR is opened by this implementation.
- Added the frozen UUID `branch_tables`, `orders`, `order_items`, and `order_item_modifiers` foundation, typed enums/models/factories, exact decimal casts, branch/order uniqueness, lookup indexes, nonnegative money/positive quantity/version constraints, restrictive historical-parent foreign keys, and nullable catalog references that retain snapshots after catalog deletion. The guarded, idempotent local/testing seeder adds MAIN/QAVE tables without seeding orders; its production guard remains covered.
- `CreatePosDraftOrder` reloads and authorizes the persisted cashier/combined role, active assignment, permission, and active branch; locks the branch and checks/locks an OPEN Store Session inside the transaction. It validates current-branch active Dine In tables or a nonblank Take Out label, active products/categories/branch availability, assigned active modifier groups/options, duplicate selections, single/min/max selection rules, notes, and strict positive integer quantities. Failed validation or insertion rolls back all draft rows.
- Server-authoritative pricing uses current branch overrides and integer cents with numeric(14,2) overflow guards; line totals include modifier deltas per product quantity. Historical product/group/option names, base prices, deltas, quantities, notes, and totals are persisted. Order numbers use `YYMMDD-` plus eight random uppercase characters, protected by branch-scoped uniqueness and at most five retries of only the expected order-number conflict; nested transactions provide a PostgreSQL savepoint. Collision/exhaustion tests pass on SQLite; a PostgreSQL order-collision concurrency audit remains for final QA.
- Stock is freshly read and quantities are aggregated across configurations of the same product. Missing/zero/insufficient tracked stock rejects drafts; untracked products stay independent of balances. Drafts remain `draft` / `unpaid` / `not_sent`, with null payment term, Store Session ownership, and commitment timestamp. No deduction, reservation, inventory movement, payment, Kitchen ticket, broadcast, or operational session mutation is added. Future commitment must revalidate stock.
- Reviewed the decoded standalone `context/design/pos.html` template and aligned the Phase 5 UI with Poppins, compact product cards, near-black headers/actions, red prices/quantities, Dine In/Take Out colors, orange notes, desktop side cart, mobile floating cart, and full-height mobile customization. Reused the existing catalog search/categories/images, dialogs, and Wayfinder/Inertia forms. Cart add/edit/remove, empty state, order information, processing protection, preserved-cart validation errors, and persisted summary are implemented; payment controls are visibly disabled.
- Focused Phase 5 tests: **98 passed / 410 assertions**. Store/catalog/inventory regression selection: **173 passed / 1168 assertions**. Full `php -d memory_limit=1G artisan test --compact -d memory_limit=1G`: **905 passed / 5042 assertions**, up from 807/4624. Coverage includes authorization, branch isolation, stale state, exact totals/overflow, historical snapshots, schema constraints, bounded collisions, transactional rollback, and repeated drafts without stock reservation. At 1/30/100 products, customization catalog reads stay at most six queries and draft SELECTs at most eighteen; the existing non-customization catalog stays at most four queries.
- Pint (`--dirty --format agent`), PHPStan (`--memory-limit=1G`), frontend lint (85 files), TypeScript, and production build passed. Existing optional fontaine and build-plugin timing warnings remain non-blocking. No dependency or Supabase change.
- Local PostgreSQL 18.4 additive migration passed. Metadata confirmed all five numeric(14,2) columns, fourteen CHECK constraints, eight restrictive and two null-on-delete foreign keys, unique keys, and expected indexes. SQLite in-memory fresh migrations passed with `DB_URL=null`. The existing isolated PostgreSQL harness migrated every table, passed its inventory concurrency regressions, removed its random schema, and left normal local data intact; no normal-database reset occurred.
- Live Chrome QA passed CLOSED QAVE read-only Browse/no order start; OPEN MAIN order-type requirement, search/category filtering, quantity/notes, add/edit/remove/empty cart, MAIN-only active table choices, Dine In Table 3 draft at PHP 60.00, Take Out required-label validation and draft at PHP 20.00, snapshot-based summaries, and disabled payment controls. Both clearly labeled QA drafts remain locally. Read-only database checks confirmed draft defaults and unchanged Rice inventory quantity/version/timestamp/movement history. POS visual/overflow checks passed at 360px, 390px, 430px, 820px tablet, and 1440px desktop; the temporary viewport override was reset.
- Remaining manual QA: required/multiple modifier interactions and modifier summary rendering, unavailable/out-of-stock product clicks, stale stock/store changes while a cart is open, and broad populated-catalog/long-content visual checks. The local catalog has only one untracked product without modifiers; automated server cases pass, but these live scenarios are not claimed. Browser URL policy blocked directly rendering the standalone local `pos.html`; source-based reference comparison and live application visual checks were completed, not a rendered side-by-side comparison. Final QA must independently review these gaps and acceptance.

Phase 5 standalone parity correction (2026-09-19):

- Supersedes the earlier Phase 5 visual-parity claim above. The mismatch came from retaining the generic workspace header, wrapped Menu/catalog card, detached cart and successful-create redirect to `workspaces/order-summary`; matching individual colors did not reproduce the standalone composition or flow.
- Extracted the `<script type="__bundler/template">` payload with a DOTALL regular expression and decoded its JSON string with Python `json.loads`, including escaped markup. Wrote only temporary decoded-reference and element-mapping files outside the repository. Inspected the actual markup and responsive style computations (94px rail, 60/66px top bar, 336/382px cart, 768/1024px breakpoints, 420px gate and 860px product dialog). The standalone artifact and bundler runtime were not modified or copied into application code.
- Rebuilt the Cashier shell with the real logo, near-black left rail, selected POS navigation, compact top bar, real branch/profile data, compact mobile branch switcher and floating mobile navigation. New Order is in the top bar and immediately opens the stacked Dine in / Take out gate. Unimplemented Dashboard, QR Orders and Transaction History remain explicitly disabled; no fake counts, Kitchen state or notifications.
- POS is a full-height integrated product/cart split with independently scrolling product and cart bodies. Category controls scroll horizontally, show real counts and sit beside (or above on narrow screens) compact search with clear/reset. Cards use stable 3:2 media, category icon fallbacks, real branch prices, unavailable/low-stock state and in-cart counts. No menu images were processed. Catalog descriptions are now exposed for customization.
- Cart uses Current order context, red quantity/amounts, modifier details, orange notes, edit/remove, empty state, Dine In / Take Out segments, item/subtotal/total hierarchy and the reference Save-left / Pay-right placement. Switching an editable cart clears table/customer information; the backend still rejects a Take Out table or missing label. New Order with an existing cart opens the type gate first, then confirms clearing items before applying the chosen type.
- Customization now follows the reference Back/title/Close header, media/monogram, red price, availability/description, quantity-first controls, required group badges, single/multiple options, deltas, special instructions and fixed Add/Update amount footer with Remove when editing. It is fullscreen on mobile and two columns on wide screens.
- Order Information is an in-place modal (mobile bottom sheet), with a serving-type banner, customer label, current-branch table choices, summary hint, Proceed and Cancel. POST uses the unchanged `CreatePosDraftOrder`, flashes only serialized persisted summary fields and redirects to `workspaces.cashier`. The same modal displays the returned order number/items/modifiers/totals; the cart then uses persisted snapshot rows rather than matching them to client rows by index. Initial flash data also initializes the modal after an asset-version remount. The protected direct show route remains recovery-only; normal flow does not navigate to a summary page.
- Intentional Phase 5 differences: an extra Order information control creates only a draft while Save/Pay remain disabled with an explanation. Persisted snapshots are read-only (new work starts through New Order); no draft-update endpoint or operational commitment was added. The standalone's short mock numbers, prices, table data, role switcher, payment/Kitchen/notification states and images are not substituted for real application data. Dine In requires a real active branch table even though the mock permits a label alone. Payment remains prepared for activation within this modal workflow in Phase 6, with no payment route/page or fake commit introduced.
- Live Chrome comparison used the actual unmodified standalone served temporarily on loopback, not only its source. Compared its rail/top bar, product split/cards, gate, customization and Order Information against the application. Checked 360px, 390px, 430px, 820px tablet and 1440px desktop; application document width stayed within the viewport. Verified mobile fullscreen customization/cart, bottom-sheet information, desktop/tablet rail and integrated cart, real category filtering, New Order gate, quantity/notes, cart edit/recalculation/remove/empty state, selected serving type, table/label clearing on switch, required Take Out label, disabled payment and CLOSED QAVE Browse/no New Order. Restored MAIN and removed viewport overrides.
- Local QA drafts retained: `260919-KL8CXJC2` (Take Out, PHP 20.00) and `260919-IOIZ507L` (Dine In Table 2, PHP 40.00), clearly labeled as Phase 5 parity QA. The first browser attempt exposed an asset-version refresh edge case, which was corrected; the subsequent successful save showed the persisted summary modal at the unchanged POS URL. Read-only database verification confirmed Rice stock remained 0, version 4, timestamp `2026-09-19 08:06:16`, with draft/unpaid/not_sent states and no stock deduction.
- Automated verification: focused POS/order foundation plus Cashier store/catalog/inventory regression **171 passed / 1180 assertions**; full `php -d memory_limit=1G artisan test --compact -d memory_limit=1G` **906 passed / 5107 assertions**. Tests now assert redirect to POS, persisted Inertia flash values and one-time consumption, retained direct-read security/snapshots, and catalog description fields. Existing missing-type, CLOSED-store, invalid-table/Take-Out-label, stock, modifier, exact-money, branch-isolation and absent-payment/Kitchen regressions still pass. Pint, PHPStan with 1G, frontend lint, TypeScript and production build pass; existing optional fontaine/build timing notices remain.
- Remaining separate final QA: live modifier selection and unavailable/stale catalog/store cases (the local catalog has no assigned active modifier groups), long-content stress, PostgreSQL order-number concurrency audit, and independent final acceptance. This correction is close structural/interaction parity, not a claim of pixel-perfect 1:1. **Phase 5 FINAL acceptance remains pending. Phase 6 has not started.**

---

## Phase 6 — Pay Now

- [ ] Cash
- [ ] Cashless
- [ ] Split
- [ ] Payment modal
- [ ] Amount received / change
- [ ] Underpayment validation
- [ ] Payment idempotency
- [ ] Atomic payment transaction
- [ ] Inventory deduction
- [ ] Kitchen ticket creation
- [ ] Payment success state
- [ ] Receipt
- [ ] Duplicate-submit tests

---

## Phase 7 — Pay Later

- [ ] Save as UNPAID / PAY LATER
- [ ] Immediate inventory deduction
- [ ] Immediate Kitchen ticket
- [ ] Transaction History entry
- [ ] Later payment settlement
- [ ] Prevent second inventory deduction
- [ ] Prevent duplicate Kitchen ticket
- [ ] Pay Later idempotency tests

---

## Phase 8 — Kitchen / KDS

- [ ] Kitchen board
- [ ] KITCHEN state
- [ ] PREPARING state
- [ ] READY state
- [ ] DONE state
- [ ] Lifecycle validation
- [ ] Fullscreen mode
- [ ] Realtime Kitchen updates
- [ ] Order-edit Kitchen updates
- [ ] Branch isolation
- [ ] No financial data exposure

---

## Phase 9 — Customer Display

- [ ] Preparing order numbers
- [ ] Ready order numbers
- [ ] Branch-scoped display
- [ ] Realtime updates
- [ ] Reconnect/refetch
- [ ] Safe public payload

---

## Phase 10 — Customer QR Ordering

- [ ] Branch QR entry
- [ ] Store Closed state
- [ ] Welcome
- [ ] Menu
- [ ] Product customization
- [ ] Cart
- [ ] Dine In / Take Out
- [ ] QR submission
- [ ] No stock deduction on initial submission
- [ ] No Kitchen ticket on initial submission
- [ ] Anonymous QR session
- [ ] One active order/session
- [ ] Active tracking
- [ ] Receipt
- [ ] 24-hour receipt expiry
- [ ] 30-minute Archived / Unclaimed behavior
- [ ] Customer tracking security tests

---

## Phase 11 — QR Orders Staff Flow

- [ ] Active QR queue
- [ ] Search
- [ ] Realtime arrival
- [ ] QR order detail
- [ ] LOAD
- [ ] Branch-safe LOAD
- [ ] Archived / Unclaimed handling
- [ ] Archive/Delete confirmation
- [ ] Pay Now after LOAD
- [ ] Pay Later after LOAD
- [ ] Duplicate LOAD protection

---

## Phase 12 — Transaction History & Editing

- [ ] Transaction list
- [ ] Search
- [ ] Filters
- [ ] Transaction detail
- [ ] Pay Later settlement from history
- [ ] Edit committed order
- [ ] Inventory delta calculation
- [ ] Inventory compensating movement
- [ ] Higher-total delta payment
- [ ] Higher-total Pay Later delta
- [ ] Lower-total correction
- [ ] Receipt actions
- [ ] Edit audit trail
- [ ] Kitchen update after relevant edit

---

## Phase 13 — Void & Audit

- [ ] Void authorization
- [ ] Void reason
- [ ] Re-auth / protected confirmation
- [ ] Compensating inventory restoration
- [ ] Original order retained
- [ ] Payment history retained
- [ ] Audit Trail
- [ ] Super Admin Void Orders
- [ ] Void authorization tests

---

## Phase 14 — Store Purchases / Expenses

- [ ] Current Store Session expense list
- [ ] Add Store Purchase / Expense
- [ ] Description
- [ ] Amount
- [ ] Cash payment source
- [ ] Cashless payment source
- [ ] Note / reason
- [ ] Optional receipt image
- [ ] Optional inventory product link
- [ ] Optional quantity
- [ ] Restock inventory movement
- [ ] Cash closing-balance effect
- [ ] Cashless closing-balance effect
- [ ] Store Session/user trace

---

## Phase 15 — Close Store & Reconciliation

- [ ] Close Store entry point
- [ ] Block unresolved UNPAID / PAY LATER
- [ ] Block Kitchen non-DONE orders
- [ ] Allow unclaimed QR without blocking
- [ ] Opening Cash summary
- [ ] Opening Cashless summary
- [ ] Cash sales
- [ ] Cashless sales
- [ ] Split breakdown
- [ ] Store purchases/expenses summary
- [ ] Relevant adjustments / void effects
- [ ] Expected Closing Cash
- [ ] Expected Closing Cashless
- [ ] Closing Cash input
- [ ] Closing Cashless input
- [ ] Cash variance
- [ ] Cashless variance
- [ ] Shortage blocks normal close
- [ ] Overage requires note
- [ ] Archive remaining unclaimed QR
- [ ] Atomic Store Close transaction
- [ ] Store Close audit
- [ ] `store.closed` broadcast after commit
- [ ] Customer QR switches to Store Closed
- [ ] Concurrent Close Store protection

---

## Phase 16 — Owner Workspace

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
- [ ] Store Session summaries
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
