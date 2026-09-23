<?php

use App\Actions\Audit\AuditRecorder;
use App\Actions\Orders\ArchiveCustomerQrOrder;
use App\Actions\StoreSessions\CloseStoreSession;
use App\Enums\CommercialStatus;
use App\Enums\KitchenStatus;
use App\Enums\StoreSessionStatus;
use App\Events\CustomerCatalogChanged;
use App\Events\DisplayOrdersChanged;
use App\Events\QrOrderChanged;
use App\Events\StoreClosed;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Order;
use App\Models\Role;
use App\Models\StoreSession;
use App\Models\User;
use App\Support\ActiveBranchContext;
use App\Support\StoreState;
use Carbon\CarbonInterface;
use Database\Seeders\RbacSeeder;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\StoreCloseScenario;

beforeEach(function () {
    $this->seed(RbacSeeder::class);
});

/** @param array<string, mixed> $payload */
function closeStoreRequest(StoreCloseScenario $scenario, array $payload, ?User $user = null): TestResponse
{
    return test()->actingAs($user ?? $scenario->cashier)
        ->withSession([ActiveBranchContext::SESSION_KEY => $scenario->branch->id])
        ->postJson(route('store-sessions.close.store'), $payload);
}

function closeStoreUnchanged(StoreCloseScenario $scenario): void
{
    $session = $scenario->session->fresh();
    expect($session->status)->toBe(StoreSessionStatus::Open)
        ->and($session->closed_at)->toBeNull()
        ->and($session->closed_by_user_id)->toBeNull()
        ->and($session->closing_cash_amount)->toBeNull()
        ->and($session->expected_cash_amount)->toBeNull()
        ->and($session->cash_variance)->toBeNull()
        ->and($session->reconciliation_snapshot)->toBeNull();
    expect(AuditLog::query()->where('action', 'store.closed')->count())->toBe(0);
}

test('exact close archives unclaimed QR, persists the snapshot, audits once and broadcasts after commit', function () {
    $scenario = StoreCloseScenario::create('1000.00', '0.00');
    $scenario->done($scenario->payNow(5, 'cash'));
    $scenario->expense('100.00', 'cash');
    $qr = $scenario->submitQr();
    Event::fake([StoreClosed::class, CustomerCatalogChanged::class, DisplayOrdersChanged::class, QrOrderChanged::class]);
    $payload = $scenario->closePayload('1400.00', '0.00');

    $this->travelTo(now()->setMicrosecond(0));
    $response = closeStoreRequest($scenario, $payload)->assertOk()
        ->assertJsonPath('replayed', false)
        ->assertJsonPath('store_session.status', 'closed')
        ->assertJsonPath('store_session.expected_cash_amount', '1400.00')
        ->assertJsonPath('store_session.closing_cash_amount', '1400.00')
        ->assertJsonPath('store_session.cash_variance', '0.00')
        ->assertJsonPath('store_session.cashless_variance', '0.00')
        ->assertJsonPath('store_session.qr_archived_count', 1);

    $session = $scenario->session->fresh();
    expect($session->status)->toBe(StoreSessionStatus::Closed)
        ->and($session->closed_by_user_id)->toBe($scenario->cashier->id)
        ->and($session->closed_at?->toIso8601String())->toBe(now()->toIso8601String())
        ->and($session->expected_cashless_amount)->toBe('0.00')
        ->and($session->closing_note)->toBeNull()
        ->and($session->reconciliation_snapshot)->toMatchArray([
            'qr_archived_count' => 1,
            'blocker_counts' => ['outstanding' => 0, 'kitchen' => 0, 'loaded_qr' => 0, 'corrections' => 0],
            'variance_definition' => 'actual - expected',
        ])
        ->and($session->reconciliation_snapshot['expenses']['cash'])->toBe('100.00');
    $qr->refresh();
    expect($qr->commercial_status)->toBe(CommercialStatus::ArchivedUnclaimed)
        ->and($qr->archive_reason)->toBe('store_closed')
        ->and($qr->archived_at?->toIso8601String())->toBe(now()->toIso8601String())
        ->and($qr->qr_sequence)->toBe(1)
        ->and($qr->payments()->count())->toBe(0)
        ->and($qr->inventoryMovements()->count())->toBe(0)
        ->and($qr->kitchenTicket()->count())->toBe(0);
    $audit = AuditLog::query()->where('action', 'store.closed')->sole();
    expect($audit->module)->toBe('store_sessions')
        ->and($audit->auditable_id)->toBe($session->id)
        ->and($audit->after)->toMatchArray(['closing_cash_amount' => '1400.00', 'expected_cash_amount' => '1400.00', 'cash_variance' => '0.00'])
        ->and(data_get($audit->metadata, 'qr_archived_count'))->toBe(1)
        ->and(data_get($audit->metadata, 'reconciliation.opening.cash'))->toBe('1000.00');
    Event::assertDispatchedTimes(StoreClosed::class, 1);
    Event::assertDispatched(QrOrderChanged::class, fn (QrOrderChanged $event): bool => $event->broadcastAs() === 'qr.order_archived');
    Event::assertDispatched(CustomerCatalogChanged::class);
    Event::assertDispatched(DisplayOrdersChanged::class);
    expect(app(StoreState::class)->customerAvailable($scenario->branch->fresh()))->toBeFalse();
    $response->assertJsonMissingPath('store_session.reconciliation_snapshot');
});

