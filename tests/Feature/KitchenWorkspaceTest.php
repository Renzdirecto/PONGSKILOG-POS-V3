<?php

use App\Enums\KitchenStatus;
use App\Enums\ModifierSemanticRole;
use App\Models\Branch;
use App\Models\KitchenTicket;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemModifier;
use App\Models\Role;
use App\Models\StoreSession;
use App\Models\User;
use App\Support\ActiveBranchContext;
use App\Support\KitchenBoard;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->seed(RbacSeeder::class);
});

function kitchenUser(Branch $branch, string $role = 'kitchen_staff'): User
{
    $user = User::factory()->create();
    $user->roles()->attach(Role::query()->where('name', $role)->sole());
    $user->branches()->attach($branch, ['is_active' => true]);

    return $user;
}

function kitchenOrder(
    Branch $branch,
    StoreSession $session,
    KitchenStatus $status = KitchenStatus::Kitchen,
    string $number = '1043',
): Order {
    $order = Order::factory()->for($branch)->for($session)->create([
        'order_number' => $number,
        'customer_label' => 'Maria',
        'commercial_status' => 'active',
        'payment_status' => 'paid',
        'payment_term' => 'immediate',
        'kitchen_status' => $status,
        'committed_at' => now()->subMinutes(8),
        'completed_at' => $status === KitchenStatus::Done ? now() : null,
        'version' => 2,
    ]);
    KitchenTicket::factory()->for($branch)->for($order)->create(['status' => $status]);

    return $order;
}

test('authorized kitchen staff receive current-session operational snapshots without financial data', function () {
    $branch = Branch::factory()->create();
    $session = StoreSession::factory()->for($branch)->create();
    $order = kitchenOrder($branch, $session);
    $item = OrderItem::factory()->for($order)->create([
        'product_name_snapshot' => 'Tapsilog',
        'quantity' => 2,
        'notes' => 'Separate sauce',
    ]);
    OrderItemModifier::factory()->for($item)->create([
        'group_name_snapshot' => 'Size',
        'semantic_role_snapshot' => ModifierSemanticRole::Size->value,
        'option_name_snapshot' => 'Large',
    ]);
    OrderItemModifier::factory()->for($item)->create([
        'group_name_snapshot' => 'Extras',
        'semantic_role_snapshot' => null,
        'option_name_snapshot' => 'Egg',
    ]);
    OrderItemModifier::factory()->for($item)->create([
        'group_name_snapshot' => 'Instructions',
        'semantic_role_snapshot' => ModifierSemanticRole::Instruction->value,
        'option_name_snapshot' => 'No onions',
    ]);

    $response = $this->actingAs(kitchenUser($branch))->get(route('workspaces.kitchen'));

    $response->assertInertia(fn (Assert $page) => $page
        ->component('workspaces/kitchen')
        ->where('kitchenBoard.is_open', true)
        ->where('kitchenBoard.counts', [
            'all' => 1,
            'kitchen' => 1,
            'preparing' => 0,
            'ready' => 0,
            'done' => 0,
        ])
        ->where('kitchenBoard.tickets.0.number', '1043')
        ->where('kitchenBoard.tickets.0.items.0.display_name', 'Large Tapsilog')
        ->where('kitchenBoard.tickets.0.items.0.standard_modifiers', ['Extras: Egg'])
        ->where('kitchenBoard.tickets.0.items.0.instructions', ['No onions'])
        ->where('kitchenBoard.tickets.0.items.0.note', 'Separate sauce'));

    $ticket = $response->inertiaProps('kitchenBoard.tickets.0');
    expect($ticket)->not->toHaveKeys(['total', 'subtotal', 'payment_status', 'payment_term', 'payments']);
});

test('kitchen workspace shows a truthful closed state and excludes historical sessions', function () {
    $branch = Branch::factory()->create();
    $oldSession = StoreSession::factory()->closed()->for($branch)->create();
    kitchenOrder($branch, $oldSession, KitchenStatus::Done);

    $this->actingAs(kitchenUser($branch))->get(route('workspaces.kitchen'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('kitchenBoard.is_open', false)
            ->where('kitchenBoard.tickets', [])
            ->where('kitchenBoard.counts.all', 0));
});

test('kitchen workspace requires authentication permission and an assigned branch', function () {
    $branch = Branch::factory()->create();

    $this->get(route('workspaces.kitchen'))->assertRedirectToRoute('login');
    $this->actingAs(kitchenUser($branch, 'cashier'))->get(route('workspaces.kitchen'))->assertForbidden();

    $unassigned = kitchenUser($branch);
    $unassigned->branches()->detach($branch);
    $this->actingAs($unassigned)
        ->withSession([ActiveBranchContext::SESSION_KEY => $branch->id])
        ->get(route('workspaces.kitchen'))
        ->assertRedirectToRoute('workspace');
});

test('cashier ready projection includes authorized fulfillment detail only for the current session', function () {
    $branch = Branch::factory()->create();
    $session = StoreSession::factory()->for($branch)->create();
    $ready = kitchenOrder($branch, $session, KitchenStatus::Ready);
    OrderItem::factory()->for($ready)->create(['product_name_snapshot' => 'Bangsilog']);
    $oldSession = StoreSession::factory()->closed()->for($branch)->create();
    kitchenOrder($branch, $oldSession, KitchenStatus::Ready, '1044');

    $this->actingAs(kitchenUser($branch, 'cashier'))->get(route('workspaces.cashier'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('workspaces/show')
            ->has('readyOrders', 1)
            ->where('readyOrders.0.id', $ready->id)
            ->where('readyOrders.0.status', 'ready')
            ->where('readyOrders.0.items.0.display_name', 'Bangsilog'));
});

test('kitchen board query count stays bounded as ticket volume grows', function () {
    $branch = Branch::factory()->create();
    $session = StoreSession::factory()->for($branch)->create();

    foreach (range(1, 20) as $sequence) {
        $order = kitchenOrder($branch, $session, KitchenStatus::Kitchen, (string) (1100 + $sequence));
        $item = OrderItem::factory()->for($order)->create();
        OrderItemModifier::factory()->for($item)->create();
    }

    DB::flushQueryLog();
    DB::enableQueryLog();

    $board = app(KitchenBoard::class)->kitchen($branch);
    $queryCount = count(DB::getQueryLog());

    DB::disableQueryLog();

    expect($board['tickets'])->toHaveCount(20)
        ->and($queryCount)->toBeLessThanOrEqual(10);
});
