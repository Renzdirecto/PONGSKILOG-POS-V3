<?php

use App\Actions\Orders\TransitionKitchenOrder;
use App\Enums\KitchenStatus;
use App\Enums\ModifierSelectionType;
use App\Events\OrderUpdated;
use App\Events\PickupNotifyChanged;
use App\Events\PickupStatusChanged;
use App\Models\Branch;
use App\Models\BranchInventory;
use App\Models\BranchProduct;
use App\Models\KitchenTicket;
use App\Models\ModifierGroup;
use App\Models\ModifierOption;
use App\Models\Order;
use App\Models\OrderPickupToken;
use App\Models\PickupPushSubscription;
use App\Models\Product;
use App\Models\Role;
use App\Models\StoreSession;
use App\Models\User;
use App\Support\CustomerQrProjection;
use App\Support\PickupTokens;
use Database\Factories\PushSubscriptionFactory;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    config(['services.webpush' => [
        'subject' => 'mailto:push@example.com',
        'public_key' => PushSubscriptionFactory::BROWSER_PUBLIC_KEY,
        'private_key' => 'server-only-vapid-private-key',
    ]]);
    $this->branch = Branch::factory()->create(['name' => 'Main Branch', 'code' => 'MAIN']);
    $this->session = StoreSession::factory()->for($this->branch)->create();
    $this->cashier = User::factory()->create();
    $this->cashier->roles()->attach(Role::query()->where('name', 'cashier')->sole());
    $this->cashier->branches()->attach($this->branch, ['is_active' => true]);
    $this->product = Product::factory()->soldAt($this->branch)->create(['default_price' => '120.00']);
});

function pickupPay(User $cashier, Product $product, string $type = 'take_out', ?string $key = null): TestResponse
{
    return test()->actingAs($cashier)->postJson(route('pos.payments.store'), [
        'order_type' => $type, 'customer_label' => 'Private Juan', 'items' => [['product_id' => $product->id, 'quantity' => 1, 'notes' => 'no onions', 'modifiers' => []]],
        'payment_method' => 'cash', 'cash_received' => '500.00', 'cashless_amount' => null, 'idempotency_key' => $key ?? (string) Str::uuid(),
    ]);
}

/** @return array{order: Order, token: string} */
function pickupTakeOutOrder(User $cashier, Product $product): array
{
    $order = Order::query()->findOrFail(pickupPay($cashier, $product)->assertOk()->json('receipt.id'));
    $token = app(PickupTokens::class)->rawToken(OrderPickupToken::query()->where('order_id', $order->id)->sole());

    return ['order' => $order, 'token' => $token];
}

function pickupSubscriptionBody(string $suffix = 'a'): array
{
    return [
        'endpoint' => 'https://fcm.googleapis.com/fcm/send/pickup-'.$suffix.Str::random(24),
        'keys' => ['p256dh' => PushSubscriptionFactory::BROWSER_PUBLIC_KEY, 'auth' => PushSubscriptionFactory::BROWSER_AUTH_SECRET],
        'content_encoding' => 'aes128gcm',
    ];
}

test('every committed Take Out order gets exactly one strong pickup token and Dine In gets none', function () {
    $key = (string) Str::uuid();
    $takeOut = pickupPay($this->cashier, $this->product, 'take_out', $key)->assertOk();
    pickupPay($this->cashier, $this->product, 'take_out', $key)->assertOk();
    pickupPay($this->cashier, $this->product, 'dine_in')->assertOk();

    $pickup = OrderPickupToken::query()->sole();
    $raw = app(PickupTokens::class)->rawToken($pickup);
    expect($pickup->order_id)->toBe($takeOut->json('receipt.id'))
        ->and($pickup->branch_id)->toBe($this->branch->id)
        ->and($raw)->toMatch('/\A[A-Za-z0-9_-]{43}\z/')
        ->and(strlen(base64_decode(strtr($raw, '-_', '+/'), true) ?: ''))->toBe(32)
        ->and($pickup->token_hash)->toBe(hash('sha256', $raw))
        ->and($pickup->getRawOriginal('token_ciphertext'))->not->toContain($raw)
        ->and($pickup->channel_key)->not->toContain($raw)
        ->and($pickup->expires_at->equalTo($pickup->order->committed_at->addHours(PickupTokens::LIFETIME_HOURS)))->toBeTrue()
        ->and(json_encode($takeOut->json()))->not->toContain($raw);
    $this->assertDatabaseMissing('audit_logs', ['metadata' => $raw]);
});

