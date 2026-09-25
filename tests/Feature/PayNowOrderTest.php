<?php

use App\Actions\Orders\CreatePosDraftOrder;
use App\Actions\Orders\PayNowOrder;
use App\Enums\BranchStatus;
use App\Enums\CommercialStatus;
use App\Enums\KitchenStatus;
use App\Enums\PaymentStatus;
use App\Enums\PaymentTerm;
use App\Events\KitchenTicketCreated;
use App\Events\OrderCommitted;
use App\Models\Branch;
use App\Models\BranchInventory;
use App\Models\BranchProduct;
use App\Models\BranchTable;
use App\Models\KitchenTicket;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Role;
use App\Models\StoreSession;
use App\Models\User;
use App\Support\ActiveBranchContext;
use App\Support\ExactMoney;
use Database\Seeders\RbacSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->seed(RbacSeeder::class);
});

/** @return array{Branch, User, Product, BranchInventory, StoreSession} */
function paymentFixture(string $price = '235.00', string $role = 'cashier'): array
{
    $branch = Branch::factory()->create();
    $user = User::factory()->create();
    $user->roles()->attach(Role::query()->where('name', $role)->sole());
    $user->branches()->attach($branch, ['is_active' => true]);
    $session = StoreSession::factory()->for($branch)->create();
    $product = Product::factory()->create(['default_price' => $price]);
    BranchProduct::factory()->for($branch)->for($product)->create(['tracks_inventory' => true]);
    $balance = BranchInventory::factory()->for($branch)->for($product)->create(['on_hand' => 10, 'version' => 3]);

    return [$branch, $user, $product, $balance, $session];
}

/** @return array<string, mixed> */
function paymentPayload(Product $product, string $method = 'cash', ?string $cash = '500.00', ?string $cashless = null): array
{
    return ['order_type' => 'take_out', 'customer_label' => 'Payment QA',
        'items' => [['product_id' => $product->id, 'quantity' => 1, 'notes' => 'Less salt', 'modifiers' => []]],
        'payment_method' => $method, 'cash_received' => $cash, 'cashless_amount' => $cashless, 'idempotency_key' => (string) Str::uuid()];
}

