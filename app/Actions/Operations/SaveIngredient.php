<?php

namespace App\Actions\Operations;

use App\Actions\Audit\AuditRecorder;
use App\Enums\IngredientMovementType;
use App\Enums\ReplenishmentRule;
use App\Events\ReportsChanged;
use App\Models\Branch;
use App\Models\BranchIngredientStock;
use App\Models\Ingredient;
use App\Models\OperationPlan;
use App\Models\OperationPlanIngredient;
use App\Models\RecipeLine;
use App\Models\User;
use App\Support\ExactMoney;
use App\Support\ExactQuantity;
use App\Support\OperationsAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Creates or updates an Ingredient definition and its Plan memberships. Initial stock is written only as an
 * opening-balance movement for the one selected Branch; later stock changes must be explicit movements (purchase,
 * wastage, count correction), never a direct balance edit. The base unit locks once stock history or a recipe uses it.
 * Changing target, purchase unit, cost or rule never rewrites past movements or snapshotted costs.
 */
class SaveIngredient
{
    public function __construct(
        private OperationsAccess $access,
        private ApplyIngredientMovement $movements,
        private AuditRecorder $audit,
    ) {}

    /** @return array<string, mixed> */
    public static function rules(): array
    {
        $quantity = ['string', 'regex:'.ExactQuantity::INPUT_PATTERN];

        return [
            'name' => ['required', 'string', 'max:80'],
            'icon' => ['required', 'string', Rule::in(Ingredient::ICONS)],
            'base_unit' => ['required', 'string', Rule::in(Ingredient::UNITS)],
            'target_quantity' => ['required', ...$quantity],
            'purchase_unit_name' => ['nullable', 'string', 'max:30'],
            'purchase_unit_size' => ['nullable', ...$quantity],
            'purchase_unit_cost' => ['nullable', 'string', 'regex:/\A[0-9]{1,9}(?:\.[0-9]{1,2})?\z/'],
            'replenishment_rule' => ['required', Rule::enum(ReplenishmentRule::class)],
            'reorder_point' => ['nullable', ...$quantity],
            'plan_ids' => ['required', 'array', 'min:1', 'max:50'],
            'plan_ids.*' => ['required', 'uuid', 'distinct'],
            'initial_quantity' => ['nullable', ...$quantity],
        ];
    }

    /** @param array<string, mixed> $input */
    public function execute(User $actor, ?Ingredient $ingredient, array $input): Ingredient
    {
        $actor = $this->access->authorize($actor);
        foreach (['name', 'purchase_unit_name'] as $field) {
            $input[$field] = is_string($input[$field] ?? null) && trim($input[$field]) !== '' ? trim($input[$field]) : ($field === 'name' ? ($input[$field] ?? null) : null);
        }
        foreach (['purchase_unit_size', 'purchase_unit_cost', 'reorder_point', 'initial_quantity'] as $field) {
            $input[$field] = is_string($input[$field] ?? null) && trim($input[$field]) !== '' ? trim($input[$field]) : null;
        }
        /** @var array{name: string, icon: string, base_unit: string, target_quantity: string, purchase_unit_name: string|null, purchase_unit_size: string|null, purchase_unit_cost: string|null, replenishment_rule: string, reorder_point: string|null, plan_ids: list<string>, initial_quantity: string|null} $data */
        $data = Validator::make($input, self::rules(), [
            'purchase_unit_cost.regex' => 'Enter a cost with no more than two decimal places.',
            'plan_ids.required' => 'Choose at least one Plan that uses this ingredient.',
            'plan_ids.min' => 'Choose at least one Plan that uses this ingredient.',
        ])->validate();
        $values = $this->values($data);
        $initial = $ingredient === null && $data['initial_quantity'] !== null ? ExactQuantity::fromInput($data['initial_quantity'], 'initial_quantity') : 0;
        $branch = $initial > 0 ? $this->access->mutableBranch($actor) : null;
        $planIds = array_values(array_unique(array_map('strtolower', $data['plan_ids'])));
        sort($planIds);

        $saved = DB::transaction(function () use ($actor, $ingredient, $data, $values, $initial, $branch, $planIds): Ingredient {
            if ($ingredient !== null) {
                /** FOR NO KEY UPDATE serializes edits without blocking sales' foreign-key KEY SHARE checks. */
                $ingredient = Ingredient::query()->whereKey($ingredient->id)->lock('for no key update')->firstOrFail();
                if ($ingredient->archived_at !== null) {
                    throw ValidationException::withMessages(['ingredient' => 'Restore this ingredient before editing it.']);
                }
                if ($ingredient->base_unit !== $data['base_unit'] && $this->unitLocked($ingredient)) {
                    throw ValidationException::withMessages(['base_unit' => 'The base unit is locked because stock history or a recipe already counts in '.$ingredient->base_unit.'.']);
                }
            }
            $duplicate = Ingredient::query()->whereRaw('LOWER(name) = ?', [mb_strtolower($data['name'])])
                ->when($ingredient !== null, fn ($query) => $query->whereKeyNot($ingredient?->id))->exists();
            if ($duplicate) {
                throw ValidationException::withMessages(['name' => 'Another ingredient already uses this name. Restore or rename it instead.']);
            }
            $plans = OperationPlan::query()->whereNull('archived_at')->whereKey($planIds)->pluck('id')->all();
            if (count($plans) !== count($planIds)) {
                throw ValidationException::withMessages(['plan_ids' => 'Choose only active Plans.']);
            }

            $before = $ingredient === null ? null : $this->snapshot($ingredient);
            $ingredient ??= new Ingredient(['created_by_user_id' => $actor->id]);
            $ingredient->fill([...$values, 'updated_by_user_id' => $actor->id])->save();

            $current = OperationPlanIngredient::query()->where('ingredient_id', $ingredient->id)->lockForUpdate()->get();
            $current->reject(fn (OperationPlanIngredient $row): bool => in_array($row->operation_plan_id, $planIds, true))->each->delete();
            foreach (array_diff($planIds, $current->pluck('operation_plan_id')->all()) as $planId) {
                OperationPlanIngredient::query()->create(['operation_plan_id' => $planId, 'ingredient_id' => $ingredient->id]);
            }

            $opening = null;
            if ($branch !== null) {
                $branch = $this->movements->lockBranch($branch);
                $opening = $this->movements->execute($branch, $ingredient->id, IngredientMovementType::OpeningBalance, $initial, [
                    'reason' => 'Opening balance',
                    'created_by_user_id' => $actor->id,
                ]);
                ReportsChanged::dispatch($branch->id, 'ingredients.opening_balance');
            }

            $this->audit->record(
                branch: $branch,
                actor: $actor,
                module: 'operations',
                action: $before === null ? 'ingredient.created' : 'ingredient.updated',
                auditableType: Ingredient::class,
                auditableId: $ingredient->id,
                before: $before,
                after: $this->snapshot($ingredient),
                metadata: $opening === null ? null : ['opening_movement_id' => $opening->id, 'opening_quantity' => ExactQuantity::display($initial)],
            );

            return $ingredient;
        });

        return $saved->refresh();
    }

