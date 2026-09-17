<?php

use App\Models\Branch;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

function userWithBranchRole(string $roleName, bool $isActive = true): User
{
    $user = User::factory()->create(['is_active' => $isActive]);
    $role = Role::factory()->create(['name' => $roleName]);
    $user->roles()->attach($role);

    return $user;
}

test('business wide scope is limited to owner and super admin roles', function (string $roleName, bool $expected) {
    $user = userWithBranchRole($roleName);

    expect($user->hasBusinessWideScope())->toBe($expected);
})->with([
    'super admin' => ['super_admin', true],
    'owner' => ['owner', true],
    'cashier' => ['cashier', false],
    'kitchen staff' => ['kitchen_staff', false],
    'cashier kitchen' => ['cashier_kitchen', false],
]);

test('staff can access an actively assigned branch', function () {
    $user = userWithBranchRole('cashier');
    $branch = Branch::factory()->create();
    $user->branches()->attach($branch, ['is_active' => true]);

    expect($user->canAccessBranch($branch))->toBeTrue();
});

test('staff cannot access an unassigned branch', function () {
    $user = userWithBranchRole('cashier');
    $branch = Branch::factory()->create();

    expect($user->canAccessBranch($branch))->toBeFalse();
});

test('staff cannot access a branch through an inactive assignment', function () {
    $user = userWithBranchRole('kitchen_staff');
    $branch = Branch::factory()->create();
    $user->branches()->attach($branch, ['is_active' => false]);

    expect($user->canAccessBranch($branch))->toBeFalse();
});

test('owner and super admin can access branches without assignments', function (string $roleName) {
    $user = userWithBranchRole($roleName);
    $branch = Branch::factory()->create();

    expect($user->canAccessBranch($branch))->toBeTrue();
})->with(['owner', 'super_admin']);

test('inactive users cannot access branches despite their role or assignment', function (string $roleName) {
    $user = userWithBranchRole($roleName, false);
    $branch = Branch::factory()->create();
    $user->branches()->attach($branch, ['is_active' => true]);

    expect($user->canAccessBranch($branch))->toBeFalse();
})->with(['cashier', 'owner', 'super_admin']);

test('branch policy allows assigned staff and denies unassigned staff', function () {
    $user = userWithBranchRole('cashier');
    $assignedBranch = Branch::factory()->create();
    $unassignedBranch = Branch::factory()->create();
    $user->branches()->attach($assignedBranch, ['is_active' => true]);

    expect(Gate::forUser($user)->allows('view', $assignedBranch))->toBeTrue()
        ->and(Gate::forUser($user)->allows('select', $assignedBranch))->toBeTrue()
        ->and(Gate::forUser($user)->denies('view', $unassignedBranch))->toBeTrue()
        ->and(Gate::forUser($user)->denies('select', $unassignedBranch))->toBeTrue();
});

test('branch policy allows active business wide users and denies inactive ones', function () {
    $owner = userWithBranchRole('owner');
    $inactiveSuperAdmin = userWithBranchRole('super_admin', false);
    $branch = Branch::factory()->create();

    expect(Gate::forUser($owner)->allows('select', $branch))->toBeTrue()
        ->and(Gate::forUser($inactiveSuperAdmin)->denies('select', $branch))->toBeTrue();
});
