# PONGSKILOG POS V3 — Library & Framework Reference

**Status:** FROZEN — Batch 5  
**Purpose:** Define the approved core libraries/frameworks and how they are used in this project.

---

## 1. Dependency Rule

Prefer the existing stack.

Before adding a dependency:

- Verify the current stack does not already solve the problem.
- Confirm maintenance and compatibility.
- Keep dependency count lean.
- Avoid packages that duplicate Laravel/Inertia/React capabilities.
- Document any newly approved package in this file.

This file describes the project's approved usage, not every feature of each library.

---

# Backend

## 2. Laravel

**Project version:** Laravel 13.x  
**Verified project runtime:** Laravel Framework 13.31.0

Use Laravel as the primary application framework for:

- Routing
- Controllers
- Middleware
- Validation
- Authorization
- Eloquent
- Database transactions
- Events
- Queues
- Broadcasting
- Cache
- Sessions
- Testing integration

Project rule:

**Laravel remains the single backend application.**

Do not add Node/Express as a second backend.

---

## 3. PHP

**Version:** PHP 8.4

Use modern PHP features where they improve clarity:

- typed properties
- enums
- readonly/value objects where useful
- match
- constructor property promotion
- strict return types

Keep code compatible with project PHP version.

---

## 4. Eloquent ORM

Use for normal application persistence and relationships.

Good uses:

- Branch-scoped CRUD
- Relationship loading
- Query scopes
- Aggregates
- Transactions with query builder/Eloquent

Use raw SQL only when:

- PostgreSQL-specific constraint/index is required
- performance evidence justifies it
- Eloquent cannot express the operation cleanly

Database constraints remain important even when Eloquent validation exists.

---

## 5. Laravel Fortify

**Installed line:** Fortify 1.x  
**Known project package version:** `^1.37.2`

Use for authentication backend flows such as:

- Login
- Password reset
- Session-oriented auth support

Do not build a second custom auth system unless required.

UI remains custom PONGSKILOG React/Inertia UI.

---

## 6. Laravel Session Authentication

Internal staff authentication uses session/cookie auth.

Use for:

- Super Admin
- Owner
- Cashier
- Kitchen Staff
- Cashier + Kitchen

Customer QR does not use internal staff authentication.

---

## 7. Policies & Gates

Use Laravel Policies/Gates for:

- Role permission enforcement
- Branch authorization
- Sensitive operations

Do not rely on frontend role checks.

---

## 8. Laravel Broadcasting / Reverb

Use for branch-scoped realtime.

Approved realtime areas:

- Store state
- QR queue
- Kitchen
- Customer Display
- Inventory/product availability
- Customer order tracking

Rule:

**Commit DB transaction first, broadcast after commit.**

Reconnect must refetch authoritative DB state.

---

## 9. Laravel Queues

Backend queue driver uses Redis.

Approved queue tasks:

- Image optimization
- Report export
- QR stale/archive maintenance
- Non-critical background processing

Do not queue critical Pay Now/Pay Later/Store Open/Close commits.

---

## 10. Redis

Use for:

- Queue
- Cache
- Supporting realtime infrastructure where configured

Redis is not authoritative for:

- Payments
- Orders
- Inventory
- Store Sessions

PostgreSQL remains source of truth.

### Predis

**Installed line:** `predis/predis ^3.6`

Use Predis as the PHP Redis client for Laravel cache, queues, and Reverb scaling where configured. It keeps local and deployment environments from requiring the PHP `redis` extension.

---

# Frontend

## 11. React

**Version:** React 19.x  
**Known project package line:** `^19.2`

Use React for:

- Page components
- Feature components
- Modals/sheets
- Forms
- Realtime UI updates
- Mobile/tablet responsive interactions

Avoid unnecessary global state tooling.

---

## 12. Inertia.js

**Version:** Inertia 3.x  
**Known packages:** `inertia-laravel ^3`, `@inertiajs/react ^3`

Use Inertia as the bridge between Laravel and React.

Preferred for:

- Internal pages
- Server-driven page props
- Form submissions
- Redirect/validation flow

Do not convert the app into a separate REST SPA unless a real requirement demands it.

---

## 13. TypeScript

Use TypeScript for all new frontend application code.

Required typing areas:

- Page props
- Form data
- Domain DTOs
- Realtime event payloads
- Shared UI contracts

Avoid `any`.

---

## 14. Tailwind CSS

**Version:** Tailwind CSS 4.x

Use for application styling.

Rules:

- Follow `08-ui-rules.md`
- Do not add Bootstrap/material UI styling framework
- Keep design tokens/classes consistent
- Prefer reusable component patterns for recurring UI

---

## 15. Vite

**Version:** Vite 8.x

Use as the frontend build/dev tooling.

Project build must remain compatible with current Vite setup.

Do not introduce another bundler.

---

## 16. Vite Plus / `vp`

If present in project tooling, use only according to existing project configuration.

Do not make application runtime depend on optional development tooling.

---

## 17. Wayfinder

Use Wayfinder where already configured for typed Laravel route interaction from frontend.

Benefits:

- Safer route references
- Better Laravel/TypeScript integration

