<?php

use App\Enums\KitchenStatus;
use App\Models\Branch;
use App\Models\BranchInventory;
use App\Models\BranchProduct;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use App\Support\ActiveBranchContext;
use Carbon\CarbonImmutable;
use Database\Seeders\RbacSeeder;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\StoreCloseScenario;

beforeEach(function () {
    $this->seed(RbacSeeder::class);
});

function dashboardAt(string $manila): void
{
    test()->travelTo(CarbonImmutable::parse($manila, 'Asia/Manila'));
}

function dashboardOwner(string $role = 'owner'): User
{
    $user = User::factory()->create();
    $user->roles()->attach(Role::query()->where('name', $role)->sole());

    return $user;
}

function dashboardScenario(string $openedAtManila, string $code = 'MAIN'): StoreCloseScenario
{
    dashboardAt($openedAtManila);
    $scenario = StoreCloseScenario::create();
    $scenario->branch->update(['code' => $code, 'name' => "{$code} Branch"]);

    return $scenario;
}

/** @param array<string, string> $query */
function ownerDashboard(?Branch $branch, array $query = [], ?User $user = null): TestResponse
{
    return test()->actingAs($user ?? dashboardOwner())
        ->withSession($branch === null ? [] : [ActiveBranchContext::SESSION_KEY => $branch->id])
        ->get(route('workspaces.owner', $query));
}

function trackedProduct(Branch $branch, string $name, int $onHand, ?int $threshold): Product
{
    $product = Product::factory()->create(['name' => $name]);
    BranchProduct::factory()->for($branch)->for($product)->create(['tracks_inventory' => true, 'low_stock_threshold' => $threshold]);
    BranchInventory::factory()->for($branch)->for($product)->create(['on_hand' => $onHand]);

    return $product;
}

test('owner and super admin open the real business dashboard for today', function (string $role) {
    $scenario = dashboardScenario('2026-09-23 09:00');
    $scenario->payNow(2, 'cash');

    ownerDashboard($scenario->branch, [], dashboardOwner($role))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('workspaces/owner-dashboard')
            ->where('period', 'today')
            ->where('analytics.kpis.sales.value', '200.00')
            ->where('analytics.comparison.description', 'yesterday')
            ->where('report.scope.code', 'MAIN')
            ->where('report.sessions.0.branch.code', 'MAIN'));
})->with(['owner', 'super_admin']);

test('operational staff cannot open the business dashboard', function (string $role) {
    $scenario = dashboardScenario('2026-09-23 09:00');

    $this->actingAs($scenario->user($role))->get(route('workspaces.owner'))->assertForbidden();
})->with(['cashier', 'kitchen_staff', 'cashier_kitchen']);

test('the reporting period tabs choose today seven or thirty days', function (string $period, int $buckets, string $comparison) {
    $scenario = dashboardScenario('2026-09-23 09:00');
    $scenario->payNow(1, 'cash');

    ownerDashboard($scenario->branch, ['period' => $period])
        ->assertInertia(fn (Assert $page) => $page
            ->where('period', $period)
            ->where('analytics.trend.granularity', 'day')
            ->has('analytics.trend.buckets', $buckets)
            ->where('analytics.comparison.description', $comparison)
            ->where('analytics.kpis.sales.value', '100.00'));
})->with([
    'seven days' => ['last_7_days', 7, 'previous 7 days'],
    'thirty days' => ['last_30_days', 30, 'previous 30 days'],
]);

test('an unsupported dashboard period is rejected', function () {
    $scenario = dashboardScenario('2026-09-23 09:00');

    ownerDashboard($scenario->branch, ['period' => 'last_12_months'])->assertSessionHasErrors(['period']);
});

test('a branch with no history shows truthful empty figures', function () {
    $branch = Branch::factory()->create(['code' => 'NEW']);
    dashboardAt('2026-09-23 09:00');

    ownerDashboard($branch)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('analytics.kpis.sales.value', '0.00')
            ->where('analytics.kpis.transactions.value', 0)
            ->where('analytics.kpis.average_order.value', null)
            ->where('analytics.kpis.cashless_share.value', null)
            ->where('analytics.collections.cash_share', null)
            ->where('analytics.peak_hour', null)
            ->where('analytics.trend.buckets', fn ($buckets) => collect($buckets)->every(fn (array $bucket) => $bucket['sales'] === '0.00'))
            ->where('kitchen.open_sessions', 0)
            ->where('kitchen.oldest', null)
            ->where('recentTransactions', [])
            ->where('report.sessions', []));
});

test('inventory attention lists low and out of stock products for a selected branch', function () {
    $scenario = dashboardScenario('2026-09-23 09:00');
    trackedProduct($scenario->branch, 'Bangsilog', 0, 5);
    trackedProduct($scenario->branch, 'Hotsilog', 3, 5);
    trackedProduct($scenario->branch, 'Plenty', 80, 5);

    ownerDashboard($scenario->branch)
        ->assertInertia(fn (Assert $page) => $page
            ->where('inventory.mode', 'branch')
            ->where('inventory.out_of_stock', 1)
            ->where('inventory.low_stock', 1)
            ->where('inventory.items', fn ($items) => collect($items)->map(fn (array $item) => [$item['name'], $item['status'], $item['on_hand']])->all() === [
                ['Bangsilog', 'out_of_stock', 0],
                ['Hotsilog', 'low_stock', 3],
            ]));
});

