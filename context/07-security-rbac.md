# PONGSKILOG POS V3 — Security & RBAC

**Status:** FROZEN — Batch 3  
**Depends on:** Frozen Batch 1–2 (`01`–`06`)

---

## 1. Security Goal

PONGSKILOG POS V3 must protect:

- Branch data
- Payments
- Inventory
- Store Sessions
- Staff access
- Audit history
- Customer QR tracking
- Super Admin controls

Security is enforced on the backend. Hidden buttons alone are never considered authorization.

---

## 2. Authentication

Internal users authenticate using Laravel session authentication with Fortify.

Internal roles:

- Super Admin
- Owner
- Cashier
- Kitchen Staff
- Cashier + Kitchen

Customer QR users do not require an internal account.

Customer Display is not an internal role.

---

## 3. Authorization Order

Every protected request must validate:

1. User is authenticated.
2. User account is active.
3. Required role/permission exists.
4. User is authorized for the requested branch.
5. Record belongs to the authorized branch.
6. Branch status permits the action.
7. Store Session state permits the action.
8. Business-specific action rules pass.

---

## 4. Role Scope

### Super Admin

Business-wide highest authority.

Dedicated control surfaces:

- Audit Trail
- Void Orders
- Access Control

May inspect all branches.

### Owner

Business-wide management access.

May access:

- Dashboard
- Transactions
- Reports
- Products
- Inventory
- Staff
- Settings

May switch:

- All Branches
- Specific Branch

Owner does not receive dedicated:

- Audit Trail
- Void Orders
- Access Control

### Cashier

Branch-scoped operational role.

May access:

- Cashier Dashboard
- POS / Order
- QR Orders
- Transaction History
- Store Open / Close flow
- Allowed Store Purchase / Expense actions

Operational mutations require an Open Store Session.

### Kitchen Staff

Branch-scoped Kitchen role.

May access:

- Kitchen / KDS
- Customer Display launch where permitted

May not access:

- Payments
- Closing balances
- Staff management
- General settings
- Audit
- Access Control

### Cashier + Kitchen

Union of intended Cashier and Kitchen permissions for authorized branches.

---

## 5. Branch Authorization

Staff may be assigned to one or more branches.

Branch-sensitive requests must validate assignment server-side.

Changing URL/request payload must never allow cross-branch access.

Owner and Super Admin may use business-wide access according to role policy.

---

## 6. Active Branch Context

If one branch is assigned:

- system may enter it automatically.

If multiple branches are assigned:

- user chooses active branch.

Changing active branch requires:

- active user account
- valid branch assignment
- permitted role

Active branch context is convenience only; backend record ownership checks remain mandatory.

---

## 7. Store Session Security

### Store Closed

Cashier sees:

- Browse
- Open Store

### Browse

Read-only.

Backend rejects mutation attempts including:

- New order
- Edit order
- Payment
- Pay Later
- Void
- Delete
- Inventory adjustment
- Store Purchase / Expense
- Other operational CRUD

### Open Store

Allowed only to authorized Cashiers assigned to branch.

Required:

- Opening Cash
- Opening Cashless

Database enforces one active Store Session per branch.

### Store Already Open

Additional authorized Cashiers use the same active Store Session.

They may not create a second concurrent Store Session.

---

## 8. Close Store Security

Authorized Cashier may initiate Close Store for current branch.

Backend rechecks:

- Store Session is Open
- No unresolved UNPAID / PAY LATER
- All committed Kitchen orders are DONE
- Closing values are valid
- Required overage note exists when applicable

Shortage blocks normal close.

Close Store must be atomic and audited.

---

## 9. Payment Security

Pay Now and Pay Later commitment are high-risk actions.

Requirements:

- Authorized Cashier
- Correct branch
- Open Store Session
- Valid order state
- Server-side total calculation
- Inventory validation
- Idempotency
- Atomic DB transaction

Never trust client totals as authoritative.