test('store closed broadcast is compact and contains no reconciliation data', function () {
    $scenario = StoreCloseScenario::create();
    $session = $scenario->session->fresh();
    $session->forceFill(['closed_at' => now(), 'closed_by_user_id' => $scenario->cashier->id]);

    $event = new StoreClosed($session);

    expect(array_keys($event->broadcastWith()))->toBe(['event_id', 'event_type', 'branch_id', 'store_session_id', 'closed_at', 'closed_by_user_id', 'state', 'occurred_at'])
        ->and($event->broadcastWith()['state'])->toBe('closed')
        ->and(collect($event->broadcastOn())->map->name->all())->toBe([
            'private-branch.'.$scenario->branch->id.'.pos',
            'private-branch.'.$scenario->branch->id.'.kitchen',
            'private-branch.'.$scenario->branch->id.'.store-session',
        ])
        ->and($event)->toBeInstanceOf(ShouldDispatchAfterCommit::class);
});

test('shortage in either channel blocks close even with a note or an offsetting overage', function (string $cash, string $cashless, string $field, string $message) {
    $scenario = StoreCloseScenario::create('1000.00', '500.00');
    $qr = $scenario->submitQr();
    Event::fake([StoreClosed::class, QrOrderChanged::class]);

    closeStoreRequest($scenario, $scenario->closePayload($cash, $cashless, ['closing_note' => 'Counted twice']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$field => $message]);

    closeStoreUnchanged($scenario);
    expect($qr->fresh()->commercial_status)->toBe(CommercialStatus::Submitted);
    Event::assertNotDispatched(StoreClosed::class);
    Event::assertNotDispatched(QrOrderChanged::class);
})->with([
    'cash short' => ['900.00', '500.00', 'closing_cash_amount', 'Closing Cash is ₱100.00 short. Review the count or missing transactions before closing.'],
    'cashless short' => ['1000.00', '499.99', 'closing_cashless_amount', 'Closing Cashless is ₱0.01 short.'],
    'mixed shortage and overage' => ['900.00', '600.00', 'closing_cash_amount', 'Closing Cash is ₱100.00 short.'],
]);

test('overage requires a meaningful note and then closes', function () {
    $scenario = StoreCloseScenario::create('1000.00', '0.00');

    closeStoreRequest($scenario, $scenario->closePayload('1000.00', '25.50'))
        ->assertUnprocessable()->assertJsonValidationErrors(['closing_note' => 'Add an explanation for the overage before closing.']);
    closeStoreRequest($scenario, $scenario->closePayload('1000.00', '25.50', ['closing_note' => '  ok  ']))
        ->assertUnprocessable()->assertJsonValidationErrors(['closing_note' => 'at least 5 characters']);
    closeStoreUnchanged($scenario);

    closeStoreRequest($scenario, $scenario->closePayload('1000.00', '25.50', ['closing_note' => '  Tip left in GCash  ']))
        ->assertOk()->assertJsonPath('store_session.cashless_variance', '25.50')->assertJsonPath('store_session.closing_note', 'Tip left in GCash');
});

