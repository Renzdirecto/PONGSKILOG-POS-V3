<?php

use App\Models\Branch;
use App\Models\Order;
use App\Models\PaymentInvoiceProof;
use App\Models\Role;
use App\Models\User;
use App\Support\ActiveBranchContext;
use Carbon\CarbonImmutable;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\StoreCloseScenario;

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->travelTo(CarbonImmutable::parse('2026-09-23 09:00', 'Asia/Manila'));
});

function businessViewer(string $role = 'owner'): User
{
    $user = User::factory()->create();
    $user->roles()->attach(Role::query()->where('name', $role)->sole());

    return $user;
}

function historyScenario(string $code): StoreCloseScenario
{
    $scenario = StoreCloseScenario::create();
    $scenario->branch->update(['code' => $code, 'name' => "{$code} Branch"]);

    return $scenario;
}

/** @param array<string, mixed> $query */
function businessHistory(?Branch $branch, array $query = [], ?User $user = null): TestResponse
{
    return test()->actingAs($user ?? businessViewer())
        ->withSession($branch === null ? [] : [ActiveBranchContext::SESSION_KEY => $branch->id])
        ->get(route('workspaces.transactions', $query));
}

test('owner reads the same transaction history page across all branches with branch identity and no write capability', function () {
    $alpha = historyScenario('ALPHA');
    $bravo = historyScenario('BRAVO');
    $alpha->payNow(1, 'cash');
    $bravo->payNow(2, 'cashless');

    businessHistory(null)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('workspaces/transaction-history')
            ->where('surface', 'business')
            ->where('scope', null)
            ->where('operational', false)
            ->where('catalog', null)
            ->where('transactions.total', 2)
            ->where('history_total', 2)
            ->where('transactions.data', fn ($rows) => collect($rows)->pluck('branch.code')->sort()->values()->all() === ['ALPHA', 'BRAVO']
                && collect($rows)->every(fn (array $row) => ! $row['can_edit'] && ! $row['can_settle'] && ! $row['can_void'])));
});

test('a selected branch scopes the owner history to that branch only', function () {
    $alpha = historyScenario('ALPHA');
    $bravo = historyScenario('BRAVO');
    $alpha->payNow(1, 'cash');
    $bravo->payNow(2, 'cashless');

    businessHistory($bravo->branch)
        ->assertInertia(fn (Assert $page) => $page
            ->where('scope.code', 'BRAVO')
            ->where('transactions.total', 1)
            ->where('history_total', 1)
            ->where('transactions.data.0.branch.code', 'BRAVO'));
});

test('owner history keeps server pagination search filters and excludes voided orders', function () {
    $scenario = historyScenario('ALPHA');
    foreach (range(1, 11) as $minute) {
        $this->travel(1)->minutes();
        $scenario->payNow(1, 'cash');
    }
    $split = $scenario->payNow(3, 'split', '100.00');
    $scenario->void($scenario->payNow(1, 'cash'));

    businessHistory(null)
        ->assertInertia(fn (Assert $page) => $page->has('transactions.data', 10)->where('transactions.total', 12)->where('history_total', 12));
    businessHistory(null, ['search' => $split->order_number])
        ->assertInertia(fn (Assert $page) => $page->where('transactions.total', 1)->where('transactions.data.0.id', $split->id));
    businessHistory(null, ['payment_method' => 'split'])
        ->assertInertia(fn (Assert $page) => $page->where('transactions.total', 1)->where('transactions.data.0.id', $split->id));
});

test('owner detail is read only with branch identity and no invoice link while another branch stays hidden', function () {
    $alpha = historyScenario('ALPHA');
    $bravo = historyScenario('BRAVO');
    $order = $alpha->payNow(2, 'cashless');
    $payment = $order->payments()->sole();
    PaymentInvoiceProof::factory()->for($payment)->create(['branch_id' => $alpha->branch->id, 'original_name' => 'gcash.jpg']);
    $foreign = $bravo->payNow(1, 'cash');
    $owner = businessViewer();

    $detail = $this->actingAs($owner)->getJson(route('workspaces.transactions.show', $order))->assertOk()->json('transaction');
    $this->actingAs($owner)->withSession([ActiveBranchContext::SESSION_KEY => $alpha->branch->id])
        ->getJson(route('workspaces.transactions.show', $foreign))->assertNotFound();

    expect($detail['branch']['code'])->toBe('ALPHA')
        ->and($detail['operational'])->toBeFalse()
        ->and($detail['can_edit'])->toBeFalse()
        ->and($detail['can_void'])->toBeFalse()
        ->and($detail['payment_groups'][0]['payments'][0]['invoice'])->toBe(['name' => 'gcash.jpg', 'url' => null])
        ->and($detail['receipt']['order_number'])->toBe($order->order_number);
});

