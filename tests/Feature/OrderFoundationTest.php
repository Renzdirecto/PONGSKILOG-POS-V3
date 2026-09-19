<?php

use App\Enums\CommercialStatus;
use App\Enums\KitchenStatus;
use App\Enums\OrderSource;
use App\Enums\OrderType;
use App\Enums\PaymentStatus;
use App\Enums\PaymentTerm;
use App\Models\Branch;
use App\Models\BranchTable;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemModifier;
use App\Models\StoreSession;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

function orderFoundationGraph(): OrderItemModifier
{
    $branch = Branch::factory()->create();
    $table = BranchTable::factory()->for($branch)->create();
    $session = StoreSession::factory()->for($branch)->create();
    $user = User::factory()->create();
    $order = Order::factory()->for($branch)->create(['branch_table_id' => $table->id, 'store_session_id' => $session->id, 'created_by_user_id' => $user->id]);
    $item = OrderItem::factory()->for($order)->create();

    return OrderItemModifier::factory()->for($item)->create();
}

test('order foundation retains exact decimals enums uuid identifiers and relationships', function () {
    $modifier = orderFoundationGraph();
    $item = $modifier->orderItem;
    $order = $item->order->refresh();
    $order->update(['subtotal' => '123456789012.34', 'total' => '123456789012.34', 'payment_term' => PaymentTerm::Immediate]);

    expect($order->id)->toBeUuid();
    expect($item->id)->toBeUuid();
    expect($modifier->id)->toBeUuid();
    expect($order->branchTable->id)->toBeUuid();
    expect($order->source)->toBe(OrderSource::Pos);
    expect($order->order_type)->toBe(OrderType::TakeOut);
    expect($order->commercial_status)->toBe(CommercialStatus::Draft);
    expect($order->payment_status)->toBe(PaymentStatus::Unpaid);
    expect($order->payment_term)->toBe(PaymentTerm::Immediate);
    expect($order->kitchen_status)->toBe(KitchenStatus::NotSent);
    expect($order->version)->toBe(1);
    expect($order->fresh()->total)->toBe('123456789012.34');
    expect($item->unit_price)->toBe('95.00');
    expect($item->line_total)->toBe('95.00');
    expect($modifier->price_delta_snapshot)->toBe('20.00');
    expect($order->branch->orders->sole()->id)->toBe($order->id);
    expect($order->branch->tables->sole()->branch->id)->toBe($order->branch_id);
    expect($order->branchTable->is_active)->toBeTrue();
    expect($order->branchTable->sort_order)->toBeInt();
    expect($order->storeSession->branch_id)->toBe($order->branch_id);
    expect($order->createdBy->id)->toBe($order->created_by_user_id);
    expect($order->items->sole()->product->id)->toBe($item->product_id);
    expect($item->modifiers->sole()->modifierOption->id)->toBe($modifier->modifier_option_id);
});

test('table names and order numbers are unique only within their branch', function (string $kind) {
    $branch = Branch::factory()->create();
    $other = Branch::factory()->create();
    if ($kind === 'table') {
        BranchTable::factory()->for($branch)->create(['name' => 'Table 1']);
        BranchTable::factory()->for($other)->create(['name' => 'Table 1']);
        expect(fn () => BranchTable::factory()->for($branch)->create(['name' => 'Table 1']))->toThrow(QueryException::class);
        $this->assertDatabaseCount('branch_tables', 2);
    } else {
        Order::factory()->for($branch)->create(['order_number' => '260919-ORDER001']);
        Order::factory()->for($other)->create(['order_number' => '260919-ORDER001']);
        expect(fn () => Order::factory()->for($branch)->create(['order_number' => '260919-ORDER001']))->toThrow(QueryException::class);
        $this->assertDatabaseCount('orders', 2);
    }
})->with(['table', 'order']);

test('database rejects invalid order amounts quantities versions and statuses', function (string $table, string $column, mixed $value) {
    $modifier = orderFoundationGraph();
    $id = match ($table) {
        'orders' => $modifier->orderItem->order_id,
        'order_items' => $modifier->order_item_id,
        'order_item_modifiers' => $modifier->id,
    };

    expect(fn () => DB::table($table)->where('id', $id)->update([$column => $value]))->toThrow(QueryException::class);
})->with([
    ['orders', 'subtotal', '-0.01'], ['orders', 'total', '-0.01'], ['orders', 'version', 0],
    ['orders', 'source', 'invalid'], ['orders', 'order_type', 'invalid'], ['orders', 'commercial_status', 'invalid'],
    ['orders', 'payment_status', 'invalid'], ['orders', 'payment_term', 'invalid'], ['orders', 'kitchen_status', 'invalid'],
    ['order_items', 'quantity', 0], ['order_items', 'quantity', -1], ['order_items', 'unit_price', '-0.01'], ['order_items', 'line_total', '-0.01'],
    ['order_item_modifiers', 'quantity', 0], ['order_item_modifiers', 'quantity', -1], ['order_item_modifiers', 'price_delta_snapshot', '-0.01'],
]);

test('historical parent foreign keys prevent deletion', function (string $target) {
    $modifier = orderFoundationGraph();
    $item = $modifier->orderItem;
    $order = $item->order;
    $record = match ($target) {
        'branch' => $order->branch,
        'table' => $order->branchTable,
        'session' => $order->storeSession,
        'user' => $order->createdBy,
        'order' => $order,
        'item' => $item,
    };

    expect(fn () => $record->delete())->toThrow(QueryException::class);
    $this->assertModelExists($modifier);
})->with(['branch', 'table', 'session', 'user', 'order', 'item']);

test('catalog deletion nulls references but retains historical snapshots', function () {
    $modifier = orderFoundationGraph();
    $item = $modifier->orderItem;

    $modifier->modifierOption->delete();
    $item->product->delete();

    expect($modifier->fresh()->modifier_option_id)->toBeNull();
    expect($modifier->fresh()->option_name_snapshot)->toBe('Egg');
    expect($modifier->fresh()->price_delta_snapshot)->toBe('20.00');
    expect($item->fresh()->product_id)->toBeNull();
    expect($item->fresh()->product_name_snapshot)->toBe('Tapsilog');
    expect($item->fresh()->unit_price)->toBe('95.00');
});
