<?php

namespace App\Actions\Operations;

use App\Models\Ingredient;
use App\Models\OperationPlan;
use App\Models\PamamalengkeListEntry;
use App\Models\User;
use App\Support\ExactMoney;
use App\Support\ExactQuantity;
use App\Support\OperationsAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * The working list of the next run for the selected Branch + Plan. Manual items (ice, tissue, LPG…) are planning
 * entries only and never Ingredient stock; "skip this run" hides an automatic suggestion until the run is confirmed.
 * Nothing here writes money or stock.
 */
class ManagePamamalengkeList
{
    public function __construct(private OperationsAccess $access) {}

    /** @return array<string, mixed> */
    public static function manualRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:80'],
            'quantity' => ['required', 'string', 'regex:'.ExactQuantity::INPUT_PATTERN],
            'unit' => ['required', 'string', 'max:30'],
            'estimated_unit_cost' => ['nullable', 'string', 'regex:/\A[0-9]{1,9}(?:\.[0-9]{1,2})?\z/'],
            'note' => ['nullable', 'string', 'max:150'],
        ];
    }

    /** @param array<string, mixed> $input */
    public function addManual(User $actor, OperationPlan $plan, array $input): PamamalengkeListEntry
    {
        $actor = $this->access->authorize($actor);
        $branch = $this->access->mutableBranch($actor);
        $this->activePlan($plan);
        foreach (['name', 'unit', 'note', 'estimated_unit_cost'] as $field) {
            $input[$field] = is_string($input[$field] ?? null) && trim($input[$field]) !== '' ? trim($input[$field]) : null;
        }
        /** @var array{name: string, quantity: string, unit: string, estimated_unit_cost: string|null, note: string|null} $data */
        $data = Validator::make($input, self::manualRules(), [
            'quantity.regex' => 'Enter a quantity with no more than four decimal places.',
            'estimated_unit_cost.regex' => 'Enter a cost with no more than two decimal places.',
        ])->validate();
        $quantity = ExactQuantity::fromInput($data['quantity']);
        if ($quantity <= 0) {
            throw ValidationException::withMessages(['quantity' => 'Enter a quantity above zero.']);
        }

        return PamamalengkeListEntry::query()->create([
            'branch_id' => $branch->id,
            'operation_plan_id' => $plan->id,
            'entry_type' => 'manual',
            'name' => $data['name'],
            'quantity' => ExactQuantity::decimal($quantity),
            'unit' => $data['unit'],
            'estimated_unit_cost' => $data['estimated_unit_cost'] === null ? null : ExactMoney::decimal(ExactMoney::cents($data['estimated_unit_cost'])),
            'note' => $data['note'],
            'created_by_user_id' => $actor->id,
        ]);
    }

    public function remove(User $actor, PamamalengkeListEntry $entry): void
    {
        $actor = $this->access->authorize($actor);
        $branch = $this->access->mutableBranch($actor);
        abort_unless($entry->branch_id === $branch->id, 404);
        $entry->delete();
    }

    public function setSkipped(User $actor, OperationPlan $plan, Ingredient $ingredient, bool $skipped): void
    {
        $actor = $this->access->authorize($actor);
        $branch = $this->access->mutableBranch($actor);
        $this->activePlan($plan);
        DB::transaction(function () use ($actor, $branch, $plan, $ingredient, $skipped): void {
            $existing = PamamalengkeListEntry::query()->where('branch_id', $branch->id)->where('operation_plan_id', $plan->id)
                ->where('entry_type', 'skip')->where('ingredient_id', $ingredient->id)->lockForUpdate()->first();
            if ($skipped && $existing === null) {
                PamamalengkeListEntry::query()->insertOrIgnore([[
                    'id' => (string) str()->uuid(),
                    'branch_id' => $branch->id,
                    'operation_plan_id' => $plan->id,
                    'entry_type' => 'skip',
                    'ingredient_id' => $ingredient->id,
                    'created_by_user_id' => $actor->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]]);
            } elseif (! $skipped) {
                $existing?->delete();
            }
        });
    }

    private function activePlan(OperationPlan $plan): void
    {
        if ($plan->archived_at !== null) {
            throw ValidationException::withMessages(['plan' => 'This Plan is archived.']);
        }
    }
}
