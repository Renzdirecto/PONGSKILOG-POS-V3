<?php

use App\Models\AuditLog;
use App\Models\PushSubscription;
use App\Models\User;
use App\Support\PushDevice;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Fortify\Features;

test('security page is displayed', function () {
    $this->skipUnlessFortifyHas(Features::twoFactorAuthentication());

    Features::twoFactorAuthentication([
        'confirm' => true,
        'confirmPassword' => true,
    ]);

    $user = User::factory()->create();

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->get(route('security.edit'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/security')
            ->where('canManageTwoFactor', true)
            ->where('twoFactorEnabled', false),
        );
});

test('security page requires password confirmation when enabled', function () {
    $this->skipUnlessFortifyHas(Features::twoFactorAuthentication());

    $user = User::factory()->create();

    Features::twoFactorAuthentication([
        'confirm' => true,
        'confirmPassword' => true,
    ]);

    $response = $this->actingAs($user)
        ->get(route('security.edit'));

    $response->assertRedirect(route('password.confirm'));
});

test('security page renders without two factor when feature is disabled', function () {
    $this->skipUnlessFortifyHas(Features::twoFactorAuthentication());

    config(['fortify.features' => []]);

    $user = User::factory()->create();

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->get(route('security.edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/security')
            ->where('canManageTwoFactor', false)
            ->missing('twoFactorEnabled')
            ->missing('requiresConfirmation'),
        );
});

test('password can be updated', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->from(route('security.edit'))
        ->put(route('user-password.update'), [
            'current_password' => 'password',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('security.edit'));

    expect(Hash::check('new-password', $user->refresh()->password))->toBeTrue();
});

test('a password change keeps this browser, drops other devices push subscriptions and is audited without the password', function () {
    $user = User::factory()->create();
    $thisDevice = PushDevice::newId();
    $current = PushSubscription::factory()->for($user)->create(['device_hash' => PushDevice::hash($thisDevice)]);
    $other = PushSubscription::factory()->for($user)->create(['device_hash' => PushDevice::hash(PushDevice::newId())]);

    $this->actingAs($user)->withCookie(PushDevice::COOKIE, $thisDevice)->from(route('security.edit'))
        ->put(route('user-password.update'), [
            'current_password' => 'password', 'password' => 'new-password', 'password_confirmation' => 'new-password',
        ])->assertSessionHasNoErrors();

    expect(PushSubscription::query()->whereKey($current->id)->exists())->toBeTrue()
        ->and(PushSubscription::query()->whereKey($other->id)->exists())->toBeFalse();
    $audit = AuditLog::query()->where('action', 'account.password_changed')->sole();
    expect($audit->user_id)->toBe($user->id)
        ->and(json_encode([$audit->before, $audit->after, $audit->metadata]))->not->toContain('new-password');
    $this->get(route('profile.edit'))->assertOk();
    $this->assertAuthenticatedAs($user);
});

test('correct password must be provided to update password', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->from(route('security.edit'))
        ->put(route('user-password.update'), [
            'current_password' => 'wrong-password',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ]);

    $response
        ->assertSessionHasErrors('current_password')
        ->assertRedirect(route('security.edit'));
});
