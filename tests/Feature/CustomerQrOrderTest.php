<?php

use App\Actions\Orders\ArchiveCustomerQrOrder;
use App\Actions\Orders\CancelLoadedCustomerQrOrder;
use App\Actions\Orders\CommitPayLaterOrder;
use App\Actions\Orders\LoadCustomerQrOrder;
use App\Actions\Orders\PayNowOrder;
use App\Actions\Orders\SubmitCustomerQrOrder;
use App\Actions\Orders\TransitionKitchenOrder;
use App\Enums\BranchStatus;
use App\Enums\CommercialStatus;
use App\Enums\KitchenStatus;
use App\Enums\OrderSource;
use App\Events\CustomerTrackingChanged;
use App\Models\Branch;
use App\Models\BranchInventory;
use App\Models\BranchProduct;
use App\Models\BranchTable;
use App\Models\CustomerQrSession;
use App\Models\ModifierGroup;
use App\Models\ModifierOption;
use App\Models\Order;
use App\Models\Product;
use App\Models\Role;
use App\Models\StoreSession;
use App\Models\User;
use App\Support\ActiveBranchContext;
use App\Support\CustomerQrAccess;
use App\Support\OrderNumber;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    $this->seed(RbacSeeder::class);
});

/** @return array{Branch, CustomerQrSession, string, Product, User, StoreSession, BranchInventory} */
function qrFixture(string $role = 'cashier'): array
{
    $branch = Branch::factory()->create();
    $store = StoreSession::factory()->for($branch)->create();
    $token = bin2hex(random_bytes(32));
    $session = CustomerQrSession::factory()->for($branch)->create(['token_hash' => hash('sha256', $token)]);
    $product = Product::factory()->create(['default_price' => '95.00']);
    BranchProduct::factory()->for($branch)->for($product)->create(['tracks_inventory' => true]);
    $stock = BranchInventory::factory()->for($branch)->for($product)->create(['on_hand' => 10]);
    $user = User::factory()->create();
    $user->roles()->attach(Role::query()->where('name', $role)->sole());
    $user->branches()->attach($branch, ['is_active' => true]);

    return [$branch, $session, $token, $product, $user, $store, $stock];
}

/** @return array<string, mixed> */
function qrPayload(Product $product): array
{
    return ['idempotency_key' => (string) Str::uuid(), 'order_type' => 'take_out', 'customer_label' => 'QR customer',
        'items' => [['product_id' => $product->id, 'quantity' => 2, 'notes' => 'Less salt', 'modifiers' => []]]];
}

function qrNoEffects(): void
{
    test()->assertDatabaseCount('payments', 0);
    test()->assertDatabaseCount('inventory_movements', 0);
    test()->assertDatabaseCount('kitchen_tickets', 0);
}

test('anonymous submit persists authoritative snapshots and recovers exact replay without operational effects', function () {
    [$branch, $session, $token, $product] = qrFixture();
    $payload = qrPayload($product);
    $payload['total'] = '0.01';
    $payload['items'][0]['unit_price'] = '0.01';
    $this->withCredentials()->withCookie(app(CustomerQrAccess::class)->cookieName($branch), $token);

    $response = $this->postJson(route('qr.orders.store', $branch), $payload)->assertOk()->assertJsonPath('order.total', '190.00');
    $order = Order::query()->sole();
    expect($order->source)->toBe(OrderSource::CustomerQr);
    expect($order->commercial_status)->toBe(CommercialStatus::Submitted);
    expect($order->kitchen_status)->toBe(KitchenStatus::NotSent);
    expect($order->payment_term)->toBeNull();
    expect($order->committed_at)->toBeNull();
    expect($session->fresh()->active_order_id)->toBe($order->id);
    $response->assertJsonMissingPath('order.id')->assertJsonMissingPath('order.created_by_user_id');
    $this->postJson(route('qr.orders.store', $branch), $payload)->assertOk()->assertJsonPath('order.order_number', $order->fresh()->order_number);
    $this->assertDatabaseCount('orders', 1);
    qrNoEffects();

    $payload['items'][0]['quantity'] = 3;
    $this->postJson(route('qr.orders.store', $branch), $payload)->assertConflict();
    $payload['idempotency_key'] = (string) Str::uuid();
    $this->postJson(route('qr.orders.store', $branch), $payload)->assertConflict();
    $this->assertDatabaseCount('orders', 1);
});

