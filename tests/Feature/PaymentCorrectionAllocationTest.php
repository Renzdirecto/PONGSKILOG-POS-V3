<?php

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\OrderAdjustment;
use App\Support\ActiveBranchContext;
use Database\Seeders\RbacSeeder;
use Illuminate\Validation\ValidationException;
use Tests\StoreCloseScenario;

beforeEach(function () {
    $this->seed(RbacSeeder::class);
});

function allocationHistoricalCorrection(StoreCloseScenario $scenario, string $cashlessPaid = '200.00', string $amount = '150.00'): OrderAdjustment
{
    $order = $scenario->payNow(5, 'split', $cashlessPaid);
    $scenario->done($order);
    /** Mirrors a pre-allocation lower-total edit: the committed total already reflects the correction. */
    $lowered = number_format(500 - (float) $amount, 2, '.', '');
    $order->update(['subtotal' => $lowered, 'total' => $lowered, 'original_total' => '500.00']);

    return OrderAdjustment::factory()->create([
        'order_id' => $order->id, 'branch_id' => $scenario->branch->id, 'store_session_id' => $scenario->session->id,
        'amount' => $amount, 'cash_amount' => null, 'cashless_amount' => null,
    ]);
}

test('a mixed-method lower-total edit requires and records an explicit Cash/Cashless refund source', function () {
    $scenario = StoreCloseScenario::create('1000.00', '1000.00');
    $order = $scenario->payNow(5, 'split', '200.00');

    expect(fn () => $scenario->edit($order, 2))->toThrow(ValidationException::class, 'Choose how much of the ₱300.00 correction was returned in Cash.')
        ->and(fn () => $scenario->edit($order, 2, ['refund_cash_amount' => '50.00']))->toThrow(ValidationException::class, 'between ₱100.00 and ₱300.00')
        ->and(OrderAdjustment::query()->count())->toBe(0);

    $scenario->edit($order, 2, ['refund_cash_amount' => '120.00']);

    expect(OrderAdjustment::query()->sole()->only(['amount', 'cash_amount', 'cashless_amount']))
        ->toBe(['amount' => '300.00', 'cash_amount' => '120.00', 'cashless_amount' => '180.00'])
        ->and($scenario->reconciliation()['corrections'])->toBe(['cash' => '120.00', 'cashless' => '180.00', 'count' => 1])
        ->and($scenario->reconciliation()['expected'])->toBe(['cash' => '1180.00', 'cashless' => '1020.00']);
});

test('a single-method correction is attributed automatically and rejects a conflicting source', function () {
    $scenario = StoreCloseScenario::create();
    $order = $scenario->payNow(3, 'cashless');

    expect(fn () => $scenario->edit($order, 1, ['refund_cash_amount' => '10.00']))
        ->toThrow(ValidationException::class, 'The Cash portion of this correction must be ₱0.00.');
    $scenario->edit($order, 1);

    expect(OrderAdjustment::query()->sole()->only(['cash_amount', 'cashless_amount']))->toBe(['cash_amount' => '0.00', 'cashless_amount' => '200.00']);
});

test('transaction detail exposes net refundable amounts per payment method', function () {
    $scenario = StoreCloseScenario::create();
    $order = $scenario->payNow(5, 'split', '200.00');
    $scenario->edit($order, 4, ['refund_cash_amount' => '30.00']);

    $this->actingAs($scenario->cashier)->withSession([ActiveBranchContext::SESSION_KEY => $scenario->branch->id])
        ->getJson(route('pos.transactions.show', $order))->assertOk()
        ->assertJsonPath('transaction.refund_sources', ['cash_available' => '270.00', 'cashless_available' => '130.00'])
        ->assertJsonPath('transaction.adjustments.0.cash_amount', '30.00')
        ->assertJsonPath('transaction.adjustments.0.cashless_amount', '70.00');
});

