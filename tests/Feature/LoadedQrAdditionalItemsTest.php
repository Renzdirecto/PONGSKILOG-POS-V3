<?php

use App\Actions\Orders\LoadCustomerQrOrder;
use App\Actions\Orders\SubmitCustomerQrOrder;
use App\Enums\PaymentStatus;
use App\Models\Branch;
use App\Models\BranchInventory;
use App\Models\BranchProduct;
use App\Models\CustomerQrSession;
use App\Models\InventoryMovement;
use App\Models\KitchenTicket;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Role;
use App\Models\StoreSession;
use App\Models\User;
use App\Support\ActiveBranchContext;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->branch = Branch::factory()->create();
    StoreSession::factory()->for($this->branch)->create();
    $this->drink = Product::factory()->create(['name' => 'Iced Tea', 'default_price' => '95.00']);
    $this->extra = Product::factory()->create(['name' => 'Fries', 'default_price' => '40.00']);
    foreach ([$this->drink, $this->extra] as $product) {
        BranchProduct::factory()->for($this->branch)->for($product)->create(['tracks_inventory' => true]);
        BranchInventory::factory()->for($this->branch)->for($product)->create(['on_hand' => 10]);
    }
    $this->cashier = User::factory()->create();
    $this->cashier->roles()->attach(Role::query()->where('name', 'cashier')->sole());
    $this->cashier->branches()->attach($this->branch, ['is_active' => true]);
    $this->order = app(SubmitCustomerQrOrder::class)->execute($this->branch, CustomerQrSession::factory()->for($this->branch)->create(), [
        'idempotency_key' => (string) Str::uuid(), 'order_type' => 'take_out', 'customer_label' => 'QR customer',
        'items' => [['product_id' => $this->drink->id, 'quantity' => 1, 'notes' => null, 'modifiers' => []]],
    ]);
    app(LoadCustomerQrOrder::class)->execute($this->cashier, $this->branch, $this->order);
    $this->actingAs($this->cashier)->withSession([ActiveBranchContext::SESSION_KEY => $this->branch->id]);
});

/** @return array<string, mixed> */
function loadedQrCommit(Order $order, string $flow, array $extra, ?string $key = null): array
{
    $data = [
        'idempotency_key' => $key ?? (string) Str::uuid(),
        'qr_metadata' => ['customer_label' => 'QR customer', 'branch_table_id' => null],
        'qr_additional_items' => $extra,
    ];

    return $flow === 'now' ? [...$data, 'draft_order_id' => $order->id, 'payment_method' => 'cash', 'cash_received' => '500.00'] : $data;
}

function loadedQrUrl(Order $order, string $flow): string
{
    return $flow === 'now' ? route('pos.payments.store') : route('pos.orders.pay-later.store', $order);
}

test('a cashier adds items to a loaded QR order and both commit together at current prices', function (string $flow) {
    $extra = [['product_id' => $this->extra->id, 'quantity' => 2, 'notes' => 'Extra crispy', 'modifiers' => []]];
    $data = loadedQrCommit($this->order, $flow, $extra);

    $this->postJson(loadedQrUrl($this->order, $flow), $data)->assertOk();
    /** An exact retry recovers the same commit; the same key with different items conflicts. */
    $this->postJson(loadedQrUrl($this->order, $flow), $data)->assertOk();
    $data['qr_additional_items'][0]['quantity'] = 3;
    $this->postJson(loadedQrUrl($this->order, $flow), $data)->assertConflict();

    $order = $this->order->fresh('items');
    expect($order->total)->toBe('175.00')
        ->and($order->items->pluck('quantity', 'product_name_snapshot')->all())->toBe(['Iced Tea' => 1, 'Fries' => 2])
        ->and($order->items->firstWhere('product_id', $this->drink->id)->unit_price)->toBe('95.00')
        ->and($order->items->firstWhere('product_id', $this->extra->id)->notes)->toBe('Extra crispy')
        ->and(BranchInventory::query()->where('product_id', $this->extra->id)->value('on_hand'))->toBe(8)
        ->and(BranchInventory::query()->where('product_id', $this->drink->id)->value('on_hand'))->toBe(9)
        ->and(KitchenTicket::query()->where('order_id', $order->id)->count())->toBe(1);
    if ($flow === 'now') {
        expect(Payment::query()->where('order_id', $order->id)->sole()->amount)->toBe('175.00')
            ->and($order->payment_status)->toBe(PaymentStatus::Paid);
    }
})->with(['now', 'later']);

test('an unavailable or out of stock added item rejects the whole commit with no partial effects', function (string $flow, string $case) {
    match ($case) {
        'unavailable' => BranchProduct::query()->where('product_id', $this->extra->id)->update(['is_available' => false]),
        'out of stock' => BranchInventory::query()->where('product_id', $this->extra->id)->update(['on_hand' => 1]),
    };

    $this->postJson(loadedQrUrl($this->order, $flow), loadedQrCommit($this->order, $flow, [
        ['product_id' => $this->extra->id, 'quantity' => 2, 'notes' => null, 'modifiers' => []],
    ]))->assertUnprocessable();

    expect($this->order->fresh('items')->items)->toHaveCount(1)
        ->and($this->order->fresh()->total)->toBe('95.00')
        ->and($this->order->fresh()->committed_at)->toBeNull()
        ->and(Payment::query()->exists())->toBeFalse()
        ->and(InventoryMovement::query()->exists())->toBeFalse()
        ->and(KitchenTicket::query()->exists())->toBeFalse();
})->with(['now', 'later'])->with(['unavailable', 'out of stock']);