test('load claims the same order and existing payment actions commit the submitted price once', function (string $method) {
    [$branch, $session, $token, $product, $user, , $stock] = qrFixture();
    $order = app(SubmitCustomerQrOrder::class)->execute($branch, $session, qrPayload($product));
    $number = $order->qr_sequence;
    expect($order->fresh()->order_number)->toBeNull();
    $product->update(['default_price' => '250.00']);
    $this->actingAs($user)->withSession([ActiveBranchContext::SESSION_KEY => $branch->id]);
    $this->postJson(route('pos.qr-orders.load', $order))->assertOk()->assertJsonPath('order.id', $order->id)->assertJsonPath('order.total', '190.00');
    qrNoEffects();
    $this->postJson(route('pos.qr-orders.load', $order))->assertConflict();
    $key = (string) Str::uuid();
    $payload = $method === 'now' ? ['draft_order_id' => $order->id, 'payment_method' => 'cash', 'cash_received' => '200.00', 'idempotency_key' => $key] : ['idempotency_key' => $key];
    $url = $method === 'now' ? route('pos.payments.store') : route('pos.orders.pay-later.store', $order);
    $this->postJson($url, $payload)->assertOk();
    $this->postJson($url, $payload)->assertOk();
    $this->assertDatabaseCount('orders', 1);
    $this->assertDatabaseCount('kitchen_tickets', 1);
    $this->assertDatabaseCount('inventory_movements', 1);
    $this->assertDatabaseCount('payments', $method === 'now' ? 1 : 0);
    expect($stock->fresh()->on_hand)->toBe(8);
    expect($order->fresh()->qr_sequence)->toBe($number);
    expect($order->fresh()->order_number)->toBe('1001');
    expect($order->fresh()->source)->toBe(OrderSource::CustomerQr);
    expect($order->fresh()->total)->toBe('190.00');
    if ($method === 'later') {
        $this->postJson(route('pos.orders.settlements.store', $order), ['payment_method' => 'cash', 'cash_received' => '200.00', 'idempotency_key' => (string) Str::uuid()])->assertOk();
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('kitchen_tickets', 1);
        $this->assertDatabaseCount('inventory_movements', 1);
    }
})->with(['now', 'later']);

test('submission rejects closed or inactive stores with no order or operational effects', function (string $change) {
    [$branch, , $token, $product, , $store] = qrFixture();
    if ($change === 'closed') {
        $store->update(['status' => 'closed']);
    } else {
        $branch->update(['status' => BranchStatus::Inactive]);
    }
    $this->withCredentials()->withCookie(app(CustomerQrAccess::class)->cookieName($branch), $token)
        ->postJson(route('qr.orders.store', $branch), qrPayload($product))->assertUnprocessable()->assertJsonValidationErrors('store');
    $this->assertDatabaseCount('orders', 0);
    qrNoEffects();
})->with(['closed', 'inactive']);

test('submission rejects malformed expired missing and foreign session credentials', function (string $case) {
    [$branch, $session, $token, $product] = qrFixture();
    if ($case === 'expired') {
        $session->update(['expires_at' => now()->subSecond()]);
    }
    if ($case === 'foreign') {
        $session->update(['branch_id' => Branch::factory()->create()->id]);
    }
    $cookie = match ($case) {
        'malformed' => 'bad', 'missing' => '', default => $token
    };
    $this->withCredentials()->withCookie(app(CustomerQrAccess::class)->cookieName($branch), $cookie)
        ->postJson(route('qr.orders.store', $branch), qrPayload($product))->assertStatus(419);
    $this->assertDatabaseCount('orders', 0);
    qrNoEffects();
})->with(['malformed', 'expired', 'missing', 'foreign']);

test('tracking and receipt are session owned and receipt expires exactly 24 hours after payment', function () {
    $this->travelTo(now()->startOfSecond());
    [$branch, $session, $token, $product, $user] = qrFixture();
    $order = app(SubmitCustomerQrOrder::class)->execute($branch, $session, qrPayload($product));
    $this->withCredentials()->withCookie(app(CustomerQrAccess::class)->cookieName($branch), $token);
    $url = route('qr.orders.receipt', [$branch, $order->public_tracking_id]);
    $this->getJson($url)->assertNotFound();
    $other = CustomerQrSession::factory()->for($branch)->create();
    $foreign = app(SubmitCustomerQrOrder::class)->execute($branch, $other, qrPayload($product));
    $this->getJson(route('qr.orders.show', [$branch, $foreign->public_tracking_id]))->assertNotFound();
    $this->postJson(route('qr.broadcasting.auth', $branch), ['channel_name' => 'private-branch.'.$branch->id.'.pos', 'socket_id' => '1.2'])->assertForbidden();
    $this->postJson(route('qr.broadcasting.auth', $branch), ['channel_name' => 'private-order-tracking.'.$foreign->public_tracking_id, 'socket_id' => '1.2'])->assertNotFound();
    $this->actingAs($user)->withSession([ActiveBranchContext::SESSION_KEY => $branch->id]);
    $this->postJson(route('pos.qr-orders.load', $order))->assertOk();
    $this->postJson(route('pos.payments.store'), ['draft_order_id' => $order->id, 'payment_method' => 'cash', 'cash_received' => '200.00', 'idempotency_key' => (string) Str::uuid()])->assertOk();
    $this->getJson($url)->assertOk()->assertJsonPath('receipt.total', '190.00')->assertJsonMissingPath('receipt.id')->assertJsonMissingPath('receipt.cashier')->assertJsonMissingPath('receipt.payments.0.idempotency_key');
    $this->travel(24)->hours();
    $this->getJson($url)->assertGone();
    $this->assertModelExists($order);
});

