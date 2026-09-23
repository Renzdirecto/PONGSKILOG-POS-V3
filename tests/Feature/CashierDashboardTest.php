<?php

use App\Models\Branch;
use App\Models\BranchInventory;
use App\Models\BranchProduct;
use App\Models\KitchenTicket;
use App\Models\Order;
use App\Models\OrderAdjustment;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Role;
use App\Models\StoreSession;
use App\Models\StoreSessionExpense;
use App\Models\User;
use App\Support\ActiveBranchContext;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->seed(RbacSeeder::class);
});

function dashboardStaff(Branch $branch, string $roleName = 'cashier'): User
{
    $user = User::factory()->create(['name' => 'Jamie Cruz']);
    $user->roles()->attach(Role::query()->where('name', $roleName)->sole());
    $user->branches()->attach($branch, ['is_active' => true]);

    return $user;
}

/** @param array<string, mixed> $attributes */
function dashboardOrder(StoreSession $session, array $attributes = []): Order
{
    return Order::factory()->for($session->branch)->create([
        'store_session_id' => $session->id,
        'commercial_status' => 'active',
        'payment_status' => 'paid',
        'payment_term' => 'immediate',
        'kitchen_status' => 'done',
        'subtotal' => '100.00',
        'total' => '100.00',
        'committed_at' => now(),
        ...$attributes,
    ]);
}

function dashboardPayment(Order $order, string $method, string $amount, ?string $groupId = null): Payment
{
    $groupId ??= (string) Str::uuid();

    return Payment::factory()->for($order)->create([
        'branch_id' => $order->branch_id,
        'store_session_id' => $order->store_session_id,
        'method' => $method,
        'amount' => $amount,
        'amount_received' => $method === 'cash' ? '1000.00' : null,
        'change_amount' => $method === 'cash' ? (string) (1000 - (float) $amount) : null,
        'payment_group_id' => $groupId,
        'idempotency_key' => $groupId.':'.$method,
    ]);
}

function dashboardLowStockProduct(Branch $branch, int $onHand, ?int $threshold = 5): Product
{
    $product = Product::factory()->create();
    BranchProduct::factory()->for($branch)->for($product)->create(['tracks_inventory' => true, 'low_stock_threshold' => $threshold]);
    BranchInventory::factory()->for($branch)->for($product)->create(['on_hand' => $onHand]);

    return $product;
}

test('assigned cashier roles can open the dashboard', function (string $roleName) {
    $branch = Branch::factory()->create();
    $user = dashboardStaff($branch, $roleName);

    $response = $this->actingAs($user)->get(route('workspaces.cashier-dashboard'));

    $response->assertInertia(fn (Assert $page) => $page
        ->component('workspaces/cashier-dashboard')
        ->where('branchContext.current.id', $branch->id));
})->with(['cashier', 'cashier_kitchen']);

test('dashboard requires authentication', function () {
    $this->get(route('workspaces.cashier-dashboard'))->assertRedirectToRoute('login');
});

test('dashboard rejects inactive staff', function () {
    $user = dashboardStaff(Branch::factory()->create());
    $user->forceFill(['is_active' => false])->save();

    $this->actingAs($user)->get(route('workspaces.cashier-dashboard'))->assertRedirectToRoute('login');

    $this->assertGuest();
});

test('dashboard is forbidden to roles outside the cashier workspace', function (string $roleName) {
    $branch = Branch::factory()->create();
    $user = dashboardStaff($branch, $roleName);

    $response = $this->actingAs($user)
        ->withSession([ActiveBranchContext::SESSION_KEY => $branch->id])
        ->get(route('workspaces.cashier-dashboard'));

    $response->assertForbidden();
})->with(['owner', 'kitchen_staff', 'super_admin']);

test('dashboard does not open a branch the cashier is not assigned to', function () {
    $user = dashboardStaff(Branch::factory()->create());
    $user->branches()->attach(Branch::factory()->create(), ['is_active' => true]);
    $foreign = Branch::factory()->create();

    $response = $this->actingAs($user)
        ->withSession([ActiveBranchContext::SESSION_KEY => $foreign->id])
        ->get(route('workspaces.cashier-dashboard'));

    $response->assertRedirectToRoute('workspace');
});

test('closed store shows the closed state without session values', function () {
    $branch = Branch::factory()->create();
    $user = dashboardStaff($branch);
    StoreSession::factory()->for($branch)->create(['status' => 'closed', 'closed_at' => now()]);
    dashboardLowStockProduct($branch, 2);

    $response = $this->actingAs($user)->get(route('workspaces.cashier-dashboard'));

    $response->assertInertia(fn (Assert $page) => $page
        ->has('dashboard', fn (Assert $dashboard) => $dashboard
            ->where('store', ['is_open' => false, 'session' => null])
            ->where('summary', null)
            ->where('kitchen', null)
            ->where('payments', null)
            ->where('expenses', null)
            ->where('inventory', ['low_stock' => 1, 'out_of_stock' => 0])));
});

