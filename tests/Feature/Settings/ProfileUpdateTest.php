<?php

use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Facades\Route;

test('profile page is displayed', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->get(route('profile.edit'));

    $response->assertOk();
});

test('profile information can be updated', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->patch(route('profile.update'), [
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('profile.edit'));

    $user->refresh();

    expect($user->name)->toBe('Test User');
    expect($user->email)->toBe('test@example.com');
    expect($user->email_verified_at)->toBeNull();
});

test('a profile email must stay unique ignoring case and is stored lowercase', function () {
    User::factory()->create(['email' => 'taken@example.com']);
    $user = User::factory()->create(['email' => 'mine@example.com']);

    $this->actingAs($user)->patch(route('profile.update'), ['name' => $user->name, 'email' => 'Taken@Example.com'])
        ->assertSessionHasErrors('email');
    expect($user->refresh()->email)->toBe('mine@example.com');

    $this->actingAs($user)->patch(route('profile.update'), ['name' => $user->name, 'email' => ' New.Me@Example.com '])
        ->assertSessionHasNoErrors();
    expect($user->refresh()->email)->toBe('new.me@example.com');
});

test('email verification status is unchanged when the email address is unchanged', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->patch(route('profile.update'), [
            'name' => 'Test User',
            'email' => $user->email,
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('profile.edit'));

    expect($user->refresh()->email_verified_at)->not->toBeNull();
});

test('an account cannot delete itself', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->delete('/settings/profile', ['password' => 'password'])
        ->assertMethodNotAllowed();

    $this->assertAuthenticatedAs($user);
    expect($user->fresh())->not->toBeNull()
        ->and(Route::has('profile.destroy'))->toBeFalse();
});

test('the last active super admin cannot remove itself through profile settings', function () {
    $this->seed(RbacSeeder::class);
    $superAdmin = User::factory()->create();
    $superAdmin->roles()->attach(Role::query()->where('name', 'super_admin')->sole());

    $this->actingAs($superAdmin)->delete('/settings/profile', ['password' => 'password'])->assertMethodNotAllowed();

    expect($superAdmin->fresh()?->is_active)->toBeTrue()
        ->and($superAdmin->roles()->pluck('name')->all())->toBe(['super_admin']);
});