test('scheduler archives untouched orders at 30 minutes and preserves claimed orders', function () {
    $this->travelTo(now()->startOfSecond());
    [$branch, $session, , $product, $user] = qrFixture();
    $order = app(SubmitCustomerQrOrder::class)->execute($branch, $session, qrPayload($product));
    $claimed = app(SubmitCustomerQrOrder::class)->execute($branch, CustomerQrSession::factory()->for($branch)->create(), qrPayload($product));
    $claimed->update(['loaded_by_user_id' => $user->id]);
    $this->travel(29)->minutes();
    $this->artisan('qr:archive-stale')->assertSuccessful();
    expect($order->fresh()->commercial_status)->toBe(CommercialStatus::Submitted);
    $this->travel(1)->minutes();
    $this->artisan('qr:archive-stale')->assertSuccessful();
    $this->artisan('qr:archive-stale')->assertSuccessful();
    expect($order->fresh()->commercial_status)->toBe(CommercialStatus::ArchivedUnclaimed);
    expect($order->fresh()->archive_reason)->toBe('stale_30_minutes');
    expect($claimed->fresh()->commercial_status)->toBe(CommercialStatus::Submitted);
    qrNoEffects();
});

test('staff QR endpoints enforce role branch and active account', function (string $case) {
    [$branch, $session, , $product, $user] = qrFixture($case === 'kitchen' ? 'kitchen_staff' : 'cashier');
    $order = app(SubmitCustomerQrOrder::class)->execute($branch, $session, qrPayload($product));
    if ($case === 'inactive') {
        $user->forceFill(['is_active' => false])->save();
    }
    if ($case === 'revoked') {
        $user->roles()->detach();
    }
    if ($case !== 'guest') {
        $this->actingAs($user)->withSession([ActiveBranchContext::SESSION_KEY => $branch->id]);
    }
    $response = $this->postJson(route('pos.qr-orders.load', $order));
    in_array($case, ['guest', 'inactive'], true) ? $response->assertUnauthorized() : $response->assertForbidden();
    expect($order->fresh()->loaded_by_user_id)->toBeNull();
    qrNoEffects();
})->with(['guest', 'kitchen', 'inactive', 'revoked']);

test('submission validates product customization table and quantity without partial snapshots', function (string $case) {
    [$branch, , $token, $product, , , $stock] = qrFixture();
    $payload = qrPayload($product);
    match ($case) {
        'product' => $payload['items'][0]['product_id'] = (string) Str::uuid(),
        'unavailable' => $product->update(['is_active' => false]),
        'out_of_stock' => $stock->update(['on_hand' => 0]),
        'quantity' => $payload['items'][0]['quantity'] = 0,
        'aggregate' => $payload['items'] = array_fill(0, 6, $payload['items'][0]),
        'modifier' => $payload['items'][0]['modifiers'] = [['group_id' => (string) Str::uuid(), 'option_id' => (string) Str::uuid()]],
        'foreign_table' => $payload['branch_table_id'] = BranchTable::factory()->create()->id,
        'inactive_table' => $payload['branch_table_id'] = BranchTable::factory()->for($branch)->create(['is_active' => false])->id,
    };
    $this->withCredentials()->withCookie(app(CustomerQrAccess::class)->cookieName($branch), $token)
        ->postJson(route('qr.orders.store', $branch), $payload)->assertUnprocessable();
    $this->assertDatabaseCount('orders', 0);
    $this->assertDatabaseCount('order_items', 0);
    qrNoEffects();
})->with(['product', 'unavailable', 'out_of_stock', 'quantity', 'aggregate', 'modifier', 'foreign_table', 'inactive_table']);

test('anonymous session cookie is hashed secure scoped and absent from public props', function () {
    $branch = Branch::factory()->create();
    $response = $this->get('https://localhost/kiosk/'.$branch->code)->assertOk();
    $cookie = $response->getCookie(app(CustomerQrAccess::class)->cookieName($branch));
    expect($cookie)->not->toBeNull();
    expect($cookie->isHttpOnly())->toBeTrue();
    expect($cookie->isSecure())->toBeTrue();
    expect($cookie->getSameSite())->toBe('lax');
    expect($cookie->getPath())->toBe('/');
    $session = CustomerQrSession::query()->sole();
    expect($session->token_hash)->toBe(hash('sha256', $cookie->getValue()));
    expect(json_encode($response->viewData('page')['props']))->not->toContain($cookie->getValue(), $session->token_hash, 'token_hash', 'opening_cash_amount');
});

test('public catalog hides operational inventory quantities and excludes another branch configuration', function () {
    [$branch, , $token, $product] = qrFixture();
    $other = Branch::factory()->create();
    BranchProduct::factory()->for($other)->for($product)->create(['price_override' => '999.00']);
    $response = $this->withCredentials()->withCookie(app(CustomerQrAccess::class)->cookieName($branch), $token)->get(route('kiosk.show', ['branch' => $branch->code]));
    $catalog = $response->viewData('page')['props']['catalog'];
    expect($catalog['products'][0]['effective_price'])->toBe('95.00');
    expect($catalog['products'][0])->not->toHaveKeys(['on_hand', 'tracks_inventory', 'low_stock_threshold']);
});

