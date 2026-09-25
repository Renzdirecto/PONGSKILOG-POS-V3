# PONGSKILOG POS V3 — Deployment & Operations

**Status:** FROZEN — Batch 4  
**Depends on:** Frozen Context `01`–`11`

---

## 1. Target Platform

### Application Hosting
Railway

### Database
Supabase PostgreSQL

### Object Storage
Supabase Storage

### Queue / Cache
Redis

### Realtime
Laravel Reverb / Broadcasting / WebSockets

### Source Control / CI
GitHub + GitHub Actions

---

## 2. Environments

Use:

- Local Development
- Staging
- Production

Each environment must have separate:

- Database
- Redis
- App secrets
- Reverb credentials/config
- Storage configuration
- Session/cookie configuration

Production credentials must never be used casually in local development.

---

## 3. Railway Services

Recommended production layout:

### Web / App

Runs Laravel HTTP + Inertia application.

### Queue Worker

Runs Laravel queue jobs.

Examples:

- Image optimization
- Report exports
- Scheduled QR archive maintenance
- Non-critical background jobs

### Reverb

Run separately when practical/required by Railway topology.

Core transaction correctness must not depend on Reverb being available.

---

## 4. PostgreSQL Operations

PostgreSQL is authoritative for:

- Branches
- Store Sessions
- Orders
- Payments
- Inventory
- Kitchen
- Products
- Staff
- Audit
- Settings

Requirements:

- TLS/SSL
- Strong credentials
- Connection pooling where appropriate
- Backups
- Restore procedure
- Index monitoring
- Migration discipline

---

## 5. Supabase Storage

Use for:

- Product images
- Brand/logo assets
- Cashless display/QR assets
- Store Purchase receipt images
- Other approved uploads

Do not store image binaries in normal relational columns.

---

## 6. Product Image Pipeline

Recommended:

1. Upload original
2. Store metadata/path
3. Queue optimization
4. Generate card thumbnail / responsive variants
5. Mark optimized variant ready
6. Serve optimized file through storage/CDN path

If optimization is delayed:

- POS still works
- Placeholder/previous image may display
- Product browsing must not block

---

## 7. Redis

Use for:

- Queue
- Cache
- Supporting realtime infrastructure as configured

Redis is not the authoritative source for:

- Payments
- Orders
- Inventory
- Store Sessions

---

## 8. Reverb

Production requirements:

- Secure WebSocket transport
- Allowed-origin configuration
- Server-side channel authorization
- Branch-scoped subscriptions
- Reconnect/refetch behavior
- Health monitoring

If realtime fails:

- HTTP/DB writes remain authoritative
- UI indicates reconnecting
- Client refetches after reconnect

---

## 9. Secrets

Keep secrets out of Git.

Examples:

- `APP_KEY`
- Database credentials
- Redis credentials
- Reverb credentials
- Supabase service credentials
- Session/cookie security config

Use environment-managed secrets in Railway/Supabase.

Rotate compromised secrets.

---

## 10. CI/CD

GitHub Actions should validate protected development/release branches.

Required checks:

- Pint
- PHPStan / Larastan
- Pest
- ESLint / TypeScript
- Vite build
- Migration smoke test

Production deployment should originate only from reviewed/approved code.

---

## 11. Migration Safety

Rules:

- Review migrations
- Test on staging
- Prefer additive/forward-compatible changes
- Avoid destructive schema changes during live branch operations

For risky changes:

1. Add new structure
2. Deploy compatible code
3. Backfill safely
4. Switch reads/writes
5. Remove old structure later

---

## 12. Backups

Production requires:

- Automated PostgreSQL backups
- Documented restore procedure
- Periodic restore verification

Important uploaded assets need a recovery strategy.

Business-critical history includes:

- Payments
- Inventory movements
- Store Sessions
- Audit
- Store purchases/expenses

---

## 13. Deploying While Stores Are Open

Avoid high-risk deployments during peak service.

For significant release:

1. Verify staging
2. Review active branch/store activity
3. Apply safe schema changes
4. Deploy application
5. Run health checks
6. Monitor transaction/realtime errors

Do not use destructive rollback without verifying data safety.

---

## 14. Health Checks

Monitor:

- Web app
- Database connectivity
- Redis connectivity
- Queue worker
- Reverb
- Storage access

Health endpoints must not expose secrets.

---

## 15. Logging

Technical logs should include:

- Application exceptions
- Failed jobs
- Realtime failures
- Payment processing failures
- Inventory transaction failures
- Store Open/Close failures
- Authorization failures
- Slow operations where useful

Do not log:

- Passwords
- Secrets
- Raw private tokens
- Sensitive data unnecessarily

---

## 16. Audit vs Technical Logs

### Audit Log

Business/security history.

Examples:

- Store Open/Close
- Opening/Closing balances
- Variance
- Void
- Inventory adjustment
- Access change

### Technical Log

Engineering/runtime evidence.

Examples:

- Exception
- Timeout
- Failed job
- DB/Reverb connection failure