test('negative expected balance is persisted exactly and closes as an explained overage', function () {
    $scenario = StoreCloseScenario::create('100.00', '0.00');
    $scenario->expense('250.00', 'cashless');

    closeStoreRequest($scenario, $scenario->closePayload('100.00', '0.00', ['closing_note' => 'Supplier paid from owner GCash']))
        ->assertOk()
        ->assertJsonPath('store_session.expected_cashless_amount', '-250.00')
        ->assertJsonPath('store_session.cashless_variance', '250.00');
});

test('closing inputs are validated as exact non-negative money', function (array $overrides, string $field) {
    $scenario = StoreCloseScenario::create();

    closeStoreRequest($scenario, $scenario->closePayload('1000.00', '0.00', $overrides))
        ->assertUnprocessable()->assertJsonValidationErrors([$field]);
    closeStoreUnchanged($scenario);
})->with([
    'missing cash' => [['closing_cash_amount' => null], 'closing_cash_amount'],
    'negative cash' => [['closing_cash_amount' => '-1.00'], 'closing_cash_amount'],
    'three decimals' => [['closing_cashless_amount' => '1.005'], 'closing_cashless_amount'],
    'too large' => [['closing_cash_amount' => '1000000000000.00'], 'closing_cash_amount'],
    'client expected totals' => [['expected_cash_amount' => '1000.00'], 'expected_cash_amount'],
    'long note' => [['closing_note' => str_repeat('a', 1001)], 'closing_note'],
]);

test('blockers are recomputed at close and reject without partial effects', function () {
    $scenario = StoreCloseScenario::create();
    $unpaid = $scenario->payLater(1);
    $qr = $scenario->submitQr();

    closeStoreRequest($scenario, $scenario->closePayload('1000.00', '0.00'))
        ->assertUnprocessable()
        ->assertJsonValidationErrors([
            'outstanding' => 'Resolve all outstanding balances before closing.',
            'kitchen' => 'All committed Kitchen orders must be Done before closing.',
        ]);
    closeStoreUnchanged($scenario);
    expect($qr->fresh()->commercial_status)->toBe(CommercialStatus::Submitted);

    $scenario->settle($unpaid, 'cash', '100.00');
    closeStoreRequest($scenario, $scenario->closePayload('1100.00', '0.00'))
        ->assertUnprocessable()->assertJsonMissingValidationErrors(['outstanding'])->assertJsonValidationErrors(['kitchen']);

    $scenario->done($unpaid);
    closeStoreRequest($scenario, $scenario->closePayload('1100.00', '0.00'))->assertOk();
});

test('higher-total balance due blocks until the difference is settled', function () {
    $scenario = StoreCloseScenario::create();
    $order = $scenario->edit($scenario->payNow(1, 'cash'), 2);
    $scenario->done($order);

    closeStoreRequest($scenario, $scenario->closePayload('1100.00', '0.00'))
        ->assertUnprocessable()->assertJsonValidationErrors(['outstanding']);

    $scenario->settle($order, 'cashless');
    closeStoreRequest($scenario, $scenario->closePayload('1100.00', '100.00'))->assertOk();
});

test('voided unpaid pay later order no longer blocks close', function () {
    $scenario = StoreCloseScenario::create();
    $scenario->void($scenario->payLater(2));

    closeStoreRequest($scenario, $scenario->closePayload('1000.00', '0.00'))->assertOk();
});

