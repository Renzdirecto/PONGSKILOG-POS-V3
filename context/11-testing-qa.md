# PONGSKILOG POS V3 — Testing & QA

**Status:** FROZEN — Batch 4  
**Depends on:** Frozen Context `01`–`10`

---

## 1. QA Principle

A feature is not Done because the UI works once.

Production readiness requires evidence across:

- Business logic
- Database integrity
- Authorization
- Branch isolation
- Concurrency
- Realtime
- Responsive UI
- Failure/recovery

High-risk operations require automated tests plus manual acceptance testing.

---

## 2. Test Layers

### Unit / Domain

For:

- Totals
- Change
- Variance
- Inventory delta
- Closing reconciliation calculations
- State transitions

### Feature / Integration

For:

- Authentication
- Authorization
- Store Sessions
- Orders
- Payments
- Inventory
- Kitchen
- QR
- Close Store
- Reports

### Browser / UI

For critical user journeys.

### Database Constraint Tests

For:

- One open Store Session
- Unique payment idempotency
- One Kitchen ticket/order
- Branch/product uniqueness
- Transfer constraints

---

## 3. Authentication & RBAC

Test:

- Valid login
- Invalid credentials
- Disabled account
- No branch assignment
- Session expiration
- Cashier blocked from Owner pages
- Kitchen blocked from payments
- Owner blocked from dedicated Super Admin controls
- Unauthorized branch URL/request manipulation rejected
- Cashier + Kitchen permission union works as intended

---

## 4. Store Open

Test:

- Closed branch shows Browse/Open Store
- Browse rejects backend mutation
- Opening Cash required
- Opening Cashless required
- Store Open succeeds
- Existing Store Session reused by later Cashier
- Concurrent Store Open results in exactly one active session
- Store Open audit created

---

## 5. Closed Store Enforcement

When Store Session is Closed:

- New POS order blocked
- Payment blocked
- Pay Later blocked
- Void/operational mutation blocked as applicable
- Store Purchase blocked
- Customer QR submission blocked
- Customer QR displays Store Closed

---

## 6. Product / Catalog

Test:

- Branch price override
- Branch availability
- Global disabled product
- Out-of-stock
- Modifier validation
- Historical snapshot stability
- Missing/broken image fallback
- Optimized image usage

---

## 7. Inventory

Test:

- Correct branch deduction
- Correct current balance
- Ledger movement created
- Negative stock blocked
- Manual adjustment audited
- Concurrent last-unit sale safe
- Branch A never affects Branch B

---

## 8. Pay Now — Cash

Test:

- Exact payment
- Overpayment/change
- Underpayment blocked
- Server recalculates total
- Double-click protected
- DB failure rolls everything back
- Stock/Kitchen created only after successful commit

---

## 9. Pay Now — Cashless

Test:

- Manual Cashless confirmation
- Correct payment record
- Duplicate request protected
- No unsupported provider reference requirement

---

## 10. Pay Now — Split

Test:

- Cash + Cashless = total
- Change only from Cash leg
- Both payment records commit atomically
- Partial commit impossible

---

## 11. Pay Later

On Save Pay Later verify:

- Order = UNPAID / PAY LATER
- Inventory deducted immediately
- Kitchen ticket created immediately
- Transaction History entry exists
- Realtime Kitchen event occurs after commit

Later settlement verify:

- Payment added
- Order becomes Paid
- No second stock deduction
- No duplicate Kitchen ticket

---

## 12. Pay Later Edit

Example:

Before:
- 2 Pork

After:
- 1 Pork
- 1 Chicken

Expected:

- Pork +1
- Chicken -1

Verify:

- Prior movement remains
- New delta movements correct
- Current stock correct
- Audit correct
- Kitchen update correct
- Insufficient stock edit rejected

---

## 13. Customer QR

Test:

- Correct branch QR
- Open Store enables submission
- Closed Store disables submission
- Initial QR submission is unpaid
- No inventory deduction
- No Kitchen ticket
- One active order/session
- Tracking token cannot expose another customer order

---

## 14. QR Archive / Unclaimed

Test:

- No-action QR reaches Archived / Unclaimed after 30 minutes
- Archive removes it from active queue
- Archive does not affect stock/payment/Kitchen
- Record remains in history
- Store Close archives remaining unclaimed QR
- Archived order cannot be replayed across branch/session incorrectly

---

## 15. QR LOAD

Test:

