<?php

use App\Actions\CustomerScreens\ShowOrderOnCustomerScreen;
use App\Enums\CustomerScreenMode;
use App\Enums\KitchenStatus;
use App\Enums\ModifierSelectionType;
use App\Events\CustomerScreenChanged;
use App\Events\CustomerScreenStatusChanged;
use App\Models\Branch;
use App\Models\BranchProduct;
use App\Models\CustomerScreen;
use App\Models\KitchenTicket;
use App\Models\ModifierGroup;
use App\Models\ModifierOption;
use App\Models\Order;
use App\Models\OrderPickupToken;
use App\Models\Product;
use App\Models\Role;
use App\Models\StoreSession;
use App\Models\User;
use App\Support\CustomerScreenLiveState;
use App\Support\CustomerScreens;
use App\Support\KitchenBoard;
use App\Support\PickupTokens;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->withCredentials();
    $this->main = Branch::factory()->create(['name' => 'Main Branch', 'code' => 'MAIN']);
    $this->qave = Branch::factory()->create(['name' => 'Qave Branch', 'code' => 'QAVE']);
    $this->cashier = liveCartCashier($this->main);
    $this->station = (string) Str::uuid();
    $this->screenToken = liveCartPairedScreen($this->cashier, $this->station);
});

function liveCartCashier(Branch $branch, string $role = 'cashier'): User
{
    $user = User::factory()->create();
    $user->roles()->attach(Role::query()->where('name', $role)->sole());
    $user->branches()->attach($branch, ['is_active' => true]);

    return $user;
}

/** Pairs a new screen device with the station and returns the device cookie value. */
function liveCartPairedScreen(User $cashier, string $station): string
{
    $device = test()->withCookie(CustomerScreens::COOKIE, '')->postJson(route('customer-screen.pairing-code'))->assertOk();
    test()->actingAs($cashier)->withHeader(CustomerScreens::STATION_HEADER, $station)
        ->postJson(route('pos.customer-screen.pair'), ['code' => $device->json('code')])->assertOk();

    return $device->getCookie(CustomerScreens::COOKIE)->getValue();
}

function liveCartState(string $token): TestResponse
{
    return test()->withCookie(CustomerScreens::COOKIE, $token)->getJson(route('customer-screen.state'))->assertOk();
}

/** @param list<array<string, mixed>> $items */
function liveCartSend(User $cashier, string $station, array $items, int $sequence, string $instance = 'pos-instance-1', ?string $type = 'take_out'): TestResponse
{
    return test()->actingAs($cashier)->withHeader(CustomerScreens::STATION_HEADER, $station)
        ->postJson(route('pos.customer-screen.cart'), ['instance' => $instance, 'sequence' => $sequence, 'order_type' => $type, 'items' => $items]);
}

/** @return array{product: Product, large: ModifierOption, pearl: ModifierOption, lessIce: ModifierOption, size: ModifierGroup, addOns: ModifierGroup, instructions: ModifierGroup} */
function liveCartLemonade(Branch $branch): array
{
    $product = Product::factory()->soldAt($branch)->create(['name' => 'Lemonade', 'default_price' => '80.00']);
    BranchProduct::query()->where('product_id', $product->id)->where('branch_id', $branch->id)->update(['price_override' => '90.00']);
    $size = ModifierGroup::factory()->create(['name' => 'Size', 'semantic_role' => 'size', 'min_select' => 1, 'max_select' => 1]);
    $addOns = ModifierGroup::factory()->create(['name' => 'Add-ons', 'selection_type' => ModifierSelectionType::Multiple, 'max_select' => 3]);
    $instructions = ModifierGroup::factory()->create(['name' => 'Instructions', 'semantic_role' => 'instruction', 'selection_type' => ModifierSelectionType::Multiple, 'max_select' => 3]);
    $large = ModifierOption::factory()->for($size)->create(['name' => 'Large', 'price_delta' => '20.00']);
    $pearl = ModifierOption::factory()->for($addOns)->create(['name' => 'Pearl', 'price_delta' => '15.00']);
    $lessIce = ModifierOption::factory()->for($instructions)->create(['name' => 'Less ice', 'price_delta' => '0.00']);
    $product->modifierGroups()->attach([$size->id, $addOns->id, $instructions->id]);

    return compact('product', 'large', 'pearl', 'lessIce', 'size', 'addOns', 'instructions');
}

