<?php

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

test('the profile page shows the admin-managed identity read-only beside the Preferred Name', function () {
    $user = User::factory()->create(['name' => 'Maria Clara Santos', 'email' => 'maria@pongskilog.test', 'employee_id' => 'EMP00042', 'position' => 'Barista', 'preferred_name' => 'Ria']);
    $user->branches()->attach(Branch::factory()->create(['name' => 'Main']), ['is_active' => true]);

    $this->actingAs($user)->get(route('profile.edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/profile')
            ->where('identity', ['name' => 'Maria Clara Santos', 'email' => 'maria@pongskilog.test', 'employee_id' => 'EMP00042', 'position' => 'Barista', 'branches' => ['Main']])
            ->where('preferredName', 'Ria'));
});

test('a staff member sets, normalizes and clears their own Preferred Name, audited', function () {
    $user = User::factory()->create(['name' => 'Maria Clara Santos']);

    $this->actingAs($user)->patch(route('profile.update'), ['preferred_name' => '  Ria   Santos '])
        ->assertSessionHasNoErrors()->assertRedirect(route('profile.edit'));
    expect($user->refresh()->preferred_name)->toBe('Ria Santos')
        ->and($user->displayName())->toBe('Ria Santos')
        ->and($user->customerFacingName())->toBe('Ria Santos');

    $this->actingAs($user)->patch(route('profile.update'), ['preferred_name' => ''])->assertSessionHasNoErrors();
    expect($user->refresh()->preferred_name)->toBeNull()
        ->and($user->displayName())->toBe('Maria Clara Santos')
        ->and($user->customerFacingName())->toBe('Maria');

    expect(AuditLog::query()->where('action', 'account.preferred_name_changed')->where('user_id', $user->id)->count())->toBe(2);
});

test('without a Preferred Name customers see only the first given name, skipping titles and abbreviations', function (string $name, string $shown) {
    expect(User::factory()->make(['name' => $name, 'preferred_name' => null])->customerFacingName())->toBe($shown);
})->with([
    ['Maria Clara Santos', 'Maria'],
    ['Dr. Jose Rizal', 'Jose'],
    ['Ma. Cristina Reyes', 'Cristina'],
    ['  Ana  ', 'Ana'],
    ['Mr.', 'Mr.'],
]);

test('the Preferred Name must look like a name', function (string $value) {
    $user = User::factory()->create();

    $this->actingAs($user)->patch(route('profile.update'), ['preferred_name' => $value])->assertSessionHasErrors('preferred_name');

    expect($user->refresh()->preferred_name)->toBeNull();
})->with([
    'markup' => ['<b>Boss</b>'],
    'url' => ['https://example.com'],
    'too long' => [str_repeat('a', 61)],
    'digits' => ['Cashier 1'],
]);

test('staff can never change their own full name, sign-in e-mail or organization identity from the profile', function () {
    $user = User::factory()->create(['name' => 'Maria Clara Santos', 'email' => 'maria@pongskilog.test', 'employee_id' => 'EMP00042', 'position' => 'Barista']);

    $this->actingAs($user)->patch(route('profile.update'), [
        'preferred_name' => 'Ria',
        'name' => 'Owner Person',
        'email' => 'attacker@example.com',
        'employee_id' => 'EMP99999',
        'position' => 'Owner',
        'is_active' => false,
    ])->assertSessionHasNoErrors();

    expect($user->refresh()->only(['name', 'email', 'employee_id', 'position', 'is_active', 'preferred_name']))->toBe([
        'name' => 'Maria Clara Santos', 'email' => 'maria@pongskilog.test', 'employee_id' => 'EMP00042',
        'position' => 'Barista', 'is_active' => true, 'preferred_name' => 'Ria',
    ]);
});

test('a staff member replaces and removes their own profile photo on the private disk', function () {
    Storage::fake('local');
    $user = User::factory()->create();

    $this->actingAs($user)->post(route('profile.avatar.update'), ['avatar' => UploadedFile::fake()->image('me.png', 256, 256)])
        ->assertSessionHasNoErrors()->assertRedirect(route('profile.edit'));
    $first = $user->refresh()->avatar_path;
    Storage::disk('local')->assertExists($first);
    $this->actingAs($user)->get(route('profile.avatar'))->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');

    $this->actingAs($user)->post(route('profile.avatar.update'), ['avatar' => UploadedFile::fake()->image('new.jpg', 128, 128)])->assertSessionHasNoErrors();
    Storage::disk('local')->assertMissing($first);

    $second = $user->refresh()->avatar_path;
    $this->actingAs($user)->delete(route('profile.avatar.destroy'))->assertRedirect(route('profile.edit'));
    expect($user->refresh()->avatar_path)->toBeNull();
    Storage::disk('local')->assertMissing($second);
    expect(AuditLog::query()->where('user_id', $user->id)->pluck('action')->all())
        ->toBe(['account.avatar_updated', 'account.avatar_updated', 'account.avatar_removed']);
});

test('a profile photo must be a real, reasonably sized image', function (UploadedFile $file) {
    Storage::fake('local');
    $user = User::factory()->create();

    $this->actingAs($user)->post(route('profile.avatar.update'), ['avatar' => $file])->assertSessionHasErrors('avatar');

    expect($user->refresh()->avatar_path)->toBeNull();
})->with([
    'svg' => fn () => UploadedFile::fake()->create('me.svg', 5, 'image/svg+xml'),
    'text file' => fn () => UploadedFile::fake()->create('me.png', 5, 'text/plain'),
    'too large' => fn () => UploadedFile::fake()->image('me.png', 256, 256)->size(2100),
    'too small' => fn () => UploadedFile::fake()->image('me.png', 32, 32),
]);

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
