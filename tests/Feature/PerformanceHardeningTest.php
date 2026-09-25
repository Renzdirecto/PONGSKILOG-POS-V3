<?php

use App\Enums\PermissionOverrideEffect;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\BranchProduct;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use App\Models\UserPermissionOverride;
use App\Support\ActiveBranchContext;
use Carbon\CarbonImmutable;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\StoreCloseScenario;

/**
 * Phase 19 structural performance checks on a disposable database: realistic volume must not add queries per Order,
 * Branch, audit row or voided Order, partial reloads compute only the props they request, and totals stay exact.
 * No timing is asserted.
 */
beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->travelTo(CarbonImmutable::parse('2026-09-23 12:00', 'Asia/Manila'));
});

function performanceUser(string $role): User
{
    $user = User::factory()->create();
    $user->roles()->attach(Role::query()->where('name', $role)->sole());

    return $user;
}

/**
 * Bulk committed, paid, done Orders in the scenario's OPEN Store Session: one item and one exact cash payment each,
 * inserted directly so thousands of rows stay fast. Returns the added Net Sales in whole pesos.
 */
function bulkSales(StoreCloseScenario $scenario, int $orders, int $offset = 0): int
{
    $sales = 0;
    foreach (array_chunk(range($offset + 1, $offset + $orders), 250) as $chunk) {
        $orderRows = [];
        $itemRows = [];
        $paymentRows = [];
        foreach ($chunk as $number) {
            $id = (string) Str::uuid();
            $price = 50 + ($number % 7) * 10;
            $sales += $price;
            $at = now()->subSeconds($number % 3000);
            $orderRows[] = [
                'id' => $id, 'branch_id' => $scenario->branch->id, 'store_session_id' => $scenario->session->id,
                'order_number' => 'BULK-'.$number, 'source' => 'pos', 'order_type' => 'take_out', 'customer_label' => 'Bulk',
                'commercial_status' => 'completed', 'payment_status' => 'paid', 'payment_term' => 'immediate', 'kitchen_status' => 'done',
                'subtotal' => $price.'.00', 'total' => $price.'.00', 'created_by_user_id' => $scenario->cashier->id,
                'committed_at' => $at, 'completed_at' => $at, 'version' => 1, 'created_at' => $at, 'updated_at' => $at,
            ];
            $itemRows[] = [
                'id' => (string) Str::uuid(), 'order_id' => $id, 'product_id' => $scenario->product->id, 'product_name_snapshot' => 'Tapsilog',
                'unit_price' => $price.'.00', 'quantity' => 1, 'line_total' => $price.'.00', 'created_at' => $at, 'updated_at' => $at,
            ];
            $paymentRows[] = [
                'id' => (string) Str::uuid(), 'branch_id' => $scenario->branch->id, 'store_session_id' => $scenario->session->id, 'order_id' => $id,
                'method' => 'cash', 'amount' => $price.'.00', 'amount_received' => $price.'.00', 'change_amount' => '0.00',
                'created_by_user_id' => $scenario->cashier->id, 'idempotency_key' => $id.':cash', 'payment_context' => 'initial',
                'paid_at' => $at, 'created_at' => $at, 'updated_at' => $at,
            ];
        }
        DB::table('orders')->insert($orderRows);
        DB::table('order_items')->insert($itemRows);
        DB::table('payments')->insert($paymentRows);
    }

    return $sales;
}

/** @return array{0: int, 1: TestResponse} queries run by one request, and its response */
function countedRequest(Closure $request): array
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    $response = $request();
    $count = count(DB::getQueryLog());
    DB::disableQueryLog();

    return [$count, $response];
}

function inertiaVersion(User $user, string $url, ?Branch $branch = null): string
{
    return (string) test()->flushHeaders()->actingAs($user)
        ->withSession($branch === null ? [] : [ActiveBranchContext::SESSION_KEY => $branch->id])
        ->get($url)->viewData('page')['version'];
}