function liveCartCommittedOrder(Branch $branch, StoreSession $session, string $type, KitchenStatus $status, string $number, array $overrides = []): Order
{
    $order = Order::factory()->for($branch)->for($session)->create([
        'order_type' => $type,
        'order_number' => $number,
        'commercial_status' => 'active',
        'payment_status' => 'paid',
        'payment_term' => 'immediate',
        'kitchen_status' => $status,
        'committed_at' => now(),
        ...$overrides,
    ]);
    KitchenTicket::factory()->for($branch)->for($order)->create(['status' => $status]);

    return $order;
}

test('the Live Cart is a customer-safe projection derived on the server from ids and quantities', function () {
    Event::fake([CustomerScreenChanged::class]);
    $menu = liveCartLemonade($this->main);

    liveCartSend($this->cashier, $this->station, [[
        'key' => 'line-1', 'product_id' => $menu['product']->id, 'quantity' => 2,
        'notes' => 'staff: comp for regular', 'unit_price' => '0.01', 'name' => 'FORGED',
        'modifiers' => [
            ['group_id' => $menu['size']->id, 'option_id' => $menu['large']->id],
            ['group_id' => $menu['addOns']->id, 'option_id' => $menu['pearl']->id],
            ['group_id' => $menu['instructions']->id, 'option_id' => $menu['lessIce']->id],
        ],
    ]], 1)->assertOk()->assertJsonPath('paired', true);

    $state = liveCartState($this->screenToken)
        ->assertJsonPath('screen.cart.lines.0.name', 'Large Lemonade')
        ->assertJsonPath('screen.cart.lines.0.quantity', 2)
        ->assertJsonPath('screen.cart.lines.0.details', ['Pearl'])
        ->assertJsonPath('screen.cart.lines.0.instructions', ['Less ice'])
        ->assertJsonPath('screen.cart.lines.0.amount', '250.00')
        ->assertJsonPath('screen.cart.total', '250.00')
        ->assertJsonPath('screen.cart.item_count', 2)
        ->assertJsonPath('screen.cart.order_type', 'take_out');
    expect(array_keys($state->json('screen.cart.lines.0')))->toBe(['key', 'name', 'quantity', 'details', 'instructions', 'amount'])
        ->and($state->getContent())->not->toContain($menu['product']->id)
        ->not->toContain($menu['large']->id)
        ->not->toContain('line-1')
        ->not->toContain('comp for regular')
        ->not->toContain('FORGED')
        ->not->toContain($this->cashier->name);
    Event::assertDispatched(CustomerScreenChanged::class, fn (CustomerScreenChanged $event): bool => $event->broadcastWith()['reason'] === 'cart'
        && ! str_contains(json_encode($event->broadcastWith()), 'Lemonade'));
});

test('lines of a Product the Branch does not sell never reach the screen', function () {
    $foreign = Product::factory()->soldAt($this->qave)->create(['name' => 'Qave Only']);
    $menu = liveCartLemonade($this->main);

    liveCartSend($this->cashier, $this->station, [
        ['key' => 'a', 'product_id' => $foreign->id, 'quantity' => 1, 'modifiers' => []],
        ['key' => 'b', 'product_id' => $menu['product']->id, 'quantity' => 1, 'modifiers' => []],
    ], 1)->assertOk();

    liveCartState($this->screenToken)->assertJsonCount(1, 'screen.cart.lines')
        ->assertJsonPath('screen.cart.lines.0.name', 'Lemonade')
        ->assertJsonPath('screen.cart.total', '90.00');
});

test('a stale or out-of-order cart send never overwrites a newer cart', function () {
    $menu = liveCartLemonade($this->main);
    $line = fn (int $quantity) => [['key' => 'x', 'product_id' => $menu['product']->id, 'quantity' => $quantity, 'modifiers' => []]];

    liveCartSend($this->cashier, $this->station, $line(3), 5)->assertOk();
    liveCartSend($this->cashier, $this->station, $line(1), 4)->assertOk();
    liveCartSend($this->cashier, $this->station, $line(9), 5)->assertOk();
    liveCartState($this->screenToken)->assertJsonPath('screen.cart.lines.0.quantity', 3);

    /** A reloaded POS page is a new instance and starts numbering again. */
    liveCartSend($this->cashier, $this->station, $line(2), 1, 'pos-instance-2')->assertOk();
    liveCartState($this->screenToken)->assertJsonPath('screen.cart.lines.0.quantity', 2);
});

