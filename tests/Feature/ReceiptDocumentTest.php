<?php

use App\Enums\ModifierSemanticRole;
use App\Enums\PaymentMethod;
use App\Enums\ReceiptAudience;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemModifier;
use App\Models\Payment;
use App\Models\Role;
use App\Models\StoreSession;
use App\Models\User;
use App\Support\ReceiptDocument;
use App\Support\ReceiptLayout;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    $this->seed(RbacSeeder::class);
});

/**
 * A paid Dine-in order: Tapsilog (options entered Instruction → Add-on → Size) and Water, paid Cashless then Cash.
 *
 * @param  array<string, mixed>  $branch
 */
function canonicalReceiptOrder(array $branch = []): Order
{
    test()->travelTo(now()->startOfSecond());
    $order = Order::factory()->for(Branch::factory()->create([
        'receipt_name' => 'Pongskilog Main', 'address' => 'Branch address', 'contact' => 'Branch contact',
        'receipt_footer' => 'Salamat!', ...$branch,
    ]))->create([
        'order_number' => '1045', 'reference_number' => 'MAIN-090726-0001', 'order_type' => 'dine_in',
        'customer_label' => 'Juan', 'table_name_snapshot' => 'Table 4', 'commercial_status' => 'completed',
        'payment_status' => 'paid', 'subtotal' => '240.00', 'total' => '240.00', 'committed_at' => now()->subMinutes(10),
    ]);
    $meal = OrderItem::factory()->for($order)->create(['product_name_snapshot' => 'Tapsilog', 'unit_price' => '95.00', 'quantity' => 2, 'line_total' => '220.00', 'notes' => 'No onions']);
    OrderItemModifier::factory()->for($meal)->create(['group_name_snapshot' => 'Notes', 'semantic_role_snapshot' => ModifierSemanticRole::Instruction->value, 'option_name_snapshot' => 'Less rice', 'price_delta_snapshot' => '0.00']);
    OrderItemModifier::factory()->for($meal)->create(['group_name_snapshot' => 'Add-ons', 'semantic_role_snapshot' => null, 'option_name_snapshot' => 'Extra egg', 'price_delta_snapshot' => '15.00']);
    OrderItemModifier::factory()->for($meal)->create(['group_name_snapshot' => 'Size', 'semantic_role_snapshot' => ModifierSemanticRole::Size->value, 'option_name_snapshot' => 'Large', 'price_delta_snapshot' => '0.00']);
    OrderItem::factory()->for($order)->create(['product_name_snapshot' => 'Bottled Water', 'unit_price' => '20.00', 'quantity' => 1, 'line_total' => '20.00']);
    $cashier = User::factory()->create(['name' => 'Ana Marie Reyes', 'preferred_name' => 'Ana']);
    $session = StoreSession::factory()->create(['branch_id' => $order->branch_id]);
    $order->update(['store_session_id' => $session->id]);
    Payment::factory()->for($order)->create(['method' => PaymentMethod::Cash, 'amount' => '140.00', 'amount_received' => '200.00', 'change_amount' => '60.00', 'paid_at' => now()->subMinutes(2), 'store_session_id' => $session->id, 'created_by_user_id' => User::factory()->create(['name' => 'Ben Cruz'])->id]);
    Payment::factory()->for($order)->create(['method' => PaymentMethod::Cashless, 'amount' => '100.00', 'amount_received' => null, 'change_amount' => null, 'paid_at' => now()->subMinutes(3), 'store_session_id' => $session->id, 'created_by_user_id' => $cashier->id]);

    return $order;
}