- Same-branch only
- One LOAD flow
- No duplicate QR-specific payment page

After LOAD:

### Pay Now
- Payment
- Inventory
- Kitchen

### Pay Later
- UNPAID / PAY LATER
- Inventory
- Kitchen

---

## 16. Kitchen

Test:

- Paid order enters Kitchen
- Pay Later enters Kitchen
- QR submission alone does not
- Valid lifecycle:
  KITCHEN → PREPARING → READY → DONE
- Invalid transition rejected
- Financial data absent
- Branch isolation
- Reconnect refetches authoritative active tickets

---

## 17. Customer Display

Test:

- Correct branch
- Preparing projection
- Ready projection
- No prices
- No private data
- Reconnect works

---

## 18. Transaction Editing

### Same total

- No additional payment

### Higher total

- Delta Pay Now works
- Delta Pay Later works where allowed

### Lower total

- Explicit correction/adjustment
- Original payment retained

All:

- Inventory delta correct
- Audit exists
- Kitchen ticket not duplicated

---

## 19. Void

Test:

- Active assigned Cashier/Cashier+Kitchen initiator and current Store Session required
- Global four-digit hashed PIN required; wrong/missing PIN and inactive/non-Super-Admin PIN owner rejected
- Initiator and configuring Super Admin authorizer remain distinct
- Reason required
- Original order retained
- Payment history retained
- Compensating inventory movement created
- Audit created
- Super Admin Void Orders protected
- Exact replay produces one Void/audit/restoration; changed intent conflicts
- PostgreSQL races cover duplicate/distinct Void, edit, settlement, Kitchen, sorted Product restoration, and Store Session close boundary
- Inventory, Void row, Order update, or audit failure rolls back every critical effect

---

## 20. Store Purchase / Expense

Test:

- Must belong to active Store Session
- Cash expense reduces expected Closing Cash
- Cashless expense reduces expected Closing Cashless
- Restock increases inventory
- Normal expense does not change inventory
- Optional receipt works
- Branch/user trace retained

---

## 21. Close Store Pre-Checks

Test:

### Unpaid Pay Later exists
- Close blocked

### Kitchen order not DONE
- Close blocked

### Unclaimed QR exists
- Close is not blocked

---

## 22. Closing Reconciliation

Verify calculations for:

- Opening Cash
- Opening Cashless
- Cash sales
- Cashless sales
- Split payment legs
- Store purchases/expenses
- Relevant adjustments
- Expected Closing Cash
- Expected Closing Cashless
- Actual Closing Cash
- Actual Closing Cashless
- Variances

---

## 23. Closing Variance

### Exact

- Close allowed

### Shortage

- Normal close blocked
- Correction/review required

### Overage

- Note required
- Close allowed after valid note

---

## 24. Successful Close Store

After commit verify:

- Store Session = Closed
- Closing values saved
- Expected values saved
- Variances saved
- Audit created
- Remaining unclaimed QR archived
- Operational mutations blocked
- QR ordering disabled
- `store.closed` emitted after commit

If transaction fails:

- Session remains Open

---

## 25. Multi-Branch Isolation

Mandatory scenario:

Create Branch A and Branch B.

Verify:

- A Cashier cannot see B orders
- A Kitchen cannot see B tickets
- A QR cannot create/update B order
- A inventory does not affect B
- A realtime event does not leak to B
- Owner can intentionally view All Branches
- Super Admin can intentionally view All Branches

---

## 26. Stock Transfer

Test:

- Source != destination
- Valid quantity
- Valid state transitions
- No double receive
- Source movement traceable
- Destination movement traceable
- Completed transfer history immutable in normal operation

---

## 27. Realtime

Test:

- Broadcast after commit only
- Failed transaction emits no success event
- Duplicate event does not duplicate UI
- Older version cannot overwrite newer state
- Branch channel authorization
- Reconnect refetch
- Customer tracking scope
- Safe Customer Display payload

---

## 28. Concurrency

Test at minimum:

- Two Cashiers Open Store simultaneously
- Two Cashiers buy last stock unit
- Double-click Pay Now
- Double-click Pay Later
- Duplicate QR LOAD
- Concurrent committed-order edit
- Duplicate transfer receive
- Concurrent Close Store attempt

Final state must remain valid.

---

## 29. Performance

Measure:

