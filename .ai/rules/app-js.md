---
paths:
  - '{app/**,resources/js/**,tests/**}'
---

# App Js

## POS customer labels stay optional
Customer/order label is optional for both Dine In and Take Out POS orders. Selecting a dine-in table should auto-fill the customer label with that table name; users may still edit or clear it. Do not reintroduce conditional required validation for Take Out.
