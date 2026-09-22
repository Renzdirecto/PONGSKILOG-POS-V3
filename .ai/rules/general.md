---
paths:
  - phpunit.xml
---

# General

## Propagate the test memory limit through PHPUnit
Laravel's `artisan test` launches Pest in a child PHP process, so a parent-only command such as `php -d memory_limit=1G artisan test` does not propagate that INI override. Keep the PHPUnit `<ini name="memory_limit" value="1G"/>` setting so the sequential suite has the intended limit without weakening runtime product-image safety checks.