/** @return array{0: list<string>, 1: TestResponse} */
function partialReload(User $user, string $url, string $component, string $props, ?Branch $branch = null): array
{
    $version = inertiaVersion($user, $url, $branch);
    DB::flushQueryLog();
    DB::enableQueryLog();
    $response = test()->actingAs($user)
        ->withSession($branch === null ? [] : [ActiveBranchContext::SESSION_KEY => $branch->id])
        ->withHeaders([
            'X-Inertia' => 'true',
            'X-Inertia-Version' => $version,
            'X-Inertia-Partial-Component' => $component,
            'X-Inertia-Partial-Data' => $props,
        ])
        ->get($url);
    $queries = collect(DB::getQueryLog())->pluck('query')->all();
    DB::disableQueryLog();
    test()->flushHeaders();

    return [$queries, $response];
}

test('reports and dashboards keep a bounded query count and exact totals as orders and branches grow', function () {
    $owner = performanceUser('owner');
    $superAdmin = performanceUser('super_admin');
    $main = StoreCloseScenario::create();
    $qave = StoreCloseScenario::create();
    $expected = bulkSales($main, 20) + bulkSales($qave, 20);
    $pages = [
        'reports' => fn () => test()->actingAs($owner)->get(route('workspaces.reports', ['date' => 'custom', 'from' => '2026-09-23', 'to' => '2026-09-23'])),
        'owner dashboard' => fn () => test()->actingAs($owner)->get(route('workspaces.owner')),
        'executive dashboard' => fn () => test()->actingAs($superAdmin)->get(route('workspaces.super-admin')),
        'all branches transactions' => fn () => test()->actingAs($owner)->get(route('workspaces.transactions')),
    ];
    $baseline = collect($pages)->map(fn (Closure $page): int => countedRequest($page)[0]);

    /** Thousands of Orders, items and payments, and a third Branch with its own sales. */
    $expected += bulkSales($main, 2500, 20) + bulkSales($qave, 1500, 20);
    $third = StoreCloseScenario::create();
    $expected += bulkSales($third, 300);

    foreach ($pages as $name => $page) {
        [$count, $response] = countedRequest($page);
        $response->assertOk();
        expect($count)->toBe($baseline[$name], "{$name} must not add queries per Order or Branch");
    }
    $sales = number_format($expected, 2, '.', '');
    [, $reports] = countedRequest($pages['reports']);
    [, $transactions] = countedRequest($pages['all branches transactions']);
    $newest = DB::table('orders')->whereNotNull('committed_at')->orderByDesc('committed_at')->orderByDesc('id')->limit(10)->pluck('id')->all();

    expect($reports->inertiaProps('analytics.kpis.sales.value'))->toBe($sales)
        ->and($reports->inertiaProps('report.summary.net_sales'))->toBe($sales)
        ->and($reports->inertiaProps('analytics.kpis.transactions.value'))->toBe(4340)
        /** Transactions stay paginated newest-first across every Branch. */
        ->and(collect($transactions->inertiaProps('transactions.data'))->pluck('id')->all())->toBe($newest)
        ->and($transactions->inertiaProps('history_total'))->toBe(4340);
});

test('the owner dashboard computes only the props a partial reload requests', function () {
    $owner = performanceUser('owner');
    $scenario = StoreCloseScenario::create();
    bulkSales($scenario, 30);

    [$periodQueries, $period] = partialReload($owner, route('workspaces.owner'), 'workspaces/owner-dashboard', 'period,analytics,report');
    [$liveQueries, $live] = partialReload($owner, route('workspaces.owner'), 'workspaces/owner-dashboard', 'kitchen');

    expect(array_keys($period->json('props')))->toContain('analytics')->not->toContain('kitchen')->not->toContain('recentTransactions')
        ->and(implode("\n", $periodQueries))->not->toContain('kitchen_tickets')
        ->and(array_keys($live->json('props')))->toContain('kitchen')->not->toContain('analytics')
        ->and(implode("\n", $liveQueries))->not->toContain('order_items')
        ->and($period->json('props.analytics.kpis.sales.value'))->toBe(test()->actingAs($owner)->get(route('workspaces.owner'))->inertiaProps('analytics.kpis.sales.value'));
});