test('one receipt document prints the saved snapshot in entry order with every payment and the cashier Preferred Name', function () {
    $order = canonicalReceiptOrder();
    $order->items()->first()->product()->update(['name' => 'Renamed today', 'default_price' => '999.00']);

    $receipt = app(ReceiptDocument::class)->for($order, ReceiptAudience::Customer);

    expect($receipt['branch'])->toMatchArray(['name' => 'Pongskilog Main', 'address' => 'Branch address', 'contact' => 'Branch contact', 'footer' => 'Salamat!', 'logo_url' => '/images/branding/logo.png'])
        ->and(array_column($receipt['items'], 'name'))->toBe(['Tapsilog', 'Bottled Water'])
        ->and($receipt['items'][0]['unit_price'])->toBe('95.00')
        ->and($receipt['items'][0]['line_total'])->toBe('220.00')
        ->and($receipt['items'][0]['size_prefix'])->toBe('Large')
        ->and($receipt['items'][0]['notes'])->toBe('No onions')
        ->and(array_column($receipt['items'][0]['modifiers'], 'name'))->toBe(['Large', 'Extra egg', 'Less rice'])
        ->and(array_column($receipt['payments'], 'method'))->toBe(['cashless', 'cash'])
        ->and($receipt['payments'][1])->toBe(['method' => 'cash', 'amount' => '140.00', 'amount_received' => '200.00', 'change_amount' => '60.00'])
        ->and($receipt['cashier'])->toBe('Ana')
        ->and($receipt['paid_at'])->toBe(now()->subMinutes(2)->toIso8601String())
        ->and($receipt['customer_label'])->toBe('Juan')
        ->and($receipt['table_name'])->toBe('Table 4')
        ->and($receipt['money'])->toBe(['paid' => '240.00', 'refunded' => '0.00', 'balance' => '0.00'])
        ->and($receipt['layout']['blocks'])->toBe(['logo', 'store', 'address', 'contact', 'header_text', 'order', 'date', 'cashier', 'customer', 'items', 'totals', 'payments', 'custom_rows', 'footer'])
        ->and($receipt)->not->toHaveKeys(['id', 'store_session_id', 'voided_at', 'amount_paid'])
        ->and($receipt['items'][0])->not->toHaveKey('id')
        ->and($receipt['payments'][0])->not->toHaveKey('id');
});

test('the audience only removes what its viewer may not see', function () {
    $order = canonicalReceiptOrder();
    $documents = app(ReceiptDocument::class);

    $staff = $documents->for($order, ReceiptAudience::Staff);
    expect($staff['id'])->toBe($order->id)
        ->and($staff['store_session_id'])->toBe($order->store_session_id)
        ->and($staff['items'][0])->toHaveKey('id')
        ->and($staff['payments'][0])->toHaveKeys(['id', 'payment_group_id', 'payment_context', 'invoice']);

    $pickup = $documents->for($order, ReceiptAudience::Pickup);
    expect($pickup['customer_label'])->toBeNull()
        ->and($pickup['table_name'])->toBeNull()
        ->and($pickup['items'][0]['notes'])->toBeNull()
        ->and(array_column($pickup['items'][0]['modifiers'], 'name'))->toBe(['Large', 'Extra egg', 'Less rice'])
        ->and($pickup['cashier'])->toBe('Ana');

    $cashier = $order->payments()->where('method', 'cashless')->sole()->createdBy;
    $cashier->update(['preferred_name' => null]);
    expect($documents->for($order, ReceiptAudience::SharedLink)['cashier'])->toBe('Ana')
        ->and(AuditLog::query()->count())->toBe(0);
});

test('Receipt Settings decide which blocks print, in which order, with plain header and custom rows', function () {
    $order = canonicalReceiptOrder([
        'qr_ordering_enabled' => true,
        'receipt_layout' => [
            'hidden' => ['address', 'cashier', 'footer'],
            'details' => ['date', 'order', 'customer', 'cashier'],
            'footer' => ['order_qr', 'custom_rows', 'footer'],
            'header_text' => '<b>Open</b> daily',
            'custom_rows' => ['Wi-Fi: pongskilog', '  ', 'Follow us'],
            'separator' => 'solid',
        ],
    ]);

    $receipt = app(ReceiptDocument::class)->for($order, ReceiptAudience::Customer);

    expect($receipt['layout']['blocks'])->toBe(['logo', 'store', 'contact', 'header_text', 'date', 'order', 'customer', 'items', 'totals', 'payments', 'order_qr', 'custom_rows'])
        ->and($receipt['branch']['address'])->toBeNull()
        ->and($receipt['branch']['footer'])->toBeNull()
        ->and($receipt['cashier'])->toBeNull()
        ->and($receipt['layout']['header_text'])->toBe('Open daily')
        ->and($receipt['layout']['custom_rows'])->toBe(['Wi-Fi: pongskilog', 'Follow us'])
        ->and($receipt['layout']['separator'])->toBe('solid')
        ->and($receipt['layout']['order_qr']['url'])->toBe(route('kiosk.show', ['branch' => $order->branch->kiosk_code]))
        ->and($receipt['layout']['order_qr']['image'])->toStartWith('data:image/svg+xml;base64,');

    $order->branch->update(['qr_ordering_enabled' => false, 'receipt_show_logo' => false]);
    $receipt = app(ReceiptDocument::class)->for($order->fresh(), ReceiptAudience::Customer);
    expect($receipt['layout']['order_qr'])->toBeNull()
        ->and($receipt['layout']['blocks'])->not->toContain('logo');
});

