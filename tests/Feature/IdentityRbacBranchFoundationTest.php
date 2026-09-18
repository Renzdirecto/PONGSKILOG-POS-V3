<?php

use App\Enums\BranchStatus;
use App\Models\Branch;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\QueryException;

test('user active status is cast to a boolean', function () {
    $activeUser = User::factory()->create();
    $inactiveUser = User::factory()->create(['is_active' => false]);

    expect($activeUser->is_active)->toBeTrue();
    expect($inactiveUser->is_active)->toBeFalse();
});

test('users and roles have a bidirectional relationship', function () {
    $user = User::factory()->create();
    $role = Role::factory()->create();

    $user->roles()->attach($role);

    expect($user->roles()->sole()->is($role))->toBeTrue();
    expect($role->users()->sole()->is($user))->toBeTrue();
});

test('roles and permissions have a bidirectional relationship', function () {
    $role = Role::factory()->create();
    $permission = Permission::factory()->create();

    $role->permissions()->attach($permission);

    expect($role->permissions()->sole()->is($permission))->toBeTrue();
    expect($permission->roles()->sole()->is($role))->toBeTrue();
});

test('users and branches expose active assignment state', function () {
    $user = User::factory()->create();
    $branch = Branch::factory()->create();

    $user->branches()->attach($branch, ['is_active' => false]);
    $assignedBranch = $user->branches()->sole();

    expect($assignedBranch->is($branch))->toBeTrue();
    expect((bool) $assignedBranch->pivot->is_active)->toBeFalse();
    expect($branch->users()->sole()->is($user))->toBeTrue();
});

test('duplicate user role assignments are rejected', function () {
    $user = User::factory()->create();
    $role = Role::factory()->create();
    $user->roles()->attach($role);

    expect(fn () => $user->roles()->attach($role))
        ->toThrow(QueryException::class);
});

test('duplicate role permission assignments are rejected', function () {
    $role = Role::factory()->create();
    $permission = Permission::factory()->create();
    $role->permissions()->attach($permission);

    expect(fn () => $role->permissions()->attach($permission))
        ->toThrow(QueryException::class);
});

test('duplicate user branch assignments are rejected', function () {
    $user = User::factory()->create();
    $branch = Branch::factory()->create();
    $user->branches()->attach($branch);

    expect(fn () => $user->branches()->attach($branch))
        ->toThrow(QueryException::class);
});

test('branch codes are unique', function () {
    Branch::factory()->create(['code' => 'MNL-01']);

    expect(fn () => Branch::factory()->create(['code' => 'MNL-01']))
        ->toThrow(QueryException::class);
});

test('branch status and operating hours use domain casts', function () {
    $operatingHours = ['monday' => ['opens_at' => '08:00', 'closes_at' => '22:00']];
    $branch = Branch::factory()->create([
        'status' => BranchStatus::TemporarilyClosed,
        'operating_hours' => $operatingHours,
    ]);

    expect($branch->status)->toBe(BranchStatus::TemporarilyClosed);
    expect($branch->operating_hours)->toBe($operatingHours);
});
