<?php

namespace App\Http\Controllers;

use App\Actions\Operations\ArchiveOperationPlan;
use App\Actions\Operations\SaveOperationPlan;
use App\Models\OperationPlan;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

class OperationPlanController extends Controller
{
    public function store(Request $request, SaveOperationPlan $save): RedirectResponse
    {
        $plan = $save->execute($request->user(), null, $request->only(['name', 'description', 'icon', 'product_ids']));
        Inertia::flash('toast', ['type' => 'success', 'message' => $plan->name.' plan added. Next: attach recipes to its products.']);

        return to_route('operations.recipes', ['plan' => $plan->id]);
    }

    public function update(Request $request, OperationPlan $plan, SaveOperationPlan $save): RedirectResponse
    {
        $plan = $save->execute($request->user(), $plan, $request->only(['name', 'description', 'icon', 'product_ids']));
        Inertia::flash('toast', ['type' => 'success', 'message' => $plan->name.' plan saved.']);

        return back();
    }

    public function archive(Request $request, OperationPlan $plan, ArchiveOperationPlan $archive): RedirectResponse
    {
        $plan = $archive->execute($request->user(), $plan);
        Inertia::flash('toast', ['type' => 'success', 'message' => $plan->name.' plan archived. Its history stays in reports.']);

        return to_route('operations.plans');
    }
}