test('Pay Later Take Out orders get a token and a failed commit gets none', function () {
    $reservation = $this->actingAs($this->cashier)->postJson(route('pos.orders.reservations.store'), ['order_type' => 'take_out'])->assertOk()->json('order');
    $this->actingAs($this->cashier)->postJson(route('pos.orders.pay-later.store', $reservation['id']), [
        'idempotency_key' => (string) Str::uuid(), 'order_type' => 'take_out', 'customer_label' => '', 'branch_table_id' => null,
        'items' => [['product_id' => $this->product->id, 'quantity' => 1, 'notes' => '', 'modifiers' => []]],
    ])->assertOk();
    expect(OrderPickupToken::query()->where('order_id', $reservation['id'])->exists())->toBeTrue();

    $tracked = Product::factory()->soldAt($this->branch)->create();
    BranchProduct::query()->where('product_id', $tracked->id)->update(['tracks_inventory' => true]);
    BranchInventory::factory()->for($this->branch)->for($tracked)->create(['on_hand' => 0]);
    pickupPay($this->cashier, $tracked)->assertUnprocessable();
    expect(OrderPickupToken::query()->count())->toBe(1);
});

test('the public pickup page shows the order number, status, both queue positions and a customer-safe summary only', function () {
    $this->travel(-3)->minutes();
    $ahead = pickupTakeOutOrder($this->cashier, $this->product)['order'];
    pickupPay($this->cashier, $this->product, 'dine_in')->assertOk();
    $this->travelBack();
    ['order' => $order, 'token' => $token] = pickupTakeOutOrder($this->cashier, $this->product);

    $status = $this->getJson(route('pickup.status', $token))->assertOk();
    expect($status->json('pickup'))->toBe([
        'order_number' => $order->order_number,
        'order_type' => 'take_out',
        'status' => 'preparing',
        'queue_position' => 2,
        'overall_position' => 3,
        'summary' => [
            'items' => [['name' => $this->product->name, 'quantity' => 1, 'details' => [], 'instructions' => [], 'amount' => '120.00']],
            'subtotal' => '120.00',
            'total' => '120.00',
        ],
        'receipt_available' => true,
        'links' => ['facebook' => null, 'website' => null, 'maps' => null],
        'notifications' => ['available' => true, 'public_key' => PushSubscriptionFactory::BROWSER_PUBLIC_KEY, 'subscribed' => false],
        'channel' => 'pickup.'.OrderPickupToken::query()->where('order_id', $order->id)->value('channel_key'),
    ]);
    expect($status->getContent())->not->toContain('Private Juan')->not->toContain('no onions')
        ->not->toContain($order->id)->not->toContain($this->branch->id)->not->toContain('Main Branch')->not->toContain($this->cashier->name)
        ->and($status->headers->get('Cache-Control'))->toContain('no-store')
        ->and($status->headers->get('X-Robots-Tag'))->toContain('noindex')
        ->and($status->headers->get('Referrer-Policy'))->toBe('no-referrer');

    $page = $this->get(route('pickup.show', $token))->assertOk();
    $page->assertInertia(fn (Assert $inertia) => $inertia->component('pickup')
        ->where('pickup.status', 'preparing')->where('problem', null)
        ->missing('auth')->missing('branchContext')->missing('storeContext'));
    expect($page->getContent())->not->toContain('manifest.webmanifest');
    expect($ahead->id)->not->toBe($order->id);
});

