<?php

use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    Route::middleware(['web', 'auth', 'permission:pos.access'])
        ->get('/_phase-1c/permissions/pos', fn () => response()->noContent());

    Route::middleware(['web', 'auth', 'permission:reports.view'])
        ->get('/_phase-1c/permissions/reports', fn () => response()->noContent());

    Route::middleware(['web', 'auth', 'permission:access_control.manage'])
        ->get('/_phase-1c/permissions/access-control', fn () => response()->noContent());

    $this->seed(RbacSeeder::class);
});

function userWithSeededPermissionRole(string $roleName): User
{
    $user = User::factory()->create();
    $role = Role::query()->where('name', $roleName)->sole();
    $user->roles()->attach($role);

    return $user;
}

test('permission middleware requires authentication', function () {
    $response = $this->get('/_phase-1c/permissions/pos');

    $response->assertRedirect(route('login'));
});

test('permission middleware allows a user with the required permission', function () {
    $user = userWithSeededPermissionRole('cashier');

    $response = $this->actingAs($user)->get('/_phase-1c/permissions/pos');

    $response->assertNoContent();
});

test('permission middleware returns forbidden without the required permission', function () {
    $user = userWithSeededPermissionRole('kitchen_staff');

    $response = $this->actingAs($user)->get('/_phase-1c/permissions/pos');

    $response->assertForbidden();
});

test('kitchen staff cannot access financial owner permissions', function () {
    $user = userWithSeededPermissionRole('kitchen_staff');

    $response = $this->actingAs($user)->get('/_phase-1c/permissions/reports');

    $response->assertForbidden();
});

test('cashiers cannot access super admin permissions', function () {
    $user = userWithSeededPermissionRole('cashier');

    $response = $this->actingAs($user)->get('/_phase-1c/permissions/access-control');

    $response->assertForbidden();
});
