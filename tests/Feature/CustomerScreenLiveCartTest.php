<?php

use App\Enums\CustomerScreenMode;
use App\Enums\KitchenStatus;
use App\Enums\ModifierSelectionType;
use App\Events\CustomerScreenChanged;
use App\Models\Branch;
use App\Models\BranchProduct;
use App\Models\CustomerScreen;
use App\Models\KitchenTicket;
use App\Models\ModifierGroup;
use App\Models\ModifierOption;
use App\Models\Order;
use App\Models\Product;
use App\Models\Role;
use App\Models\StoreSession;
use App\Models\User;
use App\Support\CustomerScreens;
use App\Support\KitchenBoard;
use Database\Seeders\RbacSeeder;
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

test('a committed Dine In order takes over for 3 seconds with its same-type queue position and keeps the mode', function () {
    $session = StoreSession::factory()->for($this->main)->create();
    $this->travel(-5)->minutes();
    liveCartCommittedOrder($this->main, $session, 'dine_in', KitchenStatus::Kitchen, '101');
    liveCartCommittedOrder($this->main, $session, 'dine_in', KitchenStatus::Preparing, '102');
    liveCartCommittedOrder($this->main, $session, 'take_out', KitchenStatus::Kitchen, '103');
    liveCartCommittedOrder($this->main, $session, 'dine_in', KitchenStatus::Ready, '104');
    liveCartCommittedOrder($this->main, $session, 'dine_in', KitchenStatus::Kitchen, '105', ['commercial_status' => 'voided', 'voided_at' => now()]);
    $this->travelBack();
    $order = liveCartCommittedOrder($this->main, $session, 'dine_in', KitchenStatus::Kitchen, '106');
    $this->actingAs($this->cashier)->withHeader(CustomerScreens::STATION_HEADER, $this->station)
        ->putJson(route('pos.customer-screen.mode'), ['control' => 'menu'])->assertOk();

    $this->actingAs($this->cashier)->withHeader(CustomerScreens::STATION_HEADER, $this->station)
        ->postJson(route('pos.customer-screen.takeover'), ['order_id' => $order->id])
        ->assertOk()->assertExactJson(['shown' => true, 'duration_ms' => 3000]);

    liveCartState($this->screenToken)
        ->assertJsonPath('screen.mode', 'menu')
        ->assertJsonPath('screen.takeover.order_number', '106')
        ->assertJsonPath('screen.takeover.order_type', 'dine_in')
        ->assertJsonPath('screen.takeover.queue_position', 3)
        ->assertJsonPath('screen.takeover.duration_ms', 3000)
        ->assertJsonPath('screen.takeover.pickup', null);

    $this->travel(3100)->milliseconds();
    liveCartState($this->screenToken)->assertJsonPath('screen.takeover', null)->assertJsonPath('screen.mode', 'menu');
    expect(CustomerScreen::query()->whereNotNull('branch_id')->sole()->mode)->toBe(CustomerScreenMode::Menu);
});

test('a committed Take Out order takes over for 5 seconds with the Take Out queue and its pickup QR', function () {
    $session = StoreSession::factory()->for($this->main)->create();
    $product = Product::factory()->soldAt($this->main)->create(['default_price' => '150.00']);
    $this->travel(-2)->minutes();
    liveCartCommittedOrder($this->main, $session, 'take_out', KitchenStatus::Preparing, '201');
    liveCartCommittedOrder($this->main, $session, 'dine_in', KitchenStatus::Kitchen, '202');
    $this->travelBack();

    $receipt = $this->actingAs($this->cashier)->postJson(route('pos.payments.store'), [
        'order_type' => 'take_out', 'customer_label' => '', 'items' => [['product_id' => $product->id, 'quantity' => 1, 'notes' => '', 'modifiers' => []]],
        'payment_method' => 'cash', 'cash_received' => '200.00', 'cashless_amount' => null, 'idempotency_key' => (string) Str::uuid(),
    ])->assertOk()->json('receipt');

    $this->actingAs($this->cashier)->withHeader(CustomerScreens::STATION_HEADER, $this->station)
        ->postJson(route('pos.customer-screen.takeover'), ['order_id' => $receipt['id']])
        ->assertOk()->assertJsonPath('duration_ms', 5000);

    $state = liveCartState($this->screenToken)
        ->assertJsonPath('screen.mode', 'ads')
        ->assertJsonPath('screen.takeover.order_number', $receipt['order_number'])
        ->assertJsonPath('screen.takeover.order_type', 'take_out')
        ->assertJsonPath('screen.takeover.queue_position', 2)
        ->assertJsonPath('screen.takeover.duration_ms', 5000);
    expect($state->json('screen.takeover.pickup.url'))->toMatch('#\Ahttps?://[^/]+/pickup/[A-Za-z0-9_-]{43}\z#')
        ->and($state->json('screen.takeover.pickup.qr_image'))->toStartWith('data:image/svg+xml;base64,')
        ->and($state->json('screen.takeover.remaining_ms'))->toBeGreaterThan(0)->toBeLessThanOrEqual(5000);

    $this->travel(4)->seconds();
    liveCartState($this->screenToken)->assertJsonPath('screen.takeover.order_number', $receipt['order_number']);
    $this->travel(2)->seconds();
    liveCartState($this->screenToken)->assertJsonPath('screen.takeover', null)->assertJsonPath('screen.mode', 'ads');
});

