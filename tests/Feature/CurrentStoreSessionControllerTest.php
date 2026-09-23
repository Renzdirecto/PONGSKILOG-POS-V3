<?php

use App\Actions\StoreSessions\OpenStoreSession;
use App\Enums\StoreSessionStatus;
use App\Models\Branch;
use App\Models\BranchProduct;
use App\Models\Product;
use App\Models\Role;
use App\Models\StoreSession;
use App\Models\StoreSessionExpense;
use App\Models\User;
use App\Support\ActiveBranchContext;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->seed(RbacSeeder::class);
});

function currentStoreSessionUser(Branch $branch, string $roleName = 'cashier'): User
{
    $user = User::factory()->create();
    $user->roles()->attach(Role::query()->where('name', $roleName)->sole());
    $user->branches()->attach($branch, ['is_active' => true]);

    return $user;
}

test('authorized cashier retrieves only the current branch open session detail', function (string $roleName) {
    $branch = Branch::factory()->create(['code' => 'MAIN', 'name' => 'Main Branch']);
    $otherBranch = Branch::factory()->create();
    $cashier = currentStoreSessionUser($branch, $roleName);
    $openedBy = User::factory()->create(['name' => 'Cashier Tester']);
    $openedAt = Carbon::parse('2026-09-20 01:25:00', 'Asia/Manila')->utc();
    $session = StoreSession::factory()->for($branch)->for($openedBy, 'openedBy')->create([
        'opened_at' => $openedAt,
        'opening_cash_amount' => '5000.00',
        'opening_cashless_amount' => '2500.25',
    ]);
    StoreSession::factory()->for($otherBranch)->create([
        'opening_cash_amount' => '999999.99',
        'opening_cashless_amount' => '888888.88',
    ]);

    $this->actingAs($cashier)->getJson(route('store-sessions.current'))
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertExactJson([
            'id' => $session->id,
            'opened_at' => '2026-09-19T17:25:00+00:00',
            'opening_cash_amount' => '5000.00',
            'opening_cashless_amount' => '2500.25',
            'opened_by' => ['name' => 'Cashier Tester'],
            'branch' => [
                'id' => $branch->id,
                'code' => 'MAIN',
                'name' => 'Main Branch',
            ],
            'expense_totals' => ['cash' => '0.00', 'cashless' => '0.00', 'total' => '0.00'],
            'expenses' => [],
            'expense_count' => 0,
            'expenses_truncated' => false,
            'restock_products' => [],
        ]);
})->with(['cashier', 'cashier_kitchen']);

test('cashier kitchen retrieves the Phase 14 projection for an already open MAIN session', function () {
    $branch = Branch::factory()->create(['code' => 'MAIN', 'name' => 'MAIN']);
    $cashier = currentStoreSessionUser($branch, 'cashier_kitchen');
    $session = StoreSession::factory()->for($branch)->for($cashier, 'openedBy')->create();
    $expense = StoreSessionExpense::factory()
        ->for($branch)
        ->for($session, 'storeSession')
        ->for($cashier, 'createdBy')
        ->create([
            'description' => 'Cooking gas',
            'amount' => '850.00',
            'payment_source' => 'cash',
        ]);
    $product = Product::factory()->create(['name' => 'Rice']);
    BranchProduct::factory()->for($branch)->for($product)->create([
        'tracks_inventory' => true,
    ]);

    $this->actingAs($cashier)->getJson(route('store-sessions.current'))
        ->assertOk()
        ->assertJsonPath('id', $session->id)
        ->assertJsonPath('branch.id', $branch->id)
        ->assertJsonPath('branch.code', 'MAIN')
        ->assertJsonPath('expense_totals.cash', '850.00')
        ->assertJsonPath('expense_count', 1)
        ->assertJsonPath('expenses.0.id', $expense->id)
        ->assertJsonPath('restock_products.0.id', $product->id);
});

test('cashier kitchen retrieves a freshly opened MAIN session', function () {
    $branch = Branch::factory()->create(['code' => 'MAIN', 'name' => 'MAIN']);
    $cashier = currentStoreSessionUser($branch, 'cashier_kitchen');
    $session = app(OpenStoreSession::class)->execute(
        $cashier,
        $branch,
        '1200.00',
        '300.00',
    );

    $this->actingAs($cashier)->getJson(route('store-sessions.current'))
        ->assertOk()
        ->assertJsonPath('id', $session->id)
        ->assertJsonPath('opening_cash_amount', '1200.00')
        ->assertJsonPath('opening_cashless_amount', '300.00')
        ->assertJsonPath('expense_totals.total', '0.00');
});

test('guest is denied current Store Session detail', function () {
    $this->getJson(route('store-sessions.current'))->assertUnauthorized();
});

test('kitchen only user is denied current Store Session detail', function () {
    $branch = Branch::factory()->create();
    $user = currentStoreSessionUser($branch, 'kitchen_staff');
    StoreSession::factory()->for($branch)->create();

    $this->actingAs($user)->getJson(route('store-sessions.current'))->assertForbidden();
});

test('inactive cashier is denied current Store Session detail', function () {
    $branch = Branch::factory()->create();
    $user = currentStoreSessionUser($branch);
    $user->forceFill(['is_active' => false])->save();
    $user->refresh();
    StoreSession::factory()->for($branch)->create();

    $this->actingAs($user)->getJson(route('store-sessions.current'))->assertUnauthorized();
});

test('stale context for an unassigned branch exposes no Store Session detail', function () {
    $assignedBranch = Branch::factory()->create();
    $unassignedBranch = Branch::factory()->create();
    $user = currentStoreSessionUser($assignedBranch);
    StoreSession::factory()->for($unassignedBranch)->create();

    $this->actingAs($user)
        ->withSession([ActiveBranchContext::SESSION_KEY => $unassignedBranch->id])
        ->getJson(route('store-sessions.current'))
        ->assertNotFound()
        ->assertJsonMissing(['opening_cash_amount' => '1000.00']);
});

test('closed store exposes no active Store Session detail', function () {
    $branch = Branch::factory()->create();
    $user = currentStoreSessionUser($branch);
    StoreSession::factory()->closed()->for($branch)->create([
        'opening_cash_amount' => '4321.00',
        'opening_cashless_amount' => '1234.00',
    ]);

    $this->actingAs($user)->getJson(route('store-sessions.current'))
        ->assertNotFound()
        ->assertJsonMissing(['opening_cash_amount' => '4321.00'])
        ->assertJsonMissing(['closing_cash_amount' => '4321.00']);
});

test('current Store Session response never leaks reconciliation fields', function () {
    $branch = Branch::factory()->create();
    $user = currentStoreSessionUser($branch);
    StoreSession::factory()->for($branch)->create([
        'status' => StoreSessionStatus::Open,
        'closing_cash_amount' => '10.00',
        'closing_cashless_amount' => '20.00',
        'expected_cash_amount' => '30.00',
        'expected_cashless_amount' => '40.00',
        'cash_variance' => '50.00',
        'cashless_variance' => '60.00',
        'closing_note' => 'must not leak',
    ]);

    $response = $this->actingAs($user)->getJson(route('store-sessions.current'));

    $response->assertOk();
    foreach ([
        'closing_cash_amount',
        'closing_cashless_amount',
        'expected_cash_amount',
        'expected_cashless_amount',
        'cash_variance',
        'cashless_variance',
        'closing_note',
        'closed_at',
        'closed_by_user_id',
    ] as $field) {
        $response->assertJsonMissingPath($field);
    }
});