test('a loaded QR claim blocks close and is never silently archived', function () {
    $scenario = StoreCloseScenario::create();
    $loaded = $scenario->submitQr();
    $this->actingAs($scenario->cashier)->withSession([ActiveBranchContext::SESSION_KEY => $scenario->branch->id])
        ->postJson(route('pos.qr-orders.load', $loaded))->assertOk();

    expect($scenario->preview()['blockers']['loaded_qr'])->toMatchArray(['count' => 1])
        ->and($scenario->preview()['blockers']['loaded_qr']['orders'][0])->toMatchArray(['qr_number' => 'QR-01', 'loaded_by' => $scenario->cashier->name]);
    closeStoreRequest($scenario, $scenario->closePayload('1000.00', '0.00'))
        ->assertUnprocessable()->assertJsonValidationErrors(['loaded_qr']);
    expect($loaded->fresh()->loaded_by_user_id)->toBe($scenario->cashier->id)
        ->and($loaded->fresh()->commercial_status)->toBe(CommercialStatus::Submitted);

    $this->postJson(route('pos.qr-orders.cancel-load', $loaded))->assertOk();
    closeStoreRequest($scenario, $scenario->closePayload('1000.00', '0.00'))->assertOk()->assertJsonPath('store_session.qr_archived_count', 1);
    expect($loaded->fresh()->archive_reason)->toBe('store_closed')->and($loaded->fresh()->loaded_by_user_id)->toBeNull();
});

test('exact retry recovers the same close without duplicate effects', function () {
    $scenario = StoreCloseScenario::create();
    $scenario->submitQr();
    Event::fake([StoreClosed::class, QrOrderChanged::class]);
    $payload = $scenario->closePayload('1000.00', '0.00');
    $first = closeStoreRequest($scenario, $payload)->assertOk()->json('store_session');
    $closedAt = $scenario->session->fresh()->closed_at;

    $this->travel(5)->minutes();
    closeStoreRequest($scenario, $payload)->assertOk()
        ->assertJsonPath('replayed', true)
        ->assertJsonPath('store_session.closed_at', $first['closed_at'])
        ->assertJsonPath('store_session.qr_archived_count', 1);

    expect($scenario->session->fresh()->closed_at?->equalTo($closedAt))->toBeTrue()
        ->and(AuditLog::query()->where('action', 'store.closed')->count())->toBe(1);
    Event::assertDispatchedTimes(StoreClosed::class, 1);
    Event::assertDispatchedTimes(QrOrderChanged::class, 1);
});

test('changed retry and a different attempt after close conflict without mutation', function () {
    $scenario = StoreCloseScenario::create('1000.00', '0.00');
    $payload = $scenario->closePayload('1000.00', '0.00');
    Event::fake([StoreClosed::class]);
    closeStoreRequest($scenario, $payload)->assertOk();
    $snapshot = $scenario->session->fresh()->only(['closing_cash_amount', 'closing_note', 'closed_at', 'updated_at']);

    closeStoreRequest($scenario, [...$payload, 'closing_cash_amount' => '1200.00', 'closing_note' => 'Recount'])->assertConflict();
    closeStoreRequest($scenario, $scenario->closePayload('1000.00', '0.00'))
        ->assertConflict()->assertJsonPath('message', 'The Store is already closed. Refresh to see the current Store state.');
    closeStoreRequest($scenario, $payload, $scenario->user('cashier'))->assertConflict();

    expect($scenario->session->fresh()->only(['closing_cash_amount', 'closing_note', 'closed_at', 'updated_at']))->toEqual($snapshot)
        ->and(AuditLog::query()->where('action', 'store.closed')->count())->toBe(1);
    Event::assertDispatchedTimes(StoreClosed::class, 1);
});

test('a stale Store Session identity is rejected', function () {
    $scenario = StoreCloseScenario::create();

    closeStoreRequest($scenario, $scenario->closePayload('1000.00', '0.00', ['store_session_id' => (string) Str::uuid()]))
        ->assertConflict()->assertJsonPath('message', 'The Store Session changed. Refresh the closing summary and try again.');
    closeStoreUnchanged($scenario);
});