test('cashier payment commits authoritative totals stock kitchen session and receipt exactly once', function (string $role, string $method, ?string $cash, ?string $cashless, string $change, int $count) {
    [$branch, $user, $product, $balance, $session] = paymentFixture('235.00', $role);
    $payload = paymentPayload($product, $method, $cash, $cashless);
    $payload += ['total' => '0.01', 'amount' => '0.01', 'branch_id' => (string) Str::uuid(), 'store_session_id' => (string) Str::uuid(), 'kitchen_status' => 'done', 'order_number' => 'FORGED', 'reference_number' => 'FORGED'];
    $payload['items'][0]['unit_price'] = '0.01';

    $response = $this->actingAs($user)->postJson(route('pos.payments.store'), $payload);

    $order = Order::query()->sole();
    $response->assertOk()->assertJsonPath('receipt.id', $order->id)->assertJsonPath('receipt.total', '235.00')
        ->assertJsonPath('receipt.payment_status', 'paid')->assertJsonPath('receipt.branch.name', $branch->name)
        ->assertJsonPath('receipt.cashier', $user->name)->assertJsonPath('receipt.items.0.notes', 'Less salt')
        ->assertJsonMissingPath('receipt.payments.0.idempotency_key');
    expect($order->order_number)->toMatch('/\A[0-9]+\z/')
        ->and($order->reference_number)->toBe($branch->code.'-'.now()->timezone('Asia/Manila')->format('mdy').'-0001')
        ->and($order->order_number)->not->toBe('FORGED')
        ->and($order->reference_number)->not->toBe('FORGED');
    expect($order->commercial_status)->toBe(CommercialStatus::Active);
    expect($order->payment_status)->toBe(PaymentStatus::Paid);
    expect($order->payment_term)->toBe(PaymentTerm::Immediate);
    expect($order->kitchen_status)->toBe(KitchenStatus::Kitchen);
    expect($order->store_session_id)->toBe($session->id);
    expect($order->committed_at)->not->toBeNull();
    expect($order->completed_at)->toBeNull();
    expect($order->version)->toBe(2);
    expect($balance->fresh()->on_hand)->toBe(9);
    expect($balance->fresh()->version)->toBe(4);
    $this->assertDatabaseHas('inventory_movements', ['branch_id' => $branch->id, 'order_id' => $order->id, 'created_by_user_id' => $user->id, 'quantity_delta' => -1, 'movement_type' => 'sale']);
    $this->assertDatabaseHas('kitchen_tickets', ['branch_id' => $branch->id, 'order_id' => $order->id, 'status' => 'kitchen']);
    expect($order->payments()->count())->toBe($count);
    expect($order->payments->sum(fn (Payment $payment): int => ExactMoney::cents($payment->amount)))->toBe(23500);
    foreach ($order->payments as $payment) {
        expect($payment->branch_id)->toBe($branch->id);
        expect($payment->store_session_id)->toBe($session->id);
        expect($payment->created_by_user_id)->toBe($user->id);
    }
    if ($method !== 'cashless') {
        $this->assertDatabaseHas('payments', ['method' => 'cash', 'amount' => $method === 'split' ? '135.00' : '235.00', 'amount_received' => $cash, 'change_amount' => $change]);
    } else {
        $this->assertDatabaseHas('payments', ['method' => 'cashless', 'amount' => '235.00', 'amount_received' => null, 'change_amount' => null]);
    }
    $this->postJson(route('pos.payments.store'), $payload)->assertExactJson($response->json());
    $this->assertDatabaseCount('orders', 1);
    $this->assertDatabaseCount('payments', $count);
    $this->assertDatabaseCount('kitchen_tickets', 1);
    $this->assertDatabaseCount('inventory_movements', 1);
    $this->assertDatabaseHas('audit_logs', [
        'auditable_id' => $order->id,
        'user_id' => $user->id,
        'action' => 'order.paid',
        'idempotency_key' => strtolower($payload['idempotency_key']),
    ]);
})->with(['cashier', 'cashier_kitchen'])->with([
    'cash exact' => ['cash', '235.00', null, '0.00', 1],
    'cash change' => ['cash', '500.00', null, '265.00', 1],
    'cashless' => ['cashless', null, null, '0.00', 1],
    'split exact' => ['split', '135.00', '100.00', '0.00', 2],
    'split change' => ['split', '500.00', '100.00', '365.00', 2],
]);

test('reserved order keeps the same numeric identifier from early POS context through receipt', function () {
    [$branch, $user, $product] = paymentFixture();
    $reservation = $this->actingAs($user)->postJson(route('pos.orders.reservations.store'), [
        'order_type' => 'take_out',
        'order_number' => '9999',
        'reference_number' => 'FORGED',
    ])->assertOk()->json('order');

    expect($reservation['order_number'])->toMatch('/\A[0-9]+\z/')
        ->and($reservation['reference_number'])->toBe($branch->code.'-'.now()->timezone('Asia/Manila')->format('mdy').'-0001');

    $payload = paymentPayload($product, 'cashless', null);
    $payload['reserved_order_id'] = $reservation['id'];
    $payload['order_number'] = '9999';
    $payload['reference_number'] = 'FORGED';

    $this->postJson(route('pos.payments.store'), $payload)
        ->assertOk()
        ->assertJsonPath('receipt.id', $reservation['id'])
        ->assertJsonPath('receipt.order_number', $reservation['order_number'])
        ->assertJsonPath('receipt.reference_number', $reservation['reference_number']);

    $this->assertDatabaseCount('orders', 1);
    $this->assertDatabaseHas('orders', [
        'id' => $reservation['id'],
        'order_number' => $reservation['order_number'],
        'reference_number' => $reservation['reference_number'],
        'payment_status' => 'paid',
    ]);
});

