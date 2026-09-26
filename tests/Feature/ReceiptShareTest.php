<?php

use App\Actions\Orders\CreatePosDraftOrder;
use App\Actions\Orders\SubmitCustomerQrOrder;
use App\Models\Branch;
use App\Models\BranchInventory;
use App\Models\BranchProduct;
use App\Models\CustomerQrSession;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Role;
use App\Models\StoreSession;
use App\Models\User;
use App\Support\ActiveBranchContext;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->travelTo(now()->startOfSecond());
});

/** @param array<string, mixed> $attributes
 * @return array{Order, User}
 */
function receiptShareFixture(array $attributes = []): array
{
    $branch = Branch::factory()->create(['receipt_name' => 'Receipt branch', 'receipt_address' => 'Receipt address', 'receipt_contact' => '09170000000', 'receipt_footer' => 'Thank you!', 'receipt_show_logo' => true, 'receipt_logo_path' => 'receipt-logos/example.png']);
    $user = User::factory()->create();
    $user->roles()->attach(Role::query()->where('name', 'cashier')->sole());
    $user->branches()->attach($branch, ['is_active' => true]);
    $order = Order::factory()->for($branch)->create([...['payment_status' => 'paid', 'order_number' => '1001', 'reference_number' => 'MAIN-092226-0001'], ...$attributes]);
    Payment::factory()->for($order)->create(['paid_at' => now()->subHours(2)]);

    return [$order, $user];
}

test('receipt sharing rejects guests missing permission and foreign branch orders', function () {
    [$order, $user] = receiptShareFixture();
    $url = route('pos.orders.receipt-share', $order);
    $this->postJson($url)->assertUnauthorized();
    $this->actingAs(User::factory()->create())->postJson($url)->assertForbidden();
    $foreign = Order::factory()->create();
    $this->actingAs($user)->postJson(route('pos.orders.receipt-share', $foreign))->assertNotFound();
    $user->branches()->detach();
    $this->withSession([ActiveBranchContext::SESSION_KEY => $order->branch_id])->postJson($url)->assertRedirect(route('workspace'));
});

test('receipt sharing rejects unpaid missing identity missing payment and expired receipts', function (string $state, int $status) {
    [$order, $user] = receiptShareFixture(match ($state) {
        'number' => ['source' => 'customer_qr', 'order_number' => null],
        'reference' => ['reference_number' => null],
        default => [],
    });
    match ($state) {
        'unpaid' => $order->update(['payment_status' => 'unpaid']),
        'number', 'reference' => null,
        'payment' => $order->payments()->delete(),
        'expired' => $this->travel(22)->hours(),
    };
    $this->actingAs($user)->postJson(route('pos.orders.receipt-share', $order))->assertStatus($status);
})->with([['unpaid', 404], ['number', 404], ['reference', 404], ['payment', 410], ['expired', 410]]);

test('signed receipt is public private and carries only customer receipt fields and persisted branding', function () {
    [$order, $user] = receiptShareFixture();
    $response = $this->actingAs($user)->postJson(route('pos.orders.receipt-share', $order))->assertOk();
    $path = $response->json('url');
    expect($path)->toStartWith('/receipt/'.$order->id.'?');
    expect($response->json('expires_at'))->toBe(now()->addHours(22)->toIso8601String());
    expect($response->json('qr_image'))->toStartWith('data:image/svg+xml;base64,');
    $this->get($path)->assertInertia(fn (AssertableInertia $page) => $page->component('public-receipt')->missing('auth')->missing('branchContext')->missing('storeContext')->where('receipt.order_number', '1001'));
    auth()->forgetGuards();
    $public = $this->getJson($path)->assertOk()->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonPath('receipt.reference_number', 'MAIN-092226-0001')
        ->assertJsonPath('receipt.branch.name', 'Receipt branch')->assertJsonPath('receipt.branch.address', 'Receipt address')
        ->assertJsonPath('receipt.branch.contact', '09170000000')->assertJsonPath('receipt.branch.footer', 'Thank you!')
        ->assertJsonPath('receipt.branch.show_logo', true)->assertJsonPath('receipt.payments.0.amount', '100.00');
    expect(array_keys($public->json('receipt')))->toEqualCanonicalizing(['order_number', 'reference_number', 'paid_at', 'receipt_expires_at', 'order_type', 'customer_label', 'table_name', 'items', 'subtotal', 'total', 'branch', 'payments']);
    expect(array_keys($public->json('receipt.payments.0')))->toEqualCanonicalizing(['method', 'amount', 'amount_received', 'change_amount']);
    expect($public->json('receipt.branch.logo_url'))->toContain('/branches/'.$order->branch_id.'/receipt-logo?v=');
    $order->branch->update(['receipt_show_logo' => false]);
    $this->getJson($path)->assertOk()->assertJsonPath('receipt.branch.show_logo', false);
});

test('signature rejects unsigned URLs changed signatures order paths and expiry values', function () {
    [$order, $user] = receiptShareFixture();
    $path = $this->actingAs($user)->postJson(route('pos.orders.receipt-share', $order))->json('url');
    $other = Order::factory()->create();
    $this->getJson(route('receipt.show', $order))->assertForbidden();
    $this->getJson($path.'0')->assertForbidden();
    $this->getJson(str_replace($order->id, $other->id, $path))->assertForbidden();
    $this->getJson(preg_replace('/expires=\d+/', 'expires=9999999999', $path))->assertForbidden();
});

