---
paths:
  - '{app/Support/PosAccess.php,app/Http/Requests/**,app/Actions/StoreSessions/**,app/Models/User.php,routes/channels.php}'
---

# Models

## Super Admin has full Cashier operational parity
Cashier POS and Store Session authorization goes through User::hasCashierOperationsRole() and hasOperationalBranchAccess(): an assigned cashier/cashier_kitchen, or business-wide super_admin with no fabricated assignment. Owner business-wide scope never grants Cashier operations. The selected Branch must be Active, Store Session rules are unchanged, and actions are audited as the Super Admin. Void keeps the two-person rule (initiator must differ from the PIN-configuring Super Admin). This supersedes the Phase 14/15 Super Admin denials.