test('a voided order detail is not available to the owner', function () {
    $scenario = historyScenario('ALPHA');
    $voided = $scenario->void($scenario->payNow(1, 'cash'));

    $this->actingAs(businessViewer())->getJson(route('workspaces.transactions.show', $voided))->assertNotFound();
});

test('owner scope never grants cashier writes on the shared history', function () {
    $scenario = historyScenario('ALPHA');
    $order = $scenario->payLater(2);
    $owner = businessViewer();
    $session = [ActiveBranchContext::SESSION_KEY => $scenario->branch->id];

    $this->actingAs($owner)->withSession($session)->getJson(route('pos.transactions.show', $order))->assertForbidden();
    $this->actingAs($owner)->withSession($session)->patchJson(route('pos.transactions.update', $order), [])->assertForbidden();
    $this->actingAs($owner)->withSession($session)->postJson(route('pos.transactions.void', $order), [])->assertForbidden();
    $this->actingAs($owner)->withSession($session)->postJson(route('pos.orders.settlements.store', $order), [
        'idempotency_key' => (string) Str::uuid(), 'payment_method' => 'cash', 'cash_received' => '200.00',
    ])->assertForbidden();

    expect($order->fresh()->payment_status->value)->toBe('unpaid')
        ->and($order->fresh()->version)->toBe($order->version);
});

test('branch staff read the business transaction surface only for their selected assigned branch', function (string $role) {
    $scenario = historyScenario('ALPHA');
    $other = historyScenario('BRAVO');

    $this->actingAs($scenario->user($role))
        ->withSession([ActiveBranchContext::SESSION_KEY => $other->branch->id])
        ->get(route('workspaces.transactions'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('scope.code', 'ALPHA'));
})->with(['cashier', 'cashier_kitchen']);

test('kitchen staff without transactions access cannot open the business transaction surface', function () {
    $scenario = historyScenario('ALPHA');

    $this->actingAs($scenario->user('kitchen_staff'))
        ->withSession([ActiveBranchContext::SESSION_KEY => $scenario->branch->id])
        ->get(route('workspaces.transactions'))
        ->assertForbidden();
});

test('the cashier terminal history keeps its pos surface and open session capabilities', function () {
    $scenario = historyScenario('ALPHA');
    $order = $scenario->payLater(1);

    $this->actingAs($scenario->cashier)
        ->withSession([ActiveBranchContext::SESSION_KEY => $scenario->branch->id])
        ->get(route('workspaces.transaction-history'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('surface', 'pos')
            ->where('operational', true)
            ->where('transactions.data.0.id', $order->id)
            ->where('transactions.data.0.can_edit', true)
            ->where('transactions.data.0.can_settle', true));
});

test('super admin keeps pos capabilities only on the selected branch of the shared history', function () {
    $alpha = historyScenario('ALPHA');
    $bravo = historyScenario('BRAVO');
    $mine = $alpha->payLater(1);
    $bravo->payLater(1);
    $superAdmin = businessViewer('super_admin');

    businessHistory($alpha->branch, [], $superAdmin)
        ->assertInertia(fn (Assert $page) => $page
            ->where('operational', true)
            ->where('transactions.data.0.id', $mine->id)
            ->where('transactions.data.0.can_edit', true));
    $this->flushSession();
    businessHistory(null, [], $superAdmin)
        ->assertInertia(fn (Assert $page) => $page
            ->where('operational', false)
            ->where('transactions.data', fn ($rows) => collect($rows)->every(fn (array $row) => ! $row['can_edit'])));
});

test('an order of an inactive business branch still keeps its history visible to the owner', function () {
    $scenario = historyScenario('ALPHA');
    $order = $scenario->payNow(1, 'cash');
    $scenario->branch->update(['status' => 'inactive']);

    businessHistory(null)->assertInertia(fn (Assert $page) => $page->where('transactions.data.0.id', $order->id));
    expect(Order::query()->whereKey($order->id)->exists())->toBeTrue();
});
