<?php

namespace App\Http\Controllers\Settings;

use App\Actions\Audit\AuditRecorder;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\PasswordUpdateRequest;
use App\Http\Requests\Settings\TwoFactorAuthenticationRequest;
use App\Models\User;
use App\Support\PushDevice;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Fortify\Features;

class SecurityController extends Controller
{
    /**
     * Show the user's security settings page.
     */
    public function edit(TwoFactorAuthenticationRequest $request): Response
    {
        $props = [
            'canManageTwoFactor' => Features::canManageTwoFactorAuthentication(),
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
        ];

        if (Features::canManageTwoFactorAuthentication()) {
            $request->ensureStateIsValid();

            $props['twoFactorEnabled'] = $request->user()->hasEnabledTwoFactorAuthentication();
            $props['requiresConfirmation'] = Features::optionEnabled(Features::twoFactorAuthentication(), 'confirm');
        }

        return Inertia::render('settings/security', $props);
    }

    /**
     * Update the user's password. This browser stays signed in; every other browser of the account is signed out by
     * `AuthenticateSession` (its stored password hash no longer matches) and stops receiving the account's push
     * notifications. The change is audited without any password material.
     */
    public function update(PasswordUpdateRequest $request, AuditRecorder $audit): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $deviceId = PushDevice::idFrom($request);

        DB::transaction(function () use ($user, $request, $audit, $deviceId): void {
            $user->update(['password' => $request->password]);
            $user->pushSubscriptions()
                ->when($deviceId !== null, fn ($query) => $query->where('device_hash', '!=', PushDevice::hash((string) $deviceId)))
                ->delete();
            $audit->record(
                branch: null,
                actor: $user,
                module: 'account',
                action: 'account.password_changed',
                auditableType: User::class,
                auditableId: (string) $user->id,
            );
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Password updated. Other devices were signed out.')]);

        return back();
    }
}