test('an empty cart clears the Live Cart and signing out clears the carts that cashier sent', function () {
    $menu = liveCartLemonade($this->main);
    $items = [['key' => 'x', 'product_id' => $menu['product']->id, 'quantity' => 1, 'modifiers' => []]];

    liveCartSend($this->cashier, $this->station, $items, 1)->assertOk();
    liveCartSend($this->cashier, $this->station, [], 2)->assertOk();
    liveCartState($this->screenToken)->assertJsonPath('screen.cart', null);

    liveCartSend($this->cashier, $this->station, $items, 3)->assertOk();
    $this->actingAs($this->cashier)->post(route('logout'));
    liveCartState($this->screenToken)->assertJsonPath('screen.cart', null)->assertJsonPath('screen.status', 'paired');
});

test('carts never cross stations and an unpaired station stores nothing', function () {
    $menu = liveCartLemonade($this->main);
    $otherCashier = liveCartCashier($this->main);
    $otherStation = (string) Str::uuid();
    $otherScreen = liveCartPairedScreen($otherCashier, $otherStation);
    $items = [['key' => 'x', 'product_id' => $menu['product']->id, 'quantity' => 4, 'modifiers' => []]];

    liveCartSend($this->cashier, $this->station, $items, 1)->assertOk();

    liveCartState($this->screenToken)->assertJsonPath('screen.cart.lines.0.quantity', 4);
    liveCartState($otherScreen)->assertJsonPath('screen.cart', null);
    liveCartSend($this->cashier, (string) Str::uuid(), $items, 1)->assertOk()->assertJsonPath('paired', false);
    liveCartSend(liveCartCashier($this->qave), $this->station, $items, 1)->assertOk()->assertJsonPath('paired', false);
    liveCartSend(liveCartCashier($this->main, 'kitchen_staff'), $this->station, $items, 1)->assertForbidden();
});

/** @param array<string, string> $headers */
function liveCartPayNow(User $cashier, Product $product, string $type, array $headers = [], ?string $key = null): TestResponse
{
    return test()->actingAs($cashier)->withHeaders($headers)->postJson(route('pos.payments.store'), [
        'order_type' => $type, 'customer_label' => 'Juan Dela Cruz', 'items' => [['product_id' => $product->id, 'quantity' => 1, 'notes' => 'staff only note', 'modifiers' => []]],
        'payment_method' => 'cash', 'cash_received' => '500.00', 'cashless_amount' => null, 'idempotency_key' => $key ?? (string) Str::uuid(),
    ]);
}

function liveCartShown(string $token, string $takeoverId): TestResponse
{
    return test()->withCookie(CustomerScreens::COOKIE, $token)->postJson(route('customer-screen.takeover.shown', $takeoverId));
}

