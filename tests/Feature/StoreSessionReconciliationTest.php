<?php

use App\Actions\StoreSessions\RecordStoreSessionExpense;
use App\Enums\KitchenStatus;
use App\Enums\PaymentMethod;
use App\Models\Branch;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\OrderAdjustment;
use App\Models\Payment;
use App\Models\StoreSession;
use App\Models\StoreSessionExpense;
use App\Support\StoreSessionReconciliation;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Str;
use Tests\StoreCloseScenario;

beforeEach(function () {
    $this->seed(RbacSeeder::class);
});

test('cash session expects opening plus cash payments minus cash expenses', function () {
    $scenario = StoreCloseScenario::create('1000.00', '0.00');
    $scenario->payNow(5, 'cash');
    $scenario->expense('100.00', 'cash');

    $result = $scenario->reconciliation();

    expect($result['opening'])->toBe(['cash' => '1000.00', 'cashless' => '0.00'])
        ->and($result['sales'])->toMatchArray(['cash' => '500.00', 'cashless' => '0.00', 'total' => '500.00', 'count' => 1])
        ->and($result['expenses'])->toBe(['cash' => '100.00', 'cashless' => '0.00', 'count' => 1])
        ->and($result['expected'])->toBe(['cash' => '1400.00', 'cashless' => '0.00']);
});

test('cashless session expects opening plus cashless payments minus cashless expenses', function () {
    $scenario = StoreCloseScenario::create('0.00', '200.00');
    $scenario->payNow(8, 'cashless');
    $scenario->expense('150.00', 'cashless');

    expect($scenario->reconciliation()['expected'])->toBe(['cash' => '0.00', 'cashless' => '850.00']);
});

test('split legs count once in each channel and the split breakdown is explanatory only', function () {
    $scenario = StoreCloseScenario::create('100.00', '50.00');
    $scenario->payNow(5, 'split', '200.00');

    $result = $scenario->reconciliation();

    expect($result['sales'])->toMatchArray(['cash' => '300.00', 'cashless' => '200.00', 'total' => '500.00', 'count' => 2])
        ->and($result['split'])->toBe(['cash' => '300.00', 'cashless' => '200.00', 'count' => 1])
        ->and($result['expected'])->toBe(['cash' => '400.00', 'cashless' => '250.00']);
});

test('cash drawer effect uses the payment amount rather than tendered cash', function () {
    $scenario = StoreCloseScenario::create('1000.00');
    $scenario->payNow(5, 'cash', overrides: ['cash_received' => '1000.00']);

    expect(Payment::query()->sole()->amount_received)->toBe('1000.00')
        ->and($scenario->reconciliation()['sales']['cash'])->toBe('500.00')
        ->and($scenario->reconciliation()['expected']['cash'])->toBe('1500.00');
});

test('voided orders reverse exactly their own payments in each channel', function (string $method) {
    $scenario = StoreCloseScenario::create('1000.00', '500.00');
    $kept = $scenario->payNow(1, 'cash');
    $voided = $scenario->payNow(4, $method, $method === 'split' ? '150.00' : null);
    $scenario->void($voided);

    $result = $scenario->reconciliation();
    $voidedCash = $method === 'cash' ? '400.00' : ($method === 'split' ? '250.00' : '0.00');
    $voidedCashless = $method === 'cashless' ? '400.00' : ($method === 'split' ? '150.00' : '0.00');

    expect(Payment::query()->where('order_id', $voided->id)->count())->toBe($method === 'split' ? 2 : 1)
        ->and($result['voids'])->toBe(['cash' => $voidedCash, 'cashless' => $voidedCashless, 'count' => 1])
        ->and($result['expected'])->toBe(['cash' => '1100.00', 'cashless' => '500.00'])
        ->and($kept->fresh()->commercial_status->value)->toBe('active');
})->with(['cash', 'cashless', 'split']);

