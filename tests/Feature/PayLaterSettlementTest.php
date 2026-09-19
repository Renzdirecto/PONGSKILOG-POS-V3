<?php

use App\Actions\Orders\CommitPayLaterOrder;
use App\Actions\Orders\CreatePosDraftOrder;
use App\Enums\KitchenStatus;
use App\Enums\PaymentStatus;
use App\Enums\PaymentTerm;
use App\Models\Branch;
use App\Models\BranchInventory;
use App\Models\BranchProduct;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Role;
use App\Models\StoreSession;
use App\Models\User;
use App\Support\ActiveBranchContext;
use App\Support\ExactMoney;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->seed(RbacSeeder::class);
});

/** @return array{Branch, User, Product, BranchInventory, StoreSession, Order} */
function settlementFixture(string $price = '500.00'): array
{
    $branch = Branch::factory()->create();
    $user = User::factory()->create();
    $user->roles()->attach(Role::query()->where('name', 'cashier')->sole());
    $user->branches()->attach($branch, ['is_active' => true]);
    $session = StoreSession::factory()->for($branch)->create();
    $product = Product::factory()->create(['default_price' => $price]);
    BranchProduct::factory()->for($branch)->for($product)->create(['tracks_inventory' => true]);
    $balance = BranchInventory::factory()->for($branch)->for($product)->create(['on_hand' => 5, 'version' => 1]);
    $order = app(CreatePosDraftOrder::class)->execute($user, $branch, [
        'order_type' => 'dine_in',
        'customer_label' => 'Settlement QA',
        'items' => [['product_id' => $product->id, 'quantity' => 1, 'modifiers' => []]],
    ]);
    $order = app(CommitPayLaterOrder::class)->execute($user, $branch, $order, ['idempotency_key' => (string) Str::uuid()]);

    return [$branch, $user, $product, $balance, $session, $order];
}

/** @return array<string, string|null> */
function settlementPayload(string $method, ?string $cash = null, ?string $cashless = null): array
{
    return [
        'idempotency_key' => (string) Str::uuid(),
        'payment_method' => $method,
        'cash_received' => $cash,
        'cashless_amount' => $cashless,
    ];
}

test('cash cashless and split settle a pay later order without repeating inventory or kitchen', function (string $method, ?string $cash, ?string $cashless, int $paymentCount, ?string $change) {
    [, $user, , $balance, $session, $order] = settlementFixture();
    $movementCount = $order->inventoryMovements()->count();
    $ticketId = $order->kitchenTicket()->sole()->id;
    $committedAt = $order->committed_at;
    $payload = settlementPayload($method, $cash, $cashless);

    $response = $this->actingAs($user)->postJson(route('pos.orders.settlements.store', $order), $payload)->assertOk();

    $order->refresh();
    $response->assertJsonPath('receipt.payment_status', 'paid')
        ->assertJsonPath('receipt.id', $order->id)
        ->assertJsonPath('receipt.store_session_id', $session->id);
    expect($order->payment_status)->toBe(PaymentStatus::Paid)
        ->and($order->payment_term)->toBe(PaymentTerm::PayLater)
        ->and($order->kitchen_status)->toBe(KitchenStatus::Kitchen)
        ->and($order->committed_at->equalTo($committedAt))->toBeTrue()
        ->and($order->store_session_id)->toBe($session->id)
        ->and($order->version)->toBe(3)
        ->and($balance->fresh()->on_hand)->toBe(4)
        ->and($order->inventoryMovements()->count())->toBe($movementCount)
        ->and($order->kitchenTicket()->sole()->id)->toBe($ticketId)
        ->and($order->payments()->count())->toBe($paymentCount)
        ->and($order->payments->sum(fn (Payment $payment): int => ExactMoney::cents($payment->amount)))->toBe(50000);
    if ($method !== 'cashless') {
        $this->assertDatabaseHas('payments', [
            'order_id' => $order->id,
            'method' => 'cash',
            'amount' => $method === 'split' ? '300.00' : '500.00',
            'amount_received' => $cash,
            'change_amount' => $change,
        ]);
    }
    if ($method !== 'cash') {
        $this->assertDatabaseHas('payments', [
            'order_id' => $order->id,
            'method' => 'cashless',
            'amount' => $method === 'split' ? '200.00' : '500.00',
        ]);
    }

    $this->postJson(route('pos.orders.settlements.store', $order), $payload)->assertExactJson($response->json());
    expect($order->fresh()->version)->toBe(3)
        ->and($balance->fresh()->on_hand)->toBe(4)
        ->and($order->inventoryMovements()->count())->toBe($movementCount)
        ->and($order->kitchenTicket()->count())->toBe(1)
        ->and($order->payments()->count())->toBe($paymentCount);
})->with([
    'cash exact' => ['cash', '500.00', null, 1, '0.00'],
    'cash change' => ['cash', '700.00', null, 1, '200.00'],
    'cashless' => ['cashless', null, null, 1, null],
    'split exact' => ['split', '300.00', '200.00', 2, '0.00'],
    'split change' => ['split', '500.00', '200.00', 2, '200.00'],
]);

