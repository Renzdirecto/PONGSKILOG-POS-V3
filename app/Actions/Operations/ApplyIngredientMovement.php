<?php

namespace App\Actions\Operations;

use App\Enums\IngredientMovementType;
use App\Models\Branch;
use App\Models\BranchIngredientStock;
use App\Models\IngredientMovement;
use App\Support\ExactQuantity;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The single writer of canonical Branch Ingredient stock. Every change appends one movement and moves the balance in
 * the same transaction; nothing else updates `branch_ingredient_stocks.on_hand`. Callers authorize their workflow,
 * own the surrounding transaction and lock the rows they will touch in Ingredient-id order through lock().
 *
 * Lock order is Branch → balances: POS commits already hold the Branch row, and every Operations-only writer takes
 * lockBranch() first, because each movement insert needs a KEY SHARE on its Branch (foreign key).
 */
class ApplyIngredientMovement
{
    /** FOR SHARE on the Branch before any balance row, matching POS commits that hold it FOR UPDATE. */
    public function lockBranch(Branch $branch): Branch
    {
        return Branch::query()->whereKey($branch->id)->sharedLock()->firstOrFail();
    }

    /**
     * Ensures a balance row exists for each Ingredient and locks them in deterministic Ingredient-id order.
     *
     * @param  list<string>  $ingredientIds
     * @return Collection<string, BranchIngredientStock>
     */
    public function lock(Branch $branch, array $ingredientIds): Collection
    {
        $ingredientIds = array_values(array_unique($ingredientIds));
        sort($ingredientIds);
        if ($ingredientIds === []) {
            return collect();
        }
        /**
         * Only missing balances are inserted (the unique pair and ON CONFLICT DO NOTHING protect a racing first row), so
         * an existing balance is never probed by a speculative insert that would wait on another writer's update.
         */
        $existing = BranchIngredientStock::query()->where('branch_id', $branch->id)->whereIn('ingredient_id', $ingredientIds)->pluck('ingredient_id')->all();
        $missing = array_values(array_diff($ingredientIds, $existing));
        if ($missing !== []) {
            $now = now();
            BranchIngredientStock::query()->insertOrIgnore(array_map(fn (string $ingredientId): array => [
                'id' => (string) Str::uuid(),
                'branch_id' => $branch->id,
                'ingredient_id' => $ingredientId,
                'on_hand' => '0.0000',
                'version' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ], $missing));
        }

        return BranchIngredientStock::query()
            ->where('branch_id', $branch->id)
            ->whereIn('ingredient_id', $ingredientIds)
            ->orderBy('ingredient_id')
            ->lockForUpdate()
            ->get()
            ->keyBy('ingredient_id');
    }

    /**
     * Appends one movement of an exact base-unit delta (ten-thousandths, see ExactQuantity) and moves the balance.
     *
     * @param  array{order_id?: string|null, order_recipe_snapshot_id?: string|null, operation_plan_id?: string|null, pamamalengke_purchase_id?: string|null, store_session_expense_id?: string|null, store_session_giveaway_id?: string|null, estimated_cost_cents?: int|null, reason_code?: string|null, reason?: string|null, created_by_user_id?: int|null, idempotency_key?: string|null}  $attributes
     */
    public function execute(Branch $branch, string $ingredientId, IngredientMovementType $type, int $delta, array $attributes = [], ?BranchIngredientStock $locked = null): IngredientMovement
    {
        if ($delta === 0) {
            throw new \InvalidArgumentException('An Ingredient movement must change stock.');
        }

        return DB::transaction(function () use ($branch, $ingredientId, $type, $delta, $attributes, $locked): IngredientMovement {
            /** A row the caller already locked in this transaction is reused instead of being selected again. */
            $stock = ($locked !== null && $locked->branch_id === $branch->id && $locked->ingredient_id === $ingredientId ? $locked : null)
                ?? $this->lock($branch, [$ingredientId])->get($ingredientId)
                ?? throw new \LogicException('The Ingredient balance could not be locked.');
            $balance = ExactQuantity::add(ExactQuantity::parse($stock->on_hand), $delta);
            $stock->update([
                'on_hand' => ExactQuantity::decimal($balance),
                'version' => $stock->version + 1,
            ]);

            return IngredientMovement::query()->create([
                ...$attributes,
                'branch_id' => $branch->id,
                'ingredient_id' => $ingredientId,
                'movement_type' => $type,
                'quantity_delta' => ExactQuantity::decimal($delta),
                'balance_after' => ExactQuantity::decimal($balance),
            ]);
        });
    }
}
