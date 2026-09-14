# PONGSKILOG POS V3 — Context Index

**Status:** FROZEN — Final Context Index  
**Purpose:** Primary entry point for VS Code / Codex / AI-assisted development.

---

## 1. How to Use This Context

Before implementing or modifying PONGSKILOG POS V3:

1. Read this file first.
2. Read the relevant frozen context files for the task.
3. Follow the source-of-truth order below.
4. Do not override frozen business rules based only on an existing prototype or old implementation.
5. Update `13-progress-tracker.md` as development phases are completed.

This context describes the authoritative target behavior of PONGSKILOG POS V3.

---

## 2. Source-of-Truth Priority

If two sources conflict, use this priority:

1. `02-business-rules.md`
2. `03-user-flows.md`
3. `05-database-data-model.md`
4. `04-architecture.md`
5. `06-realtime-contracts.md`
6. `07-security-rbac.md`
7. `08-ui-rules.md`
8. `09-ui-registry.md`
9. `10-build-plan.md`
10. `11-testing-qa.md`
11. `12-deployment-operations.md`
12. `14-coding-standards.md`
13. `15-library-docs.md`
14. Existing implementation
15. Standalone UI prototypes

The prototypes are visual/interaction references only.

They must not override frozen business logic, security, data integrity, or transaction rules.

---

## 3. Context Files

### `01-project-overview.md`

Read for:

- Project scope
- Roles
- Multi-branch model
- Store Session concept
- Pay Later behavior
- Customer QR model
- Owner/Super Admin scope
- Technical foundation

---

### `02-business-rules.md`

Primary business-rule authority.

Read before implementing:

- Orders
- Payments
- Pay Later
- Inventory
- Store Open
- Store Close
- QR
- Void
- Transaction edits
- Store purchases/expenses
- Closing reconciliation

---

### `03-user-flows.md`

Read for exact end-to-end user journeys.

Includes:

- Login/branch selection
- Browse/Open Store
- POS order flow
- Pay Now
- Pay Later
- QR submission/LOAD/archive
- Kitchen
- Store Purchase
- Close Store
- Owner/Super Admin behavior

---

### `04-architecture.md`

Read for:

- Modular monolith structure
- Module boundaries
- Transaction patterns
- Queue responsibilities
- Realtime architecture
- Performance principles
- Store Session architecture

---

### `05-database-data-model.md`

Read before:

- Creating migrations
- Creating Eloquent models
- Adding indexes
- Implementing transaction logic

Contains:

- Tables
- Relationships
- Constraints
- Store Session fields
- Inventory ledger
- QR archive fields
- Audit
- Transaction boundaries

---

### `06-realtime-contracts.md`

Read before implementing:

- Laravel Reverb events
- Channels
- Customer tracking
- Kitchen realtime
- Customer Display
- Store Open/Close realtime
- QR archive events

Core rule:

**Commit DB transaction before broadcasting success.**

---

### `07-security-rbac.md`

Read before any protected feature.

Contains:

- Role boundaries
- Branch authorization
- Browse mode security
- Store Session security
- Payment security
- QR security
- Audit security
- Realtime channel security

---

### `08-ui-rules.md`

Read before UI work.

Contains:

- PONGSKILOG visual system
- Typography
- Mobile density
- Semantic colors
- Login
- POS
- Kitchen
- QR
- Store Purchase
- Close Store
- Error/loading/offline behavior

---

### `09-ui-registry.md`

Master checklist of required screens and states.

Use it to prevent missing:

- Screens
- Modals
- Dialogs
- Empty states
- Error states
- Responsive states
- Workflow surfaces

---

### `10-build-plan.md`

Primary development sequence.

Development phases:

- Phase 0 through Phase 20

Implement by dependency order.

Do not skip integrity foundations just to complete visible UI first.

---

### `11-testing-qa.md`

Read while implementing each phase, not only at the end.

Defines required tests for:

- RBAC
- Branch isolation
- Store Sessions
- Pay Now
- Pay Later
- Inventory
- QR
- Kitchen
- Store Close
- Realtime
- Concurrency
- Responsive QA

---

### `12-deployment-operations.md`

Read for:

- Railway
- Supabase
- Redis
- Reverb
- CI/CD
- Secrets
- Backups
- Migration safety
- Monitoring
- Incident handling

---

### `13-progress-tracker.md`

Development-only progress checklist.

Update checkboxes only after the corresponding work is actually completed.

Do not use it for:

- Context drafting
- Planning conversations
- Design brainstorming

It tracks implementation progress only.

---

### `14-coding-standards.md`

Mandatory engineering rules.

Includes:

