<?php

namespace App\Http\Controllers;

use App\Actions\Orders\TransitionKitchenOrder;
use App\Enums\KitchenStatus;
use App\Http\Requests\UpdateKitchenStatusRequest;
use App\Models\Order;
use App\Models\User;
use App\Support\ActiveBranchContext;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class KitchenStatusController extends Controller
{
    public function __invoke(
        UpdateKitchenStatusRequest $request,
        Order $order,
        ActiveBranchContext $activeBranchContext,
        TransitionKitchenOrder $transitionKitchenOrder,
    ): RedirectResponse {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $branch = $activeBranchContext->current($user);
        abort_if($branch === null, 403);
        abort_unless($order->branch_id === $branch->getKey(), 404);

        $target = KitchenStatus::from($request->string('status')->toString());
        $result = $transitionKitchenOrder->executeWithResult(
            $user,
            $branch,
            $order,
            $target,
        );

        Inertia::flash('kitchenTransition', [
            'order_id' => (string) $result['order']->getKey(),
            'from' => $result['from']->value,
            'to' => $target->value,
            'changed' => $result['changed'],
        ]);

        return back();
    }
}
