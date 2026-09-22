<?php

use App\Enums\KitchenStatus;
use App\Events\DisplayOrdersChanged;
use App\Events\KitchenStatusChanged;
use App\Models\Branch;
use App\Models\KitchenTicket;
use App\Models\Order;
use App\Models\Role;
use App\Models\StoreSession;
use App\Models\User;
use App\Support\ActiveBranchContext;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    $this->seed(RbacSeeder::class);
});

function transitionUser(Branch $branch, string $role = 'kitchen_staff'): User
{
    $user = User::factory()->create();
    $user->roles()->attach(Role::query()->where('name', $role)->sole());
    $user->branches()->attach($branch, ['is_active' => true]);

    return $user;
}

/** @return array{Order, StoreSession} */
function transitionOrder(Branch $branch, KitchenStatus $status): array
{
    $session = StoreSession::factory()->for($branch)->create();
    $order = Order::factory()->for($branch)->for($session)->create([
        'commercial_status' => 'active',
        'payment_status' => 'paid',
        'payment_term' => 'immediate',
        'kitchen_status' => $status,
        'committed_at' => now()->subMinutes(5),
        'completed_at' => $status === KitchenStatus::Done ? now() : null,
        'version' => 7,
    ]);
    KitchenTicket::factory()->for($branch)->for($order)->create(['status' => $status]);

    return [$order, $session];
}

test('kitchen staff can move forward or one step backward and both records stay synchronized', function (KitchenStatus $from, KitchenStatus $to) {
    $branch = Branch::factory()->create();
    [$order] = transitionOrder($branch, $from);
    Event::fake([KitchenStatusChanged::class, DisplayOrdersChanged::class]);

    $response = $this->actingAs(transitionUser($branch))
        ->patch(route('orders.kitchen-status.update', $order), ['status' => $to->value]);

    $response->assertRedirect()
        ->assertInertiaFlash('kitchenTransition.order_id', $order->id)
        ->assertInertiaFlash('kitchenTransition.from', $from->value)
        ->assertInertiaFlash('kitchenTransition.to', $to->value)
        ->assertInertiaFlash('kitchenTransition.changed', true);

    expect($order->fresh()->kitchen_status)->toBe($to)
        ->and($order->fresh()->kitchenTicket->status)->toBe($to)
        ->and($order->fresh()->version)->toBe(8)
        ->and($order->fresh()->completed_at === null)->toBe($to !== KitchenStatus::Done);
    Event::assertDispatched(KitchenStatusChanged::class, 1);
    Event::assertDispatched(DisplayOrdersChanged::class, 1);
})->with([
    'forward one' => [KitchenStatus::Kitchen, KitchenStatus::Preparing],
    'forward jump' => [KitchenStatus::Kitchen, KitchenStatus::Done],
    'rollback one' => [KitchenStatus::Ready, KitchenStatus::Preparing],
    'done rollback' => [KitchenStatus::Done, KitchenStatus::Ready],
]);

test('duplicate status requests are idempotent for kitchen and cashier users', function (string $role, KitchenStatus $status) {
    $branch = Branch::factory()->create();
    [$order] = transitionOrder($branch, $status);
    Event::fake([KitchenStatusChanged::class, DisplayOrdersChanged::class]);

    $response = $this->actingAs(transitionUser($branch, $role))
        ->patch(route('orders.kitchen-status.update', $order), ['status' => $status->value]);

    $response->assertRedirect()
        ->assertInertiaFlash('kitchenTransition.order_id', $order->id)
        ->assertInertiaFlash('kitchenTransition.from', $status->value)
        ->assertInertiaFlash('kitchenTransition.to', $status->value)
        ->assertInertiaFlash('kitchenTransition.changed', false);

    expect($order->fresh()->version)->toBe(7);
    Event::assertNotDispatched(KitchenStatusChanged::class);
    Event::assertNotDispatched(DisplayOrdersChanged::class);
})->with([
    'kitchen duplicate preparing' => ['kitchen_staff', KitchenStatus::Preparing],
    'cashier duplicate done' => ['cashier', KitchenStatus::Done],
]);