test('a raw order id, a token hash, a guessed token or an expired token never opens a pickup page', function () {
    ['order' => $order, 'token' => $token] = pickupTakeOutOrder($this->cashier, $this->product);
    $pickup = OrderPickupToken::query()->sole();

    $this->get('/pickup/'.$order->id)->assertNotFound();
    $this->get('/pickup/'.$pickup->token_hash)->assertNotFound();
    $this->getJson('/pickup/'.$order->id.'/status')->assertNotFound();
    $guess = substr($token, 0, 42).($token[42] === 'A' ? 'B' : 'A');
    $this->getJson(route('pickup.status', $guess))->assertNotFound();
    $this->get(route('pickup.show', $guess))->assertNotFound()
        ->assertInertia(fn (Assert $inertia) => $inertia->component('pickup')->where('problem', 'invalid')->where('token', null)->where('pickup', null));

    $this->travel(PickupTokens::LIFETIME_HOURS + 1)->hours();
    $this->getJson(route('pickup.status', $token))->assertStatus(410);
    $this->get(route('pickup.show', $token))->assertStatus(410)
        ->assertInertia(fn (Assert $inertia) => $inertia->where('problem', 'expired')->where('pickup', null));
});

test('the pickup status follows the Kitchen lifecycle and a non Take Out order has no pickup page', function () {
    ['order' => $order, 'token' => $token] = pickupTakeOutOrder($this->cashier, $this->product);
    $kitchen = User::factory()->create();
    $kitchen->roles()->attach(Role::query()->where('name', 'kitchen_staff')->sole());
    $kitchen->branches()->attach($this->branch, ['is_active' => true]);
    $transition = fn (KitchenStatus $status) => app(TransitionKitchenOrder::class)->execute($kitchen, $this->branch, $order->fresh(), $status);

    $transition(KitchenStatus::Preparing);
    $this->getJson(route('pickup.status', $token))->assertJsonPath('pickup.status', 'preparing')
        ->assertJsonPath('pickup.queue_position', 1)->assertJsonPath('pickup.overall_position', 1);
    $transition(KitchenStatus::Ready);
    $this->getJson(route('pickup.status', $token))->assertJsonPath('pickup.status', 'ready')
        ->assertJsonPath('pickup.queue_position', null)->assertJsonPath('pickup.overall_position', null);
    $transition(KitchenStatus::Done);
    $this->getJson(route('pickup.status', $token))->assertJsonPath('pickup.status', 'done')
        ->assertJsonPath('pickup.notifications.available', false);

    $order->fresh()->forceFill(['commercial_status' => 'voided', 'voided_at' => now()])->save();
    $this->getJson(route('pickup.status', $token))->assertJsonPath('pickup.status', 'unavailable');
    $order->fresh()->forceFill(['order_type' => 'dine_in'])->save();
    $this->getJson(route('pickup.status', $token))->assertNotFound();
});

test('queue changes send one compact invalidation to the open pickup pages of that Branch only', function () {
    Event::fake([PickupStatusChanged::class]);
    $first = pickupTakeOutOrder($this->cashier, $this->product)['order'];
    $second = pickupTakeOutOrder($this->cashier, $this->product)['order'];
    $otherBranch = Branch::factory()->create();
    $foreign = Order::factory()->for($otherBranch)->create(['order_type' => 'take_out', 'order_number' => '9', 'commercial_status' => 'active', 'kitchen_status' => 'kitchen', 'committed_at' => now()]);
    KitchenTicket::factory()->for($otherBranch)->for($foreign)->create();
    app(PickupTokens::class)->ensureFor($foreign);
    $keys = OrderPickupToken::query()->where('branch_id', $this->branch->id)->pluck('channel_key')->sort()->values()->all();
    Event::fake([PickupStatusChanged::class]);
    $kitchen = User::factory()->create();
    $kitchen->roles()->attach(Role::query()->where('name', 'kitchen_staff')->sole());
    $kitchen->branches()->attach($this->branch, ['is_active' => true]);

    app(TransitionKitchenOrder::class)->execute($kitchen, $this->branch, $first, KitchenStatus::Preparing);

    Event::assertDispatchedTimes(PickupStatusChanged::class, 1);
    Event::assertDispatched(PickupStatusChanged::class, function (PickupStatusChanged $event) use ($keys, $second): bool {
        $channels = collect($event->broadcastOn())->map(fn ($channel): string => $channel->name)->sort()->values()->all();

        return $channels === array_map(fn (string $key): string => 'private-pickup.'.$key, $keys)
            && array_keys($event->broadcastWith()) === ['event_id', 'event_type', 'occurred_at']
            && ! str_contains(json_encode($event->broadcastWith()), $second->id);
    });
});

