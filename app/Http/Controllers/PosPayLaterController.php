<?php

namespace App\Http\Controllers;

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
    ): JsonResponse {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $branch = $context->current($user);
        abort_if($branch === null, 403);
        $order = $commit->execute($user, $branch, $order, $request->validated());

        return response()->json(['order' => $summary->summary($order)])->header('Cache-Control', 'no-store');
    }
}
