<?php

use App\Actions\StoreSessions\OpenStoreSession;
use App\Enums\KitchenStatus;
use App\Events\ReportsChanged;
use App\Models\Branch;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Facades\Event;
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

test('opening a store session signals the reports channel', function () {
    Event::fake([ReportsChanged::class]);
    $scenario = StoreCloseScenario::create();
    $scenario->session->delete();

    app(OpenStoreSession::class)->execute($scenario->cashier, $scenario->branch, '500.00', '0.00');

    Event::assertDispatched(ReportsChanged::class, fn (ReportsChanged $event): bool => $event->broadcastWith()['reason'] === 'store.opened'
        && $event->broadcastWith()['branch_id'] === $scenario->branch->id);
});

test('the reports signal is privacy-minimal and business-wide', function () {
    $branch = Branch::factory()->create();
    $event = new ReportsChanged($branch->id, 'order.committed');

    expect(array_keys($event->broadcastWith()))->toBe(['event_id', 'event_type', 'branch_id', 'reason', 'occurred_at'])
        ->and($event->broadcastAs())->toBe('reports.changed')
        ->and($event->broadcastOn()->name)->toBe('private-reports');
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
