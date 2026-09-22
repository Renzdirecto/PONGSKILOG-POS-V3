<?php

namespace App\Http\Controllers;

use App\Actions\Audit\AuditRecorder;
use App\Enums\CommercialStatus;
use App\Enums\OrderSource;
use App\Events\CustomerCatalogChanged;
use App\Models\Branch;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class BranchQrSettingsController extends Controller
{
    public function update(Request $request, Branch $branch, AuditRecorder $audit): JsonResponse
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
            'receipt_logo' => ['sometimes', 'image', 'mimes:png,jpg,jpeg,webp', 'max:2048', 'dimensions:max_width=2000,max_height=2000'],
            'remove_receipt_logo' => ['sometimes', 'boolean'],
        ]);
        $uploaded = $request->file('receipt_logo')?->store('receipt-logos/'.$branch->id, 's3');
        abort_if($uploaded === false, 503, 'The receipt logo could not be saved.');
        $remove = $request->boolean('remove_receipt_logo');
        unset($data['receipt_logo'], $data['remove_receipt_logo']);
        try {
            $previous = DB::transaction(function () use ($request, $branch, $data, $uploaded, $remove, $audit): ?string {
                $actor = $request->user();
                abort_unless($actor instanceof User, 401);
                $locked = Branch::query()->whereKey($branch->id)->lockForUpdate()->firstOrFail();
                $previous = $locked->receipt_logo_path;
                $before = $this->auditSnapshot($locked);
                if ($uploaded || $remove) {
                    $data['receipt_logo_path'] = $uploaded ?: null;
                }
                $locked->update($data);
                $audit->record(
                    branch: $locked,
                    actor: $actor,
                    module: 'settings',
                    action: 'branch_receipt_qr_settings.updated',
                    auditableType: Branch::class,
                    auditableId: $locked->id,
                    before: $before,
                    after: $this->auditSnapshot($locked),
                );
                CustomerCatalogChanged::dispatch($branch->id);

                return $previous;
            });
        } catch (Throwable $exception) {
            if ($uploaded) {
                Storage::disk('s3')->delete($uploaded);
            }
            throw $exception;
        }
        if (($uploaded || $remove) && $previous) {
            Storage::disk('s3')->delete($previous);
        }

        return response()->json(['saved' => true]);
    }

    public function logo(Branch $branch): StreamedResponse
    {
        abort_unless($branch->receipt_logo_path && Storage::disk('s3')->exists($branch->receipt_logo_path), 404);

        return Storage::disk('s3')->response($branch->receipt_logo_path, null, ['Cache-Control' => 'no-cache', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function history(Request $request, Branch $branch): JsonResponse
    {
        Gate::authorize('update', $branch);
        $data = $request->validate(['date' => ['sometimes', 'date_format:Y-m-d'], 'page' => ['sometimes', 'integer', 'min:1']]);
        $date = $data['date'] ?? now('Asia/Manila')->toDateString();
        $start = CarbonImmutable::parse($date, 'Asia/Manila')->startOfDay()->setTimezone(config('app.timezone'));
        $query = DB::table('customer_qr_visits')->where('branch_id', $branch->id)->where('visited_at', '>=', $start)->where('visited_at', '<', $start->addDay());
        $orders = $branch->orders()->where('source', OrderSource::CustomerQr)->where('created_at', '>=', $start)->where('created_at', '<', $start->addDay());

        return response()->json(['date' => $date, 'count' => (clone $query)->count(),
            'orders_placed' => (clone $orders)->count(),
            'waiting_retrieval' => (clone $orders)->where('commercial_status', CommercialStatus::Submitted)->whereNull('loaded_by_user_id')->count(),
            'visits' => $query->orderByDesc('visited_at')->paginate(30, ['visited_at'])])->header('Cache-Control', 'no-store');
    }

    /** @return array<string, mixed> */
    private function auditSnapshot(Branch $branch): array
    {
        return $branch->only([
            'qr_ordering_enabled', 'facebook_url', 'website_url', 'receipt_name',
            'receipt_address', 'receipt_contact', 'receipt_footer',
            'receipt_show_logo', 'receipt_logo_path',
        ]);
    }
}