test('staff queue is bounded searchable branch scoped and load recovery preserves the claim', function () {
    [$branch, $session, , $product, $user] = qrFixture('cashier_kitchen');
    $order = app(SubmitCustomerQrOrder::class)->execute($branch, $session, qrPayload($product));
    [$otherBranch, $otherSession, , $otherProduct] = qrFixture();
    app(SubmitCustomerQrOrder::class)->execute($otherBranch, $otherSession, qrPayload($otherProduct));
    $this->actingAs($user)->withSession([ActiveBranchContext::SESSION_KEY => $branch->id]);
    $this->getJson(route('pos.qr-orders.index', ['search' => '#'.$order->order_number]))->assertOk()->assertJsonCount(1, 'orders.data')->assertJsonPath('orders.data.0.id', $order->id);
    $this->getJson(route('pos.qr-orders.index', ['search' => 'NO MATCH']))->assertJsonCount(0, 'orders.data');
    $this->postJson(route('pos.qr-orders.load', $order))->assertOk();
    $this->getJson(route('pos.qr-orders.index'))->assertJsonCount(0, 'orders.data');
    $this->get(route('workspaces.cashier'))->assertInertia(fn (AssertableInertia $page) => $page->where('loadedQr.id', $order->id));
    $second = app(SubmitCustomerQrOrder::class)->execute($branch, CustomerQrSession::factory()->for($branch)->create(), qrPayload($product));
    $this->postJson(route('pos.qr-orders.load', $second))->assertConflict();
    qrNoEffects();
});

test('load rejects foreign branch archived closed and changed eligibility orders', function (string $case) {
    [$branch, $session, , $product, $user, $store, $stock] = qrFixture();
    $table = BranchTable::factory()->for($branch)->create();
    $order = app(SubmitCustomerQrOrder::class)->execute($branch, $session, [...qrPayload($product), 'branch_table_id' => $table->id]);
    $expected = 422;
    if ($case === 'foreign') {
        $other = Branch::factory()->create();
        $user->branches()->attach($other, ['is_active' => true]);
        StoreSession::factory()->for($other)->create();
        $branch = $other;
        $expected = 404;
    } elseif ($case === 'archived') {
        app(ArchiveCustomerQrOrder::class)->execute($order, 'cashier_archived');
        $expected = 409;
    } elseif ($case === 'closed') {
        $store->update(['status' => 'closed']);
    } elseif ($case === 'table') {
        $table->update(['is_active' => false]);
    } elseif ($case === 'stock') {
        $stock->update(['on_hand' => 1]);
    } else {
        $product->update(['is_active' => false]);
    }
    $this->actingAs($user)->withSession([ActiveBranchContext::SESSION_KEY => $branch->id])
        ->postJson(route('pos.qr-orders.load', $order))->assertStatus($expected);
    expect($order->fresh()->loaded_by_user_id)->toBeNull();
    qrNoEffects();
})->with(['foreign', 'archived', 'closed', 'table', 'stock', 'product']);

test('payment actions reject unclaimed QR and revalidate changed stock after LOAD with atomic rollback', function (string $flow, bool $claimed) {
    [$branch, $session, , $product, $user, , $stock] = qrFixture();
    $order = app(SubmitCustomerQrOrder::class)->execute($branch, $session, qrPayload($product));
    $this->actingAs($user)->withSession([ActiveBranchContext::SESSION_KEY => $branch->id]);
    if ($claimed) {
        $this->postJson(route('pos.qr-orders.load', $order))->assertOk();
        $stock->update(['on_hand' => 1]);
    }
    $payload = ['idempotency_key' => (string) Str::uuid()];
    if ($flow === 'now') {
        $payload += ['draft_order_id' => $order->id, 'payment_method' => 'cash', 'cash_received' => '200.00'];
    }
    $this->postJson($flow === 'now' ? route('pos.payments.store') : route('pos.orders.pay-later.store', $order), $payload)->assertUnprocessable();
    qrNoEffects();
    expect($order->fresh()->committed_at)->toBeNull();
})->with(['now', 'later'])->with([false, true]);

test('anonymous customers cannot call staff load archive or payment endpoints', function () {
    [$branch, $session, $token, $product] = qrFixture();
    $order = app(SubmitCustomerQrOrder::class)->execute($branch, $session, qrPayload($product));
    $this->withCredentials()->withCookie(app(CustomerQrAccess::class)->cookieName($branch), $token);
    $this->postJson(route('pos.qr-orders.load', $order))->assertUnauthorized();
    $this->deleteJson(route('pos.qr-orders.destroy', $order))->assertUnauthorized();
    $this->getJson(route('pos.qr-orders.index'))->assertUnauthorized();
    $this->postJson(route('pos.payments.store'), ['draft_order_id' => $order->id, 'payment_method' => 'cash', 'cash_received' => '200.00', 'idempotency_key' => (string) Str::uuid()])->assertUnauthorized();
    qrNoEffects();
});

