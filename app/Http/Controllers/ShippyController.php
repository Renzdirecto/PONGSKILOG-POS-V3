<?php

namespace App\Http\Controllers;

use App\Actions\Audit\AuditRecorder;
use App\Http\Requests\StoreShippyMessageRequest;
use App\Http\Requests\TestShippyRequest;
use App\Http\Requests\UpdateShippySettingsRequest;
use App\Models\ShippySetting;
use App\Support\ShippyAssistant;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Shippy, the landing page chat (v1.3.0): the public endpoint a guest's question goes to, and the Owner's switch and
 * Test Shippy in Website → Shippy. A question is answered and forgotten: nothing a guest or the Owner types is stored.
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

    /**
     * Test Shippy in Website → Shippy: the Owner's question goes through the very engine a guest's does, with the
     * same Gemini limits and quota, and comes back with where the answer came from and how long the server took.
     * It works while the chat is switched off, so knowledge can be tried before guests see it. Nothing is stored.
     */
    public function test(TestShippyRequest $request, ShippyAssistant $assistant): JsonResponse
    {
        $question = $request->question();
        if ($question === '') {
            throw ValidationException::withMessages(['message' => 'Type a question first.']);
        }
        $started = hrtime(true);
        $reply = $assistant->reply($question, [], hash('sha256', 'test|'.$request->user()?->getAuthIdentifier()));

        return response()->json(['reply' => $reply, 'milliseconds' => (int) round((hrtime(true) - $started) / 1_000_000)])
            ->header('Cache-Control', 'private, no-store');
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
