<?php

namespace App\Http\Controllers;

use App\Actions\Audit\AuditRecorder;
use App\Events\CustomerCatalogChanged;
use App\Models\Branch;
use App\Models\User;
use App\Support\CustomerScreenSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Settings › Customer Screen: a Branch's order-confirmation durations and its customer links (Phase 19.6 manual-QA
 * fixes). Same Branch-local authorization as the advertisements (`BranchPolicy::update`); the Branch always comes from
 * the URL. Facebook and Website are the Branch's existing Customer QR links (one value, shown on both surfaces); only
 * http(s) links are accepted. Every change is audited.
 */
class CustomerScreenSettingsController extends Controller
{
    public function __construct(private CustomerScreenSettings $settings) {}

    public function show(Branch $branch): JsonResponse
    {
        Gate::authorize('update', $branch);

        return response()->json(['settings' => $this->settings->forManagement($branch)])->header('Cache-Control', 'private, no-store');
    }

    public function update(Request $request, Branch $branch, AuditRecorder $audit): JsonResponse
    {
        Gate::authorize('update', $branch);
        $seconds = ['required', 'integer', 'min:'.CustomerScreenSettings::MIN_SUCCESS_SECONDS, 'max:'.CustomerScreenSettings::MAX_SUCCESS_SECONDS];
        $link = ['nullable', 'string', 'url:http,https', 'max:'.CustomerScreenSettings::LINK_MAX_LENGTH];
        $data = $request->validate([
            'dine_in_success_seconds' => $seconds,
            'take_out_success_seconds' => $seconds,
            'facebook_url' => $link,
            'website_url' => $link,
            'maps_url' => $link,
        ]);

        $saved = DB::transaction(function () use ($request, $branch, $data, $audit): Branch {
            $actor = $request->user();
            abort_unless($actor instanceof User, 401);
            $locked = Branch::query()->whereKey($branch->getKey())->lockForUpdate()->firstOrFail();
            $before = $this->snapshot($locked);
            $locked->update([
                'customer_screen_dine_in_success_seconds' => (int) $data['dine_in_success_seconds'],
                'customer_screen_take_out_success_seconds' => (int) $data['take_out_success_seconds'],
                'facebook_url' => $data['facebook_url'] ?? null,
                'website_url' => $data['website_url'] ?? null,
                'maps_url' => $data['maps_url'] ?? null,
            ]);
            $audit->record(
                branch: $locked,
                actor: $actor,
                module: 'settings',
                action: 'customer_screen_settings.updated',
                auditableType: Branch::class,
                auditableId: $locked->id,
                before: $before,
                after: $this->snapshot($locked),
            );
            if ($locked->wasChanged(['facebook_url', 'website_url'])) {
                /** The Customer QR page shows the same Facebook / Website buttons. */
                CustomerCatalogChanged::dispatch($locked->id);
            }

            return $locked;
        });

        return response()->json(['settings' => $this->settings->forManagement($saved)])->header('Cache-Control', 'private, no-store');
    }

    /** @return array<string, mixed> */
    private function snapshot(Branch $branch): array
    {
        return $branch->only([
            'customer_screen_dine_in_success_seconds', 'customer_screen_take_out_success_seconds',
            'facebook_url', 'website_url', 'maps_url',
        ]);
    }
}