test('invalid backward jumps are rejected without state or event changes', function () {
    $branch = Branch::factory()->create();
    [$order] = transitionOrder($branch, KitchenStatus::Done);
    Event::fake([KitchenStatusChanged::class, DisplayOrdersChanged::class]);

    $this->actingAs(transitionUser($branch))
        ->patch(route('orders.kitchen-status.update', $order), ['status' => 'preparing'])
        ->assertSessionHasErrors('status');

    expect($order->fresh()->kitchen_status)->toBe(KitchenStatus::Done)
        ->and($order->fresh()->kitchenTicket->status)->toBe(KitchenStatus::Done)
        ->and($order->fresh()->version)->toBe(7);
    Event::assertNotDispatched(KitchenStatusChanged::class);
    Event::assertNotDispatched(DisplayOrdersChanged::class);
});

test('cashiers may only mark ready orders done', function (KitchenStatus $from, KitchenStatus $to, bool $allowed) {
    $branch = Branch::factory()->create();
    [$order] = transitionOrder($branch, $from);
    $response = $this->actingAs(transitionUser($branch, 'cashier'))
        ->patch(route('orders.kitchen-status.update', $order), ['status' => $to->value]);

    $allowed ? $response->assertRedirect() : $response->assertForbidden();
    expect($order->fresh()->kitchen_status)->toBe($allowed ? $to : $from);
})->with([
    'ready to done' => [KitchenStatus::Ready, KitchenStatus::Done, true],
    'kitchen to ready' => [KitchenStatus::Kitchen, KitchenStatus::Ready, false],
    'ready rollback' => [KitchenStatus::Ready, KitchenStatus::Preparing, false],
]);

test('closed store foreign branch and inconsistent ticket are rejected without partial updates', function (string $failure) {
    $branch = Branch::factory()->create();
    [$order, $session] = transitionOrder($branch, KitchenStatus::Kitchen);
    $user = transitionUser($branch);

    if ($failure === 'closed') {
        $session->update(['status' => 'closed', 'closed_at' => now(), 'closed_by_user_id' => $user->id]);
    }

    if ($failure === 'mismatch') {
        $order->kitchenTicket->update(['status' => KitchenStatus::Preparing]);
    }

    if ($failure === 'foreign') {
        $other = Branch::factory()->create();
        $user->branches()->attach($other, ['is_active' => true]);
        $this->actingAs($user)->withSession([ActiveBranchContext::SESSION_KEY => $other->id])
            ->patch(route('orders.kitchen-status.update', $order), ['status' => 'ready'])
            ->assertNotFound();
    } else {
        $this->actingAs($user)
            ->patch(route('orders.kitchen-status.update', $order), ['status' => 'ready'])
            ->assertSessionHasErrors('status');
    }

    expect($order->fresh()->kitchen_status)->toBe(KitchenStatus::Kitchen)
        ->and($order->fresh()->version)->toBe(7);
})->with(['closed', 'mismatch', 'foreign']);

test('status input is required and constrained to persisted kitchen states', function (mixed $status) {
    $branch = Branch::factory()->create();
    [$order] = transitionOrder($branch, KitchenStatus::Kitchen);

    $this->actingAs(transitionUser($branch))
        ->patch(route('orders.kitchen-status.update', $order), ['status' => $status])
        ->assertSessionHasErrors('status');
})->with([null, '', 'not_sent', 'invalid']);

test('status event is compact private and branch scoped', function () {
    $branch = Branch::factory()->create();
    [$order] = transitionOrder($branch, KitchenStatus::Ready);
    $event = new KitchenStatusChanged($order, KitchenStatus::Preparing, KitchenStatus::Ready, now());

    expect(collect($event->broadcastOn())->pluck('name')->all())->toBe([
        'private-branch.'.$branch->id.'.kitchen',
        'private-branch.'.$branch->id.'.pos',
    ]);
    expect($event->broadcastWith())->toMatchArray([
        'event_type' => 'kitchen.status_changed',
        'branch_id' => $branch->id,
        'order_id' => $order->id,
        'from' => 'preparing',
        'to' => 'ready',
        'version' => 7,
    ])->not->toHaveKeys(['customer_label', 'items', 'total', 'payments']);
});