---

## 10. Pay Later Security

Saving Pay Later commits:

- Order
- Inventory deduction
- Kitchen ticket

Later settlement only adds payment/update state.

Backend must prevent:

- Duplicate inventory deduction
- Duplicate Kitchen ticket
- Duplicate settlement caused by replay/double-click

---

## 11. Void Security

Void requires:

- An active assigned Cashier or Cashier+Kitchen initiator, or a full-access Super Admin on the selected active Branch, with POS access
- Reason
- The one global four-digit PIN configured by an active Super Admin and stored only as a hash
- Attribution of the configuring Super Admin as a distinct authorizer
- Correct branch
- Valid order state
- Audit

Original records remain.

If stock was previously deducted:

- restore through compensating inventory movement.

Dedicated Void Orders history is Super Admin-only.

Kitchen-only and Owner-only identities do not gain the operational Cashier Void action. A full-access Super Admin may initiate a Void for the selected active Branch (see Super Admin foundation, 2026-09-24), but the configuring Super Admin can never approve their own initiation. Owner is denied Audit Trail, Void Orders, and PIN configuration. Possession of the configured PIN is intentionally delegated approval authority; it is not per-user password re-authentication. The plaintext PIN is never persisted, audited, returned, logged, or broadcast.

---

## 12. Inventory Security

Every inventory mutation must record:

- Branch
- Product
- Quantity delta
- Reason/source
- User/system actor
- Timestamp

Normal Cashier does not receive unrestricted inventory administration rights.

Inventory mutations include:

- sale
- pay_later_commit
- order_edit_delta
- void_restore
- manual_adjustment
- store_purchase_restock
- transfer_out
- transfer_in

---

## 13. Store Purchase / Expense Security

Allowed only during an Open Store Session and for authorized branch.

Each entry must be tied to:

- Branch
- Store Session
- User

If inventory-linked:

- expense and stock movement commit consistently.

Cash/Cashless payment source affects closing reconciliation.

---

## 14. Staff & Access Management

Owner may manage normal operational staff.

Normal staff management may assign:

- Cashier
- Kitchen Staff
- Cashier + Kitchen
- Branch assignments

Super Admin / Owner elevation must not be available through normal staff CRUD unless explicitly authorized by higher-level access control.

Access Control page remains Super Admin-only.

Phase 18 (2026-09-25): editing existing accounts, Role baselines and per-account custom access are specified in "Phase 18 — Access Control, Staff administration and Notifications" at the end of this file.

---

## 15. Customer QR Security

Customer QR uses a branch-bound anonymous session/token.

Requirements:

- No customer account required
- No IP-based primary identity
- Avoid fingerprinting
- High-entropy token
- Hash token where practical
- Customer can access only own active order/tracking scope
- Branch/order ID manipulation must not expose another customer’s order

QR archive/replay behavior must remain branch-safe.

---

## 16. Customer Display Security

Customer Display exposes only safe branch-scoped data:

- Preparing order numbers
- Ready order numbers

Never expose:

- Prices
- Payment data
- Customer private data
- Staff data
- Audit/admin controls

---

## 17. Realtime Security

Private branch channels require server-side authorization.

Customer tracking must use a narrow order/session channel, not broad branch channels.

General branch channels must not expose:

- Opening balances
- Closing balances
- Variances
- Sensitive audit metadata

---

## 18. Audit Security

Audit is append-only in normal application behavior.

Audit must cover:

- Store Open
- Opening Cash/Cashless
- Store purchases/expenses
- Closing Cash/Cashless
- Variances
- Store Close
- Order edits
- Voids
- Payment corrections
- Inventory adjustments
- Transfers
- Staff branch assignments
- Access changes
- Settings changes

Normal users cannot edit/delete audit history.

---

## 19. Sensitive Data Handling

Do not expose sensitive values in:

