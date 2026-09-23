---
paths:
  - '{app/Actions/Staff/**,app/Http/Controllers/StaffController.php,app/Http/Requests/StoreStaffRequest.php,resources/js/pages/super-admin/staff.tsx}'
---

# Super Admin

## Staff accounts use a Super Admin chosen temporary password
Only access_control.manage creates staff. The Super Admin types the temporary password; it is hashed by the User cast and never logged, audited, returned, or shown again. No invite email, forced password change, or self-service profile flow. Owner and Super Admin are business-wide with no Branch assignments; operational roles need at least one active Branch. User, role, assignments and the staff.created audit commit in one transaction.
