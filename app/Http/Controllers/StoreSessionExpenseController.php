<?php

namespace App\Http\Controllers;

use App\Actions\StoreSessions\RecordStoreSessionExpense;
use App\Http\Requests\StoreSessionExpenseRequest;
use App\Models\User;
use App\Support\ActiveBranchContext;
use App\Support\CurrentStoreSessionExpenses;
use Illuminate\Http\JsonResponse;

class StoreSessionExpenseController extends Controller
{
    public function store(
        StoreSessionExpenseRequest $request,
        ActiveBranchContext $context,
        RecordStoreSessionExpense $record,
        CurrentStoreSessionExpenses $projection,
    ): JsonResponse {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $branch = $context->current($user);
        abort_if($branch === null, 403);
        $expense = $record->execute($user, $branch, $request->validated());

        return response()->json(['expense' => $projection->expense($expense)]);
    }
}