test('customer display event contains no order or customer details', function () {
    $branch = Branch::factory()->create();
    $event = new DisplayOrdersChanged($branch, now());

    expect($event->broadcastOn()->name)->toBe('private-branch.'.$branch->id.'.customer-display')
        ->and($event->broadcastWith())->toMatchArray([
            'event_type' => 'display.orders_changed',
            'branch_id' => $branch->id,
        ])->not->toHaveKeys([
            'entity_id',
            'order_id',
            'order_number',
            'customer_label',
            'items',
            'payment_status',
        ]);
});

test('JSON transitions return authoritative status version and changed without a redirect or flash', function () {
    $branch = Branch::factory()->create();
    [$order] = transitionOrder($branch, KitchenStatus::Preparing);
    Event::fake([KitchenStatusChanged::class, DisplayOrdersChanged::class]);
    $this->actingAs(transitionUser($branch));

    foreach ([true, false] as $changed) {
        $this->patchJson(route('orders.kitchen-status.update', $order), ['status' => 'ready'])
            ->assertOk()
            ->assertExactJson(['kitchenTransition' => [
                'order_id' => $order->id,
                'from' => $changed ? 'preparing' : 'ready',
                'to' => 'ready',
                'changed' => $changed,
                'version' => 8,
            ]]);
    }

    expect($order->fresh()->kitchen_status)->toBe(KitchenStatus::Ready)
        ->and($order->fresh()->kitchenTicket->status)->toBe(KitchenStatus::Ready)
        ->and($order->fresh()->version)->toBe(8);
    Event::assertDispatched(KitchenStatusChanged::class, 1);
    Event::assertDispatched(DisplayOrdersChanged::class, 1);
});

test('JSON failures preserve authorization branch and open session boundaries', function (string $failure, int $code) {
    $branch = Branch::factory()->create();
    [$order, $session] = transitionOrder($branch, KitchenStatus::Kitchen);
    $user = transitionUser($branch, $failure === 'role' ? 'cashier' : 'kitchen_staff');
    Event::fake([KitchenStatusChanged::class, DisplayOrdersChanged::class]);

    if (in_array($failure, ['closed', 'stale'], true)) {
        $session->update(['status' => 'closed', 'closed_at' => now(), 'closed_by_user_id' => $user->id]);
        if ($failure === 'stale') {
            StoreSession::factory()->for($branch)->create();
        }
    }
    if ($failure !== 'guest') {
        $this->actingAs($user);
    }
    if ($failure === 'foreign') {
        $other = Branch::factory()->create();
        $user->branches()->attach($other, ['is_active' => true]);
        $this->withSession([ActiveBranchContext::SESSION_KEY => $other->id]);
    }

    $response = $this->patchJson(route('orders.kitchen-status.update', $order), ['status' => 'ready']);
    match ($code) {
        401 => $response->assertUnauthorized(),
        403 => $response->assertForbidden(),
        404 => $response->assertNotFound(),
        422 => $response->assertUnprocessable()->assertJsonValidationErrors('status'),
    };
    expect($order->fresh()->kitchen_status)->toBe(KitchenStatus::Kitchen)
        ->and($order->fresh()->kitchenTicket->status)->toBe(KitchenStatus::Kitchen)
        ->and($order->fresh()->version)->toBe(7);
    Event::assertNotDispatched(KitchenStatusChanged::class);
    Event::assertNotDispatched(DisplayOrdersChanged::class);
})->with(['guest' => ['guest', 401], 'role' => ['role', 403], 'foreign' => ['foreign', 404], 'closed' => ['closed', 422], 'stale' => ['stale', 422]]);
