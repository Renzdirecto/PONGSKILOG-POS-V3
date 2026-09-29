<?php

use App\Actions\Orders\TransitionKitchenOrder;
use App\Enums\KitchenStatus;
use App\Events\PickupNotifyChanged;
use App\Jobs\SendPickupBuzz;
use App\Models\Branch;
use App\Models\Order;
use App\Models\OrderPickupToken;
use App\Models\PickupPushSubscription;
use App\Models\Product;
use App\Models\PushSubscription;
use App\Models\Role;
use App\Models\StoreSession;
use App\Models\User;
use App\Support\PickupBuzzPolicy;
use App\Support\PickupPushGateway;
use App\Support\PickupTokens;
use App\Support\PushGateway;
use App\Support\PushMessage;
use Database\Factories\PushSubscriptionFactory;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

/** Records customer pickup deliveries; answers with scripted statuses (or throws) in order, then 201. */
class PickupGatewayDouble implements PickupPushGateway
{
    /** @var list<array{subscription: int, payload: array<string, mixed>, topic: string}> */
    public array $deliveries = [];

    /** @var list<int|null|Throwable> */
    public array $responses = [];

    public function deliverPickup(PickupPushSubscription $subscription, string $payload, string $topic): ?int
    {
        $this->deliveries[] = ['subscription' => (int) $subscription->id, 'payload' => json_decode($payload, true), 'topic' => $topic];
        $next = $this->responses === [] ? 201 : array_shift($this->responses);
        if ($next instanceof Throwable) {
            throw $next;
        }

        return $next;
    }
}

/** Staff pushes are recorded separately, so a Buzz can be shown never to reach them. */
class StaffGatewayDouble implements PushGateway
{
    /** @var list<string> */
    public array $types = [];

    public function deliver(PushSubscription $subscription, PushMessage $message, string $payload): ?int
    {
        $this->types[] = $message->type->value;

        return 201;
    }
}

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    config(['services.webpush' => [
        'subject' => 'mailto:push@example.com',
        'public_key' => PushSubscriptionFactory::BROWSER_PUBLIC_KEY,
        'private_key' => 'server-only-vapid-private-key',
    ]]);
    $this->pickupGateway = new PickupGatewayDouble;
    $this->staffGateway = new StaffGatewayDouble;
    app()->instance(PickupPushGateway::class, $this->pickupGateway);
    app()->instance(PushGateway::class, $this->staffGateway);
    $this->branch = Branch::factory()->create(['name' => 'Main Branch']);
    StoreSession::factory()->for($this->branch)->create();
    $this->cashier = buzzStaff($this->branch, 'cashier');
    $this->kitchen = buzzStaff($this->branch, 'kitchen_staff');
    $this->product = Product::factory()->soldAt($this->branch)->create();
});

function buzzStaff(Branch $branch, string $role): User
{
    $user = User::factory()->create();
    $user->roles()->attach(Role::query()->where('name', $role)->sole());
    $user->branches()->attach($branch, ['is_active' => true]);

    return $user;
}

/** A committed order through the real Pay Now flow, moved by the Kitchen to the given status. */
function buzzOrder(User $cashier, User $kitchen, Branch $branch, Product $product, string $type = 'take_out', KitchenStatus $status = KitchenStatus::Ready): Order
{
    $id = test()->actingAs($cashier)->postJson(route('pos.payments.store'), [
        'order_type' => $type, 'customer_label' => '', 'items' => [['product_id' => $product->id, 'quantity' => 1, 'notes' => '', 'modifiers' => []]],
        'payment_method' => 'cash', 'cash_received' => '500.00', 'cashless_amount' => null, 'idempotency_key' => (string) Str::uuid(),
    ])->assertOk()->json('receipt.id');
    $order = Order::query()->findOrFail($id);
    if ($status !== KitchenStatus::Kitchen) {
        app(TransitionKitchenOrder::class)->execute($kitchen, $branch, $order, $status);
    }

    return $order->fresh();
}

/** The customer's explicit opt-in on the pickup page. */
function buzzSubscribe(Order $order): string
{
    $token = app(PickupTokens::class)->rawToken(OrderPickupToken::query()->where('order_id', $order->id)->sole());
    test()->postJson(route('pickup.subscription.store', $token), [
        'endpoint' => 'https://fcm.googleapis.com/fcm/send/pickup-'.Str::random(32),
        'keys' => ['p256dh' => PushSubscriptionFactory::BROWSER_PUBLIC_KEY, 'auth' => PushSubscriptionFactory::BROWSER_AUTH_SECRET],
    ])->assertOk();

    return $token;
}