- Thin controllers
- Actions/Services
- Transactions
- Idempotency
- Concurrency
- Branch scoping
- React/TypeScript practices
- Testing
- Dependency discipline
- Definition of Done

---

### `15-library-docs.md`

Approved framework/library reference.

Before adding a package:

1. Check this file.
2. Confirm current stack cannot already solve the problem.
3. Confirm compatibility.
4. Keep dependencies lean.
5. Document newly approved dependency.

---

## 4. Frozen Core Business Decisions

The following are authoritative:

### Multi-Branch

- One PONGSKILOG business
- Many branches
- Branch-sensitive records remain branch-scoped

### Store Session

- One active Store Session per branch
- First authorized Cashier enters Opening Cash + Opening Cashless
- Later Cashiers reuse active Store Session
- Store Closed allows Browse or Open Store

### Browse

- Strictly read-only
- Backend-enforced

### Pay Later

Saving Pay Later immediately:

- Commits order
- Deducts inventory
- Creates Kitchen ticket
- Enters Kitchen
- Remains unpaid

Later payment:

- Adds payment
- Does not deduct inventory again
- Does not create another Kitchen ticket

### Customer QR

Initial QR submission:

- Unpaid
- No stock deduction
- No Kitchen ticket

Cashier must LOAD into normal POS before Pay Now or Pay Later commitment.

### QR Archive

- No-action QR submission after 30 minutes → Archived / Unclaimed
- Remaining unclaimed QR submissions → archived during Store Close
- Archived QR does not affect inventory/payment/Kitchen

### Kitchen

Lifecycle:

**KITCHEN → PREPARING → READY → DONE**

All committed Kitchen orders must be DONE before Store Close.

### Store Purchase / Expense

During an Open Store Session, Cashier may record:

- Cash expense
- Cashless expense
- Optional inventory restock
- Optional receipt

Expenses affect expected closing balance.

Inventory-linked purchase also increases stock.

### Close Store

Close is blocked if:

- Any UNPAID / PAY LATER remains
- Any committed Kitchen order is not DONE

Unclaimed QR does not block close.

Cashier enters:

- Closing Cash
- Closing Cashless

System calculates expected vs actual.

Shortage:

- Normal close blocked

Overage:

- Note/reason required
- Close may proceed

### Realtime

**DB commit first → broadcast after commit**

### Inventory

Use:

- Current balance
- Append-only movement ledger
- Compensating movements for edits/voids

### Offline

Do not fake successful:

- Payments
- Pay Later
- Void
- Store Open
- Store Close
- Inventory-changing Store Purchase

---

## 5. Technical Stack

Approved core stack:

- Laravel 13
- PHP 8.4
- React 19
- Inertia 3
- TypeScript
- Tailwind CSS 4
- Vite 8
- Wayfinder
- Fortify
- PostgreSQL / Supabase
- Eloquent
- Redis
- Laravel Reverb
- Supabase Storage
- Railway
- GitHub Actions
- Pest 5
- Larastan / PHPStan
- Pint
- Radix UI
- Lucide
- Laravel Boost / Codex

Do not introduce a separate Node/Express backend.

---

## 6. AI / Codex Working Rules

When using Codex or another coding agent:

1. Inspect current code before modifying.
2. Read relevant context files first.
3. Preserve unrelated working behavior.
4. Do not make broad refactors unless required.
5. Implement the smallest complete change.
6. Never weaken branch isolation/security to make tests pass.
7. Never bypass transaction integrity for UI convenience.
8. Run relevant tests after each meaningful change.
9. Report changed files.
10. Report test evidence.
11. Report blockers honestly.
12. Update `13-progress-tracker.md` only when work is actually completed.

---

## 7. Implementation Change Rule

If development reveals that a frozen rule is impossible, incorrect, or must change:

Do not silently change behavior.

Instead:

1. Stop implementation of the conflicting rule.
2. Identify exact conflict.
3. Propose the smallest rule change.
4. Update the relevant frozen context file intentionally.
5. Update affected architecture/data/tests.
6. Continue implementation after the new rule is accepted.

---

## 8. Definition of Context Complete

The context pack is complete when the repository contains:

- `00-context-index.md`
- `01-project-overview.md`
- `02-business-rules.md`
- `03-user-flows.md`
- `04-architecture.md`
- `05-database-data-model.md`
- `06-realtime-contracts.md`
- `07-security-rbac.md`
- `08-ui-rules.md`
- `09-ui-registry.md`
- `10-build-plan.md`
- `11-testing-qa.md`
- `12-deployment-operations.md`
- `13-progress-tracker.md`
- `14-coding-standards.md`
- `15-library-docs.md`

This set is the primary VS Code / Codex context for PONGSKILOG POS V3.