test('manual delete archives history and explicit reset alone permits the next customer order', function () {
    [$branch, $session, $token, $product, $user] = qrFixture();
    $order = app(SubmitCustomerQrOrder::class)->execute($branch, $session, qrPayload($product));
    $this->withCredentials()->withCookie(app(CustomerQrAccess::class)->cookieName($branch), $token);
    $this->postJson(route('qr.reset', $branch))->assertConflict();
    $this->actingAs($user)->withSession([ActiveBranchContext::SESSION_KEY => $branch->id]);
    $this->deleteJson(route('pos.qr-orders.destroy', $order))->assertOk();
    $this->deleteJson(route('pos.qr-orders.destroy', $order))->assertConflict();
    $this->getJson(route('pos.qr-orders.index', ['archived' => 1]))->assertJsonPath('orders.data.0.archive_reason', 'cashier_archived');
    $this->postJson(route('qr.orders.store', $branch), qrPayload($product))->assertConflict();
    $this->postJson(route('qr.reset', $branch))->assertOk();
    $this->postJson(route('qr.orders.store', $branch), qrPayload($product))->assertOk();
    $this->assertDatabaseCount('orders', 2);
    $this->assertModelExists($order);
    qrNoEffects();
});

test('QR size and instruction snapshots survive catalog changes through kitchen tracking completion and receipt', function () {
    [$branch, $session, $token, $product, $user] = qrFixture('cashier_kitchen');
    $product->update(['name' => 'Tapsilog']);
    $size = ModifierGroup::factory()->create(['name' => 'Size', 'semantic_role' => 'size', 'min_select' => 1, 'max_select' => 1]);
    $instruction = ModifierGroup::factory()->create(['name' => 'Egg', 'semantic_role' => 'instruction', 'min_select' => 1, 'max_select' => 1]);
    $product->modifierGroups()->attach([$size->id, $instruction->id]);
    $large = ModifierOption::factory()->for($size)->create(['name' => 'Large', 'price_delta' => '10.00']);
    $scrambled = ModifierOption::factory()->for($instruction)->create(['name' => 'Scrambled', 'price_delta' => '0.00']);
    $payload = qrPayload($product);
    $payload['items'][0]['modifiers'] = [['group_id' => $size->id, 'option_id' => $large->id], ['group_id' => $instruction->id, 'option_id' => $scrambled->id]];
    $this->withCredentials()->withCookie(app(CustomerQrAccess::class)->cookieName($branch), $token);
    $this->postJson(route('qr.orders.store', $branch), $payload)->assertOk()->assertJsonPath('order.items.0.display_name', 'Large Tapsilog')->assertJsonPath('order.total', '210.00');
    $order = Order::query()->sole();
    $this->assertDatabaseHas('order_item_modifiers', ['order_item_id' => $order->items()->sole()->id, 'modifier_group_id_snapshot' => $instruction->id, 'semantic_role_snapshot' => 'instruction', 'price_delta_snapshot' => '0.00']);
    $large->update(['price_delta' => '30.00']);
    $scrambled->update(['name' => 'Renamed egg']);
    $this->actingAs($user)->withSession([ActiveBranchContext::SESSION_KEY => $branch->id]);
    $this->get(route('workspaces.kitchen'))->assertInertia(fn (AssertableInertia $page) => $page->has('kitchenBoard.tickets', 0));
    $this->postJson(route('pos.qr-orders.load', $order))->assertOk();
    $this->postJson(route('pos.payments.store'), ['draft_order_id' => $order->id, 'payment_method' => 'cash', 'cash_received' => '250.00', 'idempotency_key' => (string) Str::uuid()])->assertOk();
    $this->get(route('workspaces.kitchen'))->assertInertia(fn (AssertableInertia $page) => $page->where('kitchenBoard.tickets.0.number', $order->fresh()->order_number)
        ->where('kitchenBoard.tickets.0.items.0.display_name', 'Large Tapsilog')->where('kitchenBoard.tickets.0.items.0.instructions', ['Scrambled'])->where('kitchenBoard.tickets.0.items.0.note', 'Less salt'));
    Event::fake([CustomerTrackingChanged::class]);
    foreach (['preparing', 'ready', 'done'] as $status) {
        $this->patch(route('orders.kitchen-status.update', $order), ['status' => $status])->assertRedirect();
        $this->getJson(route('qr.orders.show', [$branch, $order->public_tracking_id]))->assertOk()->assertJsonPath('order.kitchen_status', $status);
    }
    Event::assertDispatched(CustomerTrackingChanged::class, 3);
    $this->getJson(route('qr.orders.receipt', [$branch, $order->public_tracking_id]))->assertOk()->assertJsonPath('receipt.total', '210.00')->assertJsonPath('receipt.items.0.display_name', 'Large Tapsilog');
    $this->postJson(route('qr.reset', $branch))->assertOk();
    $this->assertDatabaseCount('kitchen_tickets', 1);
    $this->assertDatabaseCount('inventory_movements', 1);
});

