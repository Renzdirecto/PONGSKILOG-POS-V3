# PONGSKILOG POS V3 — Architecture

**Status:** FROZEN — Batch 2  
**Depends on:** Frozen Batch 1 (`01-project-overview.md`, `02-business-rules.md`, `03-user-flows.md`)

---

## 1. Architecture Goal

PONGSKILOG POS V3 uses a centralized cloud architecture for one PONGSKILOG business with multiple branches.

Priorities:

- Fast POS operation
- Strong branch isolation
- Reliable payments and inventory
- Realtime Kitchen / QR / Customer Display updates
- Auditable Store Open/Close and sensitive actions
- Remote Owner/Super Admin management
- Performance-first product image delivery
- Simple scaling without premature microservices

---

## 2. Application Architecture

Use:

**Laravel modular monolith + Inertia**

Core stack:

- Laravel 13
- PHP 8.4
- React 19
- Inertia.js 3
- TypeScript
- Tailwind CSS 4
- Vite 8
- PostgreSQL via Supabase
- Eloquent ORM
- Redis
- Laravel Reverb / Broadcasting
- Supabase Storage
- Railway

Do not introduce a separate Node/Express backend.

---

## 3. Main Modules

### Identity & Access

- Authentication
- Roles
- Permissions
- Branch assignments
- Active branch context
- Policies/Gates
- Super Admin restrictions

### Branches & Store Sessions

- Branch records/status
- Browse vs Open Store
- Opening Cash
- Opening Cashless
- Store Open detection
- Store purchases/expenses
- Closing Cash
- Closing Cashless
- Reconciliation
- Store Close
- One active Store Session per branch

### Catalog

- Categories
- Products
- Images
- Modifiers
- Global product definitions
- Branch product overrides

### Orders / POS

- Dine In / Take Out
- Cart/order creation
- Order items/modifiers
- Pay Now
- Pay Later
- Recorded-order edits
- QR LOAD

### Payments

- Cash
- Cashless
- Split
- Pay Later settlement
- Delta payments
- Payment history
- Idempotency

### Inventory

- Branch stock
- Inventory ledger
- Pay Now deduction
- Pay Later deduction
- Edit delta
- Void restoration
- Store purchase restock
- Manual adjustment
- Branch transfer

### Kitchen

- Kitchen ticket
- KITCHEN → PREPARING → READY → DONE
- Order update notifications
- Customer Display projection

### Customer QR

- Branch-bound QR session
- Store Open/Closed state
- Menu
- Unpaid QR submission
- Tracking
- Receipt
- 30-minute Archive / Unclaimed behavior

### Reporting

- Per-branch reports
- All-branch Owner reports
- Store Session summaries
- Sales/orders/payment mix
- Inventory/operational reporting

### Audit

- Store Open
- Opening balances
- Store purchases/expenses
- Closing balances
- Variance
- Store Close
- Order edits
- Voids
- Access/settings changes

---

## 4. Core Request Pattern

Read:

**Browser → Laravel/Inertia → PostgreSQL → Inertia response**

Critical write:

**Client → authorization → validation → DB transaction → commit → realtime broadcast**

Never broadcast success before commit.

---

## 5. Branch Context

Every operational request must be branch-scoped.

Backend validates:

1. User is authenticated and active.
2. Required role/permission exists.
3. User is assigned to requested branch, unless business-wide Owner/Super Admin access applies.
4. Record belongs to that branch.
5. Branch is in valid state.
6. Store Session is Open if mutation requires active operations.

Normal branch staff must never query all branches.

---

## 6. Store Session Architecture

A Store Session represents one operational opening of one branch.

Rules:

- Maximum one active Store Session per branch.
- First authorized Cashier opens it.
- Opening Cash recorded once.
- Opening Cashless recorded once.
- Additional Cashiers reuse the same open session.
- Browse mode remains read-only while no open session exists.

### Close Store

Close Store runs only after pre-close validation:

- No unresolved UNPAID / PAY LATER.
- All committed Kitchen orders = DONE.
- Unclaimed QR orders do not block closing.

Close flow records:

- Session purchases/expenses
- Closing Cash
- Closing Cashless
- Expected Cash
- Expected Cashless
- Variances
- Required notes
- QR archive actions
- Closed timestamp/user

### Variance