Audit history must not depend on ephemeral technical logs.

---

## 17. Queue Operations

Monitor:

- Queue depth
- Failed jobs
- Retry count
- Worker uptime

Critical financial commits are synchronous DB transactions, not background jobs.

Queue jobs must be retry-safe/idempotent where relevant.

---

## 18. QR Archive Maintenance

Frozen business rule:

- Unclaimed QR with no action for 30 minutes becomes Archived / Unclaimed.
- Store Close archives remaining active unclaimed QR.

Implementation may use:

- Scheduled command/job
- Queue-supported cleanup
- Deterministic server-side check

Archive is non-destructive history retention.

---

## 19. Store Open Reliability

Open Store transaction:

1. Authorize branch/user
2. Verify no active session
3. Create Store Session
4. Save Opening Cash
5. Save Opening Cashless
6. Audit
7. Commit
8. Broadcast `store.opened`

Concurrent attempt must resolve to one active session.

---

## 20. Store Close Reliability

Close Store transaction:

1. Authorize
2. Verify active Store Session
3. Recheck no unresolved Pay Later
4. Recheck Kitchen all DONE
5. Archive remaining unclaimed QR
6. Calculate expected Cash/Cashless
7. Validate Closing Cash/Cashless
8. Enforce shortage/overage rules
9. Save closing values/variances/notes
10. Close Store Session
11. Audit
12. Commit
13. Broadcast `store.closed`

If any step before commit fails:

- Roll back
- Keep Store Session Open

---

## 21. Monitoring Important Failures

Make visible:

- Pay Now failure
- Pay Later failure
- Inventory conflict
- Duplicate Store Open attempt
- Close Store blocker/failure
- Realtime outage
- Queue failure
- DB outage
- Storage/image failure

Do not silently swallow integrity-related failures.

---

## 22. Performance Monitoring

Track:

- Slow queries
- POS request latency
- Payment commit time
- Pay Later commit time
- Close Store transaction time
- Realtime issues
- Queue delay
- Image size/load behavior
- Report query duration

Optimize from measured evidence.

---

## 23. Scaling Strategy

Initial strategy:

- Keep modular monolith
- Scale Railway resources as usage increases
- Add queue workers as background demand grows
- Tune PostgreSQL indexes/queries
- Keep realtime branch-scoped
- Optimize image delivery
- Cache read-heavy non-critical data

Do not split into microservices without demonstrated need.

---

## 24. Incident Response

For production incident:

1. Confirm impact/scope
2. Protect data integrity
3. Stop risky writes if necessary
4. Inspect app/DB/queue/realtime evidence
5. Roll back application safely when appropriate
6. Avoid destructive DB rollback unless proven safe
7. Restore from backup only when necessary
8. Document root cause and corrective action

---

## 25. Production Readiness Checklist

Before production launch:

- Production database configured
- Backup enabled
- Restore procedure tested
- Redis healthy
- Queue worker healthy
- Reverb healthy
- Storage healthy
- HTTPS enabled
- Secure cookies/session settings
- CI green
- Migrations tested
- Branch isolation tested
- Pay Now tested
- Pay Later tested
- Inventory concurrency tested
- Store Open/Close tested
- QR archive tested
- Mobile/tablet QA passed
- Monitoring/logging active
- Rollback procedure documented

## 26. POST-PHASE-16 PLANNED PWA SLICE (accepted plan, NOT implemented)

**Status: PLANNED, NOT IMPLEMENTED.** Accepted by the user on 2026-09-24 during Phase 16 Final QA. There is currently **no service worker, no web manifest, no install prompt, no offline cache and no manifest-driven PWA release**. Phase 16 only prepared branding assets. The PWA needs its own dedicated implementation slice with its own tests and QA; it is tracked as **Phase 19.5 — PWA / Installable Web App** (PLANNED / NOT STARTED) in `13-progress-tracker.md` and must not be marked complete until that slice ships.

### Mental model

PONGSKILOG PWA = **INTERNET-FIRST / WIFI-FIRST**. The server stays authoritative for every financial, inventory, Store Session and concurrency decision (§02 rule 4: the system is not offline-first).

- **Online:** normal, full application behavior.
- **Offline:** a safe, degraded, **read-only / browse-oriented** experience.

### Offline read-only scope

- Primary offline-safe candidate: **Product/Menu Browse** from the last confirmed cached catalog.
- Possible later cached read-only views: Transaction History, Owner Dashboard, Owner Reports — **only** with an explicit last-confirmed snapshot strategy. Any such view must prominently show `OFFLINE` and `Last synced: <timestamp>` and must never imply the data is current.
- When no safe confirmed cache exists, show an offline-unavailable state instead of stale or invented data.

### No offline writes (first PWA release)

Offline must block, and must **not** queue for later automatic sync: Pay Now, Pay Later, settlement, Store Open, Store Close, Void, committed-order edits, Store Expenses, inventory adjustments, Customer QR LOAD/restore/delete, Kitchen status changes, Staff creation/edit, Access Control changes, Settings changes, and any other financial or stock-changing mutation. Reason: financial, inventory, Store Session and concurrency integrity remain server-authoritative.