test('pickup endpoints are rate limited and can never change the order', function () {
    ['order' => $order, 'token' => $token] = pickupTakeOutOrder($this->cashier, $this->product);
    $before = $order->fresh()->only(['kitchen_status', 'commercial_status', 'payment_status', 'version', 'total']);

    foreach (['put', 'patch'] as $method) {
        $this->{$method.'Json'}(route('pickup.show', $token), ['kitchen_status' => 'done'])->assertMethodNotAllowed();
        $this->{$method.'Json'}(route('pickup.status', $token), ['kitchen_status' => 'done'])->assertMethodNotAllowed();
    }
    $this->deleteJson(route('pickup.show', $token))->assertMethodNotAllowed();
    expect($order->fresh()->only(array_keys($before)))->toBe($before);

    foreach (range(1, 120) as $attempt) {
        $this->getJson(route('pickup.status', $token))->assertOk();
    }
    $this->getJson(route('pickup.status', $token))->assertTooManyRequests();
    /** Limits are per pickup link: another customer's phone behind the same store IP keeps updating. */
    $this->getJson(route('pickup.status', pickupTakeOutOrder($this->cashier, $this->product)['token']))->assertOk();
});

test('scanning alone subscribes nothing; an explicit opt-in is idempotent and bound to its own order', function () {
    Event::fake([PickupNotifyChanged::class]);
    ['order' => $order, 'token' => $token] = pickupTakeOutOrder($this->cashier, $this->product);
    ['token' => $otherToken] = pickupTakeOutOrder($this->cashier, $this->product);

    $this->get(route('pickup.show', $token))->assertOk();
    $this->getJson(route('pickup.status', $token))->assertOk();
    expect(PickupPushSubscription::query()->count())->toBe(0);

    $body = pickupSubscriptionBody();
    $this->postJson(route('pickup.subscription.store', $token), $body)->assertOk()->assertExactJson(['subscribed' => true]);
    $this->postJson(route('pickup.subscription.store', $token), $body)->assertOk();
    $this->postJson(route('pickup.subscription.store', $otherToken), $body)->assertOk();
    expect(PickupPushSubscription::query()->count())->toBe(2)
        ->and(PickupPushSubscription::query()->whereHas('pickupToken', fn ($query) => $query->where('order_id', $order->id))->count())->toBe(1);
    Event::assertDispatchedTimes(PickupNotifyChanged::class, 2);

    $replacement = pickupSubscriptionBody('b');
    $this->postJson(route('pickup.subscription.store', $token), $replacement)->assertOk();
    $saved = PickupPushSubscription::query()->whereHas('pickupToken', fn ($query) => $query->where('order_id', $order->id))->sole();
    expect($saved->endpoint)->toBe($replacement['endpoint'])
        ->and($saved->getRawOriginal('endpoint'))->not->toBe($replacement['endpoint'])
        ->and(json_encode($saved->toArray()))->not->toContain('fcm.googleapis.com');
    $this->getJson(route('pickup.status', $token))->assertJsonPath('pickup.notifications.subscribed', true);

    $this->postJson(route('pickup.subscription.store', $token), [...$body, 'endpoint' => 'https://internal.example/steal'])->assertUnprocessable()->assertJsonValidationErrors('endpoint');
    $this->deleteJson(route('pickup.subscription.destroy', $token))->assertOk()->assertExactJson(['subscribed' => false]);
    $this->getJson(route('pickup.status', $token))->assertJsonPath('pickup.notifications.subscribed', false);

    config(['services.webpush.private_key' => '']);
    $this->postJson(route('pickup.subscription.store', $token), $body)->assertStatus(503);
});