test('repeated reservation requests reuse the same unused order number', function () {
    [$branch, $user] = paymentFixture();

    $first = $this->actingAs($user)->postJson(route('pos.orders.reservations.store'), [
        'order_type' => 'take_out',
    ])->assertOk()->json('order');
    $second = $this->postJson(route('pos.orders.reservations.store'), [
        'order_type' => 'dine_in',
    ])->assertOk()->json('order');

    expect($second['id'])->toBe($first['id'])
        ->and($second['order_number'])->toBe($first['order_number'])
        ->and($second['reference_number'])->toBe($first['reference_number'])
        ->and($second['order_type'])->toBe('dine_in');
    $this->assertDatabaseCount('orders', 1);
    $this->assertDatabaseHas('orders', [
        'id' => $first['id'],
        'order_number' => $first['order_number'],
        'reference_number' => $first['reference_number'],
        'order_type' => 'dine_in',
    ]);
    $this->assertDatabaseHas('order_number_counters', [
        'branch_id' => $branch->id,
        'next_number' => 1002,
    ]);
});

test('a paid reservation is not reused for the next order', function () {
    [, $user, $product] = paymentFixture();
    $first = $this->actingAs($user)->postJson(route('pos.orders.reservations.store'), [
        'order_type' => 'take_out',
    ])->assertOk()->json('order');
    $payload = paymentPayload($product, 'cashless', null);
    $payload['reserved_order_id'] = $first['id'];
    $this->postJson(route('pos.payments.store'), $payload)->assertOk();

    $next = $this->postJson(route('pos.orders.reservations.store'), [
        'order_type' => 'take_out',
    ])->assertOk()->json('order');

    expect($next['id'])->not->toBe($first['id'])
        ->and($next['order_number'])->toBe('1002');
    $this->assertDatabaseCount('orders', 2);
});

test('cashier can pay either order type without a customer label', function (string $orderType) {
    [, $user, $product] = paymentFixture();
    $payload = paymentPayload($product, 'cashless', null);
    $payload['order_type'] = $orderType;
    $payload['customer_label'] = null;

    $this->actingAs($user)->postJson(route('pos.payments.store'), $payload)
        ->assertOk()
        ->assertJsonPath('receipt.order_type', $orderType)
        ->assertJsonPath('receipt.customer_label', null);
})->with(['dine_in', 'take_out']);

test('split 500 total applies 200 cashless and 300 cash with exact tender or change', function (string $received, string $change) {
    [$branch, $user, $product] = paymentFixture('500.00');
    $this->actingAs($user)->postJson(route('pos.payments.store'), paymentPayload($product, 'split', $received, '200.00'))->assertOk();
    $this->assertDatabaseHas('payments', ['method' => 'cashless', 'amount' => '200.00']);
    $this->assertDatabaseHas('payments', ['method' => 'cash', 'amount' => '300.00', 'amount_received' => $received, 'change_amount' => $change]);
})->with([['300.00', '0.00'], ['500.00', '200.00']]);

test('invalid tender returns 422 and leaves no new order or operational effects', function (string $method, mixed $cash, mixed $cashless, string $field) {
    [$branch, $user, $product, $balance] = paymentFixture('500.00');
    $payload = paymentPayload($product, $method);
    $payload['cash_received'] = $cash;
    $payload['cashless_amount'] = $cashless;
    $this->actingAs($user)->postJson(route('pos.payments.store'), $payload)->assertUnprocessable()->assertJsonValidationErrors($field);
    foreach (['orders', 'payments', 'inventory_movements', 'kitchen_tickets'] as $table) {
        $this->assertDatabaseCount($table, 0);
    }
    expect($balance->fresh()->on_hand)->toBe(10);
})->with([
    ['cash', '100.00', null, 'cash_received'], ['cash', null, null, 'cash_received'],
    ['cash', '-1', null, 'cash_received'], ['cash', '1.001', null, 'cash_received'],
    ['cash', '1e5', null, 'cash_received'], ['cash', '1000000000000', null, 'cash_received'],
    ['cash', 500.1, null, 'cash_received'], ['cash', 'NaN', null, 'cash_received'],
    ['split', '500', '0', 'cashless_amount'], ['split', '500', '500', 'cashless_amount'],
    ['split', '500', '501', 'cashless_amount'], ['split', '299.99', '200', 'cash_received'],
    ['split', '500', null, 'cashless_amount'], ['split', '500', '-1', 'cashless_amount'],
    ['gateway', '500', null, 'payment_method'],
]);