test('a committed Dine In order is confirmed on the paired screen with its queue positions, no QR and never the customer name', function () {
    Event::fake([CustomerScreenChanged::class, CustomerScreenStatusChanged::class]);
    $session = StoreSession::factory()->for($this->main)->create();
    $product = Product::factory()->soldAt($this->main)->create(['default_price' => '150.00']);
    $this->travel(-5)->minutes();
    liveCartCommittedOrder($this->main, $session, 'dine_in', KitchenStatus::Kitchen, '101');
    liveCartCommittedOrder($this->main, $session, 'take_out', KitchenStatus::Preparing, '102');
    liveCartCommittedOrder($this->main, $session, 'dine_in', KitchenStatus::Preparing, '103');
    liveCartCommittedOrder($this->main, $session, 'dine_in', KitchenStatus::Ready, '104');
    liveCartCommittedOrder($this->main, $session, 'dine_in', KitchenStatus::Done, '105', ['completed_at' => now()]);
    liveCartCommittedOrder($this->main, $session, 'dine_in', KitchenStatus::Kitchen, '106', ['commercial_status' => 'voided', 'voided_at' => now()]);
    $this->travelBack();
    $this->actingAs($this->cashier)->withHeader(CustomerScreens::STATION_HEADER, $this->station)
        ->putJson(route('pos.customer-screen.mode'), ['control' => 'menu'])->assertOk();

    $receipt = liveCartPayNow($this->cashier, $product, 'dine_in', [CustomerScreens::STATION_HEADER => $this->station])
        ->assertOk()->assertJsonPath('receipt.payment_status', 'paid')->json('receipt');

    $state = liveCartState($this->screenToken)
        ->assertJsonPath('screen.mode', 'ads')
        ->assertJsonPath('screen.takeover.order_number', $receipt['order_number'])
        ->assertJsonPath('screen.takeover.order_type', 'dine_in')
        ->assertJsonPath('screen.takeover.overall_position', 4)
        ->assertJsonPath('screen.takeover.type_position', 3)
        ->assertJsonPath('screen.takeover.queue_total', 4)
        ->assertJsonPath('screen.takeover.duration_ms', 5000)
        ->assertJsonPath('screen.takeover.remaining_ms', null)
        ->assertJsonPath('screen.takeover.pickup', null);
    expect($state->json('screen.takeover.queue'))->toBe([
        ['position' => 1, 'order_number' => '101', 'order_type' => 'dine_in', 'current' => false],
        ['position' => 2, 'order_number' => '102', 'order_type' => 'take_out', 'current' => false],
        ['position' => 3, 'order_number' => '103', 'order_type' => 'dine_in', 'current' => false],
        ['position' => 4, 'order_number' => $receipt['order_number'], 'order_type' => 'dine_in', 'current' => true],
    ])->and($state->getContent())->not->toContain('Juan Dela Cruz')->not->toContain('staff only note')->not->toContain($receipt['id']);
    expect(CustomerScreen::query()->whereNotNull('branch_id')->sole()->mode)->toBe(CustomerScreenMode::Ads);
    Event::assertDispatched(CustomerScreenChanged::class, fn (CustomerScreenChanged $event): bool => $event->broadcastWith()['reason'] === 'takeover');
    Event::assertDispatched(CustomerScreenStatusChanged::class, fn (CustomerScreenStatusChanged $event): bool => $event->broadcastWith()['branch_id'] === $this->main->id
        && array_keys($event->broadcastWith()) === ['event_id', 'event_type', 'branch_id', 'occurred_at']);
});

test('a committed Take Out order is confirmed with its pickup QR and both queue positions', function () {
    $session = StoreSession::factory()->for($this->main)->create();
    $product = Product::factory()->soldAt($this->main)->create(['default_price' => '150.00']);
    $this->travel(-2)->minutes();
    liveCartCommittedOrder($this->main, $session, 'take_out', KitchenStatus::Preparing, '201');
    liveCartCommittedOrder($this->main, $session, 'dine_in', KitchenStatus::Kitchen, '202');
    $this->travelBack();

    $receipt = liveCartPayNow($this->cashier, $product, 'take_out', [CustomerScreens::STATION_HEADER => $this->station])->assertOk()->json('receipt');

    $state = liveCartState($this->screenToken)
        ->assertJsonPath('screen.mode', 'ads')
        ->assertJsonPath('screen.takeover.order_type', 'take_out')
        ->assertJsonPath('screen.takeover.overall_position', 3)
        ->assertJsonPath('screen.takeover.type_position', 2);
    $raw = app(PickupTokens::class)->rawToken(OrderPickupToken::query()->where('order_id', $receipt['id'])->sole());
    expect($state->json('screen.takeover.pickup.url'))->toEndWith('/pickup/'.$raw)
        ->and($state->json('screen.takeover.pickup.qr_image'))->toStartWith('data:image/svg+xml;base64,')
        ->and($state->getContent())->not->toContain('Juan Dela Cruz');
});

