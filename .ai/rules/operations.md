---
paths:
  - '{app/Actions/Operations/**,app/Support/{ExactQuantity,IngredientStockReport,OperationsAccess,OperationsSummary,OperationsWorkspace,ProductSizes,ReplenishmentAdvisor}.php,app/Http/Controllers/{OperationsController,OperationPlanController,IngredientController,RecipeController,PamamalengkeController}.php}'
  - '{app/Actions/Orders/{ApplyOrderInventory,EditCommittedOrder,VoidOrder}.php,app/Actions/StoreSessions/RecordStoreSessionExpense.php,app/Actions/Catalog/UpsertBranchProduct.php}'
  - '{resources/js/pages/operations/**,resources/js/components/operations-*.tsx,resources/js/lib/operations.ts,resources/js/pages/inventory/index.tsx}'
---

# Operations

## One canonical Ingredient balance per Branch
Ingredient stock is one `branch_ingredient_stocks` row per Branch + Ingredient, shared by every Plan; Plans never own stock. Only `ApplyIngredientMovement` writes it, appending an `ingredient_movements` row in the same transaction (lock rows in Ingredient-id order, after Store Session → Order → Product inventory). Quantities are exact numeric(18,4) handled as `ExactQuantity` integer ten-thousandths — never floats, never a direct balance edit, never a second ledger. Catalog › Inventory and Operations read the same balance. Lock order is Branch → balances: Operations-only writers (wastage, count, opening stock, Confirm) call `lockBranch()` (FOR SHARE) before any balance because POS commits hold the Branch FOR UPDATE and every movement insert needs a foreign-key KEY SHARE on it; `lock()` inserts only missing balances; Ingredient definitions use FOR NO KEY UPDATE so they never block sales' foreign-key checks. The PostgreSQL harness caught the inverted order as a real deadlock.

## Ingredient usage follows the canonical order lifecycle
Pay Now and Pay Later consume through `ApplyOrderInventory` → `RecordOrderIngredientUsage::commit()` once; `EditCommittedOrder` appends only the delta and `VoidOrder` restores the current net once, both from the Order's immutable `order_recipe_snapshots` (never today's recipe, cost or Plan). Settlement, payment corrections/allocations, Kitchen, Store Close, reports and broadcasts never move Ingredients. Negative Ingredient stock is allowed for sales. A Product that tracks Product stock never also consumes Ingredients (recipe save and `UpsertBranchProduct` both guard it).

## Pamamalengke money stays in the canonical Store Purchase
Confirm Pamamalengke writes exactly one Store Session expense through `RecordStoreSessionExpense::persist()` under the existing OPEN Store Session rule, plus exact restocks and purchase metadata, idempotently. Operations › Purchases is a projection; never add a parallel expense path or stored total. Recommendations come only from `ReplenishmentAdvisor`; COGS uses movement cost snapshots and unknown costs are reported incomplete, never ₱0. Cash after purchases is never labelled profit; Store-wide expenses are never allocated to a Plan. Stock mutations need one concrete active Branch from `ActiveBranchContext` (All Branches is read-only).
