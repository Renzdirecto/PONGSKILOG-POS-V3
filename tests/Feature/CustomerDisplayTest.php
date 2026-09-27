<?php

use App\Enums\KitchenStatus;
use App\Models\Branch;
use App\Models\KitchenTicket;
use App\Models\Order;
use App\Models\Role;
use App\Models\StoreSession;
use App\Models\User;
use App\Support\KitchenBoard;
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

function displayOrder(Branch $branch, StoreSession $session, KitchenStatus $status, string $number, string $type = 'take_out'): Order
{
    $order = Order::factory()->for($branch)->for($session)->create([
        'order_number' => $number,
        'order_type' => $type,
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
            'preparing' => [['number' => '#1043', 'order_type' => 'take_out']],
            'ready' => [['number' => '#1044', 'order_type' => 'take_out']],
            'counts' => ['dine_in' => 0, 'take_out' => 1],
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

test('the Dine In / Take Out counts are the board\'s own queue, matching the one queue authority', function () {
    $branch = Branch::factory()->create();
    $session = StoreSession::factory()->for($branch)->create();
    $this->travel(-5)->minutes();
    displayOrder($branch, $session, KitchenStatus::Kitchen, '2001', 'dine_in');
    $this->travel(1)->minutes();
    displayOrder($branch, $session, KitchenStatus::Preparing, '2002', 'take_out');
    $this->travel(1)->minutes();
    displayOrder($branch, $session, KitchenStatus::Preparing, '2003', 'dine_in');
    displayOrder($branch, $session, KitchenStatus::Ready, '2004', 'dine_in');
    displayOrder($branch, $session, KitchenStatus::Done, '2005', 'take_out');
    $this->travelBack();
    $last = displayOrder($branch, $session, KitchenStatus::Kitchen, '2006', 'take_out');

    $board = app(KitchenBoard::class);
    $display = $board->customerDisplay($branch);
    $queue = $board->queue($last);

    expect($display['counts'])->toBe(['dine_in' => 2, 'take_out' => 2])
        ->and($display['preparing'])->toBe([
            ['number' => '#2001', 'order_type' => 'dine_in'],
            ['number' => '#2002', 'order_type' => 'take_out'],
            ['number' => '#2003', 'order_type' => 'dine_in'],
            ['number' => '#2006', 'order_type' => 'take_out'],
        ])
        ->and($display['ready'])->toBe([['number' => '#2004', 'order_type' => 'dine_in']])
        ->and(array_sum($display['counts']))->toBe($queue['total'])
        ->and(array_map(fn (array $row): string => '#'.$row['order_number'], $queue['rows']))
        ->toBe(array_column($display['preparing'], 'number'));
});

test('customer display shows closed and requires its permission', function () {
    $branch = Branch::factory()->create();

    $this->actingAs(displayUser($branch))->get(route('workspaces.customer-display'))
        ->assertInertia(fn (Assert $page) => $page->where('display', [
            'is_open' => false,
            'preparing' => [],
            'ready' => [],
            'counts' => ['dine_in' => 0, 'take_out' => 0],
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
