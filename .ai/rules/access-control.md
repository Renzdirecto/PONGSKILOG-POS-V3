---
paths:
  - '{app/Support/{EffectivePermissions,PermissionCatalog,AdminNotifier,StockAlerts,UserSessions,CustomRoles}.php,app/Actions/AccessControl/**,app/Models/{User,UserPermissionOverride,Role}.php,database/seeders/RbacSeeder.php}'
  - '{app/Http/Controllers/{AccessControlController,NotificationController}.php,app/Http/Requests/SaveCustomRoleRequest.php,resources/js/pages/super-admin/{access-control,notifications}.tsx,resources/js/components/custom-role-dialogs.tsx,resources/js/lib/{access-control,notifications}.ts,resources/js/hooks/use-notification-center.ts}'
  - '{app/Support/{AccessRealtime,ActiveBranchContext}.php,app/Actions/Catalog/ConfigureBranchAssortment.php,app/Http/Controllers/{BranchAssortmentController,BranchController}.php,app/Policies/BranchPolicy.php,app/Events/{UserContextChanged,StaffChanged,AccessControlChanged}.php,resources/js/hooks/{use-user-context-realtime,use-invalidation-refresh}.ts,resources/js/lib/{user-context,branch-assortment}.ts,resources/js/components/branch-assortment-dialogs.tsx}'
  - '{app/Support/BranchCatalog.php,app/Http/Controllers/{ProductController,BranchProductController}.php,resources/js/pages/catalog/products.tsx,resources/js/components/product-editor-form.tsx}'
---

# Access Control

## One effective-permission authority
Role = baseline (`role_permissions`); account = optional ALLOW/DENY row in `user_permission_overrides` (no row = INHERIT). Every check goes through `User::hasPermission()` → `EffectivePermissions` (also the shared `auth.permissions`), never a second engine or a frontend-only rule, and nothing is cached across requests. Super Admin is locked full access: overrides are ignored and never written for it, its baseline cannot be edited, and an ALLOW never grants audit.view / void_orders.manage / access_control.manage.

## Permission is WHAT, Role + Branch is WHERE
`PermissionCatalog` is the only list of permission labels, categories, defaults and grant envelopes. A permission is grantable to a Role only when the backend keeps it inside that Role's scope; Control stays Super Admin. Both Custom Role scopes may hold every operational and management permission (Pass #2): a Branch Custom Role applies each only at its selected assigned Branch (see "Branch-scoped management" below). System Cashier / Kitchen Staff envelopes never include management permissions. QR Orders follows POS. Cashier + Kitchen is always re-derived as Cashier ∪ Kitchen Staff in the same transaction.

## RbacSeeder never resets live configuration
Defaults apply only to Roles/Permissions the seeder creates; existing Role ↔ Permission pairs are never removed or re-added. Super Admin is re-completed and Cashier + Kitchen re-derived on every run.

## Notifications are post-commit and Super Admin scoped
`AdminNotifier` delivers database notifications to active Super Admins (except the actor) after commit, rescued, with category/title/body/same-app link only. Stock alerts come only from the canonical stock writers on the locked above-zero → empty transition. The realtime signal is `notifications.changed` (ids/time only) on the recipient's own user channel; the bell refetches the unread-count endpoint, never polls. Each stored `AdminAlert` also becomes a generic Web Push to that recipient's enabled devices (`QueueAdminAlertPushNotification`, tag = notification id), re-checked with `AdminNotifier::receivesAlerts()` when delivered; see `.ai/rules/pwa.md`.

## Custom Roles are metadata-scoped permission packages
System roles are the five canonical names (never edited as custom records, never archived; Super Admin locked, Cashier + Kitchen derived). A Custom Role is `custom_{id}` with an editable `label` (unique ignoring case among active roles, System names included) and an explicit `scope`: Branch (needs ≥1 active Branch, runs Cashier operations like a Cashier, Reports stay on its Branch) or business-wide (no Branch assignments; may combine every operational and management permission; Branch operations run at one selected active Branch via `Role::scopeOperatesEveryBranch()`, like Super Admin). Scope decisions live only in `Role::scopeBusinessWide()` / `scopeCashierOperations()` / `scopeOperatesEveryBranch()` (System roles by name, Custom by metadata) — never add `if role == custom_x` checks. The envelope is `PermissionCatalog::CUSTOM_GRANTABLE`; `lockReason()` needs the Role model for a Custom Role, and user ALLOWs use the same envelope. Control permissions never leave Super Admin. Scope changes only while unassigned; archive only while unassigned; RbacSeeder never touches Custom Roles. Only Super Admin creates, edits, archives or assigns them.

