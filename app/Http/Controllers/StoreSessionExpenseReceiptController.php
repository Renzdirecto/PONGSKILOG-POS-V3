<?php

namespace App\Http\Controllers;

use App\Models\StoreSessionExpense;
use App\Models\User;
use App\Support\ActiveBranchContext;
use App\Support\PosAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StoreSessionExpenseReceiptController extends Controller
{
    public function __invoke(Request $request, string $expense, ActiveBranchContext $context, PosAccess $access): StreamedResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $branch = $context->current($user);
        abort_if($branch === null, 403);
        $user = $access->authorize($user, $branch);
        abort_unless($user->hasPermission('store_expenses.manage'), 403);
        $record = StoreSessionExpense::query()->where('branch_id', $branch->id)->whereKey($expense)->firstOrFail();
        abort_if($record->receipt_disk === null || $record->receipt_image_path === null, 404);
        abort_unless(Storage::disk($record->receipt_disk)->exists($record->receipt_image_path), 404, 'Receipt image is missing.');

        return Storage::disk($record->receipt_disk)->response(
            $record->receipt_image_path,
            $record->receipt_original_name ?? 'receipt',
            ['Content-Type' => $record->receipt_mime_type ?? 'application/octet-stream', 'Cache-Control' => 'private, no-store'],
        );
    }
}