- POS initial product load
- Product search
- Product image load
- Pay Now commit
- Pay Later commit
- Kitchen update latency
- QR arrival latency
- Transaction pagination
- Owner dashboard filters
- Close Store reconciliation

Use realistic historical transaction and product-image volume.

---

## 30. Responsive QA

Minimum:

- 360px
- 390px
- 430px
- Tablet
- Desktop

Check:

- No horizontal overflow
- 44px practical tap targets
- POS density
- Modals/sheets
- Kitchen readability
- QR usability
- Store Open/Close forms
- Closing reconciliation readability

---

## 31. Failure / Recovery

Simulate:

- DB failure during Pay Now
- DB failure during Pay Later
- DB failure during Close Store
- Redis unavailable
- Reverb disconnected
- Queue delayed
- Storage/image unavailable
- Session expiration
- Browser refresh

No financial/inventory corruption is acceptable.

---

## 32. Release Gate

A feature/release cannot be considered production-ready until:

- Relevant automated tests pass
- Authorization tests pass
- Branch isolation passes
- Concurrency risk addressed
- Browser flow passes
- Mobile/tablet QA passes
- Realtime behavior verified where applicable
- No unresolved financial/inventory integrity defect
- Required audit evidence exists
- CI passes

## Phase 12 focused verification matrix

- History authorization/branch isolation, committed-only scope, server pagination, search/filter boundaries, and metrics independent of the current page.
- Same-total, higher-total, lower-total, unpaid Pay Later, and repeated-edit reconciliation with append-only Payment/Adjustment/Audit histories.
- Retained-price versus current-price item snapshots; aggregate tracked inventory delta, untracked no-op, insufficient-stock rollback, idempotent replay, stale version 409, closed/prior-session denial, and unchanged KitchenTicket lifecycle.
- Cash/Cashless/Split grouped attempts; exact outstanding settlement; duplicate replay; invoice allowlist/dimensions/size, private authorized stream, replace/remove cleanup, cash-row denial, and earlier-session read-only behavior.
- Compact after-commit POS/Kitchen events, reconnect refetch, temporary KDS UPDATED indicator, local-only view preference, camera fallback, and disabled Phase 13 Void.
- Required gates: focused Pest suite, adjacent Pay Now/Pay Later/Kitchen/receipt regression suite, PostgreSQL harness, Pint, PHPStan, frontend check, TypeScript, production build, migration fresh/up/down/reapply, whitespace and secret/artifact review.

## Phase 14 focused verification matrix

- Normal Cash/Cashless expense identity and complete-session exact totals; no fake Payment; matching a Product name never restocks implicitly; previous Session and foreign Branch records excluded.
- Cashier/Cashier+Kitchen allow paths; guest, Kitchen-only, Owner, unassigned, closed Session, foreign receipt/channel, and untracked Product denial; client Session identifier ignored in favor of the current OPEN Session.
- Stable idempotent replay, changed-intent 409, one optional item, exactly-once `store_purchase_restock`, no lost stock update, restrictive history, private receipt type/size/access/cleanup, Audit append, and no success event on rollback.
- Failure injection at item, inventory balance, movement, and Audit boundaries; all Expense/item/movement/balance/Audit effects roll back together.
- Frontend contract coverage for existing Store Open entry, reusable overview/add/detail views, multipart Wayfinder submission, offline block, compact private realtime invalidation, branch/event guard, coalescing, and reconnect refresh.
- Disposable SQLite fresh/rollback/reapply and isolated local PostgreSQL fresh/rollback/reapply. Independent PostgreSQL workers cover exact retry, changed intent, two same-Product restocks, different-Product restocks, and Expense versus future exclusive Close Store Session locking. Normal development data is never reset.
- The decoded standalone and implementation receive a source-level comparison at phone/tablet/desktop breakpoints. Final device/visual acceptance remains USER MANUAL QA; no broad automated browser sweep is claimed.

## Phase 16E Owner Operations verification matrix

