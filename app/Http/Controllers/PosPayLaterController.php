<?php

namespace App\Http\Controllers;

use App\Actions\CustomerScreens\ShowOrderOnCustomerScreen;
use App\Actions\Orders\CommitPayLaterOrder;
use App\Http\Requests\CommitPayLaterOrderRequest;
use App\Models\Order;
use App\Models\User;
use App\Support\ActiveBranchContext;
use App\Support\PayLaterOrderSummary;
use Illuminate\Http\JsonResponse;

class PosPayLaterController extends Controller
{
    public function store(
        CommitPayLaterOrderRequest $request,
        Order $order,
        ActiveBranchContext $context,
        CommitPayLaterOrder $commit,
        PayLaterOrderSummary $summary,
        ShowOrderOnCustomerScreen $customerScreen,
    ): JsonResponse {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $branch = $context->current($user);
        abort_if($branch === null, 403);
        $order = $commit->execute($user, $branch, $order, $request->validated());
        /** Committed: confirm it on this station's customer screen (best effort, never affects the order). */
        $customerScreen->afterCommit($request, $branch, $order);

        return response()->json(['order' => $summary->summary($order)])->header('Cache-Control', 'no-store');
    }
}