test('a branch reports account reloading dashboard props with a forged branch stays on its own branch', function () {
    $main = StoreCloseScenario::create();
    $qave = StoreCloseScenario::create();
    bulkSales($main, 3);
    bulkSales($qave, 5);
    $juan = $main->user('cashier');
    UserPermissionOverride::query()->create([
        'user_id' => $juan->id,
        'permission_id' => Permission::query()->where('name', 'reports.view')->value('id'),
        'effect' => PermissionOverrideEffect::Allow,
    ]);

    /**
     * A forged QAVE selection is cleared and, on the same request, the account's only active assigned Branch (MAIN) is
     * selected instead: never QAVE, never All Branches.
     */
    [, $forged] = partialReload($juan, route('workspaces.owner'), 'workspaces/owner-dashboard', 'analytics,report', $qave->branch);
    $forged->assertOk()->assertSessionHas(ActiveBranchContext::SESSION_KEY, $main->branch->id);

    expect($forged->json('props.report.scope.code'))->toBe($main->branch->code)
        ->and($forged->json('props.analytics.kpis.transactions.value'))->toBe(3)
        ->and($forged->json('props.analytics.branches'))->toBeNull()
        ->and($forged->getContent())->not->toContain($qave->branch->code)
        ->and($forged->getContent())->not->toContain((string) $qave->branch->id);

    [, $response] = partialReload($juan, route('workspaces.owner'), 'workspaces/owner-dashboard', 'analytics,report,recentTransactions', $main->branch);
    $response->assertOk();

    expect($response->json('props.report.scope.code'))->toBe($main->branch->code)
        ->and($response->json('props.analytics.kpis.transactions.value'))->toBe(3)
        ->and($response->json('props.analytics.branches'))->toBeNull();

    /** A deactivated account is signed out on its next reload. */
    $juan->forceFill(['is_active' => false])->save();
    test()->actingAs($juan)->get(route('workspaces.owner'))->assertRedirect(route('login'));
});

test('the audit trail pages newest first with every filter and a bounded query count', function () {
    $superAdmin = performanceUser('super_admin');
    $main = Branch::factory()->create(['code' => 'MAIN']);
    $qave = Branch::factory()->create(['code' => 'QAVE']);
    $insert = function (int $count, int $offset = 0) use ($superAdmin, $main, $qave): void {
        foreach (array_chunk(range($offset, $offset + $count - 1), 250) as $chunk) {
            DB::table('audit_logs')->insert(array_map(fn (int $n): array => [
                'id' => (string) Str::uuid(),
                'branch_id' => $n % 2 === 0 ? $main->id : $qave->id,
                'user_id' => $n % 3 === 0 ? $superAdmin->id : null,
                'module' => $n % 5 === 0 ? 'products' : 'transactions',
                'action' => $n % 5 === 0 ? 'branch_products.copied' : 'order.paid',
                'auditable_type' => Branch::class,
                'auditable_id' => (string) $n,
                'before' => null, 'after' => '{}', 'metadata' => '{}',
                /** Several entries share one timestamp, so the id breaks the tie. */
                'created_at' => now()->subMinutes(intdiv($n, 4)),
            ], $chunk));
        }
    };
    $insert(70);
    $page = fn (array $query = []) => test()->actingAs($superAdmin)->get(route('workspaces.audit-trail', $query));
    [$baseline] = countedRequest(fn () => $page());
    $insert(930, 70);
    [$count, $first] = countedRequest(fn () => $page());

    $expected = AuditLog::query()->orderByDesc('created_at')->orderByDesc('id')->pluck('id')->all();
    expect($count)->toBe($baseline)
        ->and($first->inertiaProps('logs.total'))->toBe(1000)
        ->and(collect($first->inertiaProps('logs.data'))->pluck('id')->all())->toBe(array_slice($expected, 0, 30))
        ->and(collect($page(['page' => 2])->inertiaProps('logs.data'))->pluck('id')->all())->toBe(array_slice($expected, 30, 30));

    $page(['branch_id' => $qave->id, 'module' => 'products', 'action' => 'branch_products.copied', 'user_id' => $superAdmin->id])
        ->assertInertia(fn (Assert $response) => $response
            ->where('logs.total', AuditLog::query()->where('branch_id', $qave->id)->where('module', 'products')->where('user_id', $superAdmin->id)->count())
            ->where('logs.data', fn ($rows): bool => collect($rows)->every(fn (array $row): bool => $row['branch']['code'] === 'QAVE' && $row['action'] === 'branch_products.copied')));
    $page(['date' => '2026-09-23'])->assertInertia(fn (Assert $response) => $response
        ->where('logs.data', fn ($rows): bool => collect($rows)->every(fn (array $row): bool => str_starts_with(CarbonImmutable::parse($row['created_at'])->setTimezone('Asia/Manila')->toDateString(), '2026-09-23'))));
    $page(['branch_id' => (string) Str::uuid()])->assertSessionHasErrors('branch_id');
    test()->actingAs(performanceUser('owner'))->get(route('workspaces.audit-trail'))->assertForbidden();

    /** A realtime refresh asks for the entries only: no filter option query runs. */
    [$queries, $reload] = partialReload($superAdmin, route('workspaces.audit-trail'), 'super-admin/audit-trail', 'logs');
    expect(array_keys($reload->json('props')))->toContain('logs')->not->toContain('users')->not->toContain('modules')
        ->and(implode("\n", $queries))->not->toContain('distinct');
});