- Exact → normal close.
- Shortage → normal close blocked until corrected.
- Overage → allowed with required note/reason.

Close Store is atomic. If it fails, Store Session stays Open.

---

## 7. QR Archive Architecture

Initial QR submission:

- Unpaid
- No inventory deduction
- No Kitchen ticket

If untouched for 30 minutes:

- Mark/move to Archived / Unclaimed
- Remove from active operational queue
- Retain record for history

At Store Close:

- Archive any remaining active unclaimed QR submissions

Archived QR records do not create payment, inventory, or Kitchen effects.

---

## 8. Order Commitment Model

A draft/cart has no stock/Kitchen effect.

A QR submission has no stock/Kitchen effect.

Operational commitment happens through:

### Pay Now

One transaction writes:

- Final order
- Payment record(s)
- Inventory movements
- Branch inventory balance
- Kitchen ticket

### Pay Later

One transaction writes:

- Final order
- `UNPAID / PAY LATER`
- Inventory movements
- Branch inventory balance
- Kitchen ticket

When Pay Later is paid later:

- Add payment record(s)
- Update payment status

Do not deduct stock or create Kitchen ticket again.

---

## 9. Order Editing

Recorded-order edits run through a domain/service operation.

For inventory-affecting edit:

1. Load committed order.
2. Authorize role + branch.
3. Calculate item deltas.
4. Apply inventory deltas.
5. Update order snapshot.
6. Update payment/balance state if needed.
7. Create audit record.
8. Notify Kitchen if relevant.
9. Commit.
10. Broadcast after commit.

Never erase earlier inventory movements.

---

## 10. Payment Architecture

Payments are separate append-only records.

One order may have multiple payment records due to:

- Split payment
- Pay Later settlement
- Delta payment after edit

High-risk payment confirmation uses idempotency key.

---

## 11. Inventory Architecture

Use:

### Current Balance

Fast lookup by:

- Branch
- Product

### Inventory Ledger

Append-only movement history.

Movement reasons include:

- sale
- pay_later_commit
- order_edit_delta
- void_restore
- manual_adjustment
- store_purchase_restock
- transfer_out
- transfer_in

Current balance and movement ledger must update in same DB transaction.

Use row locking or equivalent concurrency-safe strategy.

Negative stock blocked by default.

---

## 12. Store Purchases / Expenses Architecture

Store purchases/expenses belong to an active Store Session.

Each entry stores:

- Branch
- Store Session
- Description
- Amount
- Payment source: Cash / Cashless
- Note/reason
- Optional receipt image
- Optional linked inventory product/quantity

If linked to inventory restock:

- Record expense
- Increase inventory
- Create inventory movement

Cash/Cashless source affects expected closing balance.

---

## 13. Product / Image Architecture

Products are global with branch overrides.

Product images use Supabase Storage.

Database stores metadata/path only.

Use:

- Optimized card thumbnails
- Correct dimensions
- WebP/AVIF where practical
- Lazy loading
- Browser/CDN caching
- Queue-based optimization after upload

Image processing must never block POS operation.

---

## 14. Realtime Architecture

Use Laravel Broadcasting + Reverb.

Realtime is branch-scoped.

Examples:

- Store Open/Close state
- QR arrival/archive
- Kitchen ticket creation
- Kitchen status
- Customer Display
- Product availability
- Inventory alerts

Detailed contracts live in `06-realtime-contracts.md`.

---

## 15. Queue / Redis

Use Redis for:

- Queues
- Cache
- Realtime support where configured

Good queue candidates:

- Image optimization
- Report export
- Non-critical notifications
- QR stale/archive maintenance jobs

Do not queue authoritative transaction commits for:

- Payment
- Pay Later
- Inventory deduction
- Void
- Store Open
- Store Close

---

## 16. Failure Handling

### DB transaction fails

- Roll back.
- Do not broadcast success.
- Preserve previous valid state.

### Realtime disconnect

- UI shows Reconnecting/Offline.
- On reconnect, refetch authoritative state.

### Offline

Block:

- Payment
- Pay Later save
- Void
- Store Open
- Store Close
- Inventory-changing Store Purchase

No fake local success.

---

## 17. Performance Principles

- Branch-scope queries
- Index high-traffic branch columns
- Paginate history/reports
- Avoid N+1
- Keep realtime payloads small
- Update smallest UI region possible
- Optimize images aggressively
- Heavy analytics/export work in queue
- Avoid loading All Branches data for branch staff

