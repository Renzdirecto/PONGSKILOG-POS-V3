---
paths:
  - '{app/**,resources/js/**,tests/**}'
  - '{app/**,resources/js/**}'
---

# App Js

## POS customer labels stay optional
Customer/order label is optional for both Dine In and Take Out POS orders. Selecting a dine-in table should auto-fill the customer label with that table name; users may still edit or clear it. Do not reintroduce conditional required validation for Take Out.

## Instruction Groups stay structured and price-neutral
A Group with semantic_role=instruction is optional/multiple, has min_select=0, and every option is authoritative zero-price. Preserve Group/option/role snapshots on orders, never prefix the Product name, and keep structured selections separate from free-text notes.
