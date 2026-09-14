# PONGSKILOG POS V3 — Coding Standards

**Status:** FROZEN — Batch 5  
**Applies to:** All production code in PONGSKILOG POS V3  
**Depends on:** Frozen Context `01`–`12`

---

## 1. Core Engineering Principles

1. Keep the architecture simple.
2. Do not over-engineer.
3. Prefer explicit business rules over clever abstractions.
4. Keep business-critical behavior server-authoritative.
5. Build for correctness first, then optimize measured bottlenecks.
6. Use the existing Laravel modular monolith; do not introduce microservices without demonstrated need.
7. Every branch-sensitive query and mutation must be branch-scoped.
8. Every high-risk write must be transactional.
9. Every destructive/sensitive action must be auditable.
10. Avoid duplicate business logic across controllers/components.

---

## 2. Source-of-Truth Order

When implementation decisions conflict, follow:

1. Frozen context files
2. Database constraints/business rules
3. Tests
4. Existing implementation
5. UI prototype/reference

UI prototypes never override frozen business rules.

If a frozen rule must change, update context intentionally before treating the new behavior as authoritative.

---

## 3. Project Structure

Use feature/domain-oriented organization inside the Laravel application.

Recommended domains/modules:

- Auth / Identity
- Branches
- Store Sessions
- Catalog
- Orders
- Payments
- Inventory
- Kitchen
- Customer QR
- Reporting
- Audit
- Settings

Keep related:

- Models
- Actions/Services
- Requests
- Policies
- Events
- Jobs
- Tests

logically grouped.

Do not create unnecessary layers merely to satisfy a pattern.

---

## 4. Controllers

Controllers should remain thin.

Controller responsibilities:

- Receive request
- Resolve route/context
- Authorize
- Validate through Form Request where appropriate
- Call domain Action/Service
- Return Inertia/JSON/redirect response

Do not place complex:

- payment logic
- inventory reconciliation
- Store Close logic
- order editing
- stock transfer logic

directly in controllers.

---

## 5. Actions / Services

Use explicit Action/Service classes for meaningful business operations.

Examples:

- `OpenStoreSession`
- `CloseStoreSession`
- `CommitPayNowOrder`
- `CommitPayLaterOrder`
- `SettlePayLaterOrder`
- `EditCommittedOrder`
- `VoidOrder`
- `RecordStoreExpense`
- `TransferStock`

An Action should represent one business operation.

Avoid generic `Manager`, `Helper`, or catch-all service classes.

---

## 6. Database Transactions

Use database transactions for operations that must succeed/fail together.

Required examples:

- Open Store
- Pay Now
- Pay Later
- Pay Later settlement where multiple records change
- Committed-order edit
- Void
- Store Purchase with inventory restock
- Stock transfer state transitions
- Close Store

Never emit success realtime events before transaction commit.

---

## 7. Concurrency

Use DB constraints, row locking, version checks, or equivalent techniques for race-sensitive flows.

Critical concurrency cases:

- One active Store Session per branch
- Last inventory unit
- Payment double-submit
- Pay Later double-submit
- QR LOAD duplication
- Close Store duplication
- Stock transfer receive duplication

Do not solve concurrency only with disabled frontend buttons.

---

## 8. Idempotency

Use idempotency for high-risk operations where repeat requests could duplicate effects.

Required:

- Pay Now
- Pay Later commit
- Payment settlement
- Other retry-sensitive financial writes

Do not reuse one idempotency key across unrelated operations.

---

## 9. Models

Models should:

- Define relationships
- Define useful casts
- Define simple query scopes
- Avoid oversized business methods
- Avoid hidden side effects

Do not use model events for complex financial/inventory workflows where execution order becomes unclear.

Business-critical mutations should be explicit in Action/Service classes.

---

## 10. Eloquent Queries

Rules:

- Scope branch-sensitive queries
- Eager-load required relationships
- Avoid N+1
- Select only needed columns for heavy lists
- Paginate transaction/history/report tables
- Use aggregate queries for reports where practical

Do not fetch all branch data then filter in PHP.

---

## 11. Branch Scoping

Never trust `branch_id` from client payload by itself.

Branch-scoped operations must derive/validate branch against:

- authenticated user
- branch assignment
- active branch context
- target record ownership

Owner/Super Admin business-wide access is explicit policy behavior.

---

## 12. Authorization

Use:

- Policies
- Gates
- Explicit permission checks

Frontend hiding is convenience only.

Every protected mutation requires backend authorization.

Dedicated Super Admin surfaces:

- Audit Trail
- Void Orders
- Access Control

must remain backend-protected.

---

## 13. Validation

Use Laravel Form Requests for non-trivial request validation.

Validate:

- Required fields
- Enum/state values
- Monetary values
- Quantity
- Branch relationship
- Table relationship
- Product availability
- Store Session state
- Inventory availability

Server recalculates financial totals.

Never trust client-provided total as authoritative.

---

## 14. Money

Do not use floating-point math for authoritative currency calculations.

Use:

- integer minor units where practical; or
- exact decimal database types with controlled arithmetic

Use one consistent project approach.

Round explicitly and consistently.

---

## 15. Statuses / Enums

Use explicit enums/value objects where they improve safety.

Examples:

- Branch status
- Store Session status
- Payment status
- Payment term
- Kitchen status
- Order source
- Order type
- Inventory movement type
- Stock transfer status

Avoid scattered magic strings.

---

## 16. Inventory

Inventory rules:

- Current balance + append-only movement ledger
- Never silently rewrite movement history
- Changes happen transactionally
- Negative stock blocked by default
- Order edits use delta/compensating movement
- Void restores via compensating movement

Do not recompute historical movement rows.

---

## 17. Payments