test('all branches inventory attention counts each branch and never sums stock quantities', function () {
    $alpha = dashboardScenario('2026-09-23 09:00', 'ALPHA');
    $bravo = dashboardScenario('2026-09-23 09:00', 'BRAVO');
    trackedProduct($alpha->branch, 'Bangsilog', 0, 5);
    trackedProduct($bravo->branch, 'Hotsilog', 2, 5);
    trackedProduct($bravo->branch, 'Tocilog', 1, 5);

    ownerDashboard(null)
        ->assertInertia(fn (Assert $page) => $page
            ->where('inventory.mode', 'branches')
            ->missing('inventory.items')
            ->where('inventory.branches', fn ($rows) => collect($rows)->map(fn (array $row) => [$row['branch']['code'], $row['out_of_stock'], $row['low_stock'], array_key_exists('on_hand', $row)])->all() === [
                ['ALPHA', 1, 0, false],
                ['BRAVO', 0, 2, false],
            ]));
});

test('the kitchen snapshot counts active tickets of open sessions and times the oldest waiting ticket', function () {
    $scenario = dashboardScenario('2026-09-23 09:00');
    dashboardAt('2026-09-23 10:00');
    $scenario->payNow(1, 'cash');
    dashboardAt('2026-09-23 10:05');
    $scenario->kitchenStatus($scenario->payNow(1, 'cash'), KitchenStatus::Preparing);
    dashboardAt('2026-09-23 10:07');
    $ready = $scenario->payNow(1, 'cash');
    $scenario->kitchenStatus($ready, KitchenStatus::Preparing);
    $scenario->kitchenStatus($ready, KitchenStatus::Ready);
    $scenario->done($scenario->payNow(1, 'cash'));
    $foreign = dashboardScenario('2026-09-23 09:00', 'OTHER');
    $foreign->payNow(1, 'cash');
    dashboardAt('2026-09-23 10:20');

    ownerDashboard($scenario->branch)
        ->assertInertia(fn (Assert $page) => $page
            ->where('kitchen.open_sessions', 1)
            ->where('kitchen.kitchen', 1)
            ->where('kitchen.preparing', 1)
            ->where('kitchen.ready', 1)
            ->where('kitchen.oldest.waiting_seconds', 1200)
            ->where('kitchen.oldest.status', 'kitchen')
            ->missing('kitchen.oldest.total'));
});

test('recent transactions are the latest committed non voided orders of the scope with branch identity', function () {
    $alpha = dashboardScenario('2026-09-23 09:00', 'ALPHA');
    $bravo = dashboardScenario('2026-09-23 09:00', 'BRAVO');
    foreach (range(1, 4) as $minute) {
        dashboardAt("2026-09-23 10:0{$minute}");
        $alpha->payNow(1, 'cash');
    }
    dashboardAt('2026-09-23 10:06');
    $alpha->void($alpha->payNow(2, 'cash'));
    dashboardAt('2026-09-23 10:08');
    $latest = $bravo->payNow(3, 'split', '100.00');
    dashboardAt('2026-09-23 10:09');
    $alpha->payNow(1, 'cashless');

    $all = ownerDashboard(null)->inertiaProps('recentTransactions');
    $scoped = ownerDashboard($bravo->branch)->inertiaProps('recentTransactions');

    expect($all)->toHaveCount(5)
        ->and(collect($all)->pluck('branch.code')->all())->toBe(['ALPHA', 'BRAVO', 'ALPHA', 'ALPHA', 'ALPHA'])
        ->and($all[1])->toMatchArray(['id' => $latest->id, 'payment_method' => 'split', 'total' => '300.00'])
        ->and(collect($all)->pluck('total')->all())->not->toContain('200.00')
        ->and($scoped)->toHaveCount(1)
        ->and($scoped[0]['id'])->toBe($latest->id);
});

test('the owner navigation reaches every owner destination and no super admin control surface', function () {
    $scenario = dashboardScenario('2026-09-23 09:00');
    $owner = dashboardOwner();

    foreach (['workspaces.owner', 'workspaces.transactions', 'workspaces.reports', 'products.index', 'inventory.index', 'staff.index', 'branches.index'] as $route) {
        $this->actingAs($owner)->withSession([ActiveBranchContext::SESSION_KEY => $scenario->branch->id])->get(route($route))->assertOk();
    }
    foreach (['workspaces.super-admin', 'workspaces.audit-trail', 'workspaces.void-orders', 'super-admin.staff.index', 'super-admin.access-control'] as $route) {
        $this->actingAs($owner)->get(route($route))->assertForbidden();
    }
});