function buzzPress(User $cashier, Order $order, ?string $key = null): TestResponse
{
    return test()->actingAs($cashier)->postJson(route('pos.orders.buzz', $order), ['idempotency_key' => $key ?? (string) Str::uuid()]);
}

/** @return array<string, mixed>|null */
function buzzReadyState(User $cashier, Order $order): ?array
{
    $ready = collect(test()->actingAs($cashier)->get(route('workspaces.cashier'))->assertOk()->inertiaProps('readyOrders'));

    return $ready->firstWhere('id', $order->id)['buzz'] ?? null;
}

test('Buzz Customer is offered only for a Ready Take Out order whose customer turned notifications on', function () {
    $takeOut = buzzOrder($this->cashier, $this->kitchen, $this->branch, $this->product);
    $dineIn = buzzOrder($this->cashier, $this->kitchen, $this->branch, $this->product, 'dine_in');
    $preparing = buzzOrder($this->cashier, $this->kitchen, $this->branch, $this->product, 'take_out', KitchenStatus::Preparing);

    /** Scanning the QR (opening the page) alone never makes the order buzz-capable. */
    $token = app(PickupTokens::class)->rawToken(OrderPickupToken::query()->where('order_id', $takeOut->id)->sole());
    $this->get(route('pickup.show', $token))->assertOk();
    expect(buzzReadyState($this->cashier, $takeOut))->toBeNull()
        ->and(buzzReadyState($this->cashier, $dineIn))->toBeNull();
    buzzPress($this->cashier, $takeOut)->assertUnprocessable()->assertJsonValidationErrors('buzz');

    buzzSubscribe($takeOut);
    buzzSubscribe($preparing);

    expect(buzzReadyState($this->cashier, $takeOut))->toBe(['count' => 0, 'max' => 5, 'remaining' => 5, 'last_buzzed_at' => null, 'cooldown_until' => null]);
    buzzPress($this->cashier, $preparing)->assertUnprocessable()->assertJsonValidationErrors('buzz');
    buzzPress($this->cashier, $dineIn)->assertUnprocessable()->assertJsonValidationErrors('buzz');
    expect($this->pickupGateway->deliveries)->toBe([]);
});

test('a Buzz sends one pickup push to that customer and never changes the order', function () {
    Event::fake([PickupNotifyChanged::class]);
    $order = buzzOrder($this->cashier, $this->kitchen, $this->branch, $this->product);
    $token = buzzSubscribe($order);
    $before = $order->fresh()->only(['kitchen_status', 'commercial_status', 'payment_status', 'version', 'ready_at', 'completed_at']);

    $response = buzzPress($this->cashier, $order)->assertOk()
        ->assertJsonPath('buzz.replayed', false)->assertJsonPath('buzz.count', 1)
        ->assertJsonPath('buzz.remaining', 4)->assertJsonPath('buzz.max', 5);

    expect($response->json('buzz.cooldown_until'))->not->toBeNull()
        ->and($this->pickupGateway->deliveries)->toHaveCount(1)
        ->and($this->pickupGateway->deliveries[0]['payload'])->toBe([
            'v' => 1, 'type' => 'pickup.ready', 'tag' => 'pickup-ready:'.OrderPickupToken::query()->sole()->id,
            'order_number' => $order->order_number, 'url' => '/pickup/'.$token,
        ])
        ->and($this->staffGateway->types)->not->toContain('pickup.ready')
        ->and($order->fresh()->only(array_keys($before)))->toEqual($before);
    Event::assertDispatched(PickupNotifyChanged::class, fn (PickupNotifyChanged $event): bool => $event->broadcastWith()['order_id'] === $order->id
        && ! str_contains(json_encode($event->broadcastWith()), $token));
});

test('the server enforces a 5-second cooldown and at most 5 Buzzes per order', function () {
    $order = buzzOrder($this->cashier, $this->kitchen, $this->branch, $this->product);
    buzzSubscribe($order);
    $otherCashier = buzzStaff($this->branch, 'cashier_kitchen');

    buzzPress($this->cashier, $order)->assertOk();
    buzzPress($otherCashier, $order)->assertTooManyRequests()->assertHeader('Retry-After');
    $this->travel(PickupBuzzPolicy::COOLDOWN_SECONDS - 1)->seconds();
    buzzPress($this->cashier, $order)->assertTooManyRequests();

    foreach (range(2, 5) as $attempt) {
        $this->travel(PickupBuzzPolicy::COOLDOWN_SECONDS + 1)->seconds();
        buzzPress($attempt % 2 === 0 ? $otherCashier : $this->cashier, $order)->assertOk()->assertJsonPath('buzz.count', $attempt);
    }
    $this->travel(PickupBuzzPolicy::COOLDOWN_SECONDS + 1)->seconds();
    buzzPress($this->cashier, $order)->assertUnprocessable()->assertJsonValidationErrors('buzz');

    expect($this->pickupGateway->deliveries)->toHaveCount(5)
        ->and(OrderPickupToken::query()->sole()->buzz_count)->toBe(5)
        ->and(buzzReadyState($this->cashier, $order)['remaining'])->toBe(0);
});