test('zero total cash and cashless retain traceability and still commit inventory and kitchen', function (string $method) {
    [$branch, $user, $product, $balance] = paymentFixture('0.00');
    $payload = paymentPayload($product, $method, $method === 'cash' ? '0.00' : null);
    $this->actingAs($user)->postJson(route('pos.payments.store'), $payload)->assertOk()->assertJsonPath('receipt.total', '0.00');
    $this->assertDatabaseHas('payments', ['method' => $method, 'amount' => '0.00']);
    expect($balance->fresh()->on_hand)->toBe(9);
    $this->assertDatabaseCount('kitchen_tickets', 1);
})->with(['cash', 'cashless']);

test('zero total rejects meaningless split legs', function () {
    [$branch, $user, $product] = paymentFixture('0.00');
    $this->actingAs($user)->postJson(route('pos.payments.store'), paymentPayload($product, 'split', '0', '0'))->assertUnprocessable();
    $this->assertDatabaseCount('orders', 0);
});

test('existing draft keeps historical prices and replays while a different key is rejected', function () {
    [$branch, $user, $product] = paymentFixture();
    $order = app(CreatePosDraftOrder::class)->execute($user, $branch, paymentPayload($product));
    $product->update(['name' => 'Renamed', 'default_price' => '900.00']);
    $payload = ['draft_order_id' => $order->id, 'payment_method' => 'cashless', 'idempotency_key' => (string) Str::uuid()];
    $response = $this->actingAs($user)->postJson(route('pos.payments.store'), $payload)->assertOk()->assertJsonPath('receipt.total', '235.00');
    $this->postJson(route('pos.payments.store'), $payload)->assertExactJson($response->json());
    $payload['idempotency_key'] = (string) Str::uuid();
    $this->postJson(route('pos.payments.store'), $payload)->assertUnprocessable()->assertJsonPath('errors.order.0', 'Order has already been paid.');
    $this->assertDatabaseCount('orders', 1);
    $this->assertDatabaseCount('payments', 1);
});

test('current product availability is revalidated for an existing draft', function (string $unavailable) {
    [$branch, $user, $product, $balance] = paymentFixture();
    $order = app(CreatePosDraftOrder::class)->execute($user, $branch, paymentPayload($product));
    match ($unavailable) {
        'product' => $product->update(['is_active' => false]),
        'category' => $product->category->update(['is_active' => false]),
        'branch' => BranchProduct::query()->where('branch_id', $branch->id)->update(['is_available' => false]),
        'stock' => $balance->update(['on_hand' => 0]),
        'deleted' => $order->items()->update(['product_id' => null]),
    };
    $this->actingAs($user)->postJson(route('pos.payments.store'), ['draft_order_id' => $order->id, 'payment_method' => 'cashless', 'idempotency_key' => (string) Str::uuid()])->assertUnprocessable();
    expect($order->fresh()->payment_status)->toBe(PaymentStatus::Unpaid);
    foreach (['payments', 'inventory_movements', 'kitchen_tickets'] as $table) {
        $this->assertDatabaseCount($table, 0);
    }
})->with(['product', 'category', 'branch', 'stock', 'deleted']);