test('the pickup page authorizes only its own realtime channel', function () {
    config(['broadcasting.default' => 'pusher', 'broadcasting.connections.pusher' => [
        'driver' => 'pusher', 'key' => 'test-key', 'secret' => 'test-secret', 'app_id' => 'test-app', 'options' => ['cluster' => 'ap1'],
    ]]);
    ['order' => $order, 'token' => $token] = pickupTakeOutOrder($this->cashier, $this->product);
    ['order' => $other] = pickupTakeOutOrder($this->cashier, $this->product);
    $own = OrderPickupToken::query()->where('order_id', $order->id)->value('channel_key');
    $foreign = OrderPickupToken::query()->where('order_id', $other->id)->value('channel_key');
    $authorize = fn (string $channel) => $this->postJson(route('pickup.broadcasting.auth', $token), ['socket_id' => '1.2', 'channel_name' => $channel]);

    $authorize('private-pickup.'.$own)->assertOk()->assertJsonStructure(['auth']);
    $authorize('private-pickup.'.$foreign)->assertForbidden();
    $authorize('private-branch.'.$this->branch->id.'.pos')->assertForbidden();
    $authorize('private-branch.'.$this->branch->id.'.customer-display')->assertForbidden();
});

test('a committed edit that turns a Dine In order into Take Out issues its pickup token once', function () {
    $order = Order::query()->findOrFail(pickupPay($this->cashier, $this->product, 'dine_in')->assertOk()->json('receipt.id'));
    expect(OrderPickupToken::query()->count())->toBe(0);

    $order->forceFill(['order_type' => 'take_out'])->save();
    OrderUpdated::dispatch($order->fresh(), ['items', 'total', 'payment_status']);
    OrderUpdated::dispatch($order->fresh(), ['items', 'total', 'payment_status']);

    expect(OrderPickupToken::query()->where('order_id', $order->id)->count())->toBe(1);
});

test('the pickup summary comes from the order snapshots, never today\'s catalog', function () {
    $size = ModifierGroup::factory()->create(['name' => 'Size', 'semantic_role' => 'size', 'min_select' => 1, 'max_select' => 1]);
    $instructions = ModifierGroup::factory()->create(['name' => 'Instructions', 'semantic_role' => 'instruction', 'selection_type' => ModifierSelectionType::Multiple, 'max_select' => 2]);
    $large = ModifierOption::factory()->for($size)->create(['name' => 'Large', 'price_delta' => '30.00']);
    $lessIce = ModifierOption::factory()->for($instructions)->create(['name' => 'Less ice', 'price_delta' => '0.00']);
    $this->product->update(['name' => 'Iced Tea']);
    $this->product->modifierGroups()->attach([$size->id, $instructions->id]);
    $order = Order::query()->findOrFail($this->actingAs($this->cashier)->postJson(route('pos.payments.store'), [
        'order_type' => 'take_out', 'customer_label' => 'Private Juan', 'payment_method' => 'cash', 'cash_received' => '500.00', 'cashless_amount' => null,
        'idempotency_key' => (string) Str::uuid(), 'items' => [['product_id' => $this->product->id, 'quantity' => 2, 'notes' => 'staff: regular', 'modifiers' => [
            ['group_id' => $size->id, 'option_id' => $large->id], ['group_id' => $instructions->id, 'option_id' => $lessIce->id],
        ]]],
    ])->assertOk()->json('receipt.id'));
    $token = app(PickupTokens::class)->rawToken(OrderPickupToken::query()->where('order_id', $order->id)->sole());
    $this->product->update(['name' => 'Renamed Tea', 'default_price' => '999.00']);
    $large->update(['name' => 'Jumbo']);

    $status = $this->getJson(route('pickup.status', $token))->assertOk();
    expect($status->json('pickup.summary'))->toBe([
        'items' => [['name' => 'Large Iced Tea', 'quantity' => 2, 'details' => [], 'instructions' => ['Less ice'], 'amount' => '300.00']],
        'subtotal' => '300.00',
        'total' => '300.00',
    ])->and($status->getContent())->not->toContain('staff: regular')->not->toContain('Renamed')->not->toContain('Jumbo');
});