test('receipt lifetime never extends on reopening and backend expiry overrides a longer valid signature', function () {
    [$order, $user] = receiptShareFixture();
    $path = $this->actingAs($user)->postJson(route('pos.orders.receipt-share', $order))->json('url');
    $this->travel(21)->hours();
    $this->postJson(route('pos.orders.receipt-share', $order))->assertOk()->assertJsonPath('url', $path);
    $this->getJson($path)->assertOk();
    $this->travel(1)->hours();
    $this->getJson($path)->assertGone();
    $this->postJson(route('pos.orders.receipt-share', $order))->assertGone();
    $longer = URL::temporarySignedRoute('receipt.show', now()->addDay(), ['order' => $order->id], absolute: false);
    $this->getJson($longer)->assertGone();
    $shorter = URL::temporarySignedRoute('receipt.show', now()->subSecond(), ['order' => $order->id], absolute: false);
    $this->getJson($shorter)->assertGone();
});

test('the public receipt page is not part of the installable staff app', function () {
    [$order, $user] = receiptShareFixture();
    $path = $this->actingAs($user)->postJson(route('pos.orders.receipt-share', $order))->json('url');
    auth()->forgetGuards();

    $this->get($path)->assertOk()
        ->assertDontSee('rel="manifest"', false)
        ->assertDontSee('apple-mobile-web-app-capable', false)
        ->assertDontSee('id="pwa-boot"', false);
});

test('signed public receipt still rejects an order that is no longer paid', function () {
    [$order, $user] = receiptShareFixture();
    $path = $this->actingAs($user)->postJson(route('pos.orders.receipt-share', $order))->json('url');
    $order->update(['payment_status' => 'unpaid']);
    $this->getJson($path)->assertNotFound();
});

test('voided orders cannot create or reopen a receipt', function () {
    [$order, $user] = receiptShareFixture();
    $path = $this->actingAs($user)->postJson(route('pos.orders.receipt-share', $order))->assertOk()->json('url');

    $order->update(['commercial_status' => 'voided', 'voided_at' => now()]);

    $this->postJson(route('pos.orders.receipt-share', $order))->assertNotFound();
    auth()->forgetGuards();
    $this->getJson($path)->assertNotFound();
});

test('LAN QR uses the request origin and relative signature survives a different host', function () {
    [$order, $user] = receiptShareFixture();
    $response = $this->actingAs($user)->postJson('http://192.168.1.25:8000/pos/orders/'.$order->id.'/receipt-share')->assertOk();
    $path = $response->json('url');
    $writer = new Writer(new ImageRenderer(new RendererStyle(320), new SvgImageBackEnd));
    expect(base64_decode(explode(',', $response->json('qr_image'), 2)[1]))->toBe($writer->writeString('http://192.168.1.25:8000'.$path));
    $this->getJson('https://receipt.example'.$path)->assertOk();
});

test('real paid direct POS and loaded QR orders share the same receipt after pay now or later settlement', function (string $source, string $term) {
    $branch = Branch::factory()->create();
    $user = User::factory()->create();
    $user->roles()->attach(Role::query()->where('name', 'cashier')->sole());
    $user->branches()->attach($branch, ['is_active' => true]);
    StoreSession::factory()->for($branch)->create();
    $product = Product::factory()->create(['name' => 'Tapsilog', 'default_price' => '95.00']);
    BranchProduct::factory()->for($branch)->for($product)->create(['tracks_inventory' => true]);
    $stock = BranchInventory::factory()->for($branch)->for($product)->create(['on_hand' => 10]);
    $payload = ['order_type' => 'take_out', 'customer_label' => 'Receipt customer', 'items' => [['product_id' => $product->id, 'quantity' => 2, 'notes' => 'Less salt', 'modifiers' => []]]];
    $this->actingAs($user);
    if ($source === 'customer_qr') {
        $session = CustomerQrSession::factory()->for($branch)->create();
        $order = app(SubmitCustomerQrOrder::class)->execute($branch, $session, [...$payload, 'idempotency_key' => (string) Str::uuid()]);
        $this->postJson(route('pos.qr-orders.load', $order))->assertOk();
    } else {
        $order = app(CreatePosDraftOrder::class)->execute($user, $branch, $payload);
    }
    $shareRoute = route('pos.orders.receipt-share', $order);
    $this->postJson($shareRoute)->assertNotFound();
    $payment = ['payment_method' => 'cash', 'cash_received' => '200.00', 'idempotency_key' => (string) Str::uuid()];
    if ($term === 'later') {
        $this->postJson(route('pos.orders.pay-later.store', $order), ['idempotency_key' => (string) Str::uuid()])->assertOk();
        $this->postJson($shareRoute)->assertNotFound();
        $this->postJson(route('pos.orders.settlements.store', $order), $payment)->assertOk();
    } else {
        $this->postJson(route('pos.payments.store'), [...$payment, 'draft_order_id' => $order->id])->assertOk();
    }
    $order->refresh();
    $path = $this->postJson($shareRoute)->assertOk()->json('url');
    auth()->forgetGuards();
    $this->getJson($path)->assertOk()->assertJsonPath('receipt.order_number', $order->order_number)
        ->assertJsonPath('receipt.reference_number', $order->reference_number)
        ->assertJsonPath('receipt.items.0.name', 'Tapsilog')->assertJsonPath('receipt.items.0.quantity', 2)
        ->assertJsonPath('receipt.items.0.notes', 'Less salt')->assertJsonPath('receipt.total', '190.00')
        ->assertJsonPath('receipt.payments.0.amount_received', '200.00')->assertJsonPath('receipt.payments.0.change_amount', '10.00');
    expect($stock->fresh()->on_hand)->toBe(8);
    $this->assertDatabaseCount('payments', 1);
    $this->assertDatabaseCount('inventory_movements', 1);
    $this->assertDatabaseCount('kitchen_tickets', 1);
})->with([['pos', 'now'], ['pos', 'later'], ['customer_qr', 'now'], ['customer_qr', 'later']]);