test('a different cashier cannot commit another cashier claim', function (string $flow) {
    [$branch, $session, , $product, $user] = qrFixture();
    $order = app(SubmitCustomerQrOrder::class)->execute($branch, $session, qrPayload($product));
    app(LoadCustomerQrOrder::class)->execute($user, $branch, $order);
    $other = User::factory()->create();
    $other->roles()->attach(Role::where('name', 'cashier')->sole());
    $other->branches()->attach($branch, ['is_active' => true]);
    $payload = ['idempotency_key' => (string) Str::uuid()];
    if ($flow === 'now') {
        $payload += ['draft_order_id' => $order->id, 'payment_method' => 'cash', 'cash_received' => '200.00'];
    }
    $this->actingAs($other)->withSession([ActiveBranchContext::SESSION_KEY => $branch->id])
        ->postJson($flow === 'now' ? route('pos.payments.store') : route('pos.orders.pay-later.store', $order), $payload)->assertUnprocessable();
    expect($order->fresh()->loaded_by_user_id)->toBe($user->id);
    qrNoEffects();
})->with(['now', 'later']);

test('load and archive reject submitted orders that already have payment or kitchen state', function (string $field, string $value) {
    [$branch, $session, , $product, $user] = qrFixture();
    $order = app(SubmitCustomerQrOrder::class)->execute($branch, $session, qrPayload($product));
    $order->update([$field => $value, 'submitted_at' => now()->subMinutes(31)]);
    $this->actingAs($user)->withSession([ActiveBranchContext::SESSION_KEY => $branch->id]);
    $this->postJson(route('pos.qr-orders.load', $order))->assertConflict();
    expect(app(ArchiveCustomerQrOrder::class)->execute($order))->toBeFalse();
    expect($order->fresh()->commercial_status)->toBe(CommercialStatus::Submitted);
    qrNoEffects();
})->with([['payment_status', 'paid'], ['kitchen_status', 'kitchen'], ['payment_term', 'pay_later']]);

test('QR numbers use a separate counter per store session and delayed daily official identity', function () {
    $this->travelTo(now()->setTimezone('Asia/Manila')->setDate(2026, 9, 22)->setTime(12, 0));
    [$branch, $session, , $product, $user, $store] = qrFixture();
    $first = app(SubmitCustomerQrOrder::class)->execute($branch, $session, qrPayload($product));
    expect($first->qr_sequence)->toBe(1)->and($first->order_number)->toBeNull()->and($first->reference_number)->toBeNull();
    $this->assertDatabaseCount('order_number_counters', 0);
    $second = app(SubmitCustomerQrOrder::class)->execute($branch, CustomerQrSession::factory()->for($branch)->create(), qrPayload($product));
    expect($second->qr_sequence)->toBe(2);
    app(LoadCustomerQrOrder::class)->execute($user, $branch, $first);
    expect($first->fresh()->order_number)->toBeNull();
    app(CancelLoadedCustomerQrOrder::class)->execute($user, $branch, $first);
    expect($first->fresh()->loaded_by_user_id)->toBeNull()->and($first->fresh()->order_number)->toBeNull();
    qrNoEffects();
    $store->update(['status' => 'closed']);
    StoreSession::factory()->for($branch)->create();
    $third = app(SubmitCustomerQrOrder::class)->execute($branch, CustomerQrSession::factory()->for($branch)->create(), qrPayload($product));
    expect($third->qr_sequence)->toBe(1);
    app(LoadCustomerQrOrder::class)->execute($user, $branch, $third);
    $committed = app(CommitPayLaterOrder::class)->execute($user, $branch, $third, ['idempotency_key' => (string) Str::uuid()]);
    expect($committed->order_number)->toBe('1001')->and($committed->reference_number)->toBe($branch->code.'-092226-0001');
    $this->travel(1)->days();
    $identity = app(OrderNumber::class)->allocate($branch, now());
    expect($identity['reference_number'])->toBe($branch->code.'-092326-0001');
    expect($committed->fresh()->reference_number)->toBe($branch->code.'-092226-0001');
});

test('cancel load is owned and preserves the submitted snapshot without effects', function () {
    [$branch, $session, , $product, $user] = qrFixture();
    $order = app(SubmitCustomerQrOrder::class)->execute($branch, $session, qrPayload($product));
    app(LoadCustomerQrOrder::class)->execute($user, $branch, $order);
    $other = User::factory()->create();
    $other->roles()->attach(Role::where('name', 'cashier')->sole());
    $other->branches()->attach($branch, ['is_active' => true]);
    $this->actingAs($other)->withSession([ActiveBranchContext::SESSION_KEY => $branch->id]);
    $this->postJson(route('pos.qr-orders.cancel-load', $order))->assertConflict();
    $this->actingAs($user)->postJson(route('pos.qr-orders.cancel-load', $order))->assertOk();
    expect($order->fresh()->loaded_by_user_id)->toBeNull()->and($order->fresh()->qr_sequence)->toBe(1);
    expect($order->items()->sole()->notes)->toBe('Less salt');
    qrNoEffects();
});