test('settled pay later and edited higher orders net to zero after void', function () {
    $scenario = StoreCloseScenario::create('1000.00', '0.00');
    $payLater = $scenario->payLater(2);
    $scenario->settle($payLater, 'cashless');
    $higher = $scenario->payNow(1, 'cash');
    $higher = $scenario->edit($higher, 3);
    $scenario->settle($higher, 'split', '100.00', '100.00');
    $scenario->void($payLater);
    $scenario->void($higher);

    $result = $scenario->reconciliation();

    expect($result['sales'])->toMatchArray(['cash' => '200.00', 'cashless' => '300.00'])
        ->and($result['voids'])->toBe(['cash' => '200.00', 'cashless' => '300.00', 'count' => 2])
        ->and($result['expected'])->toBe(['cash' => '1000.00', 'cashless' => '0.00']);
});

test('a lower-total correction followed by void never double-subtracts the correction', function () {
    $scenario = StoreCloseScenario::create('1000.00', '0.00');
    $order = $scenario->payNow(5, 'cash');
    $order = $scenario->edit($order, 4);
    expect(OrderAdjustment::query()->sole()->only(['amount', 'cash_amount', 'cashless_amount']))
        ->toBe(['amount' => '100.00', 'cash_amount' => '100.00', 'cashless_amount' => '0.00'])
        ->and($scenario->reconciliation()['expected']['cash'])->toBe('1400.00');

    $scenario->void($order);
    $result = $scenario->reconciliation();

    expect($result['sales']['cash'])->toBe('500.00')
        ->and($result['corrections'])->toBe(['cash' => '0.00', 'cashless' => '0.00', 'count' => 0])
        ->and($result['voids']['cash'])->toBe('500.00')
        ->and($result['expected'])->toBe(['cash' => '1000.00', 'cashless' => '0.00']);
});

test('single-method historical corrections are attributed deterministically without an allocation', function (string $method) {
    $scenario = StoreCloseScenario::create('1000.00', '1000.00');
    $order = $scenario->payNow(3, $method);
    OrderAdjustment::factory()->create([
        'order_id' => $order->id, 'branch_id' => $scenario->branch->id, 'store_session_id' => $scenario->session->id,
        'amount' => '80.00', 'cash_amount' => null, 'cashless_amount' => null,
    ]);

    $result = $scenario->reconciliation();

    expect($result['corrections'])->toBe([
        'cash' => $method === 'cash' ? '80.00' : '0.00',
        'cashless' => $method === 'cashless' ? '80.00' : '0.00',
        'count' => 1,
    ])->and($result['expected'])->toBe([
        'cash' => $method === 'cash' ? '1220.00' : '1000.00',
        'cashless' => $method === 'cashless' ? '1220.00' : '1000.00',
    ])->and($scenario->preview()['blockers']['corrections']['count'])->toBe(0);
})->with(['cash', 'cashless']);

test('historical mixed-method corrections block reconciliation instead of guessing a split', function () {
    $scenario = StoreCloseScenario::create();
    $order = $scenario->payNow(5, 'split', '200.00');
    $scenario->done($order);
    OrderAdjustment::factory()->create([
        'order_id' => $order->id, 'branch_id' => $scenario->branch->id, 'store_session_id' => $scenario->session->id,
        'amount' => '50.00', 'cash_amount' => null, 'cashless_amount' => null,
    ]);

    $preview = $scenario->preview();

    expect($preview['ready'])->toBeFalse()
        ->and($preview['reconciliation'])->toBeNull()
        ->and($preview['blockers']['corrections']['count'])->toBe(1)
        ->and($preview['blockers']['corrections']['items'][0])->toMatchArray([
            'order_number' => $order->order_number, 'amount' => '50.00', 'min_cash' => '0.00', 'max_cash' => '50.00',
        ])
        ->and(fn () => $scenario->reconciliation())->toThrow(LogicException::class);
});

