<?php

namespace App\Http\Controllers;

use App\Actions\Operations\ConfirmPamamalengke;
use App\Actions\Operations\ManagePamamalengkeList;
use App\Models\Ingredient;
use App\Models\OperationPlan;
use App\Models\PamamalengkeListEntry;
use App\Support\ExactMoney;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

class PamamalengkeController extends Controller
{
    public function storeManual(Request $request, OperationPlan $plan, ManagePamamalengkeList $list): RedirectResponse
    {
        $entry = $list->addManual($request->user(), $plan, $request->only(['name', 'quantity', 'unit', 'estimated_unit_cost', 'note']));
        Inertia::flash('toast', ['type' => 'success', 'message' => $entry->name.' added to the '.$plan->name.' list. It is not linked to ingredient stock.']);

        return back();
    }

    public function destroyManual(Request $request, PamamalengkeListEntry $entry, ManagePamamalengkeList $list): RedirectResponse
    {
        $list->remove($request->user(), $entry);

        return back();
    }

    public function skip(Request $request, OperationPlan $plan, Ingredient $ingredient, ManagePamamalengkeList $list): RedirectResponse
    {
        $request->validate(['skipped' => ['required', 'boolean']]);
        $list->setSkipped($request->user(), $plan, $ingredient, $request->boolean('skipped'));

        return back();
    }

    public function confirm(Request $request, OperationPlan $plan, ConfirmPamamalengke $confirm): RedirectResponse
    {
        $purchase = $confirm->execute($request->user(), $plan, $request->only(['idempotency_key', 'payment_source', 'note', 'items']));
        $total = ExactMoney::cents((string) $purchase->expense()->value('amount'));
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Pamamalengke confirmed · '.ExactMoney::display($total).' saved as a Store Purchase.']);
        Inertia::flash('pamamalengkeConfirmed', ['purchase_id' => $purchase->id, 'idempotency_key' => $purchase->idempotency_key]);

        return to_route('operations.pamamalengke', ['plan' => $plan->id]);
    }
}