test('duplicate product lines aggregate one sale movement while untracked products create none', function () {
    [$branch, $user, $product, $balance] = paymentFixture('10.00');
    $untracked = Product::factory()->soldAt($branch)->create(['default_price' => '20.00']);
    $payload = paymentPayload($product);
    $payload['items'][] = [...$payload['items'][0], 'notes' => 'Different preparation'];
    $payload['items'][] = ['product_id' => $untracked->id, 'quantity' => 1, 'modifiers' => []];
    $this->actingAs($user)->postJson(route('pos.payments.store'), $payload)->assertOk();
    expect($balance->fresh()->on_hand)->toBe(8);
    expect($balance->fresh()->version)->toBe(4);
    $this->assertDatabaseCount('inventory_movements', 1);
    $this->assertDatabaseHas('inventory_movements', ['product_id' => $product->id, 'quantity_delta' => -2]);
});

test('kitchen insert failure rolls back payments inventory and either new or existing draft', function (bool $existing) {
    [$branch, $user, $product, $balance] = paymentFixture();
    $payload = paymentPayload($product, 'split', '500.00', '100.00');
    $order = $existing ? app(CreatePosDraftOrder::class)->execute($user, $branch, $payload) : null;
    if ($order) {
        $payload['draft_order_id'] = $order->id;
    }
    DB::unprepared("CREATE TRIGGER test_kitchen_failure BEFORE INSERT ON kitchen_tickets BEGIN SELECT RAISE(ABORT, 'controlled kitchen failure'); END");
    try {
        app(PayNowOrder::class)->execute($user, $branch, $payload);
        $this->fail('Kitchen failure must propagate.');
    } catch (QueryException $exception) {
        expect($exception->getMessage())->toContain('controlled kitchen failure');
    } finally {
        DB::unprepared('DROP TRIGGER test_kitchen_failure');
    }
    expect($balance->fresh()->on_hand)->toBe(10);
    expect($balance->fresh()->version)->toBe(3);
    $this->assertDatabaseCount('orders', $existing ? 1 : 0);
    foreach (['payments', 'inventory_movements', 'kitchen_tickets'] as $table) {
        $this->assertDatabaseCount($table, 0);
    }
    if ($order) {
        expect($order->fresh()->payment_status)->toBe(PaymentStatus::Unpaid);
        expect($order->fresh()->kitchen_status)->toBe(KitchenStatus::NotSent);
        expect($order->fresh()->committed_at)->toBeNull();
    }
})->with([false, true]);

test('direct payment endpoint forbids unauthorized actors', function (string $role) {
    [$branch, $user, $product] = paymentFixture('235.00', $role);
    $this->actingAs($user)->withSession([ActiveBranchContext::SESSION_KEY => $branch->id])
        ->postJson(route('pos.payments.store'), paymentPayload($product))->assertForbidden();
    $this->assertDatabaseCount('orders', 0);
})->with(['owner', 'kitchen_staff']);

test('full access super admin pays for the selected active branch without an assignment and owns the payment', function () {
    [$branch, $superAdmin, $product, $balance, $session] = paymentFixture('235.00', 'super_admin');
    $superAdmin->branches()->detach();

    $this->actingAs($superAdmin)->withSession([ActiveBranchContext::SESSION_KEY => $branch->id])
        ->postJson(route('pos.payments.store'), paymentPayload($product))
        ->assertOk()
        ->assertJsonPath('receipt.cashier', $superAdmin->name)
        ->assertJsonPath('receipt.total', '235.00');

    $order = Order::query()->sole();
    expect($order->store_session_id)->toBe($session->id)
        ->and($order->created_by_user_id)->toBe($superAdmin->id)
        ->and($balance->fresh()->on_hand)->toBe(9);
    $this->assertDatabaseHas('payments', ['order_id' => $order->id, 'created_by_user_id' => $superAdmin->id]);
});

test('full access super admin cannot operate an inactive branch POS', function () {
    [$branch, $superAdmin, $product] = paymentFixture('235.00', 'super_admin');
    $branch->update(['status' => BranchStatus::Inactive]);

    $this->actingAs($superAdmin)->withSession([ActiveBranchContext::SESSION_KEY => $branch->id])
        ->postJson(route('pos.payments.store'), paymentPayload($product))
        ->assertForbidden();

    $this->assertDatabaseCount('orders', 0);
});

