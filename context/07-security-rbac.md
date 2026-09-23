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
- The Access Control matrix remains unimplemented. Its page is a read-only placeholder with no interactive toggles.

### Phase 16B–16D Owner workspace authorization - 2026-09-24

- `workspaces.owner` (Owner Dashboard), `workspaces.reports` and `workspaces.reports.export` require `reports.view` plus business-wide scope (Owner, Super Admin). The export is throttled and built only from the authorized, scoped and filtered report arrays.
- `workspaces.transactions` and `workspaces.transactions.show` require `transactions.view` plus business-wide scope. A selected Branch limits both to that Branch (another Branch's Order is 404). Voided Orders are 404. Capabilities (`can_edit`/`can_settle`/`can_void`) are computed on the server from POS access to the selected Branch; the Owner never has it, so the Owner's POS write requests are 403 (`permission:pos.access`). The Cashier `workspaces.transaction-history` route and its `hasCashierOperationsRole` authorization are unchanged.
- Settings reuse the existing Branch Management, receipt and QR endpoints (`BranchPolicy`, `settings.manage` + business-wide); operational staff remain 403.