- Public URLs
- Realtime payloads unnecessarily
- Customer Display
- Customer QR public surfaces
- Client logs

Never expose:

- Password hashes
- Secrets
- Private auth tokens
- Sensitive reconciliation details to unauthorized roles

---

## 20. Session / Request Security

Use Laravel best practices for:

- CSRF
- Secure cookies
- Session regeneration
- Login rate limiting
- Sensitive endpoint rate limiting
- Request validation
- Authorization on every mutation

---

## 21. Public Endpoint Protection

Customer QR endpoints require:

- Rate limiting
- Branch validation
- Product availability validation
- Session/token validation
- Duplicate-submit protection
- Server-side totals

Archived QR must not be replayed into another branch.

---

## 22. Offline / Reconnect Security

If offline, block:

- Payment
- Pay Later
- Void
- Store Open
- Store Close
- Inventory-changing Store Purchase

Do not queue irreversible financial writes silently.

---

## 23. Frozen Security Decisions

Frozen:

- Backend-enforced RBAC
- Backend-enforced branch isolation
- Browse mode is read-only server-side
- One active Store Session per branch
- Pay Now/Pay Later are atomic high-risk writes
- Customer tracking is narrow-scoped
- Customer Display exposes safe projection only
- Audit is append-only
- Sensitive reconciliation data is not broadcast to general staff
- Offline financial writes are blocked

## Phase 14 Store expense authorization - 2026-09-23

- Expense create, current-session expense projection, private receipt streaming, and the Store Session realtime channel require an active, Branch-assigned `cashier` or `cashier_kitchen` with `store_expenses.manage`. Kitchen-only, Owner, unassigned, inactive, unauthenticated, and foreign-Branch access is denied; role-wide management status does not bypass the operational boundary.
- The server derives the active Branch and current OPEN Store Session. Client Branch/Session identifiers are not authoritative. Creation takes the current Session shared lock, while future Close Store must take that boundary exclusively.
- Receipts accept only validated JPG/JPEG/PNG/WebP images within the existing 2 MB and dimension bounds. Objects stay on the configured private disk and are served through an authorized Branch-scoped route with `private, no-store`; raw storage paths are never returned. Failed transactions remove newly stored objects.
- Expense records and items reject normal update/delete behavior. Audit metadata excludes receipt bytes/path and credentials. A truly offline browser cannot submit and no irreversible expense is queued locally. A Reverb/Echo disconnect alone is not treated as network offline and does not block an authoritative HTTP expense commit.

## Phase 15 Close Store authorization - 2026-09-23

Preview and close require an active user with `pos.access` and `store.open_close`, a Cashier or Cashier+Kitchen role, and an active assignment to the active Branch; the server derives the Branch and OPEN Store Session. Kitchen-only, Owner, unassigned and inactive users are denied; Super Admin is denied unless actually assigned as a Cashier. Payment-correction allocation requires `pos.access`, `transactions.view` and a Cashier or Cashier+Kitchen role (not `store.open_close`), for a correction in the current OPEN Store Session of the active Branch. Store Session inventory adjustment requires `store_expenses.manage`, a Cashier or Cashier+Kitchen role and an active assignment to the active Branch; the Product must be inventory-tracked in that Branch, and Kitchen-only, Owner, guest, inactive and unassigned users are denied. Reconciliation values stay in authorized HTTP responses and the protected Audit Trail and are never broadcast.

## Super Admin foundation — full operational access and Staff creation — 2026-09-24

**Supersedes** the Phase 14 and Phase 15 statements above that deny Super Admin Store expenses, Store inventory adjustment, Open/Close Store, and Cashier POS actions unless actually assigned as a Cashier. The product owner approved full operational parity for Super Admin.