Phase 19 (2026-09-25) applied these without new infrastructure (no cache, no queue): Inertia props that are not needed on every visit are closures (lazy, memoized when shared), so partial reloads and JSON endpoints run only their own queries; newest-first registers have matching `(created_at|committed_at, id)` indexes; list pages eager-load what their projection reads. `ActiveBranchContext::current()` gives the same answer however often it is called in a request (a forged selection is dropped and the account continues as if none were selected).

---

## 18. Deployment Shape

Railway:

- Laravel web/app service
- Queue worker
- Reverb service if separated

Managed dependencies:

- Supabase PostgreSQL
- Supabase Storage
- Redis

---

## 19. Frozen Architecture Decisions

Frozen:

- Modular monolith
- Inertia frontend
- PostgreSQL source of truth
- Multi-branch
- One active Store Session per branch
- Opening Cash + Cashless
- Closing Cash + Cashless
- Pay Later deducts stock + enters Kitchen immediately
- QR submission has no stock/Kitchen effect
- 30-minute QR Archived / Unclaimed
- Store Close archives remaining unclaimed QR
- Pay Later/Kitchen DONE are Close Store blockers
- Store purchases/expenses affect reconciliation
- Inventory ledger + compensating movements
- DB commit before broadcast
- No offline-first financial writes
- Performance-first image handling

## 20. Owner Operations (Phase 16E)

- **Stock primitive:** `App\Actions\Operations\ApplyIngredientMovement` (lock rows in Ingredient-id order, append movement + move balance in one transaction). Nothing else writes Ingredient balances.
- **Order integration:** `RecordOrderIngredientUsage` — `commit()` from `ApplyOrderInventory` (Pay Now and Pay Later), `edit()` from `EditCommittedOrder`, `void()` from `VoidOrder`, all inside the caller's existing transaction and lock order (Store Session → Order → Product inventory → Ingredient balances). Immutable `order_recipe_snapshots` make edits and voids independent of today's recipe, costs and Plans.
- **Domain services:** `ReplenishmentAdvisor` (only recommendation authority), `IngredientStockReport` (batched canonical stock + today's movements), `OperationsSummary` (sales/COGS/profit on `StoreSessionSalesReport`), `OperationsWorkspace` (page props), `ExactQuantity` (integer ten-thousandths), `OperationsAccess` (scope + authorization).
- **Recipe capacity:** `App\Support\RecipeCapacity` is the only availability formula (profiles from `ProductSizes` + recipes + Product-specific Add-on effects; servings = min floor(max(stock, 0) ÷ per-serving)). Consumers: `BranchCatalog::browse()` (only Products with a recipe, flagged by `withExists`, so non-recipe catalogs cost no extra queries), `OrderSnapshots::prepare()` (whole-order pre-check for new orders and Customer QR), `PosRecipeCapacityController` and `CustomerQrOrderController::capacity()`. The authoritative check is in `RecordOrderIngredientUsage::apply()` under the locked balances. Lock order for every Ingredient writer: Branch → Store Session → Order → Product inventory → Ingredient balances (Edit and Void take the Branch FOR SHARE first).
- **Money:** Confirm Pamamalengke writes the canonical Store Session expense through `RecordStoreSessionExpense::persist()`; Purchases is a projection. React only formats server values; its recipe-cost and checklist totals are display previews with the same integer rounding.

### 20.1 Final QA additions (2026-09-24)

- **Giveaway:** `RecordStoreSessionGiveaway` / `ReverseStoreSessionGiveaway` (`StoreSessionGiveawayController`, routes `store-session-giveaways.*`). Customization and availability come from `OrderSnapshots::prepare()` for one line (no second engine); the stock effect is `ApplyInventoryMovement` (Product stock) or `ApplyIngredientMovement` (Recipe) with `RecipeCapacity::lineRequirement()`; the reversal replays the recorded movements negated. `CurrentStoreSessionExpenses` projects Giveaways into the Store Session history; `OperationsSummary` reports them separately.
- **Canonical lock order:** Branch → Store Session → Order → catalog rows (Category/Product share, BranchProduct update) → Product inventory → Ingredient balances. Writers that do not hold the Branch FOR UPDATE take it FOR SHARE first (Operations writers, Edit, Void, Settle, correction allocation, Store Expense, Store Session and Catalog inventory adjustments, Giveaway and its reversal).
- **Branch switch with a return path:** `ActiveBranchController::update` accepts an optional same-application `redirect` path (validated; anything else lands on the workspace) so Operations can open a specific Branch's Product settings through the existing Branch context.


### 20.2 Branch-owned configuration (Phase 18 pass #2.1, 2026-09-25)

- **Assortment authority:** `BranchCatalog` lists and sells only Products with a `branch_products` row (`not_in_branch` otherwise); `ApplyOrderInventory` re-checks membership under the commit's locks, so stale or forged Product ids (drafts, loaded QR, carts) are never newly committed; `OrderSnapshots` lets a retained edit line of a removed Product stay or shrink, never grow.
- **Branch scoping:** `RecipeCapacity::profiles()`, `RecordOrderIngredientUsage` snapshots, `IngredientStockReport`, `OperationsWorkspace` and every Operations writer read only the selected Branch's mode (`branch_products.no_recipe_needed` / `tracks_inventory`), Recipes, effects, Plans and Ingredients. No global fallback. `OperationsAccess::configurationBranch()` (setup) / `mutableBranch()` (physical stock) / `ownedBy()` (404 for another Branch's record).
- **Configuration lock:** `App\Support\BranchConfiguration::lock()` (Branch FOR NO KEY UPDATE) is taken first by assortment add/remove/copy and every Operations configuration writer; it conflicts with commits (FOR UPDATE) and edits/voids/stock writers (FOR SHARE) but not FK KEY SHARE, so removal-vs-sale and recipe-edit-vs-sale serialize. `lockCopy()` locks source (FOR SHARE) and destination in Branch-id order.
- **Copy:** `ConfigureBranchAssortment` (add, remove, copy with optional Operations) and `CopyOperationsSetup` (preview = dry run of the same code path; lineage-then-name matching; skip/replace; conflicts reported). Controllers: `BranchAssortmentController` (`products.branch-assortment.{store,destroy,copy}`), `OperationsSetupCopyController` (`operations.setup-copy.{preview,store}`).