test('guest payment endpoint returns 401', function () {
    $this->postJson(route('pos.payments.store'), [])->assertUnauthorized();
});

test('revoked access and unavailable branches cannot commit payment', function (string $state) {
    [$branch, $user, $product] = paymentFixture();
    match ($state) {
        'inactive' => User::query()->whereKey($user->id)->update(['is_active' => false]),
        'assignment' => $user->branches()->updateExistingPivot($branch->id, ['is_active' => false]),
        'permission' => Role::query()->where('name', 'cashier')->sole()->permissions()->detach(),
        'branch inactive' => $branch->update(['status' => BranchStatus::Inactive]),
        'branch closed' => $branch->update(['status' => BranchStatus::TemporarilyClosed]),
    };
    $response = $this->actingAs($user)->withSession([ActiveBranchContext::SESSION_KEY => $branch->id])->postJson(route('pos.payments.store'), paymentPayload($product));
    expect($response->status())->toBeIn([401, 403, 409]);
    $this->assertDatabaseCount('orders', 0);
})->with(['inactive', 'assignment', 'permission', 'branch inactive', 'branch closed']);

test('store closed between draft and payment rejects commit', function () {
    [$branch, $user, $product, $balance, $session] = paymentFixture();
    $order = app(CreatePosDraftOrder::class)->execute($user, $branch, paymentPayload($product));
    $session->update(['status' => 'closed', 'closed_at' => now()]);
    $this->actingAs($user)->postJson(route('pos.payments.store'), ['draft_order_id' => $order->id, 'payment_method' => 'cashless', 'idempotency_key' => (string) Str::uuid()])->assertUnprocessable()->assertJsonValidationErrors('store');
    expect($balance->fresh()->on_hand)->toBe(10);
    $this->assertDatabaseCount('payments', 0);
});

test('cross branch drafts tables and replay results are inaccessible', function () {
    [$branch, $user, $product] = paymentFixture();
    [$otherBranch, $otherUser, $otherProduct] = paymentFixture();
    $otherDraft = app(CreatePosDraftOrder::class)->execute($otherUser, $otherBranch, paymentPayload($otherProduct));
    $this->actingAs($user)->postJson(route('pos.payments.store'), ['draft_order_id' => $otherDraft->id, 'payment_method' => 'cashless', 'idempotency_key' => (string) Str::uuid()])->assertNotFound();
    $payload = paymentPayload($product);
    $payload['branch_table_id'] = BranchTable::factory()->for($otherBranch)->create()->id;
    $this->postJson(route('pos.payments.store'), $payload)->assertUnprocessable();
    $payload = paymentPayload($otherProduct);
    app(PayNowOrder::class)->execute($otherUser, $otherBranch, $payload);
    $this->postJson(route('pos.payments.store'), $payload)->assertConflict()->assertJsonMissingPath('receipt');
    $this->assertDatabaseCount('payments', 1);
});

test('a used root cannot pay a different draft or modified cart or tender', function (string $changed) {
    [$branch, $user, $product] = paymentFixture();
    $payload = paymentPayload($product);
    $this->actingAs($user)->postJson(route('pos.payments.store'), $payload)->assertOk();
    match ($changed) {
        'draft' => $payload['draft_order_id'] = Order::factory()->for($branch)->create()->id,
        'cart' => $payload['items'][0]['quantity'] = 2,
        'tender' => $payload['cash_received'] = '600.00',
        'method' => $payload['payment_method'] = 'cashless',
        'label' => $payload['customer_label'] = 'Different order',
    };
    $this->postJson(route('pos.payments.store'), $payload)->assertConflict();
    $this->assertDatabaseCount('payments', 1);
    $this->assertDatabaseCount('inventory_movements', 1);
})->with(['draft', 'cart', 'tender', 'method', 'label']);

