<?php

use App\Models\User;

test('inactive users receive the generic authentication failure', function () {
    $user = User::factory()->create(['is_active' => false]);

    $response = $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $response->assertSessionHasErrors([
        'email' => trans('auth.failed'),
    ]);
    $this->assertGuest();
});

test('inactive authenticated users cannot access protected application routes', function () {
    $user = User::factory()->create(['is_active' => false]);

    $response = $this->actingAs($user)->get(route('dashboard'));

    $response->assertRedirect(route('login'));
    $this->assertGuest();
});

test('a user deactivated after login is denied on the next protected request', function () {
    $user = User::factory()->create();

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ])->assertRedirect(route('dashboard', absolute: false));

    User::query()->whereKey($user->getKey())->update(['is_active' => false]);

    $response = $this->get(route('dashboard'));

    $response->assertRedirect(route('login'));
    $this->assertGuest();
});