test('restore renews the archive deadline and rejects conflicting customer orders', function () {
    [$branch, $session, , $product, $user] = qrFixture();
    $order = app(SubmitCustomerQrOrder::class)->execute($branch, $session, qrPayload($product));
    app(ArchiveCustomerQrOrder::class)->execute($order, 'cashier_archived');
    $this->travel(31)->minutes();
    $this->actingAs($user)->withSession([ActiveBranchContext::SESSION_KEY => $branch->id]);
    $this->postJson(route('pos.qr-orders.restore', $order))->assertOk()->assertJsonPath('order.qr_number', 'QR-01');
    expect($order->fresh()->archived_at)->toBeNull()->and($order->fresh()->archive_reason)->toBeNull();
    expect(app(ArchiveCustomerQrOrder::class)->execute($order))->toBeFalse();
    qrNoEffects();
    app(ArchiveCustomerQrOrder::class)->execute($order, 'cashier_archived');
    $session->update(['active_order_id' => null]);
    app(SubmitCustomerQrOrder::class)->execute($branch, $session, qrPayload($product));
    $this->postJson(route('pos.qr-orders.restore', $order))->assertConflict();
});

test('loaded QR metadata commits with either payment path and rejects changed replay', function (string $method) {
    [$branch, $session, , $product, $user] = qrFixture();
    $order = app(SubmitCustomerQrOrder::class)->execute($branch, $session, qrPayload($product));
    app(LoadCustomerQrOrder::class)->execute($user, $branch, $order);
    $table = BranchTable::factory()->for($branch)->create();
    $this->actingAs($user)->withSession([ActiveBranchContext::SESSION_KEY => $branch->id]);
    $data = ['idempotency_key' => (string) Str::uuid(), 'qr_metadata' => ['customer_label' => '', 'branch_table_id' => $table->id]];
    if ($method === 'now') {
        $data += ['draft_order_id' => $order->id, 'payment_method' => 'cash', 'cash_received' => '200.00'];
    }
    $url = $method === 'now' ? route('pos.payments.store') : route('pos.orders.pay-later.store', $order);
    $this->postJson($url, $data)->assertOk();
    expect($order->fresh()->customer_label)->toBeNull()->and($order->fresh()->branch_table_id)->toBe($table->id);
    expect($order->items()->sole()->unit_price)->toBe('95.00');
    $this->postJson($url, $data)->assertOk();
    $data['qr_metadata']['customer_label'] = 'Changed';
    $this->postJson($url, $data)->assertConflict();
    $this->postJson(route('pos.qr-orders.cancel-load', $order))->assertConflict();
})->with(['now', 'later']);

test('previous paid receipt remains owned after a new QR order until its own exact expiry', function () {
    $this->travelTo(now()->startOfSecond());
    [$branch, $session, $token, $product, $user] = qrFixture('cashier_kitchen');
    $order = app(SubmitCustomerQrOrder::class)->execute($branch, $session, qrPayload($product));
    app(LoadCustomerQrOrder::class)->execute($user, $branch, $order);
    app(PayNowOrder::class)->execute($user, $branch, ['draft_order_id' => $order->id, 'payment_method' => 'cash', 'cash_received' => '200.00', 'idempotency_key' => (string) Str::uuid()]);
    app(TransitionKitchenOrder::class)->execute($user, $branch, $order, KitchenStatus::Done);
    $this->withCredentials()->withCookie(app(CustomerQrAccess::class)->cookieName($branch), $token);
    $this->postJson(route('qr.reset', $branch))->assertOk();
    $this->postJson(route('qr.orders.store', $branch), qrPayload($product))->assertOk();
    expect($session->fresh()->active_order_id)->not->toBe($order->id);
    $url = route('qr.orders.receipt', [$branch, $order->public_tracking_id]);
    $this->getJson($url)->assertOk();
    $this->travel(24)->hours();
    $this->getJson($url)->assertStatus(410);
    $this->assertModelExists($order);
});

test('kitchen progress persists actual transition times and clears downstream rollback times', function () {
    $this->travelTo(now()->startOfSecond());
    [$branch, $session, , $product, $user] = qrFixture('cashier_kitchen');
    $order = app(SubmitCustomerQrOrder::class)->execute($branch, $session, qrPayload($product));
    app(LoadCustomerQrOrder::class)->execute($user, $branch, $order);
    $order = app(CommitPayLaterOrder::class)->execute($user, $branch, $order, ['idempotency_key' => (string) Str::uuid()]);
    $transition = app(TransitionKitchenOrder::class);
    $this->travel(1)->minutes();
    $order = $transition->execute($user, $branch, $order, KitchenStatus::Preparing);
    $prepared = $order->preparing_at->toIso8601String();
    expect($order->preparing_at->eq(now()))->toBeTrue();
    $this->travel(1)->minutes();
    $order = $transition->execute($user, $branch, $order, KitchenStatus::Ready);
    expect($order->ready_at->eq(now()))->toBeTrue();
    $order = $transition->execute($user, $branch, $order, KitchenStatus::Preparing);
    expect($order->ready_at)->toBeNull()->and($order->preparing_at->toIso8601String())->toBe($prepared);
    $order = $transition->execute($user, $branch, $order, KitchenStatus::Kitchen);
    expect($order->preparing_at)->toBeNull()->and($order->ready_at)->toBeNull()->and($order->completed_at)->toBeNull();
});