    /**
     * @param  array{name: string, icon: string, base_unit: string, target_quantity: string, purchase_unit_name: string|null, purchase_unit_size: string|null, purchase_unit_cost: string|null, replenishment_rule: string, reorder_point: string|null}  $data
     * @return array<string, mixed>
     */
    private function values(array $data): array
    {
        $rule = ReplenishmentRule::from($data['replenishment_rule']);
        $target = ExactQuantity::fromInput($data['target_quantity'], 'target_quantity');
        if ($target <= 0) {
            throw ValidationException::withMessages(['target_quantity' => 'Enter a target stock above zero.']);
        }
        $size = $data['purchase_unit_size'] === null ? null : ExactQuantity::fromInput($data['purchase_unit_size'], 'purchase_unit_size');
        if ($size !== null && $size <= 0) {
            throw ValidationException::withMessages(['purchase_unit_size' => 'A purchase unit must hold more than zero.']);
        }
        $name = $data['purchase_unit_name'];
        if (($name === null) !== ($size === null)) {
            throw ValidationException::withMessages(['purchase_unit_size' => 'Enter both what you buy and how much it holds, or leave both empty.']);
        }
        if ($rule !== ReplenishmentRule::None && $size === null) {
            throw ValidationException::withMessages(['purchase_unit_name' => 'Enter a purchase unit and how much it holds, or choose No automatic suggestion.']);
        }
        if ($data['purchase_unit_cost'] !== null && $size === null) {
            throw ValidationException::withMessages(['purchase_unit_cost' => 'A cost needs a purchase unit.']);
        }
        $reorder = null;
        if ($rule === ReplenishmentRule::Reorder) {
            if ($data['reorder_point'] === null) {
                throw ValidationException::withMessages(['reorder_point' => 'Enter the reorder point.']);
            }
            $reorder = ExactQuantity::fromInput($data['reorder_point'], 'reorder_point');
            if ($reorder >= $target) {
                throw ValidationException::withMessages(['reorder_point' => 'The reorder point must be below the target.']);
            }
        }

        return [
            'name' => $data['name'],
            'icon' => $data['icon'],
            'base_unit' => $data['base_unit'],
            'target_quantity' => ExactQuantity::decimal($target),
            'purchase_unit_name' => $name,
            'purchase_unit_size' => $size === null ? null : ExactQuantity::decimal($size),
            'purchase_unit_cost' => $data['purchase_unit_cost'] === null ? null : ExactMoney::decimal(ExactMoney::cents($data['purchase_unit_cost'])),
            'replenishment_rule' => $rule,
            'reorder_point' => $reorder === null ? null : ExactQuantity::decimal($reorder),
        ];
    }

    private function unitLocked(Ingredient $ingredient): bool
    {
        /** Every movement bumps its balance version; a snapshot line always comes with a sale movement. */
        return BranchIngredientStock::query()->where('ingredient_id', $ingredient->id)->where('version', '>', 0)->exists()
            || RecipeLine::query()->where('ingredient_id', $ingredient->id)->exists();
    }

    /** @return array<string, mixed> */
    private function snapshot(Ingredient $ingredient): array
    {
        return [
            'name' => $ingredient->name,
            'icon' => $ingredient->icon,
            'base_unit' => $ingredient->base_unit,
            'target_quantity' => $ingredient->target_quantity,
            'purchase_unit_name' => $ingredient->purchase_unit_name,
            'purchase_unit_size' => $ingredient->purchase_unit_size,
            'purchase_unit_cost' => $ingredient->purchase_unit_cost,
            'replenishment_rule' => $ingredient->replenishment_rule->value,
            'reorder_point' => $ingredient->reorder_point,
            'plan_ids' => OperationPlanIngredient::query()->where('ingredient_id', $ingredient->id)->orderBy('operation_plan_id')->pluck('operation_plan_id')->all(),
        ];
    }
}