## 21. PWA Phase 1 — installable, internet-first (Phase 19.5, 2026-09-26)

Phase 1 = a real installable app experience; the internet is still required for every critical business operation. Nothing here changes an authorization, business rule or write path. Offline-first POS (local database, write queues, sync, conflict handling, trusted devices) is **PWA Phase 2 — future only**.

- **Install:** static `public/manifest.webmanifest` (id `/`, start `/workspace` → existing role/Branch routing, scope `/`, standalone, `#111111`, approved any + maskable icons, no forced orientation), linked from `app.blade.php` on staff and sign-in pages only (never Customer QR / kiosk / receipt). `viewport-fit=cover`, Apple standalone meta, a standalone-only startup screen removed on first render.
- **Service worker:** `resources/js/service-worker/sw.ts` (Workbox precaching/routing, built by `vite-plugin-pwa` `injectManifest` into `public/build/sw.js`, served by `ServiceWorkerController` at `/sw.js` outside the web middleware with `no-store`). Precache = fingerprinted `/build/assets` JS/CSS/fonts + brand icons + `offline.html`; navigations NetworkOnly with the static offline page as the only fallback; every other request (Inertia JSON, uploads, signed URLs, all writes) is untouched. No Background Sync, no runtime cache, no `clients.claim`. Not registered by the Vite dev server (and unregistered there).
- **Runtime:** `lib/pwa-runtime.ts`, started once from `app.tsx` (inert on public customer pages; features are detected, so an unsupported browser is a normal online web app). One connectivity state (`lib/pwa-connectivity.ts`: Online / Reconnecting / Offline, confirmed against `/up`, completed by one authoritative `router.reload()`), one write guard (the Inertia HTTP client is wrapped: every POST/PUT/PATCH/DELETE is refused before sending while not online), update control (`lib/pwa-update.ts`), install and push state. UI: `PwaStatus` in the operational / Owner / Super Admin headers, the Kitchen full-screen header and the Customer Display; a floating fallback elsewhere; "App & notifications" in every account menu.
- **Updates:** a new worker waits; Update now (only from the runtime) activates it and reloads that window once. `useUpdateBlocker` (POS order in progress) and writes in flight hold both the service-worker update and Inertia's asset-version reload.
- **Web Push:** `minishlink/web-push` (VAPID + RFC 8291 encryption) behind `PushGateway`. `PushNotifications::queue()` → queued `SendPushNotification` → `PushRecipients` (current authority at send time) → `WebPushSender` (cleanup, bounded retry). Triggers: `KitchenTicketCreated` (New Kitchen Order), `KitchenStatusChanged` to Ready except Done → Ready (Order Ready), `NotificationSent` for `AdminAlert` (Important Alert). `push_subscriptions` (encrypted material, unique `endpoint_hash`, device cookie hash) managed by `pwa.push-subscription.{show,store,destroy}`.
- **Unchanged authorities:** PostgreSQL for all state, Reverb/Echo for live invalidation (the PWA adds no realtime client), the server for every authorization decision.

