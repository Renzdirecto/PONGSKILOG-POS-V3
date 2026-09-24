<?php

namespace App\Actions\Operations;

use App\Actions\Audit\AuditRecorder;
use App\Models\OperationPlan;
use App\Models\OperationPlanProduct;
use App\Models\Product;
use App\Models\User;
use App\Support\OperationsAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Creates or updates a Pamalengke Plan and its Product membership. Products come only from the existing Catalog; a
 * Product belongs to at most one active Plan, so choosing it here moves it from its previous Plan. Moving affects only
 * future sales: committed sales keep the Plan recorded in their Order recipe snapshot.
 */
class SaveOperationPlan
{
    public function __construct(private OperationsAccess $access, private AuditRecorder $audit) {}

    /** @return array<string, mixed> */
    public static function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:60'],
            'description' => ['nullable', 'string', 'max:200'],
            'icon' => ['required', 'string', Rule::in(OperationPlan::ICONS)],
            'product_ids' => ['present', 'array', 'max:500'],
            'product_ids.*' => ['required', 'uuid', 'distinct'],
        ];
    }

    /** @param array<string, mixed> $input */
    public function execute(User $actor, ?OperationPlan $plan, array $input): OperationPlan
    {
        $actor = $this->access->authorize($actor);
        $input['name'] = is_string($input['name'] ?? null) ? trim($input['name']) : ($input['name'] ?? null);
        $input['description'] = is_string($input['description'] ?? null) && trim($input['description']) !== '' ? trim($input['description']) : null;
        /** @var array{name: string, description: string|null, icon: string, product_ids: list<string>} $data */
        $data = Validator::make($input, self::rules())->validate();
        $productIds = array_values(array_unique(array_map('strtolower', $data['product_ids'])));
        sort($productIds);

        return DB::transaction(function () use ($actor, $plan, $data, $productIds): OperationPlan {
            if ($plan !== null) {
                $plan = OperationPlan::query()->whereKey($plan->id)->lockForUpdate()->firstOrFail();
                if ($plan->archived_at !== null) {
                    throw ValidationException::withMessages(['plan' => 'This Plan is archived.']);
                }
            }
            $duplicate = OperationPlan::query()->whereNull('archived_at')
                ->whereRaw('LOWER(name) = ?', [mb_strtolower($data['name'])])
                ->when($plan !== null, fn ($query) => $query->whereKeyNot($plan?->id))
                ->exists();
            if ($duplicate) {
                throw ValidationException::withMessages(['name' => 'Another Plan already uses this name.']);
            }
            $found = Product::query()->whereKey($productIds)->orderBy('id')->lockForUpdate()->pluck('id')->all();
            if (count($found) !== count($productIds)) {
                throw ValidationException::withMessages(['product_ids' => 'Choose only existing Catalog products.']);
            }

            $before = $plan === null ? null : $this->snapshot($plan);
            $plan ??= new OperationPlan(['created_by_user_id' => $actor->id]);
            $plan->fill(['name' => $data['name'], 'description' => $data['description'], 'icon' => $data['icon']])->save();

            $memberships = OperationPlanProduct::query()
                ->where(fn ($query) => $query->where('operation_plan_id', $plan->id)->orWhereIn('product_id', $productIds))
                ->orderBy('product_id')->lockForUpdate()->get();
            $moved = [];
            foreach ($memberships as $membership) {
                if ($membership->operation_plan_id === $plan->id && ! in_array($membership->product_id, $productIds, true)) {
                    $membership->delete();
                } elseif ($membership->operation_plan_id !== $plan->id) {
                    $moved[] = ['product_id' => $membership->product_id, 'from_plan_id' => $membership->operation_plan_id];
                    $membership->update(['operation_plan_id' => $plan->id]);
                }
            }
            $existing = $memberships->pluck('product_id')->all();
            foreach (array_diff($productIds, $existing) as $productId) {
                OperationPlanProduct::query()->create(['operation_plan_id' => $plan->id, 'product_id' => $productId]);
            }

            $this->audit->record(
                branch: null,
                actor: $actor,
                module: 'operations',
                action: $before === null ? 'operation_plan.created' : 'operation_plan.updated',
                auditableType: OperationPlan::class,
                auditableId: $plan->id,
                before: $before,
                after: $this->snapshot($plan),
                metadata: ['moved_products' => $moved],
            );

            return $plan;
        });
    }

    /** @return array<string, mixed> */
    private function snapshot(OperationPlan $plan): array
    {
        return [
            'name' => $plan->name,
            'description' => $plan->description,
            'icon' => $plan->icon,
            'product_ids' => OperationPlanProduct::query()->where('operation_plan_id', $plan->id)->orderBy('product_id')->pluck('product_id')->all(),
        ];
    }
}
