---
paths:
  - '{app/Actions/Staff/**,app/Http/Controllers/StaffController.php,app/Http/Requests/StoreStaffRequest.php,resources/js/pages/super-admin/staff.tsx}'
  - app/Support/StaffRoles.php
  - '{app/Actions/Staff/**,app/Http/Requests/{UpdateStaffRequest,ResetStaffPasswordRequest}.php,resources/js/components/staff-account-dialogs.tsx}'
---

# Super Admin

## Staff accounts use a Super Admin chosen temporary password
Staff creation is scoped by `StaffRoles::manageableBy()`: access_control.manage (Super Admin) creates every role; Owner staff.manage creates only cashier, kitchen_staff and cashier_kitchen through the `staff.*` routes and the same page with surface=owner (supersedes "Only access_control.manage creates staff"). The Super Admin types the temporary password; it is hashed by the User cast and never logged, audited, returned, or shown again. No invite email, forced password change, or self-service profile flow. Owner and Super Admin are business-wide with no Branch assignments; operational roles need at least one active Branch. User, role, assignments and the staff.created audit commit in one transaction.

## Map unique violations from parsed columns, never the message
A UniqueConstraintViolationException message embeds the full INSERT SQL, so it always names every inserted column (employee_id, email). Decide which field collided from `$exception->columns` / `$exception->index` (e.g. `users_employee_id_unique`) so a racing duplicate email is not reported as a duplicate Employee ID.

## Editing existing Staff keeps one active Super Admin
`UpdateStaffAccount` locks every Super Admin row plus actor and target in id order before deciding, so crossing deactivate/demote requests serialize; at least one active Super Admin always remains and nobody changes their own role or deactivates themselves. The Employee ID is immutable. A Role change clears Branch access for Owner/Super Admin, needs an active Branch otherwise, and resets custom access to INHERIT. Deactivation and the Super Admin-only password reset rotate the remember token and end sessions (database rows + `AuthenticateSession`); the password is never returned, logged, audited or notified.

## Staff writers lock the Role before the accounts
`UpdateStaffAccount` takes the target Role row `FOR SHARE` before `lockAccounts()` (and `CreateStaffAccount` before any insert). Access Control writers hold Role rows and then key-share the actor's user row through the audit insert, so accounts-first ordering deadlocked with Custom Role archiving on PostgreSQL. The shared lock also makes archive-vs-assign serialize (an archived role never keeps an account). Owner Staff management never lists, assigns or edits Custom Role accounts; use `StaffRoles::managesEveryAccount()` for the Super Admin check, never `manageableBy() === names()`.

## Staff Position is optional display metadata
Add Staff and Manage Staff accept an optional `position` (≤100 chars, collapsed whitespace, blank → null, Custom Role name characters). It is only normalized when the field is submitted, so a client that omits it never clears it. Audited in `staff.created` / `staff.updated`; never used for authorization.
