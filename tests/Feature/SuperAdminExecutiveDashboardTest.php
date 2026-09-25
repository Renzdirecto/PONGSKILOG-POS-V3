<?php

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\BranchInventory;
use App\Models\BranchProduct;
use App\Models\Ingredient;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use App\Notifications\AdminAlert;
use App\Support\ActiveBranchContext;
use Carbon\CarbonImmutable;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\StoreCloseScenario;

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->travelTo(CarbonImmutable::parse('2026-09-23 09:00', 'Asia/Manila'));
    $this->admin = executiveUser('super_admin');
});

function executiveUser(string $role, bool $active = true): User
{
    $user = User::factory()->create(['is_active' => $active]);
    $user->roles()->attach(Role::query()->where('name', $role)->sole());

    return $user;
}

/** @param array<string, string> $query */
function executiveDashboard(User $user, ?Branch $branch = null, array $query = []): TestResponse
{
    return test()->actingAs($user)
        ->withSession($branch === null ? [] : [ActiveBranchContext::SESSION_KEY => $branch->id])
        ->get(route('workspaces.super-admin', $query));
}

test('the executive dashboard shows the same canonical figures as the owner dashboard', function (string $period) {
    $scenario = StoreCloseScenario::create();
    $scenario->payNow(2, 'cash');
    $scenario->payNow(1, 'cashless');
    $scenario->expense('50.00', 'cash');
    $owner = executiveUser('owner');

    $ownerProps = test()->actingAs($owner)->get(route('workspaces.owner', ['period' => $period]))->viewData('page')['props'];

    executiveDashboard($this->admin, null, ['period' => $period])
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('super-admin/dashboard')
            ->where('period', $period)
            ->where('analytics.kpis', $ownerProps['analytics']['kpis'])
            ->where('analytics.trend', $ownerProps['analytics']['trend'])
            ->where('analytics.collections', $ownerProps['analytics']['collections'])
            ->where('analytics.payment_mix', $ownerProps['analytics']['payment_mix'])
            ->where('analytics.kpis.sales.value', '300.00')
            ->where('report.summary.expenses.total', '50.00')
            ->where('report.summary.sessions.open', 1)
            ->where('report.latest_session.branch.code', $scenario->branch->code)
            ->has('analytics.products', 1)
            ->missing('analytics.filter_options')
            ->missing('analytics.cashiers'));
})->with(['today', 'last_7_days', 'last_30_days']);

test('only an active super admin opens the executive dashboard', function (string $role) {
    executiveDashboard(executiveUser($role))->assertForbidden();
})->with(['owner', 'cashier', 'kitchen_staff', 'cashier_kitchen']);

test('an inactive super admin and an unknown period are refused', function () {
    executiveDashboard(executiveUser('super_admin', active: false))->assertRedirect();
    executiveDashboard($this->admin, null, ['period' => 'forever'])->assertSessionHasErrors('period');
});

test('an empty business shows truthful zero figures and nothing to attend to except closed stores', function () {
    Branch::factory()->create(['code' => 'MAIN', 'name' => 'Main']);

    executiveDashboard($this->admin)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('analytics.kpis.sales.value', '0.00')
            ->where('analytics.kpis.transactions.value', 0)
            ->where('analytics.products', [])
            ->where('report.latest_session', null)
            ->where('stores.0.open', false)
            ->where('ingredients.total', 0)
            ->where('attention', fn ($items): bool => collect($items)->pluck('key')->all() === ['stores_closed']));
});