test('mixed-method corrections on a voided order need no allocation', function () {
    $scenario = StoreCloseScenario::create('1000.00', '1000.00');
    $order = $scenario->payNow(5, 'split', '200.00');
    OrderAdjustment::factory()->create([
        'order_id' => $order->id, 'branch_id' => $scenario->branch->id, 'store_session_id' => $scenario->session->id,
        'amount' => '50.00', 'cash_amount' => null, 'cashless_amount' => null,
    ]);
    $scenario->void($order);

    $preview = $scenario->preview();

    expect($preview['ready'])->toBeTrue()
        ->and($preview['reconciliation']['expected'])->toBe(['cash' => '1000.00', 'cashless' => '1000.00']);
});

test('expected balances keep exact negative math instead of clamping', function () {
    $scenario = StoreCloseScenario::create('100.00', '0.00');
    $scenario->expense('250.00', 'cashless');

    expect($scenario->reconciliation()['expected'])->toBe(['cash' => '100.00', 'cashless' => '-250.00']);
});

test('only the current session and branch contribute to reconciliation', function () {
    $scenario = StoreCloseScenario::create('0.00', '0.00');
    $scenario->payNow(1, 'cash');
    $prior = StoreSession::factory()->closed()->for($scenario->branch)->create();
    $otherBranch = Branch::factory()->create();
    $otherSession = StoreSession::factory()->for($otherBranch)->create();
    foreach ([[$scenario->branch, $prior], [$otherBranch, $otherSession]] as [$branch, $session]) {
        $order = Order::factory()->for($branch)->create([
            'store_session_id' => $session->id, 'commercial_status' => 'active', 'payment_status' => 'paid',
            'committed_at' => now(), 'total' => '999.00', 'subtotal' => '999.00',
        ]);
        Payment::factory()->create([
            'order_id' => $order->id, 'branch_id' => $branch->id, 'store_session_id' => $session->id,
            'method' => PaymentMethod::Cash, 'amount' => '999.00', 'idempotency_key' => Str::uuid().':cash',
        ]);
        StoreSessionExpense::factory()->create(['branch_id' => $branch->id, 'store_session_id' => $session->id, 'amount' => '77.00', 'payment_source' => 'cash']);
    }

    expect($scenario->reconciliation()['expected'])->toBe(['cash' => '100.00', 'cashless' => '0.00']);
});

test('pre-close blockers use authoritative outstanding money and kitchen state', function () {
    $scenario = StoreCloseScenario::create();
    $unpaid = $scenario->payLater(1);
    $partial = $scenario->edit($scenario->payNow(1, 'cash'), 2);
    $voidedUnpaid = $scenario->void($scenario->payLater(3));
    $preparing = $scenario->payNow(1, 'cash');
    $scenario->kitchenStatus($preparing, KitchenStatus::Preparing);
    $scenario->submitQr();

    $preview = $scenario->preview();

    expect($preview['ready'])->toBeFalse()
        ->and($preview['reconciliation'])->toBeNull()
        ->and($preview['blockers']['outstanding']['count'])->toBe(2)
        ->and($preview['blockers']['outstanding']['total'])->toBe('200.00')
        ->and(collect($preview['blockers']['outstanding']['orders'])->pluck('payment_status', 'order_number')->sortKeys()->all())
        ->toBe(collect([$unpaid->order_number => 'unpaid', $partial->order_number => 'partial'])->sortKeys()->all())
        ->and($partial->payment_term->value)->toBe('immediate')
        ->and(collect($preview['blockers']['kitchen']['orders'])->pluck('kitchen_status', 'order_number')->sortKeys()->all())
        ->toBe(collect([$unpaid->order_number => 'kitchen', $partial->order_number => 'kitchen', $preparing->order_number => 'preparing'])->sortKeys()->all())
        ->and(collect($preview['blockers']['kitchen']['orders'])->pluck('order_number'))->not->toContain($voidedUnpaid->order_number)
        ->and($preview['qr']['unclaimed_count'])->toBe(1);
});

