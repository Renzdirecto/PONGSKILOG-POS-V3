<?php

namespace App\Http\Controllers;

use App\Events\CustomerCatalogChanged;
use App\Models\Branch;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class BranchQrSettingsController extends Controller
{
    public function update(Request $request, Branch $branch): JsonResponse
    {
        Gate::authorize('update', $branch);
        $data = $request->validate([
            'qr_ordering_enabled' => ['sometimes', 'boolean'],
            'facebook_url' => ['nullable', 'url:http,https', 'max:500'],
            'website_url' => ['nullable', 'url:http,https', 'max:500'],
            'receipt_name' => ['nullable', 'string', 'max:150'],
            'receipt_address' => ['nullable', 'string', 'max:500'],
            'receipt_contact' => ['nullable', 'string', 'max:100'],
            'receipt_footer' => ['nullable', 'string', 'max:250'],
            'receipt_show_logo' => ['sometimes', 'boolean'],
        ]);
        DB::transaction(function () use ($branch, $data): void {
            Branch::query()->whereKey($branch->id)->lockForUpdate()->firstOrFail()->update($data);
            CustomerCatalogChanged::dispatch($branch->id);
        });

        return response()->json(['saved' => true]);
    }

    public function history(Request $request, Branch $branch): JsonResponse
    {
        Gate::authorize('update', $branch);
        $data = $request->validate(['date' => ['sometimes', 'date_format:Y-m-d'], 'page' => ['sometimes', 'integer', 'min:1']]);
        $date = $data['date'] ?? now('Asia/Manila')->toDateString();
        $start = CarbonImmutable::parse($date, 'Asia/Manila')->startOfDay()->setTimezone(config('app.timezone'));
        $query = DB::table('customer_qr_visits')->where('branch_id', $branch->id)->where('visited_at', '>=', $start)->where('visited_at', '<', $start->addDay());

        return response()->json(['date' => $date, 'count' => (clone $query)->count(),
            'visits' => $query->orderByDesc('visited_at')->paginate(30, ['visited_at'])])->header('Cache-Control', 'no-store');
    }
}
