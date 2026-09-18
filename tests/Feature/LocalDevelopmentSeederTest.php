<?php

use App\Models\Branch;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\LocalDevelopmentSeeder;
use Illuminate\Support\Facades\Hash;

test('development accounts can log in and reach their assigned workspace', function (string $email, string $destination, int $branchCount) {
    $this->seed(LocalDevelopmentSeeder::class);

    $this->post(route('login.store'), [
        'email' => $email,
        'password' => 'password',
    ])->assertRedirect('/workspace');

    $this->assertAuthenticated();
    $this->get(route('workspace'))->assertRedirectToRoute($destination);

    $user = User::query()->where('email', $email)->sole();
    expect($user->branches()->wherePivot('is_active', true)->count())->toBe($branchCount);

    $this->post(route('logout'))->assertRedirect('/');
    $this->assertGuest();
})->with([
    ['superadmin@gmail.com', 'workspaces.super-admin', 0],
    ['owner@gmail.com', 'workspaces.owner', 0],
    ['cashier@gmail.com', 'workspaces.cashier', 1],
    ['kitchen@gmail.com', 'workspaces.kitchen', 1],
    ['branch@gmail.com', 'branches.select', 2],
]);

test('development seeding can be repeated without duplicating records or resetting passwords', function () {
    $this->seed(LocalDevelopmentSeeder::class);
    $owner = User::query()->where('email', 'owner@gmail.com')->sole();
    $owner->update(['password' => 'changed-password']);

    $this->seed(LocalDevelopmentSeeder::class);

    expect(User::query()->count())->toBe(5);
    expect(Branch::query()->count())->toBe(2);
    expect(Hash::check('changed-password', $owner->fresh()->password))->toBeTrue();
    $this->assertDatabaseCount('user_roles', 5);
    $this->assertDatabaseCount('user_branch_assignments', 4);
});

test('development seeding refuses production before creating data', function () {
    app()->instance('env', 'production');

    expect(fn () => app(LocalDevelopmentSeeder::class)->run())
        ->toThrow(RuntimeException::class, 'Development accounts may only be seeded locally or in tests.');

    $this->assertDatabaseCount('users', 0);
    $this->assertDatabaseCount('branches', 0);
    $this->assertDatabaseCount('roles', 0);
});

test('development accounts have the intended roles and active branch assignments with stores closed', function () {
    $this->seed(LocalDevelopmentSeeder::class);

    foreach ([
        'superadmin@gmail.com' => ['super_admin', []],
        'owner@gmail.com' => ['owner', []],
        'cashier@gmail.com' => ['cashier', ['MAIN']],
        'kitchen@gmail.com' => ['kitchen_staff', ['MAIN']],
        'branch@gmail.com' => ['cashier', ['MAIN', 'QAVE']],
    ] as $email => [$role, $branches]) {
        $user = User::query()->where('email', $email)->sole();

        expect($user->is_active)->toBeTrue();
        expect($user->roles()->pluck('name')->all())->toBe([$role]);
        expect($user->branches()->wherePivot('is_active', true)->orderBy('code')->pluck('code')->all())
            ->toBe($branches);
    }

    expect(Branch::query()->where('status', 'active')->count())->toBe(2);
    $this->assertDatabaseCount('store_sessions', 0);
});

test('normal database seeding does not create development accounts or branches', function () {
    $this->seed(DatabaseSeeder::class);

    $this->assertDatabaseCount('users', 0);
    $this->assertDatabaseCount('branches', 0);
    $this->assertDatabaseCount('store_sessions', 0);
});