## 22. Customer Experience Expansion (Phase 19.6, frozen 2026-09-27; implemented 2026-09-27, awaiting manual QA)

Phase 19.6 was frozen as two sequential slices (Customer-Facing Screen V2, then Takeout Pickup QR + Buzz) and, on the user's one-shot instruction, implemented in one pass. The frozen boundaries below still hold; §22.1 records the implemented shape.

- **Display identity:** a customer-facing screen is paired to one Branch-scoped POS station/device installation, never to the cashier account using that station. Authentication and permission remain staff concerns; pairing determines which cart may be projected.
- **Display state:** the server owns the Branch's selected display mode. `MENU` and `CUSTOMER DISPLAY` are mutually exclusive and may both be off; the deterministic fallback is Branch advertising. Menu is catalog-only and cannot create or mutate an order.
- **Safe projections:** the paired live-cart projection is station-specific and customer-safe. The order-status board and public pickup page remain separate projections. None exposes tender/payment, customer/staff identity, internal identifiers, or mutation controls.
- **Media:** advertisement images/videos are Branch-owned managed assets with explicit active state, sequence, and duration. Validation, storage isolation, and optimization are server-controlled and may not block POS operations.
- **Order takeover:** a committed order triggers a temporary display projection. Both include order number/type and server-derived queue positions; Take Out also includes its pickup QR. *(Manual-QA fixes: durations are Branch settings, default 5 s each, and count from when the confirmation is actually on screen — see §22.2.)*
- **Pickup tracking:** every committed Take Out order receives an unguessable pickup token even if never scanned. The no-login page reads only order number, Preparing/Ready/Done, and Take Out queue position. Dine In has no pickup token or page.
- **Buzz:** a QR scan does not subscribe. Only explicit customer notification opt-in creates a potentially buzz-capable subscription bound to that pickup order. Kitchen Ready and the existing cashier Ready surface remain authoritative; Buzz is conditional, rate/replay protected, bounded per Ready order, and has no effect on Kitchen/order state.
- **Realtime:** compact after-commit invalidations trigger authoritative refetch and coalesce bursts. Reconnect refetches server truth. No polling or second realtime client is introduced.

### 22.1 Implemented shape (2026-09-27)