test('open store shows the current session identity', function () {
    $this->travelTo('2026-09-23 08:15:00');
    $branch = Branch::factory()->create();
    $user = dashboardStaff($branch);
    $session = StoreSession::factory()->for($branch)->create(['opened_by_user_id' => $user->id, 'opened_at' => now()]);

    $response = $this->actingAs($user)->get(route('workspaces.cashier-dashboard'));

    $response->assertInertia(fn (Assert $page) => $page
        ->where('dashboard.store', [
            'is_open' => true,
            'session' => ['id' => $session->id, 'opened_at' => '2026-09-23T08:15:00+00:00', 'opened_by' => 'Jamie Cruz'],
        ]));
});

test('dashboard exposes only the operational current session projection', function () {
    $branch = Branch::factory()->create();
    $user = dashboardStaff($branch);
    StoreSession::factory()->for($branch)->create();

    $response = $this->actingAs($user)->get(route('workspaces.cashier-dashboard', ['date' => 'month', 'from' => '2026-01-01']));

    $response->assertInertia(fn (Assert $page) => $page
        ->has('dashboard', fn (Assert $dashboard) => $dashboard
            ->has('store')
            ->has('summary', fn (Assert $summary) => $summary
                ->hasAll(['orders', 'sales', 'cash', 'cashless', 'corrections', 'split']))
            ->has('kitchen')
            ->has('payments')
            ->has('expenses')
            ->has('inventory'))
        ->missing('transactions')
        ->missing('reports'));
});

test('sales use current session payment rows without voided orders or split double counting', function () {
    $branch = Branch::factory()->create();
    $user = dashboardStaff($branch);
    $previous = StoreSession::factory()->for($branch)->create(['status' => 'closed', 'closed_at' => now()->subDay()]);
    $session = StoreSession::factory()->for($branch)->create();
    dashboardPayment(dashboardOrder($session), 'cash', '100.00');
    $split = dashboardOrder($session);
    $splitGroup = (string) Str::uuid();
    dashboardPayment($split, 'cash', '60.00', $splitGroup);
    dashboardPayment($split, 'cashless', '40.00', $splitGroup);
    $corrected = dashboardOrder($session, ['total' => '70.00', 'subtotal' => '70.00']);
    dashboardPayment($corrected, 'cash', '80.00');
    OrderAdjustment::factory()->for($corrected)->create(['branch_id' => $branch->id, 'store_session_id' => $session->id, 'amount' => '10.00']);
    dashboardPayment(dashboardOrder($session, ['commercial_status' => 'voided']), 'cash', '50.00');
    dashboardPayment(dashboardOrder($previous), 'cashless', '70.00');
    dashboardOrder($session, ['commercial_status' => 'draft', 'committed_at' => null]);

    $response = $this->actingAs($user)->get(route('workspaces.cashier-dashboard'));

    $response->assertInertia(fn (Assert $page) => $page
        ->where('dashboard.summary', [
            'orders' => 3,
            'sales' => '270.00',
            'cash' => '240.00',
            'cashless' => '40.00',
            'corrections' => '10.00',
            'split' => ['count' => 1, 'cash' => '60.00', 'cashless' => '40.00'],
        ]));
});

test('kitchen counts cover the current session and skip voided orders', function () {
    $branch = Branch::factory()->create();
    $user = dashboardStaff($branch);
    $previous = StoreSession::factory()->for($branch)->create(['status' => 'closed', 'closed_at' => now()->subDay()]);
    $session = StoreSession::factory()->for($branch)->create();
    foreach (['kitchen', 'preparing', 'preparing', 'ready', 'done'] as $status) {
        KitchenTicket::factory()->for(dashboardOrder($session, ['kitchen_status' => $status]))->create(['status' => $status]);
    }
    KitchenTicket::factory()->for(dashboardOrder($session, ['kitchen_status' => 'ready', 'commercial_status' => 'voided']))->create(['status' => 'ready']);
    KitchenTicket::factory()->for(dashboardOrder($previous, ['kitchen_status' => 'kitchen']))->create(['status' => 'kitchen']);

    $response = $this->actingAs($user)->get(route('workspaces.cashier-dashboard'));

    $response->assertInertia(fn (Assert $page) => $page
        ->where('dashboard.kitchen', ['kitchen' => 1, 'preparing' => 2, 'ready' => 1]));
});

