<?php

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->branch = Branch::factory()->create();
    $this->superAdmin = User::factory()->create();
    $this->superAdmin->roles()->attach(Role::query()->where('name', 'super_admin')->sole());
    $this->owner = User::factory()->create();
    $this->owner->roles()->attach(Role::query()->where('name', 'owner')->sole());
});

test('super admin can view immutable audit entries while owners are denied', function () {
    AuditLog::factory()->for($this->branch)->for($this->superAdmin)->create([
        'action' => 'order.voided',
        'metadata' => ['initiated_by_user_id' => 10, 'authorized_by_user_id' => 11],
    ]);

    $this->actingAs($this->owner)
        ->get(route('workspaces.audit-trail'))
        ->assertForbidden();

    $this->actingAs($this->superAdmin)
        ->get(route('workspaces.audit-trail'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('super-admin/audit-trail')
            ->has('logs.data', 1)
            ->where('logs.data.0.action', 'order.voided'));
});

test('owner is denied the protected void register', function () {
    $this->actingAs($this->owner)
        ->get(route('workspaces.void-orders'))
        ->assertForbidden();
});