- **Customer screen:** public kiosk page `GET /customer-screen` (`CustomerScreenController`, Inertia `customer-screen`, no layout, no staff shared props, no manifest). The device is a `customer_screens` row identified by the SHA-256 of its HttpOnly `pongskilog_customer_screen` cookie. It reads one projection (`CustomerScreenProjection`: Branch name, mode, Live Cart, takeover, board, channel names), the Menu (`CustomerMenu` over `BranchCatalog::browse()`, signed image links 60 min) and the playlist (`CustomerScreenMediaLibrary::playlist()`, signed links 60 min). Its only writes: request its own pairing code, reset its own pairing and (manual-QA fixes) its header MENU / CUSTOMER DISPLAY press and the confirmation-shown report.
- **Station pairing:** the POS station id is a UUID in local storage (`lib/pos-station.ts`), sent as `X-POS-Station`, stored as SHA-256 with the Branch. `PairCustomerScreen` (PosAccess, one-time 5-minute HMAC'd code, unique `(branch_id, station_hash)`, releases the previous screen, one retry on a racing pairing), `UnpairCustomerScreen` (station or the screen's hidden reset), `ToggleCustomerScreenMode` (row lock, `CustomerScreenMode::toggled()`). Controls: `CustomerScreenControl` in the shared operational header (`workspace-layout.tsx`) for `pos.access` accounts.
- **Live Cart / takeover (ephemeral):** `CustomerScreenLiveState` in the cache store only (cart 15 min, per-instance sequence guard, takeover fence, sign-out cleanup via `ClearCustomerScreenCartsOnLogout`). `CustomerScreenCart` derives lines from ids. `useCustomerScreenCart` (POS) debounces 300 ms and serializes writes. *(Superseded by §22.2: the confirmation is started by the Pay Now / Pay Later request itself and counts down once shown.)*
- **Queue position:** `KitchenBoard::queue()` (same scope and order as the Customer Display board; overall + same-type positions and a bounded row window — §22.2).
- **Advertisements:** `customer_screen_media` rows + `s3` objects under `customer-screen/{branch}/{uuid}/`; `CustomerScreenMediaController` (`BranchPolicy::update`) in Settings › Customer Screen; images through `ProductImageProcessor::rendition()` (WebP ≤ 1920 px), MP4 validated by container parsing.
- **Pickup:** `IssuePickupToken` (after-commit on `OrderCommitted` / `OrderUpdated`) → `order_pickup_tokens`; public `PickupController` (`/pickup/{token}`: page, status, subscribe/unsubscribe, channel auth); `PickupStatus` projection; `BroadcastPickupStatusChanged` (after-commit on `DisplayOrdersChanged`) fans one `pickup.changed` event out to the Branch's open pickup channels.
- **Buzz:** `PickupBuzzPolicy` + `BuzzPickupCustomer` (token row lock: idempotency key, 5-s cooldown, max 5) → queued `SendPickupBuzz` → `PickupPushGateway` (implemented by `WebPushGateway::deliverPickup()`); `BuzzCustomerButton` inside the existing POS Ready modal; `readyOrders[].buzz` from `KitchenBoard::readyForPos()`.
- **Public realtime:** both public pages use their own Reverb client (`lib/public-echo.ts`, like the Customer QR page) authorized by their capability endpoint; the staff app's Echo is unchanged.
- **Rate limits:** named limiters (`AppServiceProvider::configureCustomerExperienceRateLimits()`), so these routes never consume the shared per-account counter of un-named `throttle:X,Y` middleware.
- **Housekeeping:** `model:prune` daily for `CustomerScreen` (unpaired, idle 7 days) and `OrderPickupToken` (7 days past expiry, cascades its subscription).

### 22.2 Manual-QA fixes (2026-09-27)

