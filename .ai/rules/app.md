---
paths:
  - 'app/**'
---

# App

## Optional POS branch tables for both order types
Branch table selection is optional for Dine In and Take Out. Validate any selected table is active and belongs to the current branch; persist it for either type. Take Out customer/order label stays required; Dine In label stays optional. Do not restore required_if dine_in or prohibited_if take_out.