Payment records are append-only in normal application behavior.

Do not overwrite original payment history to simulate a correction.

Use explicit:

- additional payment
- adjustment/correction
- void/audit

according to frozen business rules.

---

## 18. Audit

Audit sensitive actions explicitly.

Audit records should capture enough context to understand:

- actor
- branch
- action
- target
- before/after where relevant
- timestamp
- metadata

Do not use application logs as a substitute for business audit history.

---

## 19. Events & Realtime

Event names must describe business outcomes.

Examples:

- `store.opened`
- `store.closed`
- `order.committed`
- `order.payment_updated`
- `kitchen.ticket_created`
- `kitchen.status_changed`
- `inventory.changed`
- `qr.order_archived`

Events are emitted after commit.

Keep payloads small.

Do not leak sensitive reconciliation data onto general branch channels.

---

## 20. Queues

Good queue candidates:

- Image optimization
- Report exports
- QR stale/archive maintenance
- Non-critical notifications

Do not move authoritative financial/inventory commits into a background queue.

Queue jobs should be safe to retry.

---

## 21. React Components

React components should remain focused.

Prefer:

- Small reusable primitives
- Feature components
- Page-level orchestration

Avoid giant pages containing all logic and markup.

Do not over-componentize trivial markup.

---

## 22. React State

Prefer local state for local UI behavior.

Use server-provided Inertia data for authoritative application state.

Use realtime updates to refresh/update only necessary areas.

Do not introduce a large global state library unless a real requirement appears.

---

## 23. Forms

Use consistent form patterns.

Requirements:

- Clear labels
- Inline validation
- Disabled/loading state
- Prevent duplicate submission
- Preserve button dimensions during loading
- Accessible focus behavior

High-risk forms must visually communicate result/error clearly.

---

## 24. TypeScript

Rules:

- Avoid `any` unless justified
- Type page props
- Type domain DTOs
- Type event payloads
- Type form data
- Prefer shared domain types when genuinely reused

Do not duplicate incompatible interfaces for the same domain object.

---

## 25. Naming

Use clear business terminology.

Examples:

Good:
- `StoreSession`
- `closingCashAmount`
- `paymentTerm`
- `inventoryMovement`
- `archivedUnclaimed`

Avoid vague names:
- `data`
- `stuff`
- `temp`
- `handler2`
- `misc`

Methods should describe intent.

---

## 26. PHP Style

Follow Laravel conventions and Pint.

Use:

- typed method parameters
- return types
- strict, readable conditions
- early returns where clearer

Avoid deeply nested control flow.

---

## 27. Frontend Style

Use:

- React
- TypeScript
- Tailwind CSS
- Existing component primitives already in project

Do not introduce another styling framework.

Keep visual implementation aligned with `08-ui-rules.md`.

---

## 28. Accessibility

Required:

- Semantic buttons
- Proper labels
- Keyboard accessibility
- Visible focus
- Sufficient contrast
- Do not rely only on color
- Practical touch targets

---

## 29. Product Images

Rules:

- Use optimized variants
- Preserve aspect ratio
- Prevent layout shift
- Lazy-load below fold
- Do not serve full-resolution originals to small cards
- Use lightweight fallback

Image processing must not block POS.

---

## 30. Error Handling

Errors should be:

- Specific
- Recoverable where possible
- Logged when technically important
- Safe to show to users

Never expose:

- stack traces
- secrets
- raw SQL
- internal tokens

Do not silently swallow integrity-related exceptions.

---

## 31. Logging

Use technical logs for:

- Exceptions
- Integration failures
- Queue failures
- Realtime failures
- Payment/Inventory transaction failure
- Store Open/Close failure

Avoid excessive low-value logs in hot POS paths.

Never log secrets/passwords/raw tokens.

---

## 32. Tests

Every critical Action should have relevant tests.

Priority test areas:

- Branch isolation
- Store Session concurrency
- Pay Now
- Pay Later
- Inventory delta
- Void
- QR LOAD
- Store Purchase
- Close Store
- Realtime authorization
- Stock transfer

Fix behavior first; do not simply alter tests to accept incorrect behavior.

---

## 33. Migrations

Migrations must be:

- Reviewed
- Forward-safe
- Tested
- Explicit

Prefer additive migrations for live systems.

Do not silently drop business-critical history.

---

## 34. Performance

Default rules:

- Avoid N+1
- Index high-traffic filters
- Paginate large history
- Cache read-heavy non-critical values
- Queue heavy exports
- Keep realtime payloads small
- Optimize images

Do not introduce caching that risks stale financial/inventory truth.

---

## 35. Security

Never:

- trust client role
- trust client branch
- trust client total
- expose secrets
- bypass backend policy for prototype convenience
- rely on UI hiding as security

---

## 36. Dependency Discipline

Before adding a new package:

1. Check if Laravel/React/current dependencies already solve the problem.
2. Confirm package is maintained.
3. Confirm compatibility with current stack.
4. Confirm it reduces complexity rather than adds it.
5. Document it in `15-library-docs.md`.

Avoid package sprawl.

---

## 37. Code Review Checklist

Before merge:

- Business rule matches frozen context
- Branch scope correct
- Authorization correct
- Transaction boundary correct
- Concurrency considered
- Audit included where required
- Realtime only after commit
- Tests added/updated
- No N+1 / obvious performance regression
- Mobile/tablet UI checked when frontend changed
- No dead buttons
- No unrelated refactor mixed into feature

---

## 38. Definition of Done

A development item is Done only when:

- Implementation works
- Relevant tests pass
- Authorization is correct
- Branch isolation verified
- Data integrity preserved
- UI state complete
- Error/loading states handled
- Realtime correct where applicable
- Progress tracker updated