test('a stored layout is repaired and a submitted layout is validated strictly', function () {
    expect(ReceiptLayout::normalize('broken'))->toBe(ReceiptLayout::defaults())
        ->and(ReceiptLayout::normalize(['details' => ['cashier', 'unknown'], 'hidden' => ['store', 'date', 'bogus'], 'custom_rows' => array_fill(0, 8, 'row'), 'separator' => 'zigzag']))
        ->toBe([
            'hidden' => ['date'],
            'details' => ['cashier', 'order', 'date', 'customer'],
            'footer' => ['custom_rows', 'footer', 'order_qr'],
            'header_text' => null,
            'custom_rows' => array_fill(0, 5, 'row'),
            'separator' => 'dashed',
        ]);

    $valid = [...ReceiptLayout::defaults(), 'details' => ['customer', 'cashier', 'date', 'order']];
    expect(ReceiptLayout::validate($valid)['details'])->toBe(['customer', 'cashier', 'date', 'order']);

    foreach ([
        [...$valid, 'hidden' => ['store']],
        [...$valid, 'details' => ['order', 'order', 'date', 'cashier']],
        [...$valid, 'details' => ['order', 'date', 'cashier']],
        [...$valid, 'footer' => ['footer', 'custom_rows', 'logo']],
        [...$valid, 'custom_rows' => array_fill(0, 6, 'row')],
        [...$valid, 'header_text' => str_repeat('x', 121)],
        [...$valid, 'separator' => 'zigzag'],
    ] as $invalid) {
        expect(fn () => ReceiptLayout::validate($invalid))->toThrow(ValidationException::class);
    }
});

test('Receipt Settings save the layout, audit it and feed the Branch page', function () {
    $owner = User::factory()->create();
    $owner->roles()->attach(Role::query()->where('name', 'owner')->sole());
    $branch = Branch::factory()->create();
    $layout = [...ReceiptLayout::defaults(), 'hidden' => ['cashier'], 'footer' => ['footer', 'custom_rows', 'order_qr'], 'custom_rows' => ['Wi-Fi: pongskilog']];

    $this->actingAs($owner)->putJson(route('branches.qr-settings.update', $branch), ['receipt_layout' => json_encode($layout)])->assertOk();

    expect($branch->fresh()->receipt_layout)->toBe(ReceiptLayout::normalize($layout))
        ->and(AuditLog::query()->where('action', 'branch_receipt_qr_settings.updated')->sole()->after['receipt_layout'])->toBe(ReceiptLayout::normalize($layout));
    $this->get(route('branches.index'))->assertInertia(fn (AssertableInertia $page) => $page
        ->where('branches.0.receipt_layout', ReceiptLayout::normalize($layout))
        ->where('branches.0.image_url', null));

    $this->putJson(route('branches.qr-settings.update', $branch), ['receipt_layout' => json_encode([...$layout, 'hidden' => ['items']])])
        ->assertUnprocessable()->assertJsonValidationErrors('receipt_layout.hidden.0');
    $this->putJson(route('branches.qr-settings.update', $branch), ['receipt_layout' => 'not json'])
        ->assertUnprocessable()->assertJsonValidationErrors('receipt_layout');
    expect($branch->fresh()->receipt_layout)->toBe(ReceiptLayout::normalize($layout));
});

test('the receipt logo is cached only for its current versioned URL', function () {
    Storage::fake('s3');
    Storage::disk('s3')->put('receipt-logos/logo.png', 'png');
    $branch = Branch::factory()->create(['receipt_logo_path' => 'receipt-logos/logo.png']);

    $this->get(route('branches.receipt-logo', $branch).'?v='.md5('receipt-logos/logo.png'))->assertOk()
        ->assertHeader('Cache-Control', 'immutable, max-age=31536000, public')
        ->assertHeader('X-Content-Type-Options', 'nosniff');
    expect($this->get(route('branches.receipt-logo', $branch).'?v=stale')->assertOk()->headers->get('Cache-Control'))->toContain('no-cache');
});