test('only untouched pos draft states can be committed', function (array $attributes) {
    [$branch, $user, $product] = paymentFixture();
    $order = app(CreatePosDraftOrder::class)->execute($user, $branch, paymentPayload($product));
    $order->update($attributes);
    $this->actingAs($user)->postJson(route('pos.payments.store'), ['draft_order_id' => $order->id, 'payment_method' => 'cashless', 'idempotency_key' => (string) Str::uuid()])->assertUnprocessable();
    $this->assertDatabaseCount('payments', 0);
})->with([
    [['source' => 'customer_qr']], [['commercial_status' => 'active']], [['payment_status' => 'partial']],
    [['payment_term' => 'pay_later']], [['kitchen_status' => 'kitchen']], [['committed_at' => '2026-09-19 12:00:00']],
]);

test('payment and kitchen models expose historical relationships and restrict parent deletion', function () {
    $payment = Payment::factory()->create();
    $ticket = KitchenTicket::factory()->for($payment->order)->create();
    expect($payment->branch->id)->toBe($payment->order->branch_id);
    expect($payment->storeSession->branch_id)->toBe($payment->branch_id);
    expect($payment->createdBy)->toBeInstanceOf(User::class);
    expect($ticket->order->id)->toBe($payment->order_id);
    expect($ticket->branch->id)->toBe($payment->branch_id);
    expect(fn () => $payment->order->delete())->toThrow(QueryException::class);
});

test('payment money checks reject negative historical amounts', function (string $column) {
    $payment = Payment::factory()->create();
    expect(fn () => DB::table('payments')->where('id', $payment->id)->update([$column => '-0.01']))->toThrow(QueryException::class);
})->with(['amount', 'amount_received', 'change_amount']);

test('persisted split method and duplicate kitchen tickets are rejected by database constraints', function () {
    $payment = Payment::factory()->create();
    expect(fn () => DB::table('payments')->where('id', $payment->id)->update(['method' => 'split']))->toThrow(QueryException::class);
    $ticket = KitchenTicket::factory()->for($payment->order)->create();
    expect(fn () => KitchenTicket::factory()->for($payment->order)->create())->toThrow(QueryException::class);
    expect(fn () => $ticket->update(['status' => 'not_sent']))->toThrow(QueryException::class);
});

test('unrelated kitchen unique failure propagates and preserves the existing draft and ticket', function () {
    [$branch, $user, $product, $balance] = paymentFixture();
    $draft = app(CreatePosDraftOrder::class)->execute($user, $branch, paymentPayload($product));
    KitchenTicket::factory()->for($draft)->create();
    expect(fn () => app(PayNowOrder::class)->execute($user, $branch, [
        'draft_order_id' => $draft->id, 'payment_method' => 'cashless', 'idempotency_key' => (string) Str::uuid(),
    ]))->toThrow(UniqueConstraintViolationException::class);
    expect($balance->fresh()->on_hand)->toBe(10);
    expect($draft->fresh()->payment_status)->toBe(PaymentStatus::Unpaid);
    $this->assertDatabaseCount('payments', 0);
    $this->assertDatabaseCount('inventory_movements', 0);
    $this->assertDatabaseCount('kitchen_tickets', 1);
});

test('payment rejects forged quantities and invalid root keys before persistence', function (string $field, mixed $value) {
    [$branch, $user, $product] = paymentFixture();
    $payload = paymentPayload($product);
    data_set($payload, $field, $value);
    $this->actingAs($user)->postJson(route('pos.payments.store'), $payload)->assertUnprocessable()->assertJsonValidationErrors($field);
    $this->assertDatabaseCount('orders', 0);
})->with([
    ['items.0.quantity', 0], ['items.0.quantity', -1], ['items.0.quantity', 1.5], ['items.0.quantity', '2'], ['items.0.quantity', 1000],
    ['idempotency_key', 'not-a-uuid'], ['idempotency_key', null], ['draft_order_id', 'not-a-uuid'],
]);

