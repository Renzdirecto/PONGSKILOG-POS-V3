---
paths:
  - '{app/Support/{CustomerScreens,CustomerScreenCart,CustomerScreenLiveState,CustomerScreenProjection,CustomerScreenMediaLibrary,CustomerMenu,PickupTokens,PickupStatus,PickupBuzzPolicy,PickupPushGateway,KitchenBoard}.php,app/Actions/{CustomerScreens,Pickup}/**,app/Http/Controllers/{CustomerScreenController,PosCustomerScreenController,CustomerScreenMediaController,PickupController,PickupBuzzController}.php,app/Jobs/SendPickupBuzz.php,app/Listeners/{IssuePickupToken,BroadcastPickupStatusChanged,ClearCustomerScreenCartsOnLogout}.php,app/Events/{CustomerScreenChanged,PickupStatusChanged,PickupNotifyChanged}.php,app/Models/{CustomerScreen,CustomerScreenMedia,OrderPickupToken,PickupPushSubscription}.php}'
  - '{resources/js/pages/{customer-screen,pickup}.tsx,resources/js/components/{customer-screen-*,customer-order-board,pos-buzz-customer}.tsx,resources/js/hooks/use-customer-screen*.ts,resources/js/lib/{customer-screen,pickup,pos-station,public-echo}.ts,public/pickup-sw.js}'
---

# Customer Experience (Phase 19.6)

## A customer screen belongs to a POS station, never to an account
Pair, unpair, mode, Live Cart and takeover resolve the screen by (selected Branch from `ActiveBranchContext`, SHA-256 of the `X-POS-Station` header) and authorize with `PosAccess`. Never key a pairing, a cart or a mode on the signed-in user, and never accept a Branch or screen id from the client. `(branch_id, station_hash)` is unique: pairing a new screen releases the previous one (a racing pairing retries once). The screen device is identified only by its HttpOnly cookie hash and may read only its own projection.

## Modes are one column; the takeover never touches it
`customer_screens.mode` ∈ ads | menu | customer_display; `CustomerScreenMode::toggled()` under the row lock is the only transition (other control switches, same control → ads). The order takeover (3 s Dine In / 5 s Take Out) is ephemeral cache state shown as an overlay; restoring "the previous mode" works because the mode was never changed. Never add a second mode flag or store the takeover in the database.

## The Live Cart is display-only, derived and ephemeral
The POS sends ids and quantities only; `CustomerScreenCart` derives names/prices from `BranchCatalog` (no ids, no free-text notes). `CustomerScreenLiveState` keeps it in the cache (15 min) with a per-page-instance sequence guard and a takeover fence. It is never an Order, never moves stock and is never read by payment code. The POS announces a takeover only after Pay Now / Pay Later succeeded, best effort; never make payment depend on the screen.

## Queue position has one source
Use `KitchenBoard::queuePosition()` (the Customer Display "Preparing" column: Kitchen/Preparing, same order type, open Store Session, `(committed_at, id)`). Never count in React or re-derive a second queue formula.

## Pickup tokens: hash lookup, one per committed Take Out order
Issued only by the after-commit `IssuePickupToken` listener (rescued) — never inside a payment transaction. Look up by SHA-256 only; the raw token is kept encrypted solely to re-render the QR. Tokens never go into logs, audit or broadcasts (use `channel_key`). The public projection (`PickupStatus`) is an allowlist: order number, Take Out, status, Take Out queue position, notification availability.

## Buzz: explicit opt-in, row-locked limits, separate push path
A scan never subscribes; only the page's button stores a `pickup_push_subscriptions` row (one per token). `BuzzPickupCustomer` decides under the token row lock (idempotency key, 5-s cooldown → 429, max 5 → 422) and `SendPickupBuzz` re-validates at send time and deletes rejected endpoints. Customer pushes go only through `PickupPushGateway` and `/pickup-sw.js` (scope `/pickup/`, no caching); never send them through `PushNotifications`/`PushRecipients`/`/sw.js` and never let staff messages read the pickup table. Nothing in the Buzz path writes order, Kitchen or payment state.

## Public pages have their own realtime client and named rate limits
`/customer-screen` and `/pickup/{token}` get no staff shared props, manifest or staff PWA runtime (`isPublicCustomerSurface`), and authorize channels through their own capability endpoints (`lib/public-echo.ts`). Events are id/time invalidations; clients refetch. Their routes use named `RateLimiter::for()` limiters: an un-named `throttle:X,Y` shares one counter per account/IP with every other un-named throttle in the app, so a frequent call (like the Live Cart) would starve low limits such as Void or Close Store.