test('owner QR settings enforce authorization and persist only validated public configuration', function () {
    [$branch, $session, $token, $product, $cashier] = qrFixture();
    $owner = User::factory()->create();
    $owner->roles()->attach(Role::where('name', 'owner')->sole());
    $url = route('branches.qr-settings.update', $branch);
    $this->actingAs($cashier)->putJson($url, ['qr_ordering_enabled' => false])->assertForbidden();
    $this->actingAs($owner)->putJson($url, ['qr_ordering_enabled' => false, 'receipt_footer' => 'Thank you', 'website_url' => 'javascript:alert(1)'])->assertUnprocessable();
    $this->putJson($url, ['qr_ordering_enabled' => false, 'receipt_footer' => 'Thank you', 'website_url' => 'https://example.com'])->assertOk();
    expect($branch->fresh()->qr_ordering_enabled)->toBeFalse()->and($branch->fresh()->receipt_footer)->toBe('Thank you');
    $this->withCredentials()->withCookie(app(CustomerQrAccess::class)->cookieName($branch), $token)->postJson(route('qr.orders.store', $branch), qrPayload($product))->assertUnprocessable();
    qrNoEffects();
});

test('kiosk activity deduplicates opens and exposes only bounded branch date history to owners', function () {
    $this->travelTo(now()->startOfSecond());
    [$branch, , $token] = qrFixture();
    $this->withCredentials()->withCookie(app(CustomerQrAccess::class)->cookieName($branch), $token);
    $url = route('kiosk.show', ['branch' => $branch->code]);
    $this->get($url)->assertOk();
    $this->get($url)->assertOk();
    $this->assertDatabaseCount('customer_qr_visits', 1);
    $this->travel(3)->minutes();
    $this->get($url)->assertOk();
    $this->assertDatabaseCount('customer_qr_visits', 2);
    $owner = User::factory()->create();
    $owner->roles()->attach(Role::where('name', 'owner')->sole());
    $result = $this->actingAs($owner)->getJson(route('branches.qr-history', $branch))->assertOk()->assertJsonPath('count', 2);
    expect(array_keys($result->json('visits.data.0')))->toBe(['visited_at']);
    $this->getJson(route('branches.qr-history', [$branch, 'date' => now('Asia/Manila')->subDay()->toDateString()]))->assertOk()->assertJsonPath('count', 0);
    expect(Schema::getColumnListing('customer_qr_visits'))->not->toContain('ip_address', 'user_agent', 'token_hash');
});

test('ineligible QR restore is rejected without side effects', function (string $reason) {
    [$branch, $session, , $product, $user, $store] = qrFixture();
    $order = app(SubmitCustomerQrOrder::class)->execute($branch, $session, qrPayload($product));
    app(ArchiveCustomerQrOrder::class)->execute($order, 'cashier_archived');
    $order->refresh();
    match ($reason) {
        'expired' => $session->update(['expires_at' => now()->subMinute()]),
        'closed' => $store->update(['status' => 'closed']),
        'loaded' => $order->update(['loaded_by_user_id' => $user->id]),
        'wrong_state' => $order->update(['commercial_status' => CommercialStatus::Submitted]),
        'foreign_branch' => $user->branches()->detach($branch),
    };
    $this->actingAs($user)->withSession([ActiveBranchContext::SESSION_KEY => $branch->id]);
    $response = $this->postJson(route('pos.qr-orders.restore', $order));
    $reason === 'foreign_branch' ? $response->assertRedirect(route('workspace')) : $response->assertConflict();
    expect($order->fresh()->order_number)->toBeNull();
    qrNoEffects();
})->with(['expired', 'closed', 'loaded', 'wrong_state', 'foreign_branch']);

test('failed QR commercial commitment rolls back both identity counters and all effects', function (string $method) {
    [$branch, $session, , $product, $user, , $stock] = qrFixture();
    $order = app(SubmitCustomerQrOrder::class)->execute($branch, $session, qrPayload($product));
    app(LoadCustomerQrOrder::class)->execute($user, $branch, $order);
    $stock->update(['on_hand' => 0]);
    $this->actingAs($user)->withSession([ActiveBranchContext::SESSION_KEY => $branch->id]);
    $payload = ['idempotency_key' => (string) Str::uuid()];
    if ($method === 'now') {
        $payload += ['draft_order_id' => $order->id, 'payment_method' => 'cash', 'cash_received' => '200.00'];
    }
    $this->postJson($method === 'now' ? route('pos.payments.store') : route('pos.orders.pay-later.store', $order), $payload)->assertUnprocessable();
    expect($order->fresh()->order_number)->toBeNull()->and($order->fresh()->reference_number)->toBeNull();
    $this->assertDatabaseCount('order_number_counters', 0);
    $this->assertDatabaseCount('order_reference_counters', 0);
    qrNoEffects();
})->with(['now', 'later']);