test('different key after payment and a reused settlement root on another order are rejected', function () {
    [$branch, $user, $product, $balance, , $order] = settlementFixture();
    $payload = settlementPayload('cashless');
    $this->actingAs($user)->postJson(route('pos.orders.settlements.store', $order), $payload)->assertOk();

    $this->postJson(route('pos.orders.settlements.store', $order), settlementPayload('cashless'))
        ->assertUnprocessable()->assertJsonValidationErrors('order');
    $other = app(CreatePosDraftOrder::class)->execute($user, $branch, [
        'order_type' => 'take_out',
        'items' => [['product_id' => $product->id, 'quantity' => 1, 'modifiers' => []]],
    ]);
    $other = app(CommitPayLaterOrder::class)->execute($user, $branch, $other, ['idempotency_key' => (string) Str::uuid()]);
    $this->postJson(route('pos.orders.settlements.store', $other), $payload)->assertConflict();

    expect($balance->fresh()->on_hand)->toBe(3);
    $this->assertDatabaseCount('payments', 1);
    $this->assertDatabaseCount('inventory_movements', 2);
    $this->assertDatabaseCount('kitchen_tickets', 2);
});

test('settlement requires the original currently open store session', function (string $state) {
    [$branch, $user, , $balance, $session, $order] = settlementFixture();
    $session->update(['status' => 'closed']);
    if ($state === 'different') {
        StoreSession::factory()->for($branch)->create();
    }

    $this->actingAs($user)->postJson(route('pos.orders.settlements.store', $order), settlementPayload('cashless'))
        ->assertUnprocessable()->assertJsonValidationErrors($state === 'different' ? 'store_session' : 'store');

    expect($order->fresh()->payment_status)->toBe(PaymentStatus::Unpaid)
        ->and($balance->fresh()->on_hand)->toBe(4);
    $this->assertDatabaseCount('payments', 0);
    $this->assertDatabaseCount('inventory_movements', 1);
    $this->assertDatabaseCount('kitchen_tickets', 1);
})->with(['closed', 'different']);

test('invalid settlement tender leaves the operational pay later order unchanged', function (string $method, mixed $cash, mixed $cashless, string $field) {
    [, $user, , $balance, , $order] = settlementFixture();
    $payload = settlementPayload($method, is_string($cash) ? $cash : null, is_string($cashless) ? $cashless : null);
    $payload['cash_received'] = $cash;
    $payload['cashless_amount'] = $cashless;

    $this->actingAs($user)->postJson(route('pos.orders.settlements.store', $order), $payload)
        ->assertUnprocessable()->assertJsonValidationErrors($field);

    expect($order->fresh()->payment_status)->toBe(PaymentStatus::Unpaid)
        ->and($order->fresh()->version)->toBe(2)
        ->and($balance->fresh()->on_hand)->toBe(4);
    $this->assertDatabaseCount('payments', 0);
    $this->assertDatabaseCount('inventory_movements', 1);
    $this->assertDatabaseCount('kitchen_tickets', 1);
})->with([
    ['cash', '499.99', null, 'cash_received'],
    ['cash', null, null, 'cash_received'],
    ['cash', '-1', null, 'cash_received'],
    ['split', '500', '0', 'cashless_amount'],
    ['split', '299.99', '200', 'cash_received'],
    ['split', '500', '500', 'cashless_amount'],
    ['gateway', '500', null, 'payment_method'],
]);

test('foreign orders and non cashier roles cannot settle pay later', function (string $case) {
    [$branch, , , , , $order] = settlementFixture();
    if ($case === 'foreign') {
        $activeBranch = Branch::factory()->create();
        StoreSession::factory()->for($activeBranch)->create();
        $user = User::factory()->create();
        $user->roles()->attach(Role::query()->where('name', 'cashier')->sole());
        $user->branches()->attach($activeBranch, ['is_active' => true]);
    } else {
        $activeBranch = $branch;
        $user = User::factory()->create();
        $user->roles()->attach(Role::query()->where('name', $case)->sole());
        $user->branches()->attach($branch, ['is_active' => true]);
    }

    $response = $this->actingAs($user)->withSession([ActiveBranchContext::SESSION_KEY => $activeBranch->id])
        ->postJson(route('pos.orders.settlements.store', $order), settlementPayload('cashless'));
    $case === 'foreign' ? $response->assertNotFound() : $response->assertForbidden();
    expect($order->fresh()->payment_status)->toBe(PaymentStatus::Unpaid);
    $this->assertDatabaseCount('payments', 0);
})->with(['foreign', 'kitchen_staff', 'owner', 'super_admin']);

test('forged server owned settlement fields are rejected', function (string $field) {
    [, $user, , , , $order] = settlementFixture();
    $payload = settlementPayload('cashless');
    $payload[$field] = $field === 'total' ? '0.01' : 'forged';

    $this->actingAs($user)->postJson(route('pos.orders.settlements.store', $order), $payload)
        ->assertUnprocessable()->assertJsonValidationErrors($field);

    expect($order->fresh()->payment_status)->toBe(PaymentStatus::Unpaid);
    $this->assertDatabaseCount('payments', 0);
})->with(['branch_id', 'store_session_id', 'subtotal', 'total', 'commercial_status', 'payment_status', 'payment_term', 'kitchen_status', 'order_number', 'reference_number']);
