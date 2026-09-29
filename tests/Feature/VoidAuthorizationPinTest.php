<?php

use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use App\Models\VoidAuthorizationSetting;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Facades\Hash;

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->superAdmin = User::factory()->create();
    $this->superAdmin->roles()->attach(Role::query()->where('name', 'super_admin')->sole());
});

test('super admin can securely configure the four digit void PIN', function () {
    $this->actingAs($this->superAdmin)
        ->put(route('workspaces.void-orders.pin.update'), [
            'pin' => '1234',
            'pin_confirmation' => '1234',
        ])
        ->assertRedirect(route('workspaces.void-orders', absolute: false));

    $setting = VoidAuthorizationSetting::query()->sole();

    expect(Hash::check('1234', $setting->pin_hash))->toBeTrue()
        ->and($setting->configured_by_user_id)->toBe($this->superAdmin->id);
    $this->assertDatabaseHas('audit_logs', [
        'action' => 'void_pin.configured',
        'user_id' => $this->superAdmin->id,
    ]);
    expect(json_encode(AuditLog::query()->where('action', 'void_pin.configured')->sole()->toArray(), JSON_THROW_ON_ERROR))
        ->not->toContain('1234');
});

test('a replacement PIN becomes authoritative without creating another global row', function () {
    $this->actingAs($this->superAdmin)->put(route('workspaces.void-orders.pin.update'), [
        'pin' => '1234',
        'pin_confirmation' => '1234',
    ])->assertRedirect();

    $this->put(route('workspaces.void-orders.pin.update'), [
        'pin' => '5678',
        'pin_confirmation' => '5678',
    ])->assertRedirect();

    $setting = VoidAuthorizationSetting::query()->sole();
    expect(Hash::check('1234', $setting->pin_hash))->toBeFalse()
        ->and(Hash::check('5678', $setting->pin_hash))->toBeTrue()
        ->and(AuditLog::query()->where('action', 'void_pin.configured')->count())->toBe(2);
});

test('non super admins cannot configure the void PIN', function (string $role) {
    $user = User::factory()->create();
    $user->roles()->attach(Role::query()->where('name', $role)->sole());

    $this->actingAs($user)
        ->put(route('workspaces.void-orders.pin.update'), [
            'pin' => '1234',
            'pin_confirmation' => '1234',
        ])
        ->assertForbidden();

    $this->assertDatabaseCount('void_authorization_settings', 0);
})->with(['owner', 'cashier', 'cashier_kitchen', 'kitchen_staff']);

test('void PIN requires exactly four matching digits', function () {
    $this->actingAs($this->superAdmin)
        ->from(route('workspaces.void-orders'))
        ->put(route('workspaces.void-orders.pin.update'), [
            'pin' => '12a45',
            'pin_confirmation' => '9999',
        ])
        ->assertRedirect(route('workspaces.void-orders', absolute: false))
        ->assertSessionHasErrors(['pin']);

    $this->assertDatabaseCount('void_authorization_settings', 0);
});
