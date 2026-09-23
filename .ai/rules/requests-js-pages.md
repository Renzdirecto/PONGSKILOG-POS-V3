---
paths:
  - '{app/Actions/Orders/VoidOrder.php,app/Http/Controllers/SetVoidAuthorizationPinController.php,app/Http/Requests/SetVoidAuthorizationPinRequest.php,resources/js/pages/**}'
---

# Requests Js Pages

## Void approval uses one global Super Admin PIN
Void approval uses one global 4-digit PIN configured by an active Super Admin and stored only as a hash. The configuring Super Admin is the authorizer; the active assigned Cashier/Cashier+Kitchen user is the distinct initiator. Possession of the PIN is intentionally delegated approval authority—do not replace it with per-user password re-authentication. Never persist or broadcast the plaintext PIN.

## Super Admin may initiate a Void but never self-approve
Supersedes the Cashier-only initiator wording in the global PIN rule: a full-access Super Admin on the selected active Branch may also initiate a Void. The two-person rule is unchanged. The PIN-configuring Super Admin is the authorizer and must differ from the initiator, so a Super Admin cannot approve a Void they start with their own PIN.
