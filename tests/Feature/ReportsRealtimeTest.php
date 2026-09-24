<?php

use App\Actions\Inventory\AdjustInventory;
use App\Actions\StoreSessions\CloseStoreSession;
use App\Actions\StoreSessions\OpenStoreSession;
use App\Actions\StoreSessions\RecordStoreSessionInventoryAdjustment;
use App\Enums\KitchenStatus;
use App\Events\ReportsChanged;
use App\Models\Branch;
use App\Models\Order;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\StoreCloseScenario;

beforeEach(function () {
    $this->seed(RbacSeeder::class);
});

function reportsChannelUser(string $role): User
{
    $user = User::factory()->create();
    $user->roles()->attach(Role::query()->where('name', $role)->sole());

    return $user;
}

function authorizeReportsChannel(): void
{
    config(['broadcasting.default' => 'pusher', 'broadcasting.connections.pusher' => [
        'driver' => 'pusher', 'key' => 'test-key', 'secret' => 'test-secret',
        'app_id' => 'test-app', 'options' => ['cluster' => 'mt1', 'useTLS' => true],
    ]]);
    (static function (): void {
        require base_path('routes/channels.php');
    })();
}

test('every change that moves business figures signals the reports channel for its branch', function () {
    Event::fake([ReportsChanged::class]);
    $scenario = StoreCloseScenario::create();
    $scenario->authorizeVoids();

    $scenario->payNow(1, 'cash');
    $later = $scenario->payLater(1);
    $scenario->settle($later, 'cashless');
    $scenario->edit($scenario->payNow(2, 'cash'), 1);
    $scenario->void($scenario->payNow(1, 'cash'));
    $scenario->kitchenStatus($later, KitchenStatus::Preparing);
    $scenario->expense('10.00', 'cash');

    $reasons = [];
    Event::assertDispatched(ReportsChanged::class, function (ReportsChanged $event) use ($scenario, &$reasons): bool {
        $reasons[] = $event->broadcastWith()['reason'];

        return $event->broadcastWith()['branch_id'] === $scenario->branch->id;
    });
    expect(array_values(array_unique($reasons)))->toContain('order.committed', 'order.updated', 'order.voided', 'kitchen.status_changed', 'store.expense_recorded');
});

test('each figure-changing action signals the reports channel on its own', function (Closure $act, string $reason) {
    $scenario = StoreCloseScenario::create();
    $later = $scenario->payLater(1);
    $scenario->done($later);
    Event::fake([ReportsChanged::class]);

    $act($scenario, $later);

    Event::assertDispatched(ReportsChanged::class, fn (ReportsChanged $event): bool => $event->broadcastWith()['reason'] === $reason
        && $event->broadcastWith()['branch_id'] === $scenario->branch->id);
})->with([
    'settlement' => [fn (StoreCloseScenario $scenario, Order $later) => $scenario->settle($later, 'cashless'), 'order.updated'],
    'store close' => [function (StoreCloseScenario $scenario, Order $later): void {
        $scenario->settle($later, 'cashless');
        $expected = $scenario->reconciliation()['expected'];
        app(CloseStoreSession::class)->execute($scenario->cashier, $scenario->branch, $scenario->closePayload($expected['cash'], $expected['cashless']));
    }, 'store.closed'],
    'manual stock adjustment' => [fn (StoreCloseScenario $scenario) => app(AdjustInventory::class)->execute(reportsChannelUser('owner'), $scenario->branch, $scenario->product, -3, 'Recount'), 'inventory.adjusted'],
    'store session stock adjustment' => [fn (StoreCloseScenario $scenario) => app(RecordStoreSessionInventoryAdjustment::class)->execute($scenario->cashier, $scenario->branch, [
        'idempotency_key' => (string) Str::uuid(), 'reason_code' => 'damaged', 'product_id' => $scenario->product->id, 'quantity' => 2,
    ]), 'inventory.adjusted'],
]);

test('opening a store session signals the reports channel', function () {
    Event::fake([ReportsChanged::class]);
    $scenario = StoreCloseScenario::create();
    $scenario->session->delete();

    app(OpenStoreSession::class)->execute($scenario->cashier, $scenario->branch, '500.00', '0.00');

    Event::assertDispatched(ReportsChanged::class, fn (ReportsChanged $event): bool => $event->broadcastWith()['reason'] === 'store.opened'
        && $event->broadcastWith()['branch_id'] === $scenario->branch->id);
});

test('the reports signal is privacy-minimal on the business-wide and its own branch channel', function () {
    $branch = Branch::factory()->create();
    $event = new ReportsChanged($branch->id, 'order.committed');

    expect(array_keys($event->broadcastWith()))->toBe(['event_id', 'event_type', 'branch_id', 'reason', 'occurred_at'])
        ->and($event->broadcastAs())->toBe('reports.changed')
        ->and(array_map(fn ($channel) => $channel->name, $event->broadcastOn()))
        ->toBe(['private-reports', 'private-branch.'.$branch->id.'.reports']);
});

test('owner and super admin may listen to the reports channel', function (string $role) {
    authorizeReportsChannel();

    $this->actingAs(reportsChannelUser($role))->postJson('/broadcasting/auth', [
        'socket_id' => '123.456',
        'channel_name' => 'private-reports',
    ])->assertOk();
})->with(['owner', 'super_admin']);

test('operational staff cannot listen to the reports channel', function (string $role) {
    authorizeReportsChannel();

    $this->actingAs(reportsChannelUser($role))->postJson('/broadcasting/auth', [
        'socket_id' => '123.456',
        'channel_name' => 'private-reports',
    ])->assertForbidden();
})->with(['cashier', 'kitchen_staff', 'cashier_kitchen']);

test('guests, inactive owners and owners without report access cannot listen to the reports channel', function () {
    authorizeReportsChannel();
    $payload = ['socket_id' => '123.456', 'channel_name' => 'private-reports'];

    $this->postJson('/broadcasting/auth', $payload)->assertForbidden();

    $inactive = reportsChannelUser('owner');
    $inactive->forceFill(['is_active' => false])->save();
    /** The active-user middleware signs an inactive account out before the channel is even checked. */
    $this->actingAs($inactive)->postJson('/broadcasting/auth', $payload)->assertUnauthorized();

    Role::query()->where('name', 'owner')->sole()->permissions()->detach(Permission::query()->where('name', 'reports.view')->sole());
    $this->actingAs(reportsChannelUser('owner'))->postJson('/broadcasting/auth', $payload)->assertForbidden();
});
