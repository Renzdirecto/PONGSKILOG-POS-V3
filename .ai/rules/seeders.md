---
paths:
  - 'database/seeders/Local*Seeder.php'
---

# Seeders

## Local POS QA seed data preserves configured state
Local menu QA prices, tracking, and initial stock are local/testing only. Preserve non-zero Product prices and BranchProduct price/availability/threshold overrides; enforce tracking. Initialize inventory through ApplyInventoryMovement only when the branch/product balance is missing, so repeat seeding never refills consumed stock or inflates the ledger.
