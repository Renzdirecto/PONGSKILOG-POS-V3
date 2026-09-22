<?php

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderVoid;
use App\Models\Payment;
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

test('void register provides a complete order breakdown without raw audit payloads', function () {
    $order = Order::factory()->for($this->branch)->create([
        'order_number' => '1001',
        'reference_number' => 'MAIN-092326-0001',
        'customer_label' => 'Walk-in',
        'commercial_status' => 'voided',
        'payment_status' => 'paid',
        'subtotal' => '140.00',
        'total' => '140.00',
        'committed_at' => now()->subMinutes(5),
        'voided_at' => now(),
    ]);
    OrderItem::factory()->for($order)->create([
        'product_name_snapshot' => 'Bulalo',
        'unit_price' => '140.00',
        'quantity' => 1,
        'line_total' => '140.00',
    ]);
    Payment::factory()->for($order)->create([
        'branch_id' => $this->branch->id,
        'created_by_user_id' => $this->owner->id,
        'amount' => '140.00',
        'paid_at' => now()->subMinutes(5),
    ]);
    OrderVoid::factory()->for($this->branch)->for($order)->create([
        'initiated_by_user_id' => $this->owner->id,
        'authorized_by_user_id' => $this->superAdmin->id,
        'reason_code' => 'wrong_item',
        'reason_label' => 'Wrong item',
        'authorization_method' => 'super_admin_pin',
    ]);
    AuditLog::factory()->for($this->branch)->for($this->owner)->create([
        'action' => 'order.voided',
        'auditable_type' => Order::class,
        'auditable_id' => $order->id,
        'before' => ['commercial_status' => 'active'],
        'after' => ['commercial_status' => 'voided'],
        'metadata' => ['secret' => 'not-for-the-page'],
    ]);

    $this->actingAs($this->superAdmin)
        ->get(route('workspaces.void-orders'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('voids.total', 1)
            ->where('voids.data.0.order.order_number', '1001')
            ->where('voids.data.0.order.items.0.display_name', 'Bulalo')
            ->where('voids.data.0.order.payment_groups.0.amount', '140.00')
            ->where('voids.data.0.initiated_by.id', $this->owner->id)
            ->where('voids.data.0.authorized_by.id', $this->superAdmin->id)
            ->has('voids.data.0.audit.id')
            ->missing('voids.data.0.audit.before')
            ->missing('voids.data.0.audit.after')
            ->missing('voids.data.0.audit.metadata'));
});