test('a Pay Later commit is confirmed too and a selected Customer Display stays selected', function () {
    StoreSession::factory()->for($this->main)->create();
    $product = Product::factory()->soldAt($this->main)->create(['default_price' => '150.00']);
    $this->actingAs($this->cashier)->withHeader(CustomerScreens::STATION_HEADER, $this->station)
        ->putJson(route('pos.customer-screen.mode'), ['control' => 'customer_display'])->assertOk();
    $reservation = $this->actingAs($this->cashier)->postJson(route('pos.orders.reservations.store'), ['order_type' => 'dine_in'])->assertOk()->json('order');

    $this->actingAs($this->cashier)->withHeader(CustomerScreens::STATION_HEADER, $this->station)
        ->postJson(route('pos.orders.pay-later.store', $reservation['id']), [
            'idempotency_key' => (string) Str::uuid(), 'order_type' => 'dine_in', 'customer_label' => 'Table Guest', 'branch_table_id' => null,
            'items' => [['product_id' => $product->id, 'quantity' => 1, 'notes' => '', 'modifiers' => []]],
        ])->assertOk();

    liveCartState($this->screenToken)
        ->assertJsonPath('screen.mode', 'customer_display')
        ->assertJsonPath('screen.takeover.order_number', $reservation['order_number'])
        ->assertJsonPath('screen.takeover.order_type', 'dine_in')
        ->assertJsonPath('screen.takeover.type_position', 1)
        ->assertJsonPath('screen.takeover.pickup', null)
        ->assertDontSee('Table Guest');
});

test('the countdown starts only once the screen shows the confirmation and uses the Branch durations', function () {
    StoreSession::factory()->for($this->main)->create();
    $this->main->update(['customer_screen_dine_in_success_seconds' => 3, 'customer_screen_take_out_success_seconds' => 12]);
    $product = Product::factory()->soldAt($this->main)->create(['default_price' => '150.00']);
    liveCartPayNow($this->cashier, $product, 'take_out', [CustomerScreens::STATION_HEADER => $this->station])->assertOk();
    $takeover = liveCartState($this->screenToken)->assertJsonPath('screen.takeover.duration_ms', 12000)->json('screen.takeover');

    /** Slow QR rendering / refetching does not consume the scan window: it is still pending, with its full duration. */
    $this->travel(8)->seconds();
    liveCartState($this->screenToken)->assertJsonPath('screen.takeover.id', $takeover['id'])->assertJsonPath('screen.takeover.remaining_ms', null);
    liveCartShown($this->screenToken, $takeover['id'])->assertOk()->assertExactJson(['remaining_ms' => 12000]);
    $this->travel(5)->seconds();
    liveCartShown($this->screenToken, $takeover['id'])->assertOk()->assertExactJson(['remaining_ms' => 7000]);
    liveCartState($this->screenToken)->assertJsonPath('screen.takeover.remaining_ms', 7000);
    $this->travel(7)->seconds();
    liveCartState($this->screenToken)->assertJsonPath('screen.takeover', null);
    liveCartShown($this->screenToken, $takeover['id'])->assertNotFound();

    liveCartPayNow($this->cashier, $product, 'dine_in', [CustomerScreens::STATION_HEADER => $this->station])->assertOk();
    liveCartState($this->screenToken)->assertJsonPath('screen.takeover.duration_ms', 3000);
});

test('a confirmation the screen never shows expires, and only the paired screen can report it shown', function () {
    StoreSession::factory()->for($this->main)->create();
    $product = Product::factory()->soldAt($this->main)->create(['default_price' => '150.00']);
    $otherToken = liveCartPairedScreen(liveCartCashier($this->main), (string) Str::uuid());
    liveCartPayNow($this->cashier, $product, 'dine_in', [CustomerScreens::STATION_HEADER => $this->station])->assertOk();
    $takeoverId = liveCartState($this->screenToken)->json('screen.takeover.id');

    liveCartShown($otherToken, $takeoverId)->assertNotFound();
    liveCartShown($this->screenToken, 'AAAAAAAAAAAAAAAA')->assertNotFound();
    liveCartState($otherToken)->assertJsonPath('screen.takeover', null);

    $this->travel(CustomerScreenLiveState::TAKEOVER_PENDING_MS + 1000)->milliseconds();
    liveCartState($this->screenToken)->assertJsonPath('screen.takeover', null);
    liveCartShown($this->screenToken, $takeoverId)->assertNotFound();
});