- `OperationsIngredientConsumptionTest`: Pay Now / Pay Later exact fractional consumption once; settlement, Kitchen and payment events never move stock; negative stock allowed; missing recipe and direct resale; edit decrease/increase/size change/product replacement/recipe↔missing with exact deltas and retry; recipe changed then Void restores historical quantities; post-edit Void once and retried; database refuses duplicate sale/restoration rows; historical cost and Plan snapshots stable.
- `OperationsManagementTest`: Owner and Super Admin pages with URL Plan; Cashier/Kitchen/guest/inactive denied; All Branches read-only; cross-Branch isolation; Plan membership uniqueness and move; archive; fractional opening stock; base-unit lock; validation matrix; recipe size/product/ingredient rules; No recipe needed and Product-stock exclusivity; wastage/count correction with idempotency and audit; Catalog Inventory parity with Operations stock.
- `PamamalengkeTest`: top-up, reorder, none, setup, unknown cost, negative stock; server-computed plan list; Confirm writes one canonical expense + exact restocks + metadata; manual items never restock; recommended ≠ actual; idempotent retry and changed-payload 409; Store Session rule, zero total and missing cost blocked; shared ingredient one balance; latest cost for future estimates with historical COGS unchanged; Cash after purchases, Plan vs business expenses; Net Sales parity with Reports and voids excluded; Cashier and foreign list entries denied.
- `tests/operations-ui.test.ts`: exact quantity/money helpers, recipe preview rounding, profit divider (no write), Cash vs Profit wording, guarded checklist storage, bought-line selection, navigation section, URL Plan state, truthful empty states, checklist blocking and stable key, Inventory type filter, 44px targets. `super-admin-navigation.test.ts` covers the new Super Admin Operations section.
- `tests/verify-operations-postgres.php` (isolated schema, removed afterwards): numeric(18,4) columns and indexes, rollback/reapply, cases A–M, and real two-process races (duplicate Confirm replay; concurrent sale + wastage keep balance = ledger sum). Run it more than once: the sale + wastage race is order-sensitive and previously exposed a Branch/balance lock-order deadlock.
- The Pay Now read-bound test allows three constant extra reads (size modifiers, Plan membership, recipes); reads still do not grow with cart size.

### Phase 16E follow-up: Recipe configuration, Add-on effects and Recipe availability

- `ModifierGroupSemanticsTest`: only the Size group creates base variants; Add-on and Instruction options never become recipe sizes; a second active Size group is rejected on assignment and on role change/re-activation; inactive second Size groups and existing assignments stay valid; legacy duplicates are a configuration error (sizes, recipe save, catalog, Recipes page, sale); Instructions stay price-neutral and cannot carry effects.
- `RecipeModifierEffectsTest`: exact audited effect save/removal; option/product/Product-stock guards; archived-ingredient guard; base + Add-on consumption (one/multiple Ingredients, multiple Add-ons, item and modifier quantity); Instructions and no-effect Add-ons move nothing; unknown cost stays null; reusable Groups never affect other Products; historical effect survives effect edits for Voids and edits (including a no-effect Add-on); edit deltas for add/remove/replace Add-on, size change, quantity change, Instruction-only change; Void restores exactly.
- `RecipeCapacityTest` + `tests/Unit/RecipeServingsFormulaTest.php`: the formula (whole, fractional stock/recipe, limiting, zero, negative, missing balance); per-Size catalog capacity, Regular recipe, Recipe required without a count, out of stock only when no Size can be made, existing gates first, shared Ingredients, direct resale and never-recipe Products unchanged; selected-configuration capacity with Add-ons, Instructions and the rest of the cart; unavailable Add-on keeps the base sellable; POS endpoint counts for cashiers only; QR endpoint booleans with session only.
- `RecipeOrderValidationTest`: Pay Now / Pay Later cap with no partial effects; whole-order aggregation across sizes and products; the locked commit rejects a draft whose stock was sold meanwhile; Customer QR submit; edit validates only additional usage and reductions need no stock; existing negative balances; Void restores capacity; compact realtime payload.
- `OperationsManagementTest` adds the Recipes page states (Product stock vs No recipe needed vs missing, settings link, switching back, servings per Size and none for All Branches).
- `tests/recipe-availability.test.ts`: Group behaviour labels/help, one-Size-group hint, Recipes page states and sections, availability labels, option availability, quantity cap/errors, cart sharing, POS/QR wiring and 44px targets.
- `tests/verify-operations-postgres.php` adds the new tables/indexes and rollback of the follow-up migration, the no-oversell sale check, and real two-process races R-A (last stock, Pay Now ×2), R-B (two Products sharing Water), R-C (Size + Add-on drafts via Pay Later commit), R-D (usage-increasing edit vs sale) and R-E (`pg_stat_database.deadlocks` unchanged).

### Phase 16E Final QA verification (2026-09-24)