test('only an active assigned cashier with store permission may close or preview', function (string $case) {
    $scenario = StoreCloseScenario::create();
    $user = match ($case) {
        'kitchen' => $scenario->kitchen,
        'owner' => tap(User::factory()->create(), fn (User $owner) => $owner->roles()->attach(Role::query()->where('name', 'owner')->sole())),
        'super admin' => tap(User::factory()->create(), fn (User $admin) => $admin->roles()->attach(Role::query()->where('name', 'super_admin')->sole())),
        'other branch' => $scenario->user('cashier', Branch::factory()->create()),
        'inactive assignment' => $scenario->user('cashier', assignmentActive: false),
        'inactive user' => $scenario->user('cashier', active: false),
    };

    /**
     * Existing branch-context and active-user middleware deny before Close Store authorization runs. A cashier from
     * another branch is resolved to their own assigned branch, which has no open session to preview or close.
     */
    $previewStatus = ['other branch' => 404, 'inactive assignment' => 302, 'inactive user' => 401][$case] ?? 403;
    $closeStatus = ['other branch' => 409, 'inactive assignment' => 302, 'inactive user' => 401][$case] ?? 403;

    $this->actingAs($user)->withSession([ActiveBranchContext::SESSION_KEY => $scenario->branch->id])
        ->getJson(route('store-sessions.close.show'))->assertStatus($previewStatus);
    closeStoreRequest($scenario, $scenario->closePayload('1000.00', '0.00'), $user)->assertStatus($closeStatus);

    closeStoreUnchanged($scenario);
})->with(['kitchen', 'owner', 'super admin', 'other branch', 'inactive assignment', 'inactive user']);

test('preview is read-only and withholds reconciliation while blockers remain', function () {
    $scenario = StoreCloseScenario::create('1000.00', '0.00');
    $order = $scenario->payNow(2, 'cash');
    $request = fn () => $this->actingAs($scenario->cashier)->withSession([ActiveBranchContext::SESSION_KEY => $scenario->branch->id])
        ->getJson(route('store-sessions.close.show'));

    $request()->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonPath('ready', false)
        ->assertJsonPath('reconciliation', null)
        ->assertJsonPath('blockers.kitchen.count', 1)
        ->assertJsonPath('store_session.id', $scenario->session->id);

    $scenario->done($order);
    $request()->assertOk()
        ->assertJsonPath('ready', true)
        ->assertJsonPath('reconciliation.expected.cash', '1200.00')
        ->assertJsonPath('reconciliation.sales.cash', '200.00');
    closeStoreUnchanged($scenario);
});

test('close rolls back every effect when a later step fails', function (string $failure) {
    $scenario = StoreCloseScenario::create();
    $qrOrders = [$scenario->submitQr(), $scenario->submitQr()];
    Event::fake([StoreClosed::class, QrOrderChanged::class, CustomerCatalogChanged::class]);
    match ($failure) {
        'qr archive' => app()->instance(ArchiveCustomerQrOrder::class, new class extends ArchiveCustomerQrOrder
        {
            private int $calls = 0;

            public function execute(Order $requested, string $reason = 'stale_30_minutes', ?CarbonInterface $archivedAt = null): bool
            {
                if (++$this->calls === 2) {
                    throw new RuntimeException('Injected archive failure');
                }

                return parent::execute($requested, $reason, $archivedAt);
            }
        }),
        'session update' => StoreSession::updating(fn () => throw new RuntimeException('Injected session failure')),
        'audit' => app()->instance(AuditRecorder::class, new class extends AuditRecorder
        {
            public function record(...$arguments): AuditLog
            {
                throw new RuntimeException('Injected audit failure');
            }
        }),
    };

    expect(fn () => app(CloseStoreSession::class)->execute(
        $scenario->cashier, $scenario->branch, $scenario->closePayload('1000.00', '0.00'),
    ))->toThrow(RuntimeException::class);

    closeStoreUnchanged($scenario);
    foreach ($qrOrders as $qr) {
        expect($qr->fresh()->commercial_status)->toBe(CommercialStatus::Submitted)->and($qr->fresh()->archived_at)->toBeNull();
    }
    Event::assertNotDispatched(StoreClosed::class);
    Event::assertNotDispatched(QrOrderChanged::class);
    Event::assertNotDispatched(CustomerCatalogChanged::class);
})->with(['qr archive', 'session update', 'audit']);