test('a replayed payment never shows its order twice and a commit without a paired station shows nothing', function () {
    StoreSession::factory()->for($this->main)->create();
    $product = Product::factory()->soldAt($this->main)->create(['default_price' => '150.00']);
    $key = (string) Str::uuid();
    liveCartPayNow($this->cashier, $product, 'dine_in', [CustomerScreens::STATION_HEADER => $this->station], $key)->assertOk();
    $first = liveCartState($this->screenToken)->json('screen.takeover.id');
    liveCartShown($this->screenToken, $first)->assertOk();
    $this->travel(6)->seconds();

    liveCartPayNow($this->cashier, $product, 'dine_in', [CustomerScreens::STATION_HEADER => $this->station], $key)->assertOk();
    liveCartState($this->screenToken)->assertJsonPath('screen.takeover', null);

    $this->withoutHeader(CustomerScreens::STATION_HEADER);
    liveCartPayNow($this->cashier, $product, 'dine_in')->assertOk();
    liveCartPayNow($this->cashier, $product, 'dine_in', [CustomerScreens::STATION_HEADER => (string) Str::uuid()])->assertOk();
    liveCartPayNow(liveCartCashier($this->qave), Product::factory()->soldAt($this->qave)->create(), 'dine_in', [CustomerScreens::STATION_HEADER => $this->station])
        ->assertUnprocessable();
    liveCartState($this->screenToken)->assertJsonPath('screen.takeover', null);
});

test('a confirmation clears the cart and a cart send still in flight cannot bring it back', function () {
    StoreSession::factory()->for($this->main)->create();
    $menu = liveCartLemonade($this->main);
    $items = [['key' => 'x', 'product_id' => $menu['product']->id, 'quantity' => 1, 'modifiers' => []]];
    liveCartSend($this->cashier, $this->station, $items, 7)->assertOk();
    liveCartState($this->screenToken)->assertJsonCount(1, 'screen.cart.lines');

    liveCartPayNow($this->cashier, Product::factory()->soldAt($this->main)->create(['default_price' => '50.00']), 'dine_in', [
        CustomerScreens::STATION_HEADER => $this->station,
        ShowOrderOnCustomerScreen::CART_FENCE_HEADER => 'pos-instance-1:7',
    ])->assertOk();
    liveCartState($this->screenToken)->assertJsonPath('screen.cart', null);

    liveCartSend($this->cashier, $this->station, $items, 7)->assertOk();
    liveCartState($this->screenToken)->assertJsonPath('screen.cart', null);
    liveCartSend($this->cashier, $this->station, $items, 8)->assertOk();
    liveCartState($this->screenToken)->assertJsonCount(1, 'screen.cart.lines');
});

test('a pickup token failure never affects the committed payment and the screen still confirms without a QR', function () {
    StoreSession::factory()->for($this->main)->create();
    $product = Product::factory()->soldAt($this->main)->create(['default_price' => '150.00']);
    $this->mock(PickupTokens::class, function ($mock): void {
        $mock->shouldReceive('issue', 'ensureFor')->andThrow(new RuntimeException('token store down'));
    });

    $receipt = liveCartPayNow($this->cashier, $product, 'take_out', [CustomerScreens::STATION_HEADER => $this->station])
        ->assertOk()->assertJsonPath('receipt.payment_status', 'paid')->json('receipt');

    expect(Order::query()->findOrFail($receipt['id'])->committed_at)->not->toBeNull()
        ->and(OrderPickupToken::query()->count())->toBe(0);
    liveCartState($this->screenToken)->assertJsonPath('screen.takeover.order_type', 'take_out')->assertJsonPath('screen.takeover.pickup', null);
});

