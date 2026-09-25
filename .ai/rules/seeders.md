---
paths:
  - 'database/seeders/Local*Seeder.php'
---

# Seeders

## Local POS QA seed data preserves configured state
Local menu QA prices, tracking, and initial stock are local/testing only. Preserve non-zero Product prices and BranchProduct price/availability/threshold overrides; enforce tracking. Initialize inventory through ApplyInventoryMovement only when the branch/product balance is missing, so repeat seeding never refills consumed stock or inflates the ledger.

## Normal local development data is persistent
Never run `migrate:fresh`, `migrate:reset`, `migrate:refresh`, or `db:wipe` against the configured normal local development database. Use an isolated SQLite database or a temporary PostgreSQL database/schema for fresh-migration verification. The local QA dataset is restored only through the opt-in `LocalDevelopmentSeeder`.

## Operations QA data is opt-in and resets only through canonical movements
`php artisan operations:seed-qa` (`LocalOperationsQaSeeder`, local/testing only, never in DatabaseSeeder) ensures the Lemon drinks in the MAIN and QAVE assortments and, separately for each Branch (Operations setup is Branch-owned), the Drinks Plan, Ingredients, Lemon drink recipes per Size, "Drink Add-ons" effects and Ingredient stock through the production actions with that Branch selected; "Instructions" and the Size/Add-on groups stay global. Reuse an Ingredient only when its base unit matches (locked mismatches keep the owner's record and use the QA fallback name, e.g. "Yakult Bottle", "Calamansi Juice"). Stock is brought to the QA starting balance with audited count corrections, never repeated opening balances or direct balance edits.
