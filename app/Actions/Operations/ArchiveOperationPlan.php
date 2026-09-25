<?php

namespace App\Actions\Operations;

use App\Actions\Audit\AuditRecorder;
use App\Models\OperationPlan;
use App\Models\OperationPlanProduct;
use App\Models\PamamalengkeListEntry;
use App\Models\User;
use App\Support\OperationsAccess;
use Illuminate\Support\Facades\DB;

/**
 * Archives a Plan instead of deleting it: its historical snapshots, movements, purchases and audit stay readable. Its
 * Products are released so they can join another Plan (future sales only), and its unconfirmed working list is cleared.
 * Ingredient stock is untouched because a Plan never owned any.
 */
class ArchiveOperationPlan
{
    public function __construct(private OperationsAccess $access, private AuditRecorder $audit) {}

    public function execute(User $actor, OperationPlan $plan): OperationPlan
    {
        $actor = $this->access->authorizeDefinitions($actor);

        return DB::transaction(function () use ($actor, $plan): OperationPlan {
            $plan = OperationPlan::query()->whereKey($plan->id)->lockForUpdate()->firstOrFail();
            if ($plan->archived_at !== null) {
                return $plan;
            }
            $products = OperationPlanProduct::query()->where('operation_plan_id', $plan->id)->orderBy('product_id')->lockForUpdate()->get();
            $products->each->delete();
            PamamalengkeListEntry::query()->where('operation_plan_id', $plan->id)->delete();
            $archivedAt = now();
            $plan->update(['archived_at' => $archivedAt]);

            $this->audit->record(
                branch: null,
                actor: $actor,
                module: 'operations',
                action: 'operation_plan.archived',
                auditableType: OperationPlan::class,
                auditableId: $plan->id,
                before: ['name' => $plan->name, 'product_ids' => $products->pluck('product_id')->all()],
                after: ['archived_at' => $archivedAt->toIso8601String()],
            );

            return $plan;
        });
    }
}
