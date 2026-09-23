---
paths:
  - 'database/seeders/Local*Seeder.php'
---

# Seeders

## Local POS QA seed data preserves configured state
Local menu QA prices, tracking, and initial stock are local/testing only. Preserve non-zero Product prices and BranchProduct price/availability/threshold overrides; enforce tracking. Initialize inventory through ApplyInventoryMovement only when the branch/product balance is missing, so repeat seeding never refills consumed stock or inflates the ledger.

## Normal local development data is persistent
Never run `migrate:fresh`, `migrate:reset`, `migrate:refresh`, or `db:wipe` against the configured normal local development database. Use an isolated SQLite database or a temporary PostgreSQL database/schema for fresh-migration verification. The local QA dataset is restored only through the opt-in `LocalDevelopmentSeeder`.
