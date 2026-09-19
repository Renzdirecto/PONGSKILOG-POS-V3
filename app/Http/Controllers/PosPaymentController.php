<?php

namespace App\Http\Controllers;

use App\Actions\Orders\PayNowOrder;
use App\Http\Requests\PayNowOrderRequest;
use App\Models\User;
use App\Support\ActiveBranchContext;
use App\Support\PosReceipt;
use Illuminate\Http\JsonResponse;

class PosPaymentController extends Controller
{
    public function store(PayNowOrderRequest $request, ActiveBranchContext $context, PayNowOrder $pay, PosReceipt $receipt): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $branch = $context->current($user);
        abort_if($branch === null, 403);
        $order = $pay->execute($user, $branch, $request->validated());

        return response()->json(['receipt' => $receipt->summary($order)])->header('Cache-Control', 'no-store');
    }
}
