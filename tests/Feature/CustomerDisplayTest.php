<?php

use App\Enums\KitchenStatus;
use App\Models\Branch;
use App\Models\KitchenTicket;
use App\Models\Order;
use App\Models\Role;
use App\Models\StoreSession;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->seed(RbacSeeder::class);
});

function displayUser(Branch $branch, string $role = 'kitchen_staff'): User
{
    $user = User::factory()->create();
    $user->roles()->attach(Role::query()->where('name', $role)->sole());
    $user->branches()->attach($branch, ['is_active' => true]);

    return $user;
}

function displayOrder(Branch $branch, StoreSession $session, KitchenStatus $status, string $number): Order
{
    $order = Order::factory()->for($branch)->for($session)->create([
        'order_number' => $number,
        'customer_label' => 'Private Customer',
        'commercial_status' => 'active',
        'payment_status' => 'paid',
        'payment_term' => 'immediate',
        'kitchen_status' => $status,
        'committed_at' => now(),
        'completed_at' => $status === KitchenStatus::Done ? now() : null,
    ]);
    KitchenTicket::factory()->for($branch)->for($order)->create(['status' => $status]);

    return $order;
}

test('customer display exposes order numbers only and maps kitchen into preparing', function () {
    $branch = Branch::factory()->create(['name' => 'Main Branch']);
    $session = StoreSession::factory()->for($branch)->create();
    displayOrder($branch, $session, KitchenStatus::Kitchen, '1043');
    $ready = displayOrder($branch, $session, KitchenStatus::Ready, '1044');
    $done = displayOrder($branch, $session, KitchenStatus::Done, '1045');

    $response = $this->actingAs(displayUser($branch))->get(route('workspaces.customer-display'));

    $response->assertInertia(fn (Assert $page) => $page
        ->component('workspaces/customer-display')
        ->where('branchName', 'Main Branch')
        ->where('display', [
            'is_open' => true,
            'preparing' => ['#1043'],
            'ready' => ['#1044'],
        ])
        ->missing('auth')
        ->missing('branchContext')
        ->missing('storeContext'));

    $props = $response->inertiaProps();
    expect(json_encode($props))
        ->not->toContain('Private Customer')
        ->not->toContain($ready->id)
        ->not->toContain($done->id)
        ->not->toContain('payment_status')
        ->not->toContain('items');
});

test('customer display shows closed and requires its permission', function () {
    $branch = Branch::factory()->create();

    $this->actingAs(displayUser($branch))->get(route('workspaces.customer-display'))
        ->assertInertia(fn (Assert $page) => $page->where('display', [
            'is_open' => false,
            'preparing' => [],
            'ready' => [],
        ]));

    $this->actingAs(displayUser($branch, 'cashier'))
        ->get(route('workspaces.customer-display'))
        ->assertForbidden();
});

test('customer display private channel requires launch permission and branch access', function () {
    $branch = Branch::factory()->create();
    $authorized = displayUser($branch);
    $cashier = displayUser($branch, 'cashier');
    config(['broadcasting.default' => 'pusher', 'broadcasting.connections.pusher' => [
        'driver' => 'pusher',
        'key' => 'test-key',
        'secret' => 'test-secret',
        'app_id' => 'test-app',
        'options' => ['cluster' => 'ap1'],
    ]]);
    (static function (): void {
        require base_path('routes/channels.php');
    })();

    $channel = 'private-branch.'.$branch->id.'.customer-display';
    $this->actingAs($authorized)->postJson('/broadcasting/auth', [
        'socket_id' => '123.456',
        'channel_name' => $channel,
    ])->assertOk();
    $this->actingAs($cashier)->postJson('/broadcasting/auth', [
        'socket_id' => '123.456',
        'channel_name' => $channel,
    ])->assertForbidden();
    $this->actingAs($authorized)->postJson('/broadcasting/auth', [
        'socket_id' => '123.456',
        'channel_name' => 'private-branch.'.Branch::factory()->create()->id.'.customer-display',
    ])->assertForbidden();
});
