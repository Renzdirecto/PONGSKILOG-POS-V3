<?php

namespace App\Actions\StoreSessions;

use App\Actions\Audit\AuditRecorder;
use App\Actions\Inventory\ApplyInventoryMovement;
use App\Actions\Operations\ApplyIngredientMovement;
use App\Enums\GiveawayReason;
use App\Enums\GiveawayStockMode;
use App\Enums\IngredientMovementType;
use App\Enums\InventoryMovementType;
use App\Enums\ModifierSemanticRole;
use App\Enums\OrderType;
use App\Enums\StoreSessionStatus;
use App\Events\ReportsChanged;
use App\Http\Requests\StoreSessionGiveawayRequest;
use App\Models\Branch;
use App\Models\BranchProduct;
use App\Models\Ingredient;
use App\Models\InventoryMovement;
use App\Models\OperationPlanProduct;
use App\Models\Product;
use App\Models\Recipe;
use App\Models\StoreSession;
use App\Models\StoreSessionGiveaway;
use App\Models\User;
use App\Support\CatalogRealtime;
use App\Support\ExactMoney;
use App\Support\ExactQuantity;
use App\Support\OrderSnapshots;
use App\Support\PosAccess;
use App\Support\RecipeCapacity;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Records a Product given away free during the current OPEN Store Session ("Record giveaway"): real stock leaves the
 * store but revenue is ₱0. It never creates an Order, Payment or Store Expense, so Sales, Cash/Cashless and Store
 * Close reconciliation are unaffected.
 *
 * The selection is validated by the canonical order customization engine (OrderSnapshots: availability, Group
 * rules, Instruction pricing, Product stock and whole-line Recipe fit). The stock effect then follows the Product:
 *
 * - direct Product stock: one Product-stock movement of −quantity;
 * - Recipe-backed: (base recipe of the Size + each selected Add-on's Product-specific effect) × quantity, checked
 *   against the locked Branch Ingredient balances so a Giveaway can never drive an Ingredient below zero;
 * - neither: recorded for history only. Instructions never move stock, and a Product never moves both stocks.
 *
 * The recipe basis is snapshotted so a reversal restores exactly what was deducted, never today's recipe. Lock order
 * matches POS commits: Branch (share) → Store Session (share) → catalog rows → Product stock → Ingredient balances.
 *
 * @phpstan-import-type Selection from StoreSessionGiveaway
 * @phpstan-import-type BasisLine from StoreSessionGiveaway
 * @phpstan-import-type StockBasis from StoreSessionGiveaway
 */
class RecordStoreSessionGiveaway
{
    public function __construct(
        private PosAccess $access,
        private OrderSnapshots $snapshots,
        private RecipeCapacity $recipes,
        private ApplyInventoryMovement $inventory,
        private ApplyIngredientMovement $ingredients,
        private AuditRecorder $audit,
        private CatalogRealtime $realtime,
    ) {}

    /** @param array<string, mixed> $input */
    public function execute(User $actor, Branch $branch, array $input): StoreSessionGiveaway
    {
        $input['note'] = is_string($input['note'] ?? null) && trim($input['note']) !== '' ? trim($input['note']) : null;
        /** @var array{idempotency_key: string, product_id: string, quantity: int|string, modifiers: list<array{group_id: string, option_id: string}>, reason_code: string, note: string|null} $data */
        $data = Validator::make($input, StoreSessionGiveawayRequest::giveawayRules(), StoreSessionGiveawayRequest::giveawayMessages())->validate();
        $key = strtolower($data['idempotency_key']);
        $productId = strtolower($data['product_id']);
        $reason = GiveawayReason::from($data['reason_code']);
        $quantity = (int) $data['quantity'];
        $modifiers = array_map(fn (array $selection): array => [
            'group_id' => strtolower($selection['group_id']),
            'option_id' => strtolower($selection['option_id']),
        ], $data['modifiers']);
        usort($modifiers, fn (array $left, array $right): int => [$left['group_id'], $left['option_id']] <=> [$right['group_id'], $right['option_id']]);

        return DB::transaction(function () use ($actor, $branch, $data, $key, $productId, $reason, $quantity, $modifiers): StoreSessionGiveaway {
            /** Branch FOR SHARE first: POS commits hold it FOR UPDATE before the Store Session. */
            $branch = Branch::query()->whereKey($branch->getKey())->sharedLock()->firstOrFail();
            $actor = $this->access->authorize($actor, $branch);
            abort_unless($actor->hasPermission('store_expenses.manage'), 403);

            /** Shared Session boundary: Store Close takes it exclusively, so no Giveaway crosses a close. */
            $session = StoreSession::query()->where('branch_id', $branch->id)->where('status', StoreSessionStatus::Open)->sharedLock()->first();
            if ($session === null) {
                throw ValidationException::withMessages(['store' => 'This Store Session is no longer open.']);
            }
            if (DB::getDriverName() === 'pgsql') {
                DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', [$branch->id.':giveaway:'.$key]);
            }

            $intent = hash('sha256', json_encode([
                'branch_id' => $branch->id,
                'store_session_id' => $session->id,
                'actor_id' => $actor->id,
                'product_id' => $productId,
                'quantity' => $quantity,
                'modifiers' => $modifiers,
                'reason_code' => $reason->value,
                'note' => $data['note'],
            ], JSON_THROW_ON_ERROR));
            $existing = StoreSessionGiveaway::query()->where('idempotency_key', $key)->first();
            if ($existing !== null) {
                if (! hash_equals($existing->intent_hash, $intent)) {
                    abort(409, 'This giveaway attempt has already been used with different details.');
                }

                return $existing;
            }

            $prepared = $this->prepare($branch, $productId, $quantity, $modifiers);
            $item = $prepared['items'][0];
            /** @var list<Selection> $selections */
            $selections = array_map(fn (array $modifier): array => [
                'group_id' => (string) $modifier['modifier_group_id_snapshot'],
                'group_name' => (string) $modifier['group_name_snapshot'],
                'semantic_role' => $modifier['semantic_role_snapshot'],
                'option_id' => (string) $modifier['modifier_option_id'],
                'option_name' => (string) $modifier['option_name_snapshot'],
            ], $prepared['modifiers']);
            $size = collect($selections)->firstWhere('semantic_role', ModifierSemanticRole::Size->value);
            $sizeKey = Recipe::sizeKey($size['option_id'] ?? null);

            $product = Product::query()->whereKey($productId)->firstOrFail();
            $tracked = BranchProduct::query()->where('branch_id', $branch->id)->where('product_id', $product->id)->where('tracks_inventory', true)->exists();
            $profile = $this->recipes->profiles($branch, [$product->id])[$product->id] ?? null;
            $mode = match (true) {
                $tracked => GiveawayStockMode::ProductStock,
                $profile !== null && $profile['mode'] === 'recipe' => GiveawayStockMode::Recipe,
                default => GiveawayStockMode::None,
            };
            $label = 'Giveaway · '.$reason->label().($data['note'] !== null ? ' — '.$data['note'] : '');

            $movement = null;
            if ($mode === GiveawayStockMode::ProductStock) {
                $movement = $this->deductProductStock($branch, $product, $quantity, $label, $actor);
            }
            $requirement = null;
            $basis = null;
            $ingredients = collect();
            if ($mode === GiveawayStockMode::Recipe) {
                $requirement = $this->recipes->lineRequirement($profile, array_column($selections, 'option_id'));
                if ($requirement === null || $requirement['state'] !== 'ok') {
                    throw ValidationException::withMessages(['product_id' => RecipeCapacity::recipeRequiredMessage($profile, $sizeKey)]);
                }
                $ingredients = Ingredient::query()->whereKey(array_keys($requirement['lines']))->get()->keyBy('id');
                $basis = $this->basis($profile, $sizeKey, $selections, $ingredients);
            }

            $giveaway = StoreSessionGiveaway::query()->create([
                'branch_id' => $branch->id,
                'store_session_id' => $session->id,
                'product_id' => $product->id,
                'product_name_snapshot' => $item['product_name_snapshot'],
                'size_key' => $sizeKey,
                'size_name_snapshot' => $size['option_name'] ?? null,
                'selections' => $selections,
                'quantity' => $quantity,
                'stock_mode' => $mode,
                'stock_basis' => $basis,
                'inventory_movement_id' => $movement?->id,
                'reason_code' => $reason,
                'note' => $data['note'],
                'created_by_user_id' => $actor->id,
                'idempotency_key' => $key,
                'intent_hash' => $intent,
            ]);

            $deducted = [];
            if ($requirement !== null) {
                $deducted = $this->deductIngredients($branch, $giveaway, $requirement['lines'], $quantity, $ingredients, $label, $actor);
            }

            $this->audit->record(
                branch: $branch,
                actor: $actor,
                module: 'store_sessions',
                action: 'store_session.giveaway_recorded',
                auditableType: StoreSessionGiveaway::class,
                auditableId: $giveaway->id,
                after: [
                    'store_session_id' => $session->id,
                    'product_id' => $product->id,
                    'product_name' => $giveaway->product_name_snapshot,
                    'size' => $giveaway->size_name_snapshot,
                    'selections' => array_column($selections, 'option_name'),
                    'quantity' => $quantity,
                    'stock_mode' => $mode->value,
                    'reason_code' => $reason->value,
                    'note' => $data['note'],
                ],
                metadata: [
                    'request_hash' => $intent,
                    'inventory_movement_id' => $movement?->id,
                    'ingredient_movements' => $deducted,
                ],
                idempotencyKey: $key,
            );
            if ($deducted !== []) {
                $this->realtime->ingredientsChanged($branch, 'giveaway');
            }
            ReportsChanged::dispatch((string) $branch->id, 'giveaway.recorded');

            return $giveaway;
        });
    }

    /**
     * The one customization and availability authority shared with POS orders, applied to a single line. Its order
     * field names are mapped back to the Giveaway form.
     *
     * @param  list<array{group_id: string, option_id: string}>  $modifiers
     * @return array{attributes: array<string, mixed>, items: list<array<string, mixed>>, modifiers: list<array<string, mixed>>}
     */
    private function prepare(Branch $branch, string $productId, int $quantity, array $modifiers): array
    {
        try {
            return $this->snapshots->prepare($branch, [
                'order_type' => OrderType::TakeOut->value,
                'items' => [['product_id' => $productId, 'quantity' => $quantity, 'notes' => null, 'modifiers' => $modifiers]],
            ], (string) Str::uuid(), lockCatalog: true);
        } catch (ValidationException $exception) {
            $errors = [];
            foreach ($exception->errors() as $field => $messages) {
                $mapped = match ($field) {
                    'items' => 'quantity',
                    default => str_replace('items.0.', '', $field),
                };
                $errors[$mapped] = $field === 'items'
                    ? 'Not enough ingredient stock to give away this item. Reduce the quantity or choose another size.'
                    : $messages[0];
            }

            throw ValidationException::withMessages($errors);
        }
    }

    private function deductProductStock(Branch $branch, Product $product, int $quantity, string $label, User $actor): InventoryMovement
    {
        try {
            return $this->inventory->execute($branch, $product, InventoryMovementType::Giveaway, -$quantity, $label, $actor);
        } catch (ValidationException $exception) {
            if (array_key_exists('quantity_delta', $exception->errors())) {
                throw ValidationException::withMessages(['quantity' => 'The quantity is more than the current stock of '.$product->name.'.']);
            }
            throw $exception;
        }
    }

    /**
     * Deducts (per-serving requirement × quantity) from the locked Branch balances in Ingredient-id order, rejecting any
     * Ingredient that would go below zero (an existing negative balance can never be driven further down).
     *
     * @param  array<string, int>  $perServing
     * @param  Collection<string, Ingredient>  $ingredients
     * @return list<array{ingredient_id: string, quantity_delta: string, movement_id: string}>
     */
    private function deductIngredients(Branch $branch, StoreSessionGiveaway $giveaway, array $perServing, int $quantity, Collection $ingredients, string $label, User $actor): array
    {
        $needed = array_map(fn (int $perUnit): int => ExactQuantity::times($perUnit, $quantity), $perServing);
        ksort($needed);
        $stocks = $this->ingredients->lock($branch, array_keys($needed));
        $short = [];
        foreach ($needed as $ingredientId => $need) {
            $onHand = ExactQuantity::parse($stocks->get($ingredientId)?->on_hand);
            if ($onHand - $need < 0) {
                $ingredient = $ingredients->get($ingredientId);
                $short[] = ($ingredient->name ?? 'An ingredient').' needs '.ExactQuantity::display($need).' '.$ingredient?->base_unit
                    .', '.ExactQuantity::display(max($onHand, 0)).' '.$ingredient?->base_unit.' left';
            }
        }
        if ($short !== []) {
            throw ValidationException::withMessages(['quantity' => 'Not enough ingredient stock for this giveaway ('.implode('; ', $short).'). Reduce the quantity or restock first.']);
        }
        $planId = OperationPlanProduct::query()
            ->join('operation_plans', 'operation_plans.id', '=', 'operation_plan_products.operation_plan_id')
            ->whereNull('operation_plans.archived_at')
            ->where('operation_plan_products.branch_id', $branch->id)
            ->where('operation_plan_products.product_id', $giveaway->product_id)
            ->value('operation_plan_products.operation_plan_id');

        $deducted = [];
        foreach ($needed as $ingredientId => $need) {
            $movement = $this->ingredients->execute($branch, $ingredientId, IngredientMovementType::Giveaway, -$need, [
                'store_session_giveaway_id' => $giveaway->id,
                'operation_plan_id' => $planId === null ? null : (string) $planId,
                'estimated_cost_cents' => $this->cost($ingredients->get($ingredientId), $need),
                'reason' => $label,
                'created_by_user_id' => $actor->id,
            ], $stocks->get($ingredientId));
            $deducted[] = ['ingredient_id' => $ingredientId, 'quantity_delta' => ExactQuantity::display(-$need), 'movement_id' => $movement->id];
        }

        return $deducted;
    }

    /** Estimated cost from the Ingredient's current purchase-unit cost; unknown stays null, never ₱0. */
    private function cost(?Ingredient $ingredient, int $quantity): ?int
    {
        $size = ExactQuantity::parseNullable($ingredient?->purchase_unit_size);
        if ($ingredient?->purchase_unit_cost === null || $size === null || $size <= 0) {
            return null;
        }

        return ExactQuantity::costCents($quantity, ExactMoney::cents((string) $ingredient->purchase_unit_cost), $size);
    }

    /**
     * The per-serving recipe basis in force now: the Size's base recipe and each selected Add-on's own effect.
     *
     * @param  array{sizes: array<string, array{lines: array<string, int>|null}>, effects: array<string, array<string, int>>}  $profile
     * @param  list<Selection>  $selections
     * @param  Collection<string, Ingredient>  $ingredients
     * @return StockBasis
     */
    private function basis(array $profile, string $sizeKey, array $selections, Collection $ingredients): array
    {
        /** @return list<BasisLine> */
        $lines = function (array $requirement) use ($ingredients): array {
            ksort($requirement);

            return array_map(fn (string $ingredientId, int $quantity): array => [
                'ingredient_id' => $ingredientId,
                'name' => (string) $ingredients->get($ingredientId)?->name,
                'unit' => (string) $ingredients->get($ingredientId)?->base_unit,
                'quantity' => ExactQuantity::display($quantity),
            ], array_keys($requirement), $requirement);
        };
        $addOns = [];
        foreach ($selections as $selection) {
            $effect = $profile['effects'][$selection['option_id']] ?? null;
            if ($effect !== null) {
                $addOns[] = ['option_id' => $selection['option_id'], 'option_name' => $selection['option_name'], 'lines' => $lines($effect)];
            }
        }

        return ['size_key' => $sizeKey, 'base' => $lines($profile['sizes'][$sizeKey]['lines'] ?? []), 'add_ons' => $addOns];
    }
}