User manual QA found: Dine In confirmations often never appeared, the Take Out QR appeared too late, and the Menu / cart stayed on screen too long after payment. **Root cause:** the confirmation was a second, best-effort POS request sent after the payment response (queued behind cart sends and competing with the POS's own catalog reload), and its fixed 3 s / 5 s countdown was anchored on the server at that request. On a slow or single-worker server the screen's invalidation → state refetch (with QR rendering) arrived after most or all of the window: a 3 s Dine In expired before the screen ever fetched it; a 5 s Take Out showed briefly and late. Fixes:

- **Server-side confirmation from the canonical commit:** `PosPaymentController` and `PosPayLaterController` (the only paths that create the Kitchen ticket, for every order type and loaded Customer QR orders) call `ShowOrderOnCustomerScreen::afterCommit()` after the transaction, using the same request's `X-POS-Station` and `X-Customer-Screen-Cart` (last cart send, the in-flight fence) headers added by `useCustomerScreenCart().commitHeaders`. The POS `pos/customer-screen/takeover` endpoint was removed. Best effort and rescued: nothing can affect the committed payment. Each order is announced once per screen (payment replays show nothing).
- **Countdown starts when shown:** the confirmation waits in cache (up to `TAKEOVER_PENDING_MS` = 20 s) until the screen reports it is on screen (`POST customer-screen/takeover/{id}/shown`, device cookie): immediately for Dine In, after the QR image loaded for Take Out (4 s readiness fallback, and a Take Out without a token is shown without a QR so nothing hangs). A reload shows only the time left; finished confirmations are never shown twice.
- **Branch durations:** `branches.customer_screen_dine_in_success_seconds` / `customer_screen_take_out_success_seconds` (3–15 s, default 5), `CustomerScreenSettings`, Settings › Customer Screen (`CustomerScreenSettingsController`, `BranchPolicy::update`, audited).
- **Mode after confirmation:** `CustomerScreenMode::afterOrderSuccess()` — Menu closes to Ads (never reopens by itself); an explicit Customer Display stays; Ads stays. `CustomerScreenStatusChanged` (`branch.{id}.pos`) makes POS headers refetch.
- **Cart presentations:** Ads + a live cart → full-screen order summary (`customer-screen-order-summary.tsx`); Menu + a live cart → the existing split (Live Cart ≤ 30% above the Menu); cart cleared → Ads.
- **Dedicated confirmation view:** `customer-screen-takeover.tsx` — large green number, type, strong same-type position card, overall position text, a two-column window of the active queue (≤ 10 rows, customer's row green, true absolute positions), Take Out QR. Never the Customer Display board; never a customer name.
- **Queue authority:** `KitchenBoard::queue()` — overall (Dine In + Take Out) and same-type positions plus a window that always contains the order; one query whatever the queue length. `queuePosition()` delegates to it. Used by the confirmation and the pickup page.
- **Customer screen header:** logo, Branch, MENU, CUSTOMER DISPLAY (screen-side `PUT customer-screen/mode` → the same row-locked toggle) and browser Fullscreen (hidden where unsupported; never touches pairing/mode/cart).
- **Ads:** image durations 3/5/8/10/15 s (default 5; older rows keep their value), arrows, swipe, arrow keys, press-and-hold pause (videos pause too), one slide timer (`createSlideTimer`) so navigation leaves no timers, progress bar.
- **Pickup page:** overall + Take Out positions (Take Out emphasized; none once Ready/Done), snapshot order summary (`CustomerScreenCart::customerLine`), View / Print receipt via `GET pickup/{token}/receipt` (the canonical `CustomerQrProjection::publicReceipt()` + `DigitalReceiptCard`, without name/table/notes; paid orders only; ends with the 12-hour token), Facebook / Website / Maps buttons (Branch links; `maps_url` new; only safe http(s) links rendered).
- **Optional sound:** `CustomerScreenProjection::SUCCESS_SOUND_PATH` = `public/audio/customer-screen-success.mp3`; played on the open customer screen only when an approved file exists there (none is committed yet — the kitchen cues are staff-facing and were not reused); autoplay refusals are ignored. Phone Buzz keeps the OS notification sound/vibration.

## Phase 20 — final hardening modules — 2026-09-28

- **Canonical receipt:** `ReceiptDocument` (server) builds the one receipt contract from Order snapshots and Payment rows for an audience (`ReceiptAudience`: Staff, Customer, SharedLink, Pickup); `ReceiptLayout` validates/normalizes the Branch's Receipt Settings and resolves which blocks print in which order. `PosReceipt` (staff) and `CustomerQrProjection` (customer, link, pickup) only delegate to it. The React `ReceiptDocument` component renders every receipt surface (and the Settings preview through `lib/receipt.ts`); `DigitalReceiptCard` and `PosPaid` are thin wrappers.
- **Named rate limits:** `RateLimits::register()` (AppServiceProvider) defines every limiter; routes use only `throttle:<name>`. Fortify's recovery routes are throttled by `ThrottleAccountRecovery`.
- **Operations readiness:** `HealthChecks` + `HealthController` (`GET /health`), `RecordQueueHeartbeat` (scheduled every minute through the queue), `ReleaseInfo` (APP_VERSION / APP_BUILD_SHA, shared as `release`).
- **Stable media links:** `SignedUrls` signs object-storage URLs once per time window with one cache read per list; `ProductImages::safeCardUrls()` and `CustomerScreenMediaLibrary::urls()` batch them.
- **Pamamalengke funding:** `PamamalengkeFunding` lists the selectable funding sessions and labels; `ConfirmPamamalengke` writes a Store Purchase only for an open funding session.
- **Security helpers:** `VoidPinGuard` (wrong-PIN lockout), `SecurityHeaders` middleware.
- **Shared UI sources:** `StoreStatusControl` + `OpenStoreForm` (the one Store status / Open Store control of every Store Operations page), `useManagementSidebar` (the collapsible desktop sidebar preference of both management shells, from the `sidebar_state` cookie), `AccountLayout` (Account & preferences), the generated `resources/css/dark-theme.css` adapter (Dark appearance; `theme-static` opts a surface out).