test('the receipt is the canonical customer receipt, reachable only through a valid pickup link', function () {
    ['order' => $order, 'token' => $token] = pickupTakeOutOrder($this->cashier, $this->product);

    $response = $this->getJson(route('pickup.receipt', $token))->assertOk()
        ->assertJsonPath('receipt.order_number', $order->order_number)
        ->assertJsonPath('receipt.reference_number', $order->reference_number)
        ->assertJsonPath('receipt.total', '120.00')
        ->assertJsonPath('receipt.customer_label', null)
        ->assertJsonPath('receipt.table_name', null)
        ->assertJsonPath('receipt.items.0.notes', null)
        ->assertJsonPath('receipt.payments.0.method', 'cash');
    expect(array_keys($response->json('receipt')))->toBe(array_keys(app(CustomerQrProjection::class)->publicReceipt($order->fresh())))
        ->and($response->getContent())->not->toContain('Private Juan')->not->toContain('no onions')->not->toContain($order->id)->not->toContain($this->cashier->name)
        ->and($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and($response->headers->get('X-Robots-Tag'))->toContain('noindex');

    $this->getJson('/pickup/'.$order->id.'/receipt')->assertNotFound();
    $this->getJson(route('pickup.receipt', substr($token, 0, 42).($token[42] === 'A' ? 'B' : 'A')))->assertNotFound();
    $this->travel(PickupTokens::LIFETIME_HOURS)->hours();
    $this->getJson(route('pickup.receipt', $token))->assertStatus(410);
});

test('an unpaid Pay Later Take Out order has no receipt until it is paid', function () {
    $reservation = $this->actingAs($this->cashier)->postJson(route('pos.orders.reservations.store'), ['order_type' => 'take_out'])->assertOk()->json('order');
    $this->actingAs($this->cashier)->postJson(route('pos.orders.pay-later.store', $reservation['id']), [
        'idempotency_key' => (string) Str::uuid(), 'order_type' => 'take_out', 'customer_label' => '', 'branch_table_id' => null,
        'items' => [['product_id' => $this->product->id, 'quantity' => 1, 'notes' => '', 'modifiers' => []]],
    ])->assertOk();
    $token = app(PickupTokens::class)->rawToken(OrderPickupToken::query()->where('order_id', $reservation['id'])->sole());

    $this->getJson(route('pickup.status', $token))->assertJsonPath('pickup.receipt_available', false);
    $this->getJson(route('pickup.receipt', $token))->assertNotFound();
});

test('customer links appear only when configured with a safe http(s) address', function () {
    ['token' => $token] = pickupTakeOutOrder($this->cashier, $this->product);
    $this->branch->forceFill([
        'facebook_url' => 'https://facebook.com/pongskilog',
        'website_url' => 'javascript:alert(1)',
        'maps_url' => 'https://maps.google.com/?q=Pongskilog',
    ])->save();

    $this->getJson(route('pickup.status', $token))->assertJsonPath('pickup.links', [
        'facebook' => 'https://facebook.com/pongskilog',
        'website' => null,
        'maps' => 'https://maps.google.com/?q=Pongskilog',
    ]);
});
