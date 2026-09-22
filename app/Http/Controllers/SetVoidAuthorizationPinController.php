<?php

namespace App\Http\Controllers;

use App\Actions\Audit\AuditRecorder;
use App\Http\Requests\SetVoidAuthorizationPinRequest;
use App\Models\User;
use App\Models\VoidAuthorizationSetting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class SetVoidAuthorizationPinController extends Controller
{
    public function __invoke(SetVoidAuthorizationPinRequest $request, AuditRecorder $audit): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        DB::transaction(function () use ($request, $audit, $user): void {
            if (DB::getDriverName() === 'pgsql') {
                DB::select("SELECT pg_advisory_xact_lock(hashtextextended('void_authorization_settings:global', 0))");
            }

            $setting = VoidAuthorizationSetting::query()
                ->where('scope', 'global')
                ->lockForUpdate()
                ->first();
            $before = $setting === null ? null : [
                'configured_by_user_id' => $setting->configured_by_user_id,
                'configured_at' => $setting->configured_at->toIso8601String(),
            ];

            $setting ??= new VoidAuthorizationSetting(['scope' => 'global']);
            $setting->fill([
                'pin_hash' => Hash::make($request->string('pin')->toString()),
                'configured_by_user_id' => $user->id,
                'configured_at' => now(),
            ]);
            $setting->save();

            $audit->record(
                branch: null,
                actor: $user,
                module: 'void_authorization',
                action: 'void_pin.configured',
                auditableType: VoidAuthorizationSetting::class,
                auditableId: (string) $setting->id,
                before: $before,
                after: [
                    'configured_by_user_id' => $setting->configured_by_user_id,
                    'configured_at' => $setting->configured_at->toIso8601String(),
                ],
                metadata: ['pin' => '[REDACTED]'],
            );
        });

        return to_route('workspaces.void-orders')->with('success', 'Void PIN updated.');
    }
}