Do not duplicate route constants manually when Wayfinder already provides them.

---

## 18. Radix UI

Radix primitives are available in the frontend dependency set.

Use selectively for accessible primitives such as:

- Dialog
- Dropdown
- Popover
- Tabs
- Checkbox
- Select

Keep styling in PONGSKILOG design language.

Do not let Radix default appearance dictate the visual system.

---

## 19. Lucide

Lucide is available for interface icons.

Use:

- consistent icon sizing
- semantic icons
- restrained icon usage

Do not mix multiple icon libraries without reason.

---

# Database / Storage

## 20. PostgreSQL

**Provider:** Supabase PostgreSQL

Use PostgreSQL as authoritative relational database.

Important PostgreSQL features used/allowed:

- Transactions
- Foreign keys
- Unique constraints
- Partial unique index
- JSONB where appropriate
- Row locking
- Indexes

Example critical constraint:

One active Store Session per branch via partial unique index.

---

## 21. Supabase

Use Supabase for:

- PostgreSQL hosting
- Object Storage

Do not treat Supabase as a second application backend.

Laravel owns business logic and authorization.

---

## 22. Supabase Storage

Approved storage:

- Product images
- Brand/logo assets
- Cashless display assets
- Store Purchase receipt images
- Other approved uploads

Use optimized image variants for POS.

Do not store image binary blobs in normal PostgreSQL tables.

### AWS S3 Flysystem Adapter

**Installed line:** `league/flysystem-aws-s3-v3 3.0`

Use Laravel's `s3` filesystem disk with this adapter for Supabase Storage's S3-compatible endpoint. Configure credentials, bucket, endpoint, and path-style behavior through environment variables; do not hardcode them.

---

# Testing / Quality

## 23. Pest

**Version:** Pest 5.x

Primary PHP test framework.

Use for:

- Unit/domain tests
- Feature tests
- Authorization tests
- Database integrity tests
- Business flow tests

Critical flows must have automated coverage.

---

## 24. Larastan / PHPStan

Use for static analysis.

Keep new code compliant with configured analysis level.

Do not suppress errors without documented reason.

---

## 25. Laravel Pint

Use Pint for PHP formatting.

Do not manually maintain a conflicting PHP style.

---

## 26. ESLint / TypeScript Checks

Use project frontend lint/type checks for:

- Type safety
- React correctness
- Common code quality issues

CI should run them before merge.

---

# Deployment / Operations

## 27. Railway

Use Railway for Laravel application deployment.

Expected services:

- Web/App
- Queue Worker
- Reverb service where separated

Railway is infrastructure only; business logic stays in Laravel.

---

## 28. GitHub Actions

Use for CI.

Expected checks:

- Pint
- PHPStan/Larastan
- Pest
- ESLint/TypeScript
- Vite build
- Migration smoke tests

Protected branches should require passing checks as project rules evolve.

---

# Tooling

## 29. Laravel Boost

Laravel Boost is approved as project development tooling.

Use it to improve Laravel-aware development assistance where available.

It must not become a production runtime dependency for business behavior.

---

## 30. Codex / VS Code Context

Frozen context files are the primary implementation guidance for Codex/VS Code.

Codex should:

- Read `00-context-index.md`
- Follow frozen business rules
- Follow `14-coding-standards.md`
- Use this library reference before adding dependencies
- Update `13-progress-tracker.md` as phases progress

---

# Dependency Approval Rules

## 31. New PHP Package

Before adding:

- Confirm Laravel 13 compatibility
- Confirm PHP 8.4 compatibility
- Confirm active maintenance
- Confirm no built-in Laravel solution is preferable
- Add tests
- Document purpose here

---

## 32. New Frontend Package

Before adding:

- Confirm React 19 compatibility
- Confirm Inertia architecture remains intact
- Confirm Tailwind/Radix/current dependencies do not already solve it
- Consider bundle impact
- Document purpose here

---

## 33. Avoid by Default

Do not add without explicit need:

- Separate Node/Express backend
- Redux/global state framework
- Second CSS framework
- Second ORM
- Multiple icon libraries
- Microservice framework
- Heavy chart package just for basic metrics
- Client-side database/offline-first sync framework

---

# Approved Stack Summary

| Area | Approved |
|---|---|
| Backend | Laravel 13 |
| Runtime | PHP 8.4 |
| Frontend | React 19 |
| Bridge | Inertia 3 |
| Language | TypeScript |
| Styling | Tailwind CSS 4 |
| Build | Vite 8 |
| Routes | Wayfinder |
| Auth | Fortify + Laravel Session Auth |
| Authorization | Policies / Gates / custom RBAC |
| Database | PostgreSQL / Supabase |
| ORM | Eloquent |
| Queue/Cache | Redis |
| Realtime | Laravel Reverb / Broadcasting |
| Storage | Supabase Storage |
| UI Primitives | Radix UI |
| Icons | Lucide |
| Tests | Pest 5 |
| Static Analysis | Larastan / PHPStan |
| Formatting | Pint |
| Hosting | Railway |
| CI | GitHub Actions |
| Dev Assistance | Laravel Boost / Codex |