test('a closed Store Session is immutable', function () {
    $scenario = StoreCloseScenario::create();
    closeStoreRequest($scenario, $scenario->closePayload('1000.00', '0.00'))->assertOk();
    $session = $scenario->session->fresh();

    expect(fn () => $session->update(['closing_note' => 'Rewrite']))->toThrow(LogicException::class)
        ->and(fn () => $session->update(['status' => StoreSessionStatus::Open]))->toThrow(LogicException::class);
});

test('operational mutations are rejected by the backend after close', function () {
    $scenario = StoreCloseScenario::create();
    $unpaidLater = $scenario->payLater(1);
    $paid = $scenario->payNow(1, 'cash');
    $scenario->settle($unpaidLater, 'cash', '100.00');
    $scenario->done($unpaidLater, $paid);
    $qr = $scenario->submitQr();
    closeStoreRequest($scenario, $scenario->closePayload('1200.00', '0.00'))->assertOk();
    $http = $this->actingAs($scenario->cashier)->withSession([ActiveBranchContext::SESSION_KEY => $scenario->branch->id]);

    $http->postJson(route('pos.payments.store'), [
        'order_type' => 'take_out', 'customer_label' => null, 'items' => $scenario->items(1),
        'idempotency_key' => (string) Str::uuid(), 'payment_method' => 'cash', 'cash_received' => '100.00',
    ])->assertUnprocessable();
    $http->postJson(route('pos.orders.reservations.store'), ['order_type' => 'take_out'])->assertUnprocessable();
    $http->postJson(route('pos.orders.store'), ['order_type' => 'take_out', 'items' => $scenario->items(1)])->assertUnprocessable();
    $http->patchJson(route('pos.transactions.update', $paid), [
        'idempotency_key' => (string) Str::uuid(), 'expected_version' => $paid->fresh()->version, 'order_type' => 'take_out',
        'items' => [['existing_order_item_id' => $paid->items()->value('id'), 'product_id' => $scenario->product->id, 'quantity' => 2, 'modifiers' => []]],
    ])->assertUnprocessable();
    $http->postJson(route('pos.transactions.void', $paid), [
        'reason_code' => 'wrong_item', 'authorization_pin' => '1234', 'idempotency_key' => (string) Str::uuid(), 'expected_version' => $paid->fresh()->version,
    ])->assertUnprocessable();
    $http->postJson(route('store-session-expenses.store'), [
        'idempotency_key' => (string) Str::uuid(), 'description' => 'Ice', 'amount' => '10.00', 'payment_source' => 'cash', 'restock' => '0',
    ])->assertUnprocessable();
    $http->postJson(route('pos.qr-orders.restore', $qr))->assertConflict();
    expect(fn () => $scenario->kitchenStatus($paid, KitchenStatus::Ready))->toThrow(ValidationException::class)
        ->and(fn () => $scenario->submitQr())->toThrow(ValidationException::class);

    expect($scenario->session->fresh()->status)->toBe(StoreSessionStatus::Closed)
        ->and(StoreSession::query()->where('status', 'open')->count())->toBe(0)
        ->and($qr->fresh()->commercial_status)->toBe(CommercialStatus::ArchivedUnclaimed);
});

test('a cashier with kitchen access may close while a guest may not', function () {
    $scenario = StoreCloseScenario::create();

    $this->postJson(route('store-sessions.close.store'), $scenario->closePayload('1000.00', '0.00'))->assertUnauthorized();
    $this->getJson(route('store-sessions.close.show'))->assertUnauthorized();
    closeStoreUnchanged($scenario);

    closeStoreRequest($scenario, $scenario->closePayload('1000.00', '0.00'), $scenario->user('cashier_kitchen'))->assertOk();
});