- `tests/verify-operations-postgres.php` (isolated random schema, removed afterwards) now also covers:
  - **S — legacy direct Product-stock deadlock audit.** Each writer is queued first behind a held row, then a Coke Pay Now. Before the fix, SA Catalog inventory adjustment, SB Store Purchase restock, SC Store Session inventory adjustment, SD plain Store Expense and SE Pay Later settlement each produced a real PostgreSQL deadlock (SQLSTATE 40P01); SF Void did not (it already took the Branch first). After the Branch-FOR-SHARE-first fix all six commit with zero deadlocks and ledger = balance.
  - **R-F** Void restoring vs sale consuming the same Ingredient; **R-G** Pamamalengke restock vs sale on the same Ingredient.
  - **G-A..G-D** Giveaway vs Pay Now for the last stock (one winner), direct-stock Giveaway vs Pay Now (no deadlock), duplicate Giveaway submit (one record, one deduction), two different reversal requests (restored once).
  - Giveaway migration rollback/re-apply, movement-type constraints, partial unique indexes and a guard against new PostgreSQL identifier truncation.
- Pest: `StoreSessionGiveawayTest`, `RecipeBranchModeTest`, `OperationsFinalQaTest` (QR gate, change-only broadcasts, list validation, bounded query counts for the POS catalog with recipe Products and the Operations summary). Frontend: `store-giveaway-ui.test.ts`.


### Phase 18 pass #2.1 verification (2026-09-25)

- Pest: `BranchOperationsCutoverMigrationTest` (legacy dataset in an isolated SQLite file, forward cutover, `PRAGMA foreign_key_check`, new Branch clean, refusal to roll back Branch setup), `BranchSetupCopyTest` (new Branch clean, Product + Operations copy, independence, skip/replace without duplicates, standalone copy review, copy authorization, removal vs stale draft/cart/QR ids, Branch-scoped realtime), updated `RecipeBranchModeTest` (per-Branch mode: MAIN recipe while QAVE direct; QAVE recipe only QAVE Ingredients), `OperationsManagementTest`, `BranchScopedManagementTest`, `ProductManagementTest`, `BranchCatalogTest` and fixtures that now declare membership (`ProductFactory::soldAt()`).
- PostgreSQL: `tests/verify-branch-operations-postgres.php` (random `bops_*` schema, dropped): A rollback-on-empty + cutover with composite-FK rejection and identifier-length guard; B/C four racing copies incl. replace and reverse direction → one setup, no stock, no deadlock; D Pay Now vs Remove in both queue orders (sale then removal, or clean rejection; no partial Order/Payment/movement); E Pay Now vs Recipe edit (snapshot = one whole recipe version). `tests/verify-branch-assortment-postgres.php` still passes.
- Frontend: `tests/branch-setup-copy.test.ts` plus updated assortment/operations/recipe tests.


### Phase 18 Final QA verification (2026-09-25)

- All 16 `tests/verify-*-postgres.php` harnesses run (random schemas, all dropped). Three had stale assertions after the Branch cutover and were corrected: access-admin rolls back every migration from the Custom Role migration on (not `--step 1`); branch-operations scopes its composite-FK check to the isolated schema (the migrated development schema has the same constraint names); operations checks the post-cutover Branch unique keys and runs Branch-only page projections only for a concrete Branch.
- New operations case **G-E**: Confirm Pamamalengke vs a Plan save queued behind the held Branch row, 3 rounds, no deadlock. With the previous Plan-before-Branch order it reproduced SQLSTATE 40P01.
- Regression tests: Product with zero Groups over multipart (absent list = empty; null/keyed/blank values rejected), each server error shown once, committed edit keeps the committed stock path after a Branch mode change, Ingredient unit locked by Add-on effects, grouped Branch attention counts equal the per-Branch filters, dashboard query count stable as Branches grow, staff email change Super Admin only, no self-deletion, case-insensitive profile email, staff-creation notification, Operations-only channel access, tracking-change signal.

### Phase 19 implementation verification (2026-09-25)