test('repeating the same Buzz request never sends twice', function () {
    $order = buzzOrder($this->cashier, $this->kitchen, $this->branch, $this->product);
    buzzSubscribe($order);
    $key = (string) Str::uuid();

    buzzPress($this->cashier, $order, $key)->assertOk()->assertJsonPath('buzz.replayed', false);
    buzzPress($this->cashier, $order, strtoupper($key))->assertOk()->assertJsonPath('buzz.replayed', true)->assertJsonPath('buzz.count', 1);

    expect($this->pickupGateway->deliveries)->toHaveCount(1);
});

test('Buzz requires POS access at the order Branch and a signed-in account', function () {
    $order = buzzOrder($this->cashier, $this->kitchen, $this->branch, $this->product);
    buzzSubscribe($order);
    $otherBranch = Branch::factory()->create();

    buzzPress($this->kitchen, $order)->assertForbidden();
    buzzPress(buzzStaff($otherBranch, 'cashier'), $order)->assertNotFound();
    auth()->logout();
    $this->postJson(route('pos.orders.buzz', $order), ['idempotency_key' => (string) Str::uuid()])->assertUnauthorized();
    $this->actingAs($this->cashier)->postJson(route('pos.orders.buzz', $order), ['idempotency_key' => 'not-a-uuid'])->assertUnprocessable();

    expect($this->pickupGateway->deliveries)->toBe([])
        ->and(OrderPickupToken::query()->sole()->buzz_count)->toBe(0);
});

test('a rejected push endpoint is removed and the order stops being buzz-capable', function () {
    Event::fake([PickupNotifyChanged::class]);
    $order = buzzOrder($this->cashier, $this->kitchen, $this->branch, $this->product);
    buzzSubscribe($order);
    $this->pickupGateway->responses = [410];
    $before = $order->fresh()->only(['kitchen_status', 'commercial_status', 'version']);

    buzzPress($this->cashier, $order)->assertOk();

    expect(PickupPushSubscription::query()->count())->toBe(0)
        ->and(buzzReadyState($this->cashier, $order))->toBeNull()
        ->and($order->fresh()->only(array_keys($before)))->toBe($before);
    Event::assertDispatchedTimes(PickupNotifyChanged::class, 3);
    $this->travel(PickupBuzzPolicy::COOLDOWN_SECONDS + 1)->seconds();
    buzzPress($this->cashier, $order)->assertUnprocessable();
    expect($this->pickupGateway->deliveries)->toHaveCount(1);
});

test('a transient push failure is retried once and never changes the order', function () {
    $order = buzzOrder($this->cashier, $this->kitchen, $this->branch, $this->product);
    buzzSubscribe($order);
    $this->pickupGateway->responses = [new RuntimeException('push service down'), 503];
    $before = $order->fresh()->only(['kitchen_status', 'commercial_status', 'version']);

    buzzPress($this->cashier, $order)->assertOk();

    expect($this->pickupGateway->deliveries)->toHaveCount(SendPickupBuzz::MAX_ATTEMPTS)
        ->and(PickupPushSubscription::query()->count())->toBe(1)
        ->and($order->fresh()->only(array_keys($before)))->toBe($before);
});

test('Kitchen Ready alone never buzzes the customer and staff Ready pushes never reach pickup subscriptions', function () {
    PushSubscription::factory()->for($this->cashier)->create();
    $order = buzzOrder($this->cashier, $this->kitchen, $this->branch, $this->product, 'take_out', KitchenStatus::Preparing);
    buzzSubscribe($order);

    app(TransitionKitchenOrder::class)->execute($this->kitchen, $this->branch, $order->fresh(), KitchenStatus::Ready);

    expect($this->staffGateway->types)->toContain('order.ready')
        ->and($this->pickupGateway->deliveries)->toBe([]);
});

test('the Ready notification modal renders Buzz state from the server only', function () {
    $order = buzzOrder($this->cashier, $this->kitchen, $this->branch, $this->product);
    buzzSubscribe($order);
    buzzPress($this->cashier, $order)->assertOk();

    $this->actingAs($this->cashier)->get(route('workspaces.cashier'))->assertInertia(fn (Assert $page) => $page
        ->where('readyOrders.0.id', $order->id)
        ->where('readyOrders.0.buzz.count', 1)
        ->where('readyOrders.0.buzz.remaining', 4)
        ->has('readyOrders.0.buzz.cooldown_until'));
});