test('a historical ambiguous correction is allocated once from the cashier answer and unblocks reconciliation', function () {
    $scenario = StoreCloseScenario::create('1000.00', '0.00');
    $adjustment = allocationHistoricalCorrection($scenario);
    $http = $this->actingAs($scenario->cashier)->withSession([ActiveBranchContext::SESSION_KEY => $scenario->branch->id]);
    $url = route('pos.order-adjustments.allocation.store', $adjustment);

    $http->postJson($url, ['cash_amount' => '160.00'])->assertUnprocessable()->assertJsonValidationErrors(['cash_amount' => 'between ₱0.00 and ₱150.00']);
    $http->postJson($url, ['cash_amount' => '100.00'])->assertOk()
        ->assertJsonPath('adjustment.cash_amount', '100.00')->assertJsonPath('adjustment.cashless_amount', '50.00');
    $http->postJson($url, ['cash_amount' => '100.00'])->assertOk();
    $http->postJson($url, ['cash_amount' => '90.00'])->assertConflict();

    expect(AuditLog::query()->where('action', 'payment_correction.allocated')->sole()->after)
        ->toBe(['cash_amount' => '100.00', 'cashless_amount' => '50.00'])
        ->and($scenario->preview()['ready'])->toBeTrue()
        ->and($scenario->reconciliation()['expected'])->toBe(['cash' => '1200.00', 'cashless' => '150.00']);
});

test('allocation is refused for deterministic, voided, other-branch or closed-session corrections', function () {
    $scenario = StoreCloseScenario::create();
    $http = fn () => $this->actingAs($scenario->cashier)->withSession([ActiveBranchContext::SESSION_KEY => $scenario->branch->id]);
    $single = $scenario->payNow(3, 'cash');
    $deterministic = OrderAdjustment::factory()->create([
        'order_id' => $single->id, 'branch_id' => $scenario->branch->id, 'store_session_id' => $scenario->session->id, 'cash_amount' => null, 'cashless_amount' => null,
    ]);
    $http()->postJson(route('pos.order-adjustments.allocation.store', $deterministic), ['cash_amount' => '10.00'])
        ->assertUnprocessable()->assertJsonValidationErrors(['cash_amount' => 'single payment method']);

    $voided = allocationHistoricalCorrection($scenario);
    $scenario->void($voided->order);
    $http()->postJson(route('pos.order-adjustments.allocation.store', $voided), ['cash_amount' => '10.00'])
        ->assertUnprocessable()->assertJsonValidationErrors(['cash_amount' => 'voided']);

    $foreign = StoreCloseScenario::create();
    $foreignAdjustment = allocationHistoricalCorrection($foreign);
    $http()->postJson(route('pos.order-adjustments.allocation.store', $foreignAdjustment), ['cash_amount' => '10.00'])->assertNotFound();

    $pending = allocationHistoricalCorrection($scenario);
    $scenario->session->update(['status' => 'closed', 'closed_at' => now(), 'closed_by_user_id' => $scenario->cashier->id]);
    $http()->postJson(route('pos.order-adjustments.allocation.store', $pending), ['cash_amount' => '10.00'])
        ->assertUnprocessable()->assertJsonValidationErrors(['store']);
    expect($pending->fresh()->cash_amount)->toBeNull()->and(Branch::query()->count())->toBe(2);
});

test('payment corrections are append-only apart from a single missing allocation', function () {
    $scenario = StoreCloseScenario::create();
    $adjustment = allocationHistoricalCorrection($scenario);

    expect(fn () => $adjustment->fresh()->update(['amount' => '1.00']))->toThrow(LogicException::class)
        ->and(fn () => $adjustment->fresh()->update(['cash_amount' => '10.00']))->toThrow(LogicException::class)
        ->and(fn () => $adjustment->fresh()->delete())->toThrow(LogicException::class);

    $adjustment->fresh()->update(['cash_amount' => '100.00', 'cashless_amount' => '50.00']);

    expect(fn () => $adjustment->fresh()->update(['cash_amount' => '50.00', 'cashless_amount' => '100.00']))->toThrow(LogicException::class)
        ->and($adjustment->fresh()->only(['amount', 'cash_amount', 'cashless_amount']))->toBe(['amount' => '150.00', 'cash_amount' => '100.00', 'cashless_amount' => '50.00']);
});