test('expenses reduce only their own channel once, including an inventory-linked restock', function () {
    $scenario = StoreCloseScenario::create('1000.00', '1000.00');
    $scenario->expense('100.00', 'cash');
    $scenario->expense('200.00', 'cashless');
    $restock = app(RecordStoreSessionExpense::class)->execute($scenario->cashier, $scenario->branch, [
        'idempotency_key' => (string) Str::uuid(), 'description' => 'Yakult restock', 'amount' => '50.00',
        'payment_source' => 'cash', 'note' => null, 'restock' => true, 'product_id' => $scenario->product->id, 'quantity' => 6,
    ]);

    $result = $scenario->reconciliation();

    expect(InventoryMovement::query()->where('store_session_expense_id', $restock->id)->sole()->quantity_delta)->toBe(6)
        ->and($result['expenses'])->toBe(['cash' => '150.00', 'cashless' => '200.00', 'count' => 3])
        ->and($result['sales']['total'])->toBe('0.00')
        ->and($result['expected'])->toBe(['cash' => '850.00', 'cashless' => '800.00']);
});

test('a single-method lower-total correction nets from its own channel', function (string $method) {
    $scenario = StoreCloseScenario::create('1000.00', '1000.00');
    $scenario->edit($scenario->payNow(5, $method), 4);

    $result = $scenario->reconciliation();

    expect($result['sales'][$method])->toBe('500.00')
        ->and($result['corrections'][$method])->toBe('100.00')
        ->and($result['expected'][$method])->toBe('1400.00')
        ->and($result['expected'][$method === 'cash' ? 'cashless' : 'cash'])->toBe('1000.00');
})->with(['cash', 'cashless']);

test('only committed non-voided Kitchen work that is not Done blocks close', function (?KitchenStatus $status, bool $voided, int $blocked) {
    $scenario = StoreCloseScenario::create();
    $order = $scenario->payNow(1, 'cash');
    if ($status !== null) {
        $scenario->kitchenStatus($order, $status);
    }
    if ($voided) {
        $scenario->void($order);
    }

    expect($scenario->preview()['blockers']['kitchen']['count'])->toBe($blocked);
})->with([
    'kitchen' => [null, false, 1],
    'preparing' => [KitchenStatus::Preparing, false, 1],
    'ready' => [KitchenStatus::Ready, false, 1],
    'done' => [KitchenStatus::Done, false, 0],
    'voided while in kitchen' => [null, true, 0],
]);

test('batched session flows match each session reconciliation and ignore rows from another Branch', function () {
    $first = StoreCloseScenario::create('1000.00', '0.00');
    $first->edit($first->payNow(5, 'cash'), 4);
    $first->payNow(3, 'split', '100.00');
    $first->void($first->payNow(2, 'cashless'));
    $first->expense('40.00', 'cashless');
    $second = StoreCloseScenario::create('500.00', '200.00');
    $second->payNow(7, 'cashless');
    $second->expense('25.00', 'cash');
    Payment::factory()->for($second->payNow(1, 'cash'))->create([
        'branch_id' => $first->branch->id, 'store_session_id' => $second->session->id, 'amount' => '999.00',
    ]);
    $service = app(StoreSessionReconciliation::class);

    $flows = $service->flows([$first->session->id => $first->branch->id, $second->session->id => $second->branch->id]);

    foreach ([$first, $second] as $scenario) {
        $session = $scenario->session->fresh();
        $single = $service->calculate($scenario->branch, $session);
        $batched = $flows[$session->id];

        expect(array_diff_key($single, ['opening' => true, 'expected' => true, 'corrections' => true]))
            ->toBe(array_diff_key($batched, ['corrections' => true]))
            ->and($batched['corrections'])->toBe([
                'cash' => $single['corrections']['cash'], 'cashless' => $single['corrections']['cashless'],
                'unallocated' => 0, 'count' => $single['corrections']['count'],
            ])
            ->and($service->expected($service->opening($session), $batched))->toBe($single['expected']);
    }
    expect($flows[$second->session->id]['sales']['cash'])->toBe(10000);
});