test('void orders load every inventory restoration for the page without a query per voided order', function () {
    $superAdmin = performanceUser('super_admin');
    $scenario = StoreCloseScenario::create();
    $voidSome = function (int $count) use ($scenario): void {
        foreach (range(1, $count) as $quantity) {
            $scenario->void($scenario->payNow($quantity, 'cash'));
        }
    };
    $voidSome(2);
    [$baseline] = countedRequest(fn () => test()->actingAs($superAdmin)->get(route('workspaces.void-orders')));
    [$reloadBaseline] = partialReload($superAdmin, route('workspaces.void-orders'), 'super-admin/void-orders', 'voids,pinStatus');
    $voidSome(8);
    [$count, $response] = countedRequest(fn () => test()->actingAs($superAdmin)->get(route('workspaces.void-orders')));
    /** The realtime refresh asks for the register only: flat as voids grow, and no Branch / user option list query. */
    [$reloadQueries, $reload] = partialReload($superAdmin, route('workspaces.void-orders'), 'super-admin/void-orders', 'voids,pinStatus');

    expect($count)->toBe($baseline)
        ->and(collect($response->inertiaProps('voids.data'))->map(fn (array $void): int => $void['order']['inventory_restorations'][0]['quantity_restored'])->sort()->values()->all())
        ->toBe([1, 1, 2, 2, 3, 4, 5, 6, 7, 8])
        ->and(count($reloadQueries))->toBe(count($reloadBaseline))
        ->and(array_keys($reload->json('props')))->toContain('voids')->not->toContain('branches')->not->toContain('users')
        ->and($reload->json('props.voids.total'))->toBe(10)
        ->and(collect($reloadQueries)->contains(fn (string $query): bool => str_contains($query, 'order by "name" asc')))->toBeFalse();
});

test('shared props run no queries for json endpoints and a selected branch loads only its own product rows', function () {
    $owner = performanceUser('owner');
    $superAdmin = performanceUser('super_admin');
    [$count] = countedRequest(fn () => test()->actingAs($superAdmin)->getJson(route('super-admin.notifications.unread-count'))->assertOk());
    $queries = collect(DB::getQueryLog());

    $product = Product::factory()->create();
    $branches = Branch::factory()->count(5)->create();
    foreach ($branches as $branch) {
        BranchProduct::factory()->for($branch)->for($product)->create();
    }
    DB::flushQueryLog();
    DB::enableQueryLog();
    $page = test()->actingAs($owner)->withSession([ActiveBranchContext::SESSION_KEY => $branches[2]->id])->get(route('products.index'))->assertOk();
    $eagerLoad = collect(DB::getQueryLog())->first(fn (array $query): bool => str_contains($query['query'], 'from "branch_products" where "branch_products"."product_id" in'));
    DB::disableQueryLog();

    expect($queries->pluck('query')->implode("\n"))->not->toContain('"roles"."label"')
        ->and($eagerLoad['query'])->toContain('"branch_id" = ?')
        ->and($eagerLoad['bindings'])->toContain($branches[2]->id)
        ->and($page->inertiaProps('products.data.0.branch_prices'))->toHaveCount(1);
});