test('closing one branch never reads or mutates another branch or an earlier session', function () {
    $main = StoreCloseScenario::create('1000.00', '0.00');
    $main->done($main->payNow(2, 'cash'));
    $priorSession = StoreSession::factory()->closed()->for($main->branch)->create();
    $priorQr = Order::factory()->for($main->branch)->create([
        'store_session_id' => $priorSession->id, 'source' => 'customer_qr', 'commercial_status' => 'submitted', 'submitted_at' => now()->subDay(),
    ]);
    $qave = StoreCloseScenario::create('500.00', '500.00');
    $qave->payLater(1);
    $qave->kitchenStatus($qave->payNow(1, 'cash'), KitchenStatus::Preparing);
    $qave->edit($qave->payNow(3, 'cashless'), 2);
    $qave->void($qave->payNow(1, 'split', '40.00'));
    $qave->expense('70.00', 'cash');
    $qaveQr = $qave->submitQr();
    Event::fake([StoreClosed::class]);

    closeStoreRequest($main, $main->closePayload('1200.00', '0.00'))->assertOk()
        ->assertJsonPath('store_session.expected_cash_amount', '1200.00')
        ->assertJsonPath('store_session.expected_cashless_amount', '0.00')
        ->assertJsonPath('store_session.qr_archived_count', 0);

    expect($qave->session->fresh()->status)->toBe(StoreSessionStatus::Open)
        ->and($qaveQr->fresh()->commercial_status)->toBe(CommercialStatus::Submitted)
        ->and($priorQr->fresh()->commercial_status)->toBe(CommercialStatus::Submitted)
        ->and($priorQr->fresh()->archive_reason)->toBeNull()
        ->and($qave->preview()['blockers']['outstanding']['count'])->toBe(1);
    Event::assertDispatched(StoreClosed::class, fn (StoreClosed $event): bool => $event->broadcastWith()['branch_id'] === $main->branch->id);
});

test('a new split correction with a recorded source reconciles and closes', function () {
    $scenario = StoreCloseScenario::create('1000.00', '0.00');
    $order = $scenario->edit($scenario->payNow(5, 'split', '200.00'), 3, ['refund_cash_amount' => '150.00']);
    $scenario->done($order);

    closeStoreRequest($scenario, $scenario->closePayload('1150.00', '150.00'))->assertOk()
        ->assertJsonPath('store_session.cash_variance', '0.00')
        ->assertJsonPath('store_session.cashless_variance', '0.00');
});

test('after close the kiosk shows Store Closed, submission is blocked and committed history is retained', function () {
    $scenario = StoreCloseScenario::create('1000.00', '0.00');
    $paid = $scenario->payNow(1, 'cash');
    $scenario->done($paid);
    closeStoreRequest($scenario, $scenario->closePayload('1100.00', '0.00'))->assertOk();
    /** Shared props persist on the Inertia singleton across requests inside one test; a real kiosk request starts clean. */
    Inertia::flushShared();

    $this->get(route('kiosk.show', ['branch' => $scenario->branch->kiosk_code]))->assertInertia(fn (Assert $page) => $page
        ->where('store', ['status' => 'closed', 'is_open' => false])
        ->missing('storeContext'));
    expect(fn () => $scenario->submitQr())->toThrow(ValidationException::class);
    expect($paid->fresh()->commercial_status)->toBe(CommercialStatus::Active)
        ->and($paid->fresh()->payments()->sole()->amount)->toBe('100.00');
});

test('after close the cashier workspace is Store Closed with Open Store available for the next session', function () {
    $scenario = StoreCloseScenario::create();
    closeStoreRequest($scenario, $scenario->closePayload('1000.00', '0.00'))->assertOk();

    $this->actingAs($scenario->cashier)->withSession([ActiveBranchContext::SESSION_KEY => $scenario->branch->id])
        ->get(route('workspaces.cashier'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('storeContext', ['status' => 'closed', 'isOpen' => false, 'branchId' => $scenario->branch->id])
            ->where('store', ['branchStatus' => 'active', 'canOpen' => true]));
    $this->post(route('store-sessions.open'), ['opening_cash_amount' => '500.00', 'opening_cashless_amount' => '0.00'])
        ->assertRedirect(route('workspaces.cashier'));
    expect(StoreSession::query()->where('branch_id', $scenario->branch->id)->where('status', 'open')->sole()->id)
        ->not->toBe($scenario->session->id);
});
