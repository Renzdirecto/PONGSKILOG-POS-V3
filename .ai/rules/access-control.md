---
paths:
  - '{app/Support/{EffectivePermissions,PermissionCatalog,AdminNotifier,StockAlerts,UserSessions}.php,app/Actions/AccessControl/**,app/Models/{User,UserPermissionOverride}.php,database/seeders/RbacSeeder.php}'
  - '{app/Http/Controllers/{AccessControlController,NotificationController}.php,resources/js/pages/super-admin/{access-control,notifications}.tsx,resources/js/lib/{access-control,notifications}.ts,resources/js/hooks/use-notification-center.ts}'
---

# Access Control

## One effective-permission authority
Role = baseline (`role_permissions`); account = optional ALLOW/DENY row in `user_permission_overrides` (no row = INHERIT). Every check goes through `User::hasPermission()` → `EffectivePermissions` (also the shared `auth.permissions`), never a second engine or a frontend-only rule, and nothing is cached across requests. Super Admin is locked full access: overrides are ignored and never written for it, its baseline cannot be edited, and an ALLOW never grants audit.view / void_orders.manage / access_control.manage.

## Permission is WHAT, Role + Branch is WHERE
`PermissionCatalog` is the only list of permission labels, categories, defaults and grant envelopes. A permission is grantable to a Role only when the backend keeps it inside that Role's scope (Products, Inventory, Staff, Settings stay business-wide; Control stays Super Admin). Custom Reports for Branch staff must stay on the selected assigned Branch (never All Branches). QR Orders follows POS. Cashier + Kitchen is always re-derived as Cashier ∪ Kitchen Staff in the same transaction.

## RbacSeeder never resets live configuration
Defaults apply only to Roles/Permissions the seeder creates; existing Role ↔ Permission pairs are never removed or re-added. Super Admin is re-completed and Cashier + Kitchen re-derived on every run.

## Notifications are post-commit and Super Admin scoped
`AdminNotifier` delivers database notifications to active Super Admins (except the actor) after commit, rescued, with category/title/body/same-app link only. Stock alerts come only from the canonical stock writers on the locked above-zero → empty transition. The realtime signal is `notifications.changed` (ids/time only) on the recipient's own user channel; the bell refetches the unread-count endpoint, never polls.