test('queue positions come from the canonical board order across both order types and ignore finished and voided orders', function () {
    $session = StoreSession::factory()->for($this->main)->create();
    $this->travel(-10)->minutes();
    $first = liveCartCommittedOrder($this->main, $session, 'take_out', KitchenStatus::Kitchen, '501');
    $this->travel(1)->minutes();
    $dineIn = liveCartCommittedOrder($this->main, $session, 'dine_in', KitchenStatus::Kitchen, '502');
    $second = liveCartCommittedOrder($this->main, $session, 'take_out', KitchenStatus::Preparing, '503');
    $this->travel(1)->minutes();
    liveCartCommittedOrder($this->main, $session, 'take_out', KitchenStatus::Done, '504', ['completed_at' => now()]);
    $third = liveCartCommittedOrder($this->main, $session, 'take_out', KitchenStatus::Kitchen, '505');
    $this->travelBack();
    $board = app(KitchenBoard::class);

    /** Same committed_at: the id decides, exactly like the Customer Display board. */
    [$tieFirst, $tieSecond] = collect([$dineIn, $second])->sortBy('id')->values()->all();
    expect($board->queue($first))->toMatchArray(['overall_position' => 1, 'type_position' => 1, 'total' => 4])
        ->and($board->queue($tieFirst)['overall_position'])->toBe(2)
        ->and($board->queue($tieSecond)['overall_position'])->toBe(3)
        ->and($board->queue($second)['type_position'])->toBe(2)
        ->and($board->queue($dineIn)['type_position'])->toBe(1)
        ->and($board->queue($third))->toMatchArray(['overall_position' => 4, 'type_position' => 3])
        ->and($board->queue($third)['type_position'])->toBe(3);

    $first->update(['kitchen_status' => KitchenStatus::Ready]);
    $second->update(['commercial_status' => 'voided', 'voided_at' => now()]);
    expect($board->queue($third->fresh()))->toMatchArray(['overall_position' => 2, 'type_position' => 1, 'total' => 2])
        ->and($board->queue($first->fresh()))->toMatchArray(['overall_position' => null, 'type_position' => null])
        ->and($board->queue($second->fresh())['type_position'])->toBeNull();
});

test('a long queue shows a bounded window that always contains the customer order with its true position', function () {
    $session = StoreSession::factory()->for($this->main)->create();
    $orders = collect(range(1, 30))->map(function (int $index) use ($session): Order {
        $this->travel(1)->seconds();

        return liveCartCommittedOrder($this->main, $session, $index % 3 === 0 ? 'dine_in' : 'take_out', KitchenStatus::Kitchen, (string) (1000 + $index));
    });
    $board = app(KitchenBoard::class);

    $near = $board->queue($orders[3]);
    expect(array_column($near['rows'], 'position'))->toBe(range(1, 10))
        ->and($near['rows'][3]['current'])->toBeTrue();

    $far = $board->queue($orders[26]);
    expect($far['overall_position'])->toBe(27)
        ->and($far['type_position'])->toBe(9)
        ->and($far['total'])->toBe(30)
        ->and(array_column($far['rows'], 'position'))->toBe(range(19, 28))
        ->and(collect($far['rows'])->firstWhere('current', true))->toBe(['position' => 27, 'order_number' => '1027', 'order_type' => 'dine_in', 'current' => true]);

    $last = $board->queue($orders[29]);
    expect(array_column($last['rows'], 'position'))->toBe(range(21, 30));

    /** One queue read whatever the queue length. */
    $count = function (Order $order) use ($board): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $board->queue($order->fresh());
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };
    expect($count($orders[2]))->toBe($count($orders[29]));
});

test('the screen header toggles the same single mode as the POS and tells the POS header to refetch', function () {
    Event::fake([CustomerScreenStatusChanged::class]);
    $press = fn (string $control, ?string $token = null) => $this->withCookie(CustomerScreens::COOKIE, $token ?? $this->screenToken)
        ->putJson(route('customer-screen.mode'), ['control' => $control]);

    $press('menu')->assertOk()->assertJsonPath('screen.mode', 'menu');
    $this->actingAs($this->cashier)->withHeader(CustomerScreens::STATION_HEADER, $this->station)
        ->getJson(route('pos.customer-screen.status'))->assertJsonPath('mode', 'menu');
    $press('customer_display')->assertOk()->assertJsonPath('screen.mode', 'customer_display');
    $press('customer_display')->assertOk()->assertJsonPath('screen.mode', 'ads');
    $press('ads')->assertUnprocessable();
    Event::assertDispatchedTimes(CustomerScreenStatusChanged::class, 3);

    $unpaired = $this->withCookie(CustomerScreens::COOKIE, '')->postJson(route('customer-screen.pairing-code'))->getCookie(CustomerScreens::COOKIE)->getValue();
    $press('menu', $unpaired)->assertNotFound();
    $press('menu', str_repeat('a', 64))->assertNotFound();
});