- Super Admin is the full-access role. Cashier POS and Store Session authorization now goes through `User::hasCashierOperationsRole()` (cashier, cashier_kitchen, super_admin) and `User::hasOperationalBranchAccess()` (an active assignment, or business-wide Super Admin). This covers `PosAccess`, every Cashier FormRequest, `OpenStoreSession`, the Cashier workspace, the current Store Session endpoint, and the private `store-session` channel.
- Super Admin is never given fabricated Branch assignments. It operates only the active Branch it selects, which must be Active. Store Session state, reconciliation, idempotency, stock and payment invariants are unchanged, and every action is attributed and audited as the Super Admin.
- Owner business-wide scope alone still grants no Cashier operations. Kitchen and Customer Display were already permission-based and now open for Super Admin once a Branch is selected.
- Void keeps two-person authorization: a Super Admin may initiate, but the Super Admin who configured the global PIN cannot approve their own initiation.
- Authorization stays backend-authoritative. Hidden or visible navigation is never the control; branch isolation (404 for another Branch's records) is unchanged.

### Staff account creation

- `GET/POST workspaces/super-admin/staff` requires an active user with `access_control.manage`, enforced by route middleware, the FormRequest, and the action.
- Phase 16D: `GET/POST workspaces/staff` (`staff.index` / `staff.store` / `staff.avatar`) requires `staff.manage` (route middleware) and business-wide scope. `StaffRoles::manageableBy()` is the one role authority used by the FormRequests and re-checked inside `CreateStaffAccount`: `access_control.manage` covers every role; Owner `staff.manage` covers **Cashier, Kitchen Staff and Cashier + Kitchen only**. An Owner submitting Owner or Super Admin gets "Choose a valid role." and the action refuses it even if called directly. The Owner list shows only accounts whose every role is operational. Owner never gains `access_control.manage`, Access Control, Audit Trail, Void Orders or the Super Admin Staff routes.
- Assignable roles are the seeded canonical roles only. Creating Owner or Super Admin is allowed here because this is the higher-level access-control surface required by §14. Owner and Super Admin are business-wide and reject Branch assignments. Cashier, Kitchen Staff, and Cashier + Kitchen require at least one Active Branch, rechecked under lock inside the transaction.
- The temporary password is chosen by the Super Admin, validated with `Password::default()` plus confirmation, and hashed by the User `hashed` cast. It is never persisted in plaintext, logged, audited, broadcast, returned in page props, or shown again. There is no invite email, forced password change, first-login flow, or password expiry.
- User, role, Branch assignments, active state, and one `staff/staff.created` Audit record commit in one transaction. The Audit records user id, Employee ID, name, email, role, Branch ids/codes, business-wide flag, and active state, with no password, confirmation, hash, secret, or token. Duplicate emails, including races on the unique index, return a validation error, so retries cannot create a second user or Audit record.
- An optional staff profile picture set by the Super Admin (JPG/PNG/WebP, 64–8000px, up to 2 MB, no SVG) is stored on the private `staff_avatars_disk` (default `local`) under `staff-avatars/{user}` and served only through `super-admin.staff.avatar` (`access_control.manage`, `nosniff`) or, for operational Staff an Owner manages, `staff.avatar` (404 for any account outside that scope, e.g. Owner or Super Admin). A failed creation deletes the stored file. The Audit records only `has_profile_picture`, never the path. This is admin-set; staff self-service avatar upload remains out of scope.
- ~~The Access Control matrix remains unimplemented.~~ Superseded by Phase 18 (below): Access Control is a real, backend-enforced page.

### Phase 16B–16D Owner workspace authorization - 2026-09-24

- `workspaces.owner` (Owner Dashboard), `workspaces.reports` and `workspaces.reports.export` require `reports.view` plus business-wide scope (Owner, Super Admin). *Phase 18: Reports and its export also accept a Branch-scoped account with custom `reports.view`, limited to its selected assigned Branch (see Phase 18 below); the Owner Dashboard stays business-wide only.* The export is throttled and built only from the authorized, scoped and filtered report arrays.
- `workspaces.transactions` and `workspaces.transactions.show` require `transactions.view` plus business-wide scope. A selected Branch limits both to that Branch (another Branch's Order is 404). Voided Orders are 404. Capabilities (`can_edit`/`can_settle`/`can_void`) are computed on the server from POS access to the selected Branch; the Owner never has it, so the Owner's POS write requests are 403 (`permission:pos.access`). The Cashier `workspaces.transaction-history` route and its `hasCashierOperationsRole` authorization are unchanged.
- Settings reuse the existing Branch Management, receipt and QR endpoints (`BranchPolicy`, `settings.manage` + business-wide); operational staff remain 403.

### Phase 16E Owner Operations authorization - 2026-09-24

- All `operations.*` routes require `permission:inventory.manage`; `OperationsAccess` re-checks on every page and action: an **active, business-wide** user (Owner or Super Admin) with `inventory.manage`. Cashier, Kitchen Staff, Cashier + Kitchen, guests and inactive users are denied (403 / redirect). Hidden navigation is never the control.
- Cashier-originated POS sales still consume Ingredients inside the existing Pay Now / Pay Later / Edit / Void transactions as domain behavior; that grants no Operations access.
- The Branch is always the server-side global Branch context (`ActiveBranchContext`), never a browser `branch_id`. Opening stock, wastage, count correction, manual list items, skips and Confirm Pamamalengke require one concrete **active** Branch; All Branches is read-only. List entries of another Branch are 404. Plans, Ingredients, Products and manual entries are resolved and validated on the server (existing Products only, active Plans/Ingredients only, the Product's own sizes only).
- Confirm Pamamalengke is the only path by which an Owner records a Store Purchase. It uses the canonical `RecordStoreSessionExpense::persist()` under the same OPEN Store Session shared lock and idempotency rules as the Cashier form (no alternate expense path) and is audited as the Owner. Close Store still takes the Store Session exclusively.
- Audit (`module = operations`): `operation_plan.created|updated|archived` (with moved Products), `ingredient.created|updated|archived|restored`, `recipe.saved|removed|mode_changed`, `ingredient.wastage_recorded`, `ingredient.count_corrected`, `pamamalengke.confirmed` (restocks and cost updates), plus the canonical `store_expense_recorded`. Order edits and voids record `ingredient_deltas` / `ingredient_restorations` in their existing audit metadata.

### Phase 16E Final QA authorization (2026-09-24)

- **Giveaway / reversal / giveaway catalog**: authenticated active user with `pos.access` + `store_expenses.manage` and Cashier operations (`PosAccess`: assigned active cashier/cashier_kitchen or business-wide Super Admin) on the server-chosen active Branch; OPEN Store Session required. Owner (no Cashier operations), Kitchen, guests and inactive users are rejected. Browser ids (product, options) are validated against the Branch catalog; a Giveaway of another Branch returns 404 and only the Giveaway's own open Store Session may reverse it.
- **Customer QR capacity** (`qr.recipe-capacity`) now also requires an active Branch with QR ordering enabled, like the QR menu and submission. It returns fit booleans only; the coarse yes/no for a chosen quantity is an accepted disclosure equal to what order submission already reveals.
- **Branch switch return path**: only a same-application path (`/…`, no scheme, host, `//`, backslash or whitespace) is followed; the Branch selection policy is unchanged.
- Pamamalengke: a skip mark is accepted only for an active Ingredient of the Plan, and the manual-item delete route cannot remove skip marks.

## Phase 18 — Access Control, Staff administration and Notifications — 2026-09-25

### Effective permissions (one authority)

- Role = baseline permissions (`role_permissions`); account = optional explicit exception (`user_permission_overrides`, `allow` or `deny`; no row = INHERIT).
- `App\Support\EffectivePermissions` is the only resolver. `User::hasPermission()`, the `permission:` middleware, every FormRequest/action check, broadcast channels and the shared `auth.permissions` prop all resolve through it, so navigation and backend decisions always agree. Nothing is cached between requests: a revoked permission is denied on the very next request or mutation.
- For a non-Super-Admin: ALLOW override → yes; DENY override → no; otherwise the Role baseline (union over its roles).
- **Super Admin is locked full access**: its baseline is always every permission (the seeder re-completes it), overrides are ignored for it and are never written for it, and its baseline cannot be edited. An ALLOW override never grants `audit.view`, `void_orders.manage` or `access_control.manage`, even if such a row were inserted directly.
- Permission = WHAT; Role + Branch assignment = WHERE. A permission never widens scope.

### Grant envelope (PermissionCatalog)

`App\Support\PermissionCatalog` is the single catalog (label, description, category, scope, first-install defaults, grant envelope, lock reasons) used by the seeder, the actions and the Access Control page.

| Role | May hold (baseline or custom) | Locked, with reason |
| --- | --- | --- |
| Owner | Transactions, Reports, Products, Inventory, Staff, Settings | POS, QR, Store Open/Close, Expenses, Kitchen, Customer Display (operations belong to Branch staff); Control permissions (Super Admin only) |
| Cashier | POS, Transactions, Store Open/Close, Expenses, Kitchen, Customer Display, **Reports (own Branch)** | Products, Inventory, Staff, Settings (business-wide, cannot be Branch-limited); Audit Trail, Void Orders, Access Control (Super Admin only) |
| Kitchen Staff | Kitchen, Customer Display, **Reports (own Branch)** | Cashier operations (need a Cashier role); business-wide and Control permissions |
| Cashier + Kitchen | derived: union of Cashier and Kitchen Staff | not edited directly |
| Super Admin | everything | locked full access |

QR Orders has no route of its own (it is enforced through POS) and always follows POS in a Role baseline; it is not individually configurable.

### Writes

- Access Control routes (`super-admin.access-control*`) require an active account with `access_control.manage`; the actions re-read the actor inside the transaction.
- **Role baseline** (`UpdateRolePermissions`): Owner, Cashier or Kitchen Staff only; only catalog permissions inside the envelope (unknown names are rejected, not ignored); locked baseline entries are kept; Roles are locked `FOR UPDATE` in id order and Cashier + Kitchen is re-derived as the union in the same transaction; an unchanged submission records nothing. Audit `access_control/access.role_permissions_updated` with before/after lists, added/removed and the derived Cashier + Kitchen before/after.
- **Custom access** (`UpdateUserPermissionOverrides`): locks the account row (serializing with Staff role changes); accounts must have exactly one staff role; Super Admin accounts are refused. INHERIT deletes; an ALLOW of an included permission or a DENY of an excluded one is stored as INHERIT (no meaningless rows). Audit `access.user_override_updated` / `access.user_overrides_reset` with before/after maps.

### RBAC seeding rule (live configuration is never reset)

`RbacSeeder` stays safe for fresh installs, tests and every deployment rerun:

- a Role receives its catalog defaults only when the seeder creates that Role;
- a Permission new in this run is granted to the Roles whose defaults include it;
- existing Role ↔ Permission pairs are never removed or re-added, so an edited baseline survives;
- Super Admin is always completed to every permission; Cashier + Kitchen is always re-derived from Cashier ∪ Kitchen Staff.

### Branch-scoped Reports (custom access)

- `ReportsRequest` requires `reports.view` only; `ReportsController` then derives the scope: Owner/Super Admin keep the selected Branch or All Branches; a Branch-scoped account uses its selected **assigned** Branch (`ActiveBranchContext`, which rejects any other Branch) and is redirected to choose one instead of ever receiving All Branches. The session filter only matches that Branch's Store Sessions. The CSV export follows the same scope.
- The Owner Dashboard (`workspaces.owner`) stays business-wide only. Branch staff see Reports inside their operational shell.
- Realtime: `branch.{branch}.reports` (reports.view + access to that Branch) for Branch-scoped viewers; the business-wide `reports` channel still requires business-wide scope.

### Staff administration

- `PUT super-admin/staff/{user}` (Super Admin, every account) and `PUT workspaces/staff/{user}` (Owner `staff.manage`, operational accounts only) run `UpdateStaffAccount`: name, email (unique ignoring case), one Role, Branch access, active status, picture replace/remove. The Employee ID is never changed.
- Locks every Super Admin row plus actor and target in id order first. **At least one active Super Admin always remains**; nobody changes their own role or deactivates themselves; a crossing race (A deactivates/demotes B while B deactivates/demotes A) lets exactly one win (verified on PostgreSQL).
- A Role change clears Branch assignments for Owner/Super Admin, requires at least one active Branch for operational Roles, and **resets custom access to INHERIT** (recorded in the audit).
- Deactivation rotates the remember token and, with the database session driver, deletes that account's session rows; `EnsureUserIsActive` signs an inactive account out on its next request and channels refuse inactive users. Accounts are never deleted.
- `PUT super-admin/staff/{user}/password` (Super Admin only, never oneself): new temporary password with `Password::default()` + confirmation, hashed by the User cast, never returned, logged, audited or notified; the remember token is rotated, database sessions are deleted, and `AuthenticateSession` (web middleware) signs out any other session whose stored password hash no longer matches.
- Audit (`module = staff`): `staff.updated`, `staff.role_changed`, `staff.branch_access_changed`, `staff.deactivated`, `staff.reactivated`, `staff.avatar_updated`, `staff.avatar_removed`, `staff.password_reset` — one row per distinct change category, readable before/after, no credentials.

### Notifications

- In-app only (Laravel database notifications, `notifications` table). The Control Center notification center is Super Admin only (`access_control.manage`); a viewer reads and marks only their own rows (another account's id is 404).
- Recipients: active Super Admins, excluding the actor. Delivery runs after the business transaction commits and is rescued (a failed notification never fails the change). Payloads hold category, title, summary and a server-generated same-app link only — no credentials, audit payloads or money.
- Realtime: `notifications.changed` (event id/type/time only) on the recipient's own `App.Models.User.{id}` channel, which now also requires an active account.

## Phase 18 final — Custom Roles — 2026-09-25

### Model

- **System Role** = one of the five built-in roles (Super Admin, Owner, Cashier, Kitchen Staff, Cashier + Kitchen), identified by its unchanged machine name, never editable as a custom record, never archived or deleted. Super Admin stays **Locked · Full access**; Cashier + Kitchen stays derived.
- **Custom Role** = a reusable permission package a Super Admin creates (e.g. "Branch Supervisor"), shared by many Staff accounts. Stable key `custom_{id}`; editable display name (trimmed, single-spaced, ≤ 40 characters, letters/numbers/spaces and `& + - / ( ) . ' ,`, unique ignoring case among active roles including System names).
- **User override** = the existing per-account ALLOW / DENY exception. Effective access for a Custom Role account = ALLOW → yes, DENY → no, otherwise the Custom Role baseline (the same `EffectivePermissions` resolver; no second engine).

### Scope (WHERE) is separate from permissions (WHAT)

- **Branch role**: every account needs ≥ 1 active Branch; everything stays inside its assigned Branches (Reports: selected assigned Branch only, never All Branches; `branch.{branch}.reports` channel only). A Branch Custom Role runs Cashier operations like a Cashier (`Role::scopeCashierOperations()`), still gated by each permission (e.g. POS needs `pos.access`).
- **Business-wide role**: no Branch assignments (fabricated ones are rejected); reaches every Branch through `User::hasBusinessWideScope()`, which is now metadata-driven (`Role::scopeBusinessWide()`: Owner/Super Admin by name, plus active business-wide Custom Roles). It never gains Cashier operations or Control permissions.
- Scope can change only while **no** account holds the role (checked under the Role row lock that Staff assignment also takes); otherwise reassign Staff first or create a new role.

### Grant envelope (PermissionCatalog::CUSTOM_GRANTABLE)

| Scope | May hold (baseline or user ALLOW) | Locked |
| --- | --- | --- |
| Branch | POS (QR Orders follows), Transactions, Store Open / Close, Expenses, Kitchen, Customer Display, Reports (own Branch) | Products, Inventory, Staff, Settings (business-wide, cannot be Branch-limited); Audit Trail, Void Orders, Access Control |
| Business-wide | Transactions, Reports, Products, Inventory, Staff (operational Staff only, like the Owner), Settings | POS, QR, Store Open / Close, Expenses, Kitchen, Customer Display (Branch operations); Audit Trail, Void Orders, Access Control |

Every business permission's backend was checked: business Transactions, Owner Dashboard, Operations, Branch settings and Staff management already require business-wide scope; Inventory/Products are gated by permission and stay business-wide. A per-user ALLOW is limited by the same envelope (`PermissionCatalog::lockReason(Role, …)`), so it can never escape the role's scope or reach Control.

### Custom Role Builder (Super Admin only)

- Routes (`super-admin.access-control.custom-roles.store|update|archive`) sit in the `permission:access_control.manage` group (throttled); `SaveCustomRoleRequest` authorizes again; `CreateCustomRole` / `UpdateCustomRole` / `ArchiveCustomRole` re-read the actor inside the transaction. Owner, Cashier, Kitchen, Custom Roles, inactive accounts and forged requests are refused.
- Create: name, scope, baseline inside the envelope, QR follows POS, audit, one transaction; the partial unique index is the final guard against two admins racing on one name (one wins, the other gets a validation error).
- Update: Role row `FOR UPDATE`, then the whole submitted baseline replaces the old one, so concurrent saves serialize to one complete submission (never a merge). User overrides are never touched; inheriting accounts follow the new baseline on their next request.
- Archive: blocked while any account holds the role ("Reassign them in Staff first"); an archived role is never offered or accepted for assignment; System roles cannot be archived or deleted.
- Audit (`module = access_control`): `access.custom_role_created`, `access.custom_role_updated` (name/scope before/after), `access.custom_role_permissions_updated` (permission names + labels, added/removed), `access.custom_role_archived` — Role id, key, label, scope; never credentials. Notifications go to the **other** active Super Admins only (one per save).

### Staff assignment

- Only Super Admin access control assigns Custom Roles (`StaffRoles::manageableBy()` = System roles + active Custom Roles). Owner Staff management stays limited to Cashier, Kitchen Staff and Cashier + Kitchen and never lists or edits Custom Role accounts.
- Any Role change (System ↔ Custom, Custom A → Custom B) resets custom access to INHERIT (audited with the removed map) and follows the scope's Branch rule (Branch role: explicit active Branch; business-wide: Branch assignments cleared).
- Lock order: Staff writers take the target Role row (`FOR SHARE`) **before** the account rows. Access Control writers hold Role rows and then key-share the actor's account row through their audit insert; the inverted order was reproduced as a real PostgreSQL deadlock (archive vs assign) and fixed.

### RbacSeeder

Maintains only System roles (canonical label/is_system/scope, Super Admin completion, Cashier + Kitchen derivation) and known permissions. It never reads, renames, archives, re-permissions or reassigns Custom Roles (tested on SQLite and PostgreSQL).

### Super Admin Executive Dashboard

`workspaces.super-admin` (`SuperAdminDashboardController`, `access_control.manage`) is read-only. Money comes only from `SalesAnalytics` (the Owner Dashboard's own call); live state from `BusinessSnapshot`; Staff counts, the viewer's unread count and a payload-free Audit summary (action, actor, Branch, time) from `ExecutiveSnapshot`. No before/after audit payloads, credentials or other users' notifications are exposed.