test('pending payments report the exact outstanding balance of current session orders', function () {
    $branch = Branch::factory()->create();
    $user = dashboardStaff($branch);
    $session = StoreSession::factory()->for($branch)->create();
    dashboardOrder($session, ['payment_status' => 'unpaid', 'payment_term' => 'pay_later', 'total' => '150.25']);
    $partial = dashboardOrder($session, ['payment_status' => 'partial', 'payment_term' => 'pay_later', 'total' => '200.00']);
    dashboardPayment($partial, 'cashless', '50.00');
    dashboardPayment(dashboardOrder($session), 'cash', '100.00');
    dashboardOrder($session, ['payment_status' => 'unpaid', 'commercial_status' => 'voided', 'total' => '999.00']);

    $response = $this->actingAs($user)->get(route('workspaces.cashier-dashboard'));

    $response->assertInertia(fn (Assert $page) => $page
        ->where('dashboard.payments', ['pending' => 2, 'balance_due' => '300.25']));
});

test('expense total matches the current store session only', function () {
    $branch = Branch::factory()->create();
    $user = dashboardStaff($branch);
    $previous = StoreSession::factory()->for($branch)->create(['status' => 'closed', 'closed_at' => now()->subDay()]);
    $session = StoreSession::factory()->for($branch)->create();
    StoreSessionExpense::factory()->for($branch)->create(['store_session_id' => $session->id, 'amount' => '120.50', 'payment_source' => 'cash', 'created_by_user_id' => $user->id]);
    StoreSessionExpense::factory()->for($branch)->create(['store_session_id' => $session->id, 'amount' => '30.00', 'payment_source' => 'cashless', 'created_by_user_id' => $user->id]);
    StoreSessionExpense::factory()->for($branch)->create(['store_session_id' => $previous->id, 'amount' => '500.00', 'payment_source' => 'cash', 'created_by_user_id' => $user->id]);

    $response = $this->actingAs($user)->get(route('workspaces.cashier-dashboard'));

    $response->assertInertia(fn (Assert $page) => $page
        ->where('dashboard.expenses', ['count' => 2, 'total' => '150.50']));
});

test('dashboard values are isolated to the active branch', function () {
    $branch = Branch::factory()->create();
    $user = dashboardStaff($branch);
    StoreSession::factory()->for($branch)->create();
    $foreign = Branch::factory()->create();
    $foreignSession = StoreSession::factory()->for($foreign)->create();
    $foreignOrder = dashboardOrder($foreignSession, ['kitchen_status' => 'kitchen', 'payment_status' => 'partial', 'total' => '300.00']);
    dashboardPayment($foreignOrder, 'cash', '100.00');
    KitchenTicket::factory()->for($foreignOrder)->create(['status' => 'kitchen']);
    StoreSessionExpense::factory()->for($foreign)->create(['store_session_id' => $foreignSession->id, 'amount' => '75.00']);
    dashboardLowStockProduct($foreign, 1);
    $shared = dashboardLowStockProduct($branch, 20);
    BranchProduct::factory()->for($foreign)->for($shared)->create(['tracks_inventory' => true, 'low_stock_threshold' => 5]);
    BranchInventory::factory()->for($foreign)->for($shared)->create(['on_hand' => 0]);

    $response = $this->actingAs($user)->get(route('workspaces.cashier-dashboard'));

    $response->assertInertia(fn (Assert $page) => $page
        ->where('dashboard.summary.orders', 0)
        ->where('dashboard.summary.sales', '0.00')
        ->where('dashboard.kitchen', ['kitchen' => 0, 'preparing' => 0, 'ready' => 0])
        ->where('dashboard.payments', ['pending' => 0, 'balance_due' => '0.00'])
        ->where('dashboard.expenses', ['count' => 0, 'total' => '0.00'])
        ->where('dashboard.inventory', ['low_stock' => 0, 'out_of_stock' => 0]));
});

test('low stock counts tracked active products at or below the branch threshold', function () {
    $branch = Branch::factory()->create();
    $user = dashboardStaff($branch);
    dashboardLowStockProduct($branch, 5);
    dashboardLowStockProduct($branch, 1);
    dashboardLowStockProduct($branch, 6);
    dashboardLowStockProduct($branch, 1, null);
    dashboardLowStockProduct($branch, 0);
    dashboardLowStockProduct($branch, 2)->update(['is_active' => false]);

    $response = $this->actingAs($user)->get(route('workspaces.cashier-dashboard'));

    $response->assertInertia(fn (Assert $page) => $page
        ->where('dashboard.inventory', ['low_stock' => 2, 'out_of_stock' => 1]));
});

test('cashier login still lands on the pos workspace rather than the dashboard', function (string $roleName) {
    $branch = Branch::factory()->create();
    $user = dashboardStaff($branch, $roleName);

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect(route('workspace', absolute: false));

    $this->get(route('workspace'))->assertRedirectToRoute('workspaces.cashier');
})->with(['cashier', 'cashier_kitchen']);