- Pest: `PerformanceHardeningTest` (structural, no timing: Reports / Owner Dashboard / Executive Dashboard / All Branches Transactions keep the same query count from 40 Orders to 4,340 Orders over 3 Branches with exact Net Sales and newest-first paging; Owner Dashboard partial reloads compute only requested props; forged Branch on a partial reload never shows another Branch; inactive account signed out; Audit Trail newest-first paging with id tie-break, every filter, forged Branch id rejected, Owner denied, bounded query count at 1,000 rows, realtime reload runs no option query; Void Orders query count constant as voids grow; JSON endpoint runs no shared-prop queries; Products page loads only the selected Branch's rows). `BranchSetupCopyTest` adds Replace conflict skip + report, no membership for a skipped new Product, conflicts excluded from the Operations part, forged Product/source ids. `ActiveBranchContextTest` adds the forged-selection fallback on the first call.
- PostgreSQL: new `tests/verify-reporting-performance-postgres.php` (random `perf_*` schema, dropped): index migration up / rollback / re-apply; with 60,000 audit rows, 30,000 Orders and 40,000 notifications, EXPLAIN of the application's own queries shows Index Scan Backward on each new index with no Sort; SalesAnalytics All Branches Net Sales equals the SQL sum with the same query count at 90 and 30,000 Orders. `verify-branch-operations-postgres.php` case **F**: Copy with Replace vs a Recipe save on the conflicting Product, both queue orders, never both Product stock and a recipe, no deadlock (its cutover rollback step is now relative). `verify-branch-assortment-postgres.php` still passes.
- Frontend: `realtime-refresh.test.ts` (ignored reasons, revalidation guards, QR refetch-after-reconnect, business Transactions realtime, Audit `logs`-only reload) and `branch-assortment.test.ts` (copy result with skipped products).

### Phase 19 Final QA verification (2026-09-26)

- Harness defect fixed: `verify-reporting-performance-postgres.php` was wall-clock dependent (failed 00:00–03:00 Manila: its Store Sessions opened on the previous business date, Net Sales 0.00) and its raw SQL used PostgreSQL `now()`, which the local server (session zone Asia/Kuala_Lumpur) writes 8 hours ahead of the application's UTC. It now pins one Manila-midday UTC moment for Carbon and substitutes it for `now()` in its SQL. Reproduced failing at 00:02 Manila, passing after the fix at the same hour.
- `PerformanceHardeningTest`: Void Orders realtime partial reload (`voids,pinStatus`) stays flat as voids grow and runs no filter option query.
- PostgreSQL: all 17 `tests/verify-*-postgres.php` harnesses passed (random schemas, all dropped), incl. branch-operations A (Phase 18 cutover rollback/forward) and F (Replace copy vs Recipe save, 4 rounds, both queue orders), branch-assortment racing copies, owner-reports parity, void-audit and transaction-history races. `pg_stat_database.deadlocks` 34 before and after: no new deadlock.
- Focused Pest (26 files covering performance, copy, Branch context, reports, dashboards, audit, voids, notifications, transactions, realtime, catalog, operations, security): 513 passed / 4,889 assertions before the new assertion; frontend 251/251; lint 0 errors / 0 warnings (190 files); TypeScript, PHPStan (0 errors), Pint, production build and `git diff --check` clean; secret/debug scan clean.
- Complete Laravel suite (run once): 2,077 passed, 14,997 assertions, 0 failed, 0 skipped, 203.3 s.

### Phase 19.5 implementation verification — PWA Phase 1 (2026-09-26)

Focused automated checks only (not Final QA; the complete Laravel suite is reserved for Phase 19.5 Final QA).

- Pest (new): `PushSubscriptionTest` (enable / re-sync / disable for the signed-in active account only; guests 401, inactive account refused; malformed or foreign subscriptions rejected — plain http, internal or look-alike hosts, credentials, custom port, bad P-256 key or auth length, unexpected key material, unknown encoding; submitted user id ignored; one row per endpoint across repeated enables and tabs; a browser moves to the account now enabling it; a new endpoint replaces the device's stale one; disabling never touches another account; 503 while push is unconfigured; encrypted at rest; no endpoint, key, device id or VAPID private key in responses or audit; logout removes this device's subscription only; `UserSessions::invalidate()` removes the account's subscriptions). `WebPushDeliveryTest` (New Kitchen Order recipients = Branch kitchen.access accounts incl. business-wide Custom Roles, never Super Admin (follow-up: Super Admin gets only Important Alerts), other Branches, Owner, cashier-only, inactive or unsubscribed; Order Ready = Branch pos.access, Done → Ready undo not pushed; recipients re-decided at delivery after a revoked permission, removed assignment or deactivation; alerts reach exactly AdminNotifier's recipients, tagged by the stored notification, no alert text in the payload, demoted/deactivated admin skipped; 404/410/400/401/403 delete the subscription with an id-only log; 429/5xx/no-response retry only that subscription, 3 attempts max; unexpected local error keeps it, undecryptable material removes it; queued only after commit; nothing queued while unconfigured; a broken queue never fails the Kitchen transition). `WebPushGatewayTest` (real library: aes128gcm body without plaintext, VAPID `Authorization`, TTL / Urgency / Topic headers, 201 and 410 reported; topic stable and push-service safe). `PwaShellTest` (`/sw.js` from the build with no-store and no session cookie, 404 without a build or while Vite runs; trusted-proxy https URLs, untrusted forwarded scheme ignored; manifest and startup screen on staff pages, never on the kiosk page). `GenerateVapidKeysTest` (never overwrites a pair; writes a new pair printing only the public key). Updated: `BrandingTest` now requires the manifest link.
- Frontend (new): `pwa-connectivity` (Online → Offline without probing; online → Reconnecting → probe → authoritative reload → Online; bounded backoff, Offline after 3 failed attempts; paused while hidden; no timer once online; failed revalidation keeps writes blocked; going offline mid-check wins; Last synced), `pwa-update` (waiting worker announced without reload; Update now refused with the POS reason; SKIP_WAITING then exactly one reload; another window's activation never reloads this one; work starting mid-activation stops the reload; server version change; Later snooze), `pwa-install-push` (platform detection only for instructions; installed hides the CTA; prompt only when captured; iOS Add to Home Screen and Mac Add to Dock guidance; insecure context; push support incl. iOS install-first; blocked/off/on/unavailable; VAPID key bytes and rotation; subscription body), `pwa-notifications-guard` (every write blocked while not online, reads never; background writes silent; canonical message; fixed lock-screen texts ignore payload details; malformed pushes safe; taps open only same-origin allowlisted paths; launch recovery rules) and `pwa-contracts` (manifest fields and real icon sizes, offline page, service-worker caching/update contracts, build precache list, runtime guard/registration/logout wiring, no IndexedDB, Blade meta, status placement and POS update blocker). Updated `kitchen-ui` for the safe-area-aware operational `main` padding.
- PostgreSQL: new `tests/verify-push-subscriptions-postgres.php` (random `pwa_*` schema, dropped): A migration fresh / rollback / reapply; B unique `endpoint_hash`, encoding CHECK, user FK and indexes; C two independent processes enabling one endpoint behind an uncommitted conflicting row → both succeed, one row (the `updateOrCreate` savepoint absorbs the unique violation); D disable vs a key-rotation re-enable behind a held row lock → both succeed, consistent, no deadlock.
- Results: focused Pest 653 passed / 4,913 assertions (new PWA files + every suite touching changed shared code; with `OPENSSL_CONF` set — without it the two EC-key tests skip: 651 passed, 2 skipped). Frontend 300/300. Lint 0/0 (203 files), TypeScript (app + service worker), PHPStan 0 errors, Pint, production build (`public/build/sw.js`, 144 precache entries ≈ 2.2 MB), SQLite migration fresh / rollback / reapply, `git diff --check` clean.

### Phase 19.5 USER MANUAL QA checklist (PASSED — reported by the user)

Use the HTTPS workflow in `12-deployment-operations.md` §30.1 for phones. The user reported every item below as passed (desktop, phone over an HTTPS tunnel, offline, reconnect, push incl. Super Admin exclusion, update, responsive).

- **Desktop (Windows/Mac):** open over https (or localhost) → App & notifications › Install PONGSKILOG (Mac Safari: File › Add to Dock) → icon/name → standalone window → close/reopen → uninstall/reinstall.
- **Phone:** Android install or iPhone/iPad Add to Home Screen → icon, name, dark startup screen → standalone → safe areas (notch, home indicator, landscape) on POS, Kitchen, Customer Display, Owner and Super Admin.
- **Offline:** start online → network off → Offline pill (Last synced) → screen stays readable → try Pay Now, Pay Later, a Kitchen status and a Staff change → each refused with the offline message, no fake success → the POS cart stays.
- **Reconnect:** network on → Reconnecting → Online → the screen refreshes → realtime resumes (new orders appear).
- **Push:** Enable Notifications from the button (never on page load) → background or close the app → New Kitchen Order, Order Ready and one Important Alert arrive → tap opens/focuses PONGSKILOG on the right page; a focused Kitchen screen keeps its own sound; denied permission is explained; logout stops notifications on that device.
- **Update:** build a newer version → "PONGSKILOG update available" appears, no automatic reload → with a non-empty POS cart Update now waits with the reason → clear or finish the order → Update now → one reload to the new version.
- **Responsive:** 360, 390, 430 px, tablet, desktop — no overflow, pills never cover controls, 44 px targets.

### Phase 19.5 Final QA verification — PWA Phase 1 (2026-09-26)

- New / updated regression tests: `PwaShellTest` (hermetic against a local `TRUSTED_PROXIES`; a trusted proxy — one address or `*` — can set the https scheme but never the host, port or path prefix of generated links), `PushSubscriptionTest` (another account signing in on this browser unbinds the previous account; the same account signing in keeps its subscription), `WebPushGatewayTest` (bounded Guzzle timeouts and a PSR-3 logger for the library), `ReceiptShareTest` (public receipt has no manifest, Apple standalone meta or startup screen).
- Complete Laravel suite: **2,134 passed / 15,223 assertions, 0 failed, 0 skipped**, 202 s, with `OPENSSL_CONF=C:\php\extras\ssl\openssl.cnf` set for this Windows process only (Linux CI needs nothing).
- Focused: 454 passed / 2,874 assertions (PWA, receipt, admin notifications, Kitchen, Pay Now / Pay Later / settlement, Access Control, Staff, Auth). PostgreSQL `verify-push-subscriptions-postgres.php` A–D passed. Frontend 300/300 (built-worker contract executed against a fresh build). Lint 0/0, TypeScript (app + service worker), PHPStan 0, Pint, production build, SQLite migration fresh / rollback / reapply (disposable file), `git diff --check`.
- Built artifacts: `public/build/sw.js` routes only precache + navigation; 144 precache entries — `/build/assets/*.{js,css,woff2}`, 6 brand images and `/offline.html`; no runtime cache, Background Sync or `clients.claim`; no VAPID private key, Reverb secret, APP_KEY, DB password, tunnel host, LAN IP or local path in the build (built with neutral `VITE_REVERB_*`).

## Phase 19.6 — Customer Experience Expansion QA (planned)

Run Phase 19.6A acceptance before any Phase 19.6B implementation.

Phase 19.6A must test:

- Pair/unpair/re-pair of one customer screen to one Branch/POS station, cashier account changes, stale pairing, forged station/Branch identifiers, and strict cart isolation between simultaneous stations
- Atomic `MENU` / `CUSTOMER DISPLAY` mutual exclusion, both-off advertisement fallback, reconnect recovery, and concurrent control changes
- Browse-only Menu parity for categories/products/prices/availability, with every cart/order/payment mutation absent from the UI and rejected server-side
- Live Cart customer-safe projection, rapid edits/removals, station disconnect, successful commitment takeover, Dine In 3-second and Take Out 5-second return behavior, and same-type server queue-position correctness
- Branch advertisement authorization and isolation; image/video signature/type, size/duration, malformed/spoofed media, sequence, active state, optimization failure, missing media, and POS non-blocking behavior
- Realtime burst coalescing, reconnect refetch, event-payload privacy, and no polling

Phase 19.6B must test:

- Pickup token creation exactly once for every successfully committed Take Out order, including an order whose QR is never scanned; no token/QR for Dine In and no token on rolled-back commitment
- Unguessable/cross-order/cross-Branch/expired-or-ineligible token handling and the public projection's exact privacy allowlist
- Preparing/Ready/Done plus same-type Take Out queue-position correctness across forward transition, one-step rollback, Done, void/other terminal behavior, and reconnect
- Explicit notification opt-in only: scan without opt-in, denied permission, unsupported browser, invalid/expired subscription, re-subscription, and no cross-order subscription reuse
- Buzz visibility only on the existing cashier Ready surface for eligible Take Out; absence in every other state/case
- One push per accepted Buzz, supported vibration behavior, 5-second server cooldown, maximum attempts, replay/idempotency, concurrent clicks/workers, retry/failure cleanup, and no Kitchen/order-state mutation on delivery failure
- Event-driven behavior with no polling and no sensitive token/subscription/order data in logs, push payloads, or realtime events

Phase 20 repeats the full RBAC, Branch/station isolation, concurrency, realtime reconnect, responsive/device, staging, backup/restore, health-check, CI, and production-readiness gates with Phase 19.6 included.