test('attention lists real stock-outs, low stock, empty ingredients and unread notifications', function () {
    $scenario = StoreCloseScenario::create();
    $scenario->branch->update(['code' => 'MAIN', 'name' => 'Main']);
    $out = Product::factory()->create(['name' => 'Cola']);
    BranchProduct::factory()->for($scenario->branch)->for($out)->create(['tracks_inventory' => true]);
    BranchInventory::factory()->for($scenario->branch)->for($out)->create(['on_hand' => 0]);
    $low = Product::factory()->create(['name' => 'Chips']);
    BranchProduct::factory()->for($scenario->branch)->for($low)->create(['tracks_inventory' => true, 'low_stock_threshold' => 5]);
    BranchInventory::factory()->for($scenario->branch)->for($low)->create(['on_hand' => 2]);
    $ingredient = Ingredient::factory()->create(['name' => 'Calamansi']);
    DB::table('branch_ingredient_stocks')->insert([
        'id' => (string) Str::uuid(), 'branch_id' => $scenario->branch->id, 'ingredient_id' => $ingredient->id,
        'on_hand' => '0.0000', 'version' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $this->admin->notify(new AdminAlert('stock', 'Cola is out of stock', 'MAIN has no Cola left.'));

    executiveDashboard($this->admin, $scenario->branch)
        ->assertInertia(fn (Assert $page) => $page
            ->where('stores.0.open', true)
            ->where('ingredients.total', 1)
            ->where('security.unread', 1)
            ->where('attention', fn ($items): bool => collect($items)->pluck('key')->all() === ['products_out', 'ingredients_out', 'products_low', 'notifications']
                && collect($items)->firstWhere('key', 'products_out')['tone'] === 'critical'
                && collect($items)->firstWhere('key', 'ingredients_out')['detail'] === 'Recipes that use them are unavailable (MAIN 1).'));
});

test('people and security summaries are counts and payload-free audit rows', function () {
    executiveUser('cashier');
    executiveUser('owner', active: false);
    AuditLog::query()->create([
        'user_id' => $this->admin->id, 'module' => 'staff', 'action' => 'staff.password_reset',
        'auditable_type' => User::class, 'auditable_id' => '1', 'before' => ['secret' => 'x'], 'after' => ['sessions_ended' => true],
    ]);

    executiveDashboard($this->admin)
        ->assertInertia(fn (Assert $page) => $page
            ->where('people.active', 2)
            ->where('people.inactive', 1)
            ->where('people.super_admins', 1)
            ->where('people.custom_roles', 0)
            ->where('security.audit.0.action', 'staff.password_reset')
            ->where('security.audit.0.actor', $this->admin->name)
            ->where('security.audit', fn ($rows): bool => ! str_contains(json_encode($rows), 'secret')
                && array_keys(collect($rows)->first()) === ['id', 'action', 'module', 'actor', 'branch', 'at']));
});

test('a partial reload computes only the requested executive props', function () {
    StoreCloseScenario::create()->payNow(1, 'cash');
    $version = test()->actingAs($this->admin)->get(route('workspaces.super-admin'))->viewData('page')['version'];

    DB::enableQueryLog();
    $response = test()->actingAs($this->admin)
        ->withHeaders([
            'X-Inertia' => 'true',
            'X-Inertia-Version' => (string) $version,
            'X-Inertia-Partial-Component' => 'super-admin/dashboard',
            'X-Inertia-Partial-Data' => 'security',
        ])
        ->get(route('workspaces.super-admin'))
        ->assertOk();
    $queries = collect(DB::getQueryLog())->pluck('query')->implode("\n");

    expect(array_keys($response->json('props')))->toContain('security')->not->toContain('analytics')
        ->and($queries)->not->toContain('order_items')
        ->and($queries)->not->toContain('branch_ingredient_stocks');
});

test('the dashboard query count does not grow with staff or audit volume', function () {
    $count = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        executiveDashboard($this->admin)->assertOk();

        return count(DB::getQueryLog());
    };
    StoreCloseScenario::create()->payNow(1, 'cash');
    $baseline = $count();

    foreach (range(1, 6) as $index) {
        executiveUser($index % 2 === 0 ? 'cashier' : 'kitchen_staff');
        AuditLog::query()->create(['user_id' => $this->admin->id, 'module' => 'staff', 'action' => 'staff.updated', 'auditable_type' => User::class, 'auditable_id' => (string) $index]);
    }

    expect($count())->toBeLessThanOrEqual($baseline);
});