test('paid events contain only branch scoped committed projections', function () {
    [$branch, $user, $product] = paymentFixture();
    $order = app(PayNowOrder::class)->execute($user, $branch, paymentPayload($product));
    $committed = new OrderCommitted($order);
    $ticket = new KitchenTicketCreated($order, $order->kitchenTicket);
    expect($committed->broadcastOn()[0]->name)->toBe('private-branch.'.$branch->id.'.pos');
    expect(collect($ticket->broadcastOn())->pluck('name')->all())->toBe([
        'private-branch.'.$branch->id.'.kitchen',
        'private-branch.'.$branch->id.'.pos',
    ]);
    expect($committed->broadcastWith())->toMatchArray(['event_type' => 'order.committed', 'branch_id' => $branch->id, 'order_id' => $order->id, 'payment_status' => 'paid', 'version' => 2]);
    expect($ticket->broadcastWith())->toMatchArray(['event_type' => 'kitchen.ticket_created', 'kitchen_ticket_id' => $order->kitchenTicket->id]);
    expect($committed->broadcastWith())->not->toHaveKeys(['total', 'customer_label', 'payments', 'idempotency_key']);
    expect($ticket->broadcastWith())->not->toHaveKeys(['total', 'customer_label', 'payments', 'idempotency_key']);
});

test('payment product and order item reads remain bounded as the cart grows', function (int $count) {
    $branch = Branch::factory()->create();
    $user = User::factory()->create();
    $user->roles()->attach(Role::query()->where('name', 'cashier')->sole());
    $user->branches()->attach($branch, ['is_active' => true]);
    StoreSession::factory()->for($branch)->create();
    $products = Product::factory()->count($count)->create(['default_price' => '1.00']);
    foreach ($products as $product) {
        BranchProduct::factory()->for($branch)->for($product)->create(['tracks_inventory' => false]);
    }
    $payload = [
        'order_type' => 'take_out',
        'payment_method' => 'cashless',
        'idempotency_key' => (string) Str::uuid(),
        'items' => $products->map(fn (Product $product): array => [
            'product_id' => $product->id,
            'quantity' => 1,
            'notes' => '',
            'modifiers' => [],
        ])->all(),
    ];

    DB::flushQueryLog();
    DB::enableQueryLog();
    app(PayNowOrder::class)->execute($user, $branch, $payload);
    $reads = collect(DB::getQueryLog())->filter(fn (array $query): bool => str_starts_with(strtolower($query['query']), 'select'));
    DB::disableQueryLog();

    /** Phase 16E adds three constant reads (size modifiers, Plan membership, recipes) for the Ingredient snapshot. */
    expect($reads->count())->toBeLessThanOrEqual(39)
        ->and($reads->filter(fn (array $query): bool => str_contains(strtolower($query['query']), 'from "products"'))->count())->toBeLessThanOrEqual(4)
        ->and($reads->filter(fn (array $query): bool => str_contains(strtolower($query['query']), 'from "order_items"'))->count())->toBeLessThanOrEqual(2);
})->with([1, 30, 100]);

test('private operational channels require the appropriate branch and permission', function (string $role, string $channel, bool $allowed) {
    [$branch, $user] = paymentFixture('235.00', $role);
    config(['broadcasting.default' => 'pusher', 'broadcasting.connections.pusher' => [
        'driver' => 'pusher', 'key' => 'test-key', 'secret' => 'test-secret', 'app_id' => 'test-app', 'options' => ['cluster' => 'ap1'],
    ]]);
    (static function (): void {
        require base_path('routes/channels.php');
    })();
    $response = $this->actingAs($user)->postJson('/broadcasting/auth', ['socket_id' => '123.456', 'channel_name' => 'private-branch.'.$branch->id.'.'.$channel]);
    $allowed ? $response->assertOk() : $response->assertForbidden();
    $other = Branch::factory()->create();
    $this->postJson('/broadcasting/auth', ['socket_id' => '123.456', 'channel_name' => 'private-branch.'.$other->id.'.'.$channel])->assertForbidden();
})->with([
    ['cashier', 'pos', true], ['cashier', 'kitchen', false], ['kitchen_staff', 'kitchen', true], ['kitchen_staff', 'pos', false], ['cashier_kitchen', 'kitchen', true],
]);