test('a takeover clears the cart and a cart send still in flight cannot bring it back', function () {
    $menu = liveCartLemonade($this->main);
    $session = StoreSession::factory()->for($this->main)->create();
    $order = liveCartCommittedOrder($this->main, $session, 'dine_in', KitchenStatus::Kitchen, '301');
    $items = [['key' => 'x', 'product_id' => $menu['product']->id, 'quantity' => 1, 'modifiers' => []]];
    liveCartSend($this->cashier, $this->station, $items, 7)->assertOk();

    $this->actingAs($this->cashier)->withHeader(CustomerScreens::STATION_HEADER, $this->station)
        ->postJson(route('pos.customer-screen.takeover'), ['order_id' => $order->id, 'instance' => 'pos-instance-1', 'sequence' => 7])->assertOk();
    liveCartState($this->screenToken)->assertJsonPath('screen.cart', null);

    liveCartSend($this->cashier, $this->station, $items, 7)->assertOk();
    liveCartState($this->screenToken)->assertJsonPath('screen.cart', null);
    liveCartSend($this->cashier, $this->station, $items, 8)->assertOk();
    liveCartState($this->screenToken)->assertJsonCount(1, 'screen.cart.lines');
});

test('a takeover only shows a recent committed order of the cashier Branch', function () {
    $session = StoreSession::factory()->for($this->main)->create();
    $foreign = liveCartCommittedOrder($this->qave, StoreSession::factory()->for($this->qave)->create(), 'dine_in', KitchenStatus::Kitchen, '401');
    $old = liveCartCommittedOrder($this->main, $session, 'dine_in', KitchenStatus::Kitchen, '402', ['committed_at' => now()->subMinutes(30)]);
    $draft = Order::factory()->for($this->main)->create(['order_number' => '403', 'commercial_status' => 'draft', 'committed_at' => null]);
    $takeover = fn (string $orderId) => $this->actingAs($this->cashier)->withHeader(CustomerScreens::STATION_HEADER, $this->station)
        ->postJson(route('pos.customer-screen.takeover'), ['order_id' => $orderId]);

    $takeover($foreign->id)->assertNotFound();
    $takeover($old->id)->assertNotFound();
    $takeover($draft->id)->assertNotFound();
    liveCartState($this->screenToken)->assertJsonPath('screen.takeover', null);
});

test('queue positions come from the canonical board order and ignore finished and voided orders', function () {
    $session = StoreSession::factory()->for($this->main)->create();
    $this->travel(-10)->minutes();
    $first = liveCartCommittedOrder($this->main, $session, 'take_out', KitchenStatus::Kitchen, '501');
    $this->travel(1)->minutes();
    $second = liveCartCommittedOrder($this->main, $session, 'take_out', KitchenStatus::Preparing, '502');
    $this->travel(1)->minutes();
    liveCartCommittedOrder($this->main, $session, 'take_out', KitchenStatus::Done, '503', ['completed_at' => now()]);
    $third = liveCartCommittedOrder($this->main, $session, 'take_out', KitchenStatus::Kitchen, '504');
    $this->travelBack();
    $board = app(KitchenBoard::class);

    expect($board->queuePosition($first))->toBe(1)
        ->and($board->queuePosition($second))->toBe(2)
        ->and($board->queuePosition($third))->toBe(3);

    $first->update(['kitchen_status' => KitchenStatus::Ready]);
    $second->update(['commercial_status' => 'voided', 'voided_at' => now()]);
    expect($board->queuePosition($third->fresh()))->toBe(1)
        ->and($board->queuePosition($first->fresh()))->toBeNull()
        ->and($board->queuePosition($second->fresh()))->toBeNull();
});