## Inventory and Operations are separate permissions
`inventory.manage` = Product stock (Inventory pages, adjustments). `operations.manage` = the whole Operations workspace (Plans, Overview, Ingredients, Recipes, Ingredient Stock, Pamamalengke, Purchases). Grant them independently; never require `inventory.manage` to open Operations (Operations never writes Product stock). A new permission that replaces part of an existing one needs a forward migration copying the existing Role grants and user overrides, so the split never silently removes access; RbacSeeder only covers fresh installs.

## Position is a display title, never access
`users.position` is a business/job title (e.g. "Area Manager") for Staff cards, the sidebar footer (fallback: Role label) and the Audit actor ("Name · Position", current value). Never derive a permission, scope, landing page or workspace from it; access comes only from the Role plus user overrides.

## Branch-scoped management (Manual QA pass #2)
Every management page resolves its Branch through `ActiveBranchContext::managementBranch()`: selected Branch, null = All Branches (business-wide only), false = Branch-scoped account without a selected assigned Branch → `to_route('workspace')`. Never read `current()` and treat null as All Branches for a Branch role. Products: shared definitions need the `catalog.define` gate (products.manage + business-wide); Branch roles only write `branch_products` rows of Branches they can access (`UpsertBranchProduct`, `ConfigureBranchAssortment`).

## Branch assortment is explicit (pass #2.1)
A `branch_products` row IS membership: no row = not sold at that Branch (POS, QR, Giveaway, drafts, edits and Pay Now/Pay Later all reject it via `BranchCatalog`/`ApplyOrderInventory`, reason `not_in_branch`); `is_available = false` = still a member, temporarily unavailable. Remove from Branch deletes the row and its Branch Plan links only (never the Product, stock balance, movements, Orders or snapshots). A new Branch and a new global Product start with no rows; only explicit Add/Copy/editor selection creates them (`UpsertBranchProduct` with `createMembership`; Branch settings edits require membership). Copy authorization covers both Branches; copying Operations setup additionally needs `operations.manage`. Settings: `BranchPolicy::update` = own Branch local settings, `create`/`updateIdentity` = business-wide. No `if role == custom_x` checks: scope comes from `hasBusinessWideScope()` / `canAccessBranch()`.

## Access and identity changes are signalled after commit
Writers that change an account's identity, Role, Role baseline, overrides, Branch assignments or status call `AccessRealtime` (`usersChanged`, `rolesChanged`, `staffChanged`, `accessControlChanged`) inside their transaction; the events are `ShouldDispatchAfterCommit` + rescued and carry ids/type/time only. A new writer of those facts must signal too, or open sessions keep stale navigation until the next visit (the backend still denies immediately).

## Branch context is deterministic; shared props are lazy (Phase 19)
`ActiveBranchContext::current()` re-authorizes the selection on every call; a stale or forged id is cleared and the account continues as if nothing was selected (a single active assignment is re-selected, business-wide → All Branches, several → picker) on that same call. Never rely on another caller (e.g. shared props) having cleared it first. `HandleInertiaRequests` shares `auth`, `branchContext`, `storeContext` and `notificationCenter` as closures (Branch resolved once per response), so JSON endpoints and partial reloads never pay for them.

## Copy skips a conflicting Product instead of aborting (Phase 19)
`ConfigureBranchAssortment::copy()` writes each Product in its own savepoint: a `ValidationException` from the canonical writer (`UpsertBranchProduct`, e.g. source tracks Product stock while the destination has a Recipe/Add-on effect) rolls back only that Product, which is reported in `conflicts` (name + reason), audited by name, left out of the Operations part and never has destination setup deleted to fit. Database errors still abort the whole copy. The controller flashes `assortmentCopy`; the dialog stays open on a result with skipped items.
