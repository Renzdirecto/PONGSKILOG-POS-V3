<?php

namespace App\Http\Controllers;

use App\Actions\Payments\ReplacePaymentInvoiceProof;
use App\Http\Requests\StorePaymentInvoiceProofRequest;
use App\Models\Payment;
use App\Models\PaymentInvoiceProof;
use App\Models\User;
use App\Support\ActiveBranchContext;
use App\Support\PosAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PaymentInvoiceProofController extends Controller
{
    public function store(StorePaymentInvoiceProofRequest $request, Payment $payment, ActiveBranchContext $context, ReplacePaymentInvoiceProof $replace): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $branch = $context->current($user);
        abort_if($branch === null, 403);
        $proof = $replace->execute($user, $branch, $payment, $request->file('invoice'));

        return response()->json(['invoice' => ['name' => $proof->original_name, 'url' => route('pos.payments.invoice.show', $payment, false)]]);
    }

    public function show(Request $request, Payment $payment, ActiveBranchContext $context, PosAccess $access): StreamedResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $branch = $context->current($user);
        abort_if($branch === null, 403);
        $access->authorize($user, $branch);
        $proof = PaymentInvoiceProof::query()->where('branch_id', $branch->id)->where('payment_id', $payment->id)->firstOrFail();
        abort_unless(Storage::disk($proof->disk)->exists($proof->path), 404, 'Invoice proof file is missing.');

        return Storage::disk($proof->disk)->response($proof->path, $proof->original_name, ['Content-Type' => $proof->mime_type, 'Cache-Control' => 'private, no-store']);
    }

    public function destroy(Request $request, Payment $payment, ActiveBranchContext $context, ReplacePaymentInvoiceProof $replace): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $branch = $context->current($user);
        abort_if($branch === null, 403);
        $replace->delete($user, $branch, $payment);

        return response()->json(status: 204);
    }
}
