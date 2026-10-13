<?php

namespace App\Http\Controllers;

use App\Actions\Audit\AuditRecorder;
use App\Http\Requests\StoreShippyMessageRequest;
use App\Http\Requests\UpdateShippySettingsRequest;
use App\Models\ShippySetting;
use App\Support\ShippyAssistant;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Shippy, the landing page chat (v1.3.0): the public endpoint a guest's question goes to, and the Owner's switch in
 * Website → Shippy. A question is answered and forgotten: nothing a guest types is stored.
 */
class ShippyController extends Controller
{
    /** Answers one question. While the chat is switched off the endpoint does not exist. */
    public function message(StoreShippyMessageRequest $request, ShippyAssistant $assistant): JsonResponse
    {
        abort_unless(ShippySetting::current()->is_enabled, 404);
        $question = $request->question();
        if ($question === '') {
            throw ValidationException::withMessages(['message' => 'Type a question first.']);
        }

        return response()->json(['reply' => $assistant->reply(
            $question,
            $request->turns(),
            hash('sha256', $request->session()->getId()),
        )])->header('Cache-Control', 'private, no-store');
    }

    public function update(UpdateShippySettingsRequest $request, AuditRecorder $audit): JsonResponse
    {
        DB::transaction(function () use ($request, $audit): void {
            $setting = ShippySetting::query()->whereKey(ShippySetting::current()->id)->lockForUpdate()->firstOrFail();
            $before = $setting->only(['is_enabled']);
            $setting->update($request->validated());
            if ($before !== $setting->only(['is_enabled'])) {
                $audit->record(null, $request->user(), 'settings', $setting->is_enabled ? 'shippy.enabled' : 'shippy.disabled', ShippySetting::class, (string) $setting->id, $before, $setting->only(['is_enabled']));
            }
        });

        return response()->json(['saved' => true]);
    }
}