### Recommended: POS cart draft preservation

- An unfinished local POS cart/draft intent MAY be preserved on the device. If the connection drops, the cart stays visible and Payment/commit stays disabled.
- When the connection returns, the server MUST revalidate Product availability, price, modifiers, stock, Branch and Store Session before payment/commit. An offline cart is never silently auto-submitted.

### Reconnect

Network returns → Reverb/Echo reconnects → the client performs an authoritative refetch → server truth replaces any stale cached display. The existing realtime contracts already treat events as invalidation signals followed by refetch (catalog, Customer Display, Kitchen, Owner reports), which is compatible with this plan.

### Installability goals

- Installable on Android, desktop and other browser-supported devices; standalone, app-like launch; branded launcher/home-screen icon; app name **Pongskilog**; splash/theme branding where supported.
- HTTPS is required in production. No App Store / Play Store listing is required for the initial release.

### Branding asset registry for the future PWA

| Use | Asset | Source original |
| --- | --- | --- |
| Browser tab / favicon | Approved gold rounded-square chef icon: `public/favicon.ico`, `public/images/branding/icons/favicon-32.png`, `favicon-192.png` | `public/images/branding/source/pongskilog-tab-icon.png` |
| App install / launcher icon | Approved square Pongskilog-branded icon: `public/images/branding/icons/icon-192.png`, `icon-512.png`, `icon-maskable-192.png`, `icon-maskable-512.png`; `public/apple-touch-icon.png` | `public/images/branding/source/pongskilog-square-logo.jpg` |
| Social / Messenger link preview | Approved cream Pongskilog preview card: `public/images/branding/og-image.jpg` (1200×630) | `public/images/branding/source/pongskilog-link-preview-mockup.png` |
| Header / sidebar | Existing approved Pongskilog wordmark treatment (sidebars and rails keep the wordmark only) | — |
| Phone top bar / auth identity | Round emblem `public/images/branding/pongskilog-emblem.png` | `public/images/branding/source/pongskilog-round-emblem.jpg` |

The 192/512 and maskable icons are ready for a future manifest but are not yet referenced by one.

### Phase 16E note (2026-09-24)

Owner Operations pages (Overview, Ingredient Stock, Purchases) are possible future **read-only** PWA snapshot candidates under the rules above; Pamamalengke confirmation, adjustments and every other Operations write stay online-only. Nothing PWA-related was implemented in Phase 16E. Deploying 16E needs only the additive migration `2026_09_24_053738_create_owner_operations_tables` (`php artisan migrate --force`); no seeder, queue or environment change.

### Phase 16E Final QA note (2026-09-24)

Deploying the Final QA corrections adds one forward migration, `2026_09_24_134328_create_store_session_giveaways` (`php artisan migrate --force`). No seeder, queue or environment change. `php artisan operations:seed-qa` remains LOCAL QA ONLY (refuses outside local/testing, never part of `DatabaseSeeder`).

## 27. Phase 18 deployment note — Access Control, Staff administration and Notifications (2026-09-25)

- Two additive migrations: `2026_09_24_165603_create_user_permission_overrides_table` and `2026_09_24_165604_create_notifications_table` (`php artisan migrate --force`). No queue or environment change.
- `php artisan db:seed --class=RbacSeeder` (or `DatabaseSeeder`) is **safe to rerun**: it never removes or re-adds existing Role ↔ Permission pairs, so Role baselines configured in Access Control survive deployments. It only seeds defaults for Roles/Permissions it creates, completes Super Admin, and re-derives Cashier + Kitchen.
- `AuthenticateSession` is now in the web middleware group: after an administrative password reset, other sessions of that account are signed out on their next request with any session driver (database sessions are also deleted immediately).
- PWA remains NOT implemented (§26 / Phase 19.5).

## 28. Phase 18 final deployment note — Custom Roles and Executive Overview (2026-09-25)

- One additive migration: `2026_09_25_052453_add_custom_role_metadata_to_roles_table` (`php artisan migrate --force`); it backfills the five System roles. Rollback is safe (drops the partial unique index, then the four columns).
- `RbacSeeder` remains safe to rerun and never touches Custom Roles.
- No dependency, queue, environment or realtime-channel change. PWA remains NOT implemented (§26 / Phase 19.5).

## 29. Phase 19 deployment note — performance indexes (2026-09-25)

- One additive migration: `2026_09_25_150406_add_reporting_performance_indexes` (`php artisan migrate --force`). It only creates five btree indexes; rollback drops exactly those.
- `CREATE INDEX` (non-concurrent) briefly blocks writes to `audit_logs`, `orders`, `notifications` and the two Pamamalengke tables while each index builds. At current volumes this is seconds; on a much larger production dataset run it outside trading hours.
- No dependency, queue, cache, environment or realtime-channel change. PWA remains NOT implemented (§26 / Phase 19.5).
