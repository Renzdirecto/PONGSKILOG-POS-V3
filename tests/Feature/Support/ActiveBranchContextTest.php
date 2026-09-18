<?php

use App\Models\Branch;
use App\Models\Role;
use App\Models\User;
use App\Support\ActiveBranchContext;
use Illuminate\Auth\Access\AuthorizationException;

function userForActiveBranchContext(string $roleName = 'cashier'): User
{
    $user = User::factory()->create();
    $role = Role::factory()->create(['name' => $roleName]);
    $user->roles()->attach($role);

    return $user;
}

test('a single active branch assignment is resolved and stored automatically', function () {
    $user = userForActiveBranchContext();
    $branch = Branch::factory()->create();
    $user->branches()->attach($branch, ['is_active' => true]);

    $resolvedBranch = app(ActiveBranchContext::class)->current($user);

    expect($resolvedBranch?->is($branch))->toBeTrue()
        ->and(session(ActiveBranchContext::SESSION_KEY))->toBe($branch->getKey());
});

test('multiple active branch assignments are not selected arbitrarily', function () {
    $user = userForActiveBranchContext();
    $branches = Branch::factory()->count(2)->create();
    $user->branches()->attach($branches, ['is_active' => true]);

    $resolvedBranch = app(ActiveBranchContext::class)->current($user);

    expect($resolvedBranch)->toBeNull()
        ->and(session()->has(ActiveBranchContext::SESSION_KEY))->toBeFalse();
});

test('staff can set an actively assigned branch', function () {
    $user = userForActiveBranchContext();
    $branch = Branch::factory()->create();
    $user->branches()->attach($branch, ['is_active' => true]);
    $context = app(ActiveBranchContext::class);

    $context->set($user, $branch);

    expect($context->current($user)?->is($branch))->toBeTrue()
        ->and(session(ActiveBranchContext::SESSION_KEY))->toBe($branch->getKey());
});

test('staff cannot set an unassigned branch', function () {
    $user = userForActiveBranchContext();
    $branch = Branch::factory()->create();

    expect(fn () => app(ActiveBranchContext::class)->set($user, $branch))
        ->toThrow(AuthorizationException::class);
    expect(session()->has(ActiveBranchContext::SESSION_KEY))->toBeFalse();
});

test('staff cannot set a branch through an inactive assignment', function () {
    $user = userForActiveBranchContext('kitchen_staff');
    $branch = Branch::factory()->create();
    $user->branches()->attach($branch, ['is_active' => false]);

    expect(fn () => app(ActiveBranchContext::class)->set($user, $branch))
        ->toThrow(AuthorizationException::class);
    expect(session()->has(ActiveBranchContext::SESSION_KEY))->toBeFalse();
});

test('stale unauthorized branch context is rejected and cleared', function () {
    $user = userForActiveBranchContext();
    $branch = Branch::factory()->create();
    $user->branches()->attach($branch, ['is_active' => false]);
    session()->put(ActiveBranchContext::SESSION_KEY, $branch->getKey());

    $resolvedBranch = app(ActiveBranchContext::class)->current($user);

    expect($resolvedBranch)->toBeNull()
        ->and(session()->has(ActiveBranchContext::SESSION_KEY))->toBeFalse();
});

test('business wide users keep a null context when no branch is selected', function (string $roleName) {
    $user = userForActiveBranchContext($roleName);
    $branch = Branch::factory()->create();
    $user->branches()->attach($branch, ['is_active' => true]);

    $resolvedBranch = app(ActiveBranchContext::class)->current($user);

    expect($resolvedBranch)->toBeNull()
        ->and(session()->has(ActiveBranchContext::SESSION_KEY))->toBeFalse();
})->with(['owner', 'super_admin']);

test('business wide users may explicitly select any branch', function (string $roleName) {
    $user = userForActiveBranchContext($roleName);
    $branch = Branch::factory()->create();
    $context = app(ActiveBranchContext::class);

    $context->set($user, $branch);

    expect($context->current($user)?->is($branch))->toBeTrue();
})->with(['owner', 'super_admin']);
