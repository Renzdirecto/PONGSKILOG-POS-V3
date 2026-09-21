<?php

use App\Actions\Orders\CreatePosDraftOrder;
use App\Enums\BranchStatus;
use App\Enums\CommercialStatus;
use App\Enums\KitchenStatus;
use App\Enums\ModifierSemanticRole;
use App\Enums\OrderSource;
use App\Enums\OrderType;
use App\Enums\PaymentStatus;
use App\Models\Branch;
use App\Models\BranchInventory;
use App\Models\BranchProduct;
use App\Models\BranchTable;
use App\Models\Category;
use App\Models\ModifierGroup;
use App\Models\ModifierOption;
use App\Models\Order;
use App\Models\Product;
use App\Models\Role;
use App\Models\StoreSession;
use App\Models\User;
use App\Support\ActiveBranchContext;
use App\Support\BranchCatalog;
use App\Support\PayLaterOrderSummary;
use App\Support\PosReceipt;
use Carbon\CarbonImmutable;
use Database\Seeders\RbacSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->seed(RbacSeeder::class);
});

function posCashier(Branch $branch, string $role = 'cashier'): User
{
    $user = User::factory()->create();
    $user->roles()->attach(Role::query()->where('name', $role)->sole());
    $user->branches()->attach($branch, ['is_active' => true]);

    return $user;
}

function posPayload(Product $product): array
{
    return ['order_type' => 'take_out', 'customer_label' => 'Maria', 'branch_table_id' => null,
        'items' => [['product_id' => $product->id, 'quantity' => 2, 'notes' => 'Less rice', 'modifiers' => []]]];
}

test('assigned cashier roles save exact priced drafts and receive persisted summaries without operational side effects', function (string $role) {
    $branch = Branch::factory()->create();
    $user = posCashier($branch, $role);
    $session = StoreSession::factory()->for($branch)->create();
    $product = Product::factory()->create(['name' => 'Tapsilog', 'description' => 'Garlic rice and egg', 'default_price' => '95.00']);
    BranchProduct::factory()->for($branch)->for($product)->create(['tracks_inventory' => true, 'low_stock_threshold' => 5]);
    $balance = BranchInventory::factory()->for($branch)->for($product)->create(['on_hand' => 4, 'version' => 3]);
    $group = ModifierGroup::factory()->create(['name' => 'Extras', 'min_select' => 1, 'max_select' => 1]);
    $product->modifierGroups()->attach($group);
    $option = ModifierOption::factory()->for($group)->create(['name' => 'Egg', 'price_delta' => '20.00']);
    $payload = posPayload($product);
    $payload['items'][0]['modifiers'] = [['group_id' => $group->id, 'option_id' => $option->id, 'price_delta' => '0.01']];
    $payload['items'][0]['unit_price'] = '0.01';
    $payload['total'] = '0.01';
    $payload['branch_id'] = Branch::factory()->create()->id;
    $payload['payment_status'] = 'paid';
    $originalBalance = $balance->refresh()->getAttributes();
    $originalSession = $session->refresh()->getAttributes();
    Queue::fake();

    $response = $this->actingAs($user)->post(route('pos.orders.store'), $payload);

    $order = Order::query()->sole();
    $response->assertRedirectToRoute('workspaces.cashier')
        ->assertInertiaFlash('posDraft.id', $order->id)
        ->assertInertiaFlash('posDraft.total', '230.00')
        ->assertInertiaFlash('posDraft.items.0.modifiers.0.name', 'Egg');
    $this->get(route('workspaces.cashier'))->assertInertia(fn (Assert $page) => $page
        ->component('workspaces/show')->where('catalog.products.0.description', 'Garlic rice and egg')->hasFlash('posDraft.order_number', $order->order_number)
        ->hasFlash('posDraft.items.0.line_total', '230.00'));
    $this->get(route('workspaces.cashier'))->assertInertia(fn (Assert $page) => $page->missingFlash('posDraft'));
    expect($order->branch_id)->toBe($branch->id);
    expect($order->source)->toBe(OrderSource::Pos);
    expect($order->order_type)->toBe(OrderType::TakeOut);
    expect($order->commercial_status)->toBe(CommercialStatus::Draft);
    expect($order->payment_status)->toBe(PaymentStatus::Unpaid);
    expect($order->kitchen_status)->toBe(KitchenStatus::NotSent);
    expect($order->payment_term)->toBeNull();
    expect($order->store_session_id)->toBeNull();
    expect($order->committed_at)->toBeNull();
    expect($order->version)->toBe(1);
    expect($order->subtotal)->toBe('230.00');
    expect($order->total)->toBe('230.00');
    expect($order->created_by_user_id)->toBe($user->id);
    expect($balance->fresh()->getAttributes())->toBe($originalBalance);
    expect($session->fresh()->getAttributes())->toBe($originalSession);
    $this->assertDatabaseCount('inventory_movements', 0);
    Queue::assertNothingPushed();
    $this->get(route('pos.orders.show', $order))->assertInertia(fn (Assert $page) => $page
        ->component('workspaces/order-summary')->where('order.total', '230.00')
        ->where('order.items.0.name', 'Tapsilog')->where('order.items.0.unit_price', '95.00')
        ->where('order.items.0.line_total', '230.00')->where('order.items.0.notes', 'Less rice')
        ->where('order.items.0.modifiers.0.name', 'Egg')->where('order.items.0.modifiers.0.price_delta', '20.00'));
})->with(['cashier', 'cashier_kitchen']);

test('saving order information fills the early reservation without changing its identifiers', function () {
    $branch = Branch::factory()->create();
    $user = posCashier($branch);
    StoreSession::factory()->for($branch)->create();
    $product = Product::factory()->create(['default_price' => '95.00']);

    $reservation = $this->actingAs($user)->postJson(route('pos.orders.reservations.store'), [
        'order_type' => 'take_out',
    ])->assertOk()->json('order');

    $this->post(route('pos.orders.store'), [
        ...posPayload($product),
        'reserved_order_id' => $reservation['id'],
    ])->assertRedirectToRoute('workspaces.cashier')
        ->assertInertiaFlash('posDraft.id', $reservation['id'])
        ->assertInertiaFlash('posDraft.order_number', $reservation['order_number'])
        ->assertInertiaFlash('posDraft.reference_number', $reservation['reference_number']);

    $this->assertDatabaseCount('orders', 1);
    $this->assertDatabaseHas('orders', [
        'id' => $reservation['id'],
        'order_number' => $reservation['order_number'],
        'reference_number' => $reservation['reference_number'],
        'subtotal' => '190.00',
    ]);
});

test('draft snapshots survive catalog renaming repricing and disablement', function () {
    $branch = Branch::factory()->create();
    $user = posCashier($branch);
    StoreSession::factory()->for($branch)->create();
    $product = Product::factory()->create(['name' => 'Original product', 'default_price' => '95.00']);
    $config = BranchProduct::factory()->for($branch)->for($product)->create(['price_override' => '95.00']);
    $group = ModifierGroup::factory()->create(['name' => 'Original group']);
    $product->modifierGroups()->attach($group);
    $option = ModifierOption::factory()->for($group)->create(['name' => 'Original option', 'price_delta' => '20.00']);
    $payload = posPayload($product);
    $payload['items'][0]['modifiers'] = [['group_id' => $group->id, 'option_id' => $option->id]];
    $order = app(CreatePosDraftOrder::class)->execute($user, $branch, $payload);
    $number = $order->order_number;

    $product->update(['name' => 'Changed', 'default_price' => '999.00', 'is_active' => false]);
    $config->update(['price_override' => '800.00']);
    $group->update(['name' => 'Changed', 'is_active' => false]);
    $option->update(['name' => 'Changed', 'price_delta' => '50.00', 'is_active' => false]);

    $this->actingAs($user)->get(route('pos.orders.show', $order))->assertInertia(fn (Assert $page) => $page
        ->where('order.order_number', $number)->where('order.total', '230.00')
        ->where('order.items.0.name', 'Original product')->where('order.items.0.unit_price', '95.00')
        ->where('order.subtotal', '230.00')->where('order.items.0.line_total', '230.00')
        ->where('order.items.0.notes', 'Less rice')
        ->where('order.items.0.modifiers.0.group_name', 'Original group')
        ->where('order.items.0.modifiers.0.name', 'Original option')->where('order.items.0.modifiers.0.price_delta', '20.00'));
});

test('size groups prefix operational item names without changing the canonical product snapshot', function () {
    $branch = Branch::factory()->create();
    $user = posCashier($branch);
    StoreSession::factory()->for($branch)->create();
    $product = Product::factory()->create(['name' => 'Yakult', 'default_price' => '55.00']);
    $size = ModifierGroup::factory()->create([
        'name' => 'Size',
        'semantic_role' => ModifierSemanticRole::Size,
        'min_select' => 1,
        'max_select' => 1,
    ]);
    $extras = ModifierGroup::factory()->create(['name' => 'Extras']);
    $product->modifierGroups()->attach([$size->id, $extras->id]);
    $small = ModifierOption::factory()->for($size)->create(['name' => 'Small', 'price_delta' => '0.00']);
    $pearls = ModifierOption::factory()->for($extras)->create(['name' => 'Pearls', 'price_delta' => '10.00']);
    $payload = posPayload($product);
    $payload['items'][0]['quantity'] = 1;
    $payload['items'][0]['modifiers'] = [
        ['group_id' => $size->id, 'option_id' => $small->id],
        ['group_id' => $extras->id, 'option_id' => $pearls->id],
    ];

    $response = $this->actingAs($user)->post(route('pos.orders.store'), $payload)
        ->assertRedirectToRoute('workspaces.cashier')
        ->assertInertiaFlash('posDraft.items.0.name', 'Yakult')
        ->assertInertiaFlash('posDraft.items.0.size_prefix', 'Small')
        ->assertInertiaFlash('posDraft.items.0.display_name', 'Small Yakult')
        ->assertInertiaFlash('posDraft.items.0.modifiers.0.semantic_role', 'size')
        ->assertInertiaFlash('posDraft.items.0.modifiers.1.semantic_role', null);

    $order = Order::query()->sole();
    expect($order->items()->sole()->product_name_snapshot)->toBe('Yakult');
    $this->assertDatabaseHas('order_item_modifiers', [
        'order_item_id' => $order->items()->sole()->id,
        'option_name_snapshot' => 'Small',
        'semantic_role_snapshot' => 'size',
    ]);

    foreach ([app(PayLaterOrderSummary::class)->summary($order), app(PosReceipt::class)->summary($order)] as $summary) {
        expect($summary['items'][0]['name'])->toBe('Yakult')
            ->and($summary['items'][0]['size_prefix'])->toBe('Small')
            ->and($summary['items'][0]['display_name'])->toBe('Small Yakult');
    }

    $response->assertSessionHasNoErrors();
});

test('both order types accept an optional current branch table', function (string $orderType, string $tableState) {
    $branch = Branch::factory()->create(['code' => 'MAIN']);
    StoreSession::factory()->for($branch)->create();
    $table = $tableState === 'active' ? BranchTable::factory()->for($branch)->create() : null;
    $payload = posPayload(Product::factory()->create());
    $payload['order_type'] = $orderType;
    $payload['customer_label'] = $orderType === 'dine_in' ? null : 'Maria';
    $payload['branch_table_id'] = $table?->id;
    if ($tableState === 'omitted') {
        unset($payload['branch_table_id']);
    }

    $this->actingAs(posCashier($branch))->post(route('pos.orders.store'), $payload)
        ->assertRedirectToRoute('workspaces.cashier')
        ->assertInertiaFlash('posDraft.table_name', $table?->name);

    $this->assertDatabaseHas('orders', [
        'branch_table_id' => $table?->id, 'order_type' => $orderType,
        'customer_label' => $payload['customer_label'], 'commercial_status' => 'draft',
        'payment_status' => 'unpaid', 'kitchen_status' => 'not_sent', 'committed_at' => null,
    ]);
    $this->assertDatabaseCount('inventory_movements', 0);
})->with(['dine_in', 'take_out'])->with(['active', 'null', 'omitted']);

test('direct draft callers can leave the optional table blank', function (string $orderType) {
    $branch = Branch::factory()->create();
    StoreSession::factory()->for($branch)->create();
    $payload = posPayload(Product::factory()->create());
    $payload['order_type'] = $orderType;
    $payload['branch_table_id'] = '';

    $order = app(CreatePosDraftOrder::class)->execute(posCashier($branch), $branch, $payload);

    expect($order->branch_table_id)->toBeNull();
    expect($order->commercial_status)->toBe(CommercialStatus::Draft);
})->with(['dine_in', 'take_out']);

test('both order types reject inactive or foreign tables without saving a draft', function (string $orderType, string $tableState) {
    $branch = Branch::factory()->create(['code' => 'MAIN']);
    StoreSession::factory()->for($branch)->create();
    $table = BranchTable::factory()->for($tableState === 'foreign' ? Branch::factory()->create(['code' => 'QAVE']) : $branch)
        ->create(['is_active' => $tableState !== 'inactive']);
    $payload = posPayload(Product::factory()->create());
    $payload['order_type'] = $orderType;
    $payload['branch_table_id'] = $table->id;

    $this->actingAs(posCashier($branch))->post(route('pos.orders.store'), $payload)
        ->assertInvalid(['branch_table_id' => 'Choose an active table in this branch.']);

    $this->assertDatabaseCount('orders', 0);
    $this->assertDatabaseCount('order_items', 0);
    $this->assertDatabaseCount('inventory_movements', 0);
})->with(['dine_in', 'take_out'])->with(['foreign', 'inactive']);

test('both order types accept a null or blank customer label', function (string $orderType, ?string $customerLabel) {
    $branch = Branch::factory()->create();
    StoreSession::factory()->for($branch)->create();
    $payload = posPayload(Product::factory()->create());
    $payload['order_type'] = $orderType;
    $payload['customer_label'] = $customerLabel;

    $this->actingAs(posCashier($branch))->post(route('pos.orders.store'), $payload)
        ->assertRedirectToRoute('workspaces.cashier');

    $this->assertDatabaseHas('orders', [
        'order_type' => $orderType,
        'customer_label' => null,
    ]);
})->with(['dine_in', 'take_out'])->with([
    'null' => null,
    'blank' => '   ',
]);

test('invalid order information and cart values are rejected before persistence', function (string $path, mixed $value) {
    $branch = Branch::factory()->create();
    StoreSession::factory()->for($branch)->create();
    $payload = posPayload(Product::factory()->create());
    data_set($payload, $path, $value);

    $this->actingAs(posCashier($branch))->post(route('pos.orders.store'), $payload)->assertInvalid($path);

    $this->assertDatabaseCount('orders', 0);
    $this->assertDatabaseCount('order_items', 0);
})->with([
    'missing type' => ['order_type', null], 'unknown type' => ['order_type', 'delivery'],
    'long label' => ['customer_label', str_repeat('x', 151)],
    'malformed table' => ['branch_table_id', 'not-a-uuid'],
    'unknown table' => ['branch_table_id', '11111111-1111-4111-8111-111111111111'],
    'empty cart' => ['items', []], 'zero' => ['items.0.quantity', 0], 'negative' => ['items.0.quantity', -1],
    'fractional' => ['items.0.quantity', 1.5], 'malformed' => ['items.0.quantity', '2 eggs'],
    'boolean' => ['items.0.quantity', true],
    'excessive quantity' => ['items.0.quantity', 1000], 'long notes' => ['items.0.notes', str_repeat('x', 1001)],
    'forged product' => ['items.0.product_id', '11111111-1111-4111-8111-111111111111'],
]);

test('stale or unavailable catalog products are rejected without changing inventory', function (string $state) {
    $branch = Branch::factory()->create();
    $user = posCashier($branch);
    StoreSession::factory()->for($branch)->create();
    $product = Product::factory()->create();
    $config = BranchProduct::factory()->for($branch)->for($product)->create(['tracks_inventory' => true]);
    $balance = BranchInventory::factory()->for($branch)->for($product)->create(['on_hand' => 5]);
    app(BranchCatalog::class)->browse($branch, true);
    match ($state) {
        'product' => $product->update(['is_active' => false]),
        'category' => $product->category->update(['is_active' => false]),
        'branch' => $config->update(['is_available' => false]),
        'zero' => $balance->update(['on_hand' => 0]),
        'missing' => $balance->delete(),
        'insufficient' => $balance->update(['on_hand' => 1]),
        'aggregate' => null,
    };
    $payload = posPayload($product);
    if ($state === 'aggregate') {
        $payload['items'] = array_fill(0, 3, $payload['items'][0]);
    }
    $balances = DB::table('branch_inventory')->get()->toArray();

    $this->actingAs($user)->post(route('pos.orders.store'), $payload)->assertInvalid();

    $this->assertDatabaseCount('orders', 0);
    expect(DB::table('branch_inventory')->get()->toArray())->toEqual($balances);
    $this->assertDatabaseCount('inventory_movements', 0);
})->with(['product', 'category', 'branch', 'zero', 'missing', 'insufficient', 'aggregate']);

test('modifier rules reject stale unassigned foreign duplicate and invalid counts', function (string $state) {
    $branch = Branch::factory()->create();
    StoreSession::factory()->for($branch)->create();
    $product = Product::factory()->create();
    $group = ModifierGroup::factory()->create(['selection_type' => 'multiple', 'min_select' => 1, 'max_select' => 2]);
    $product->modifierGroups()->attach($group);
    $options = ModifierOption::factory()->count(3)->for($group)->create();
    $payload = posPayload($product);
    $selection = ['group_id' => $group->id, 'option_id' => $options[0]->id];
    $payload['items'][0]['modifiers'] = [$selection];
    match ($state) {
        'required' => $payload['items'][0]['modifiers'] = [],
        'maximum' => $payload['items'][0]['modifiers'] = $options->map(fn ($option) => ['group_id' => $group->id, 'option_id' => $option->id])->all(),
        'single' => [$group->update(['selection_type' => 'single', 'max_select' => 1]), $payload['items'][0]['modifiers'][] = ['group_id' => $group->id, 'option_id' => $options[1]->id]],
        'inactive group' => $group->update(['is_active' => false]),
        'inactive option' => $options[0]->update(['is_active' => false]),
        'unassigned' => $product->modifierGroups()->detach($group),
        'foreign option' => $payload['items'][0]['modifiers'][0]['option_id'] = ModifierOption::factory()->create()->id,
        'foreign group' => $payload['items'][0]['modifiers'][0]['group_id'] = ModifierGroup::factory()->create()->id,
        'duplicate' => $payload['items'][0]['modifiers'][] = $selection,
    };

    $this->actingAs(posCashier($branch))->post(route('pos.orders.store'), $payload)->assertInvalid('items.0.modifiers');

    $this->assertDatabaseCount('orders', 0);
    $this->assertDatabaseCount('order_item_modifiers', 0);
})->with(['required', 'maximum', 'single', 'inactive group', 'inactive option', 'unassigned', 'foreign option', 'foreign group', 'duplicate']);

test('exact cents multiple groups and repeated configurations use current branch prices', function () {
    $main = Branch::factory()->create();
    $qave = Branch::factory()->create();
    StoreSession::factory()->for($main)->create();
    $product = Product::factory()->create(['default_price' => '99.99']);
    BranchProduct::factory()->for($main)->for($product)->create(['price_override' => '0.00']);
    BranchProduct::factory()->for($qave)->for($product)->create(['price_override' => '800.00', 'is_available' => false]);
    $groups = ModifierGroup::factory()->count(2)->create(['selection_type' => 'multiple', 'min_select' => 0, 'max_select' => 2]);
    $product->modifierGroups()->attach($groups->modelKeys());
    $inactive = ModifierGroup::factory()->create(['is_active' => false, 'min_select' => 1]);
    $product->modifierGroups()->attach($inactive);
    $payload = posPayload($product);
    $payload['items'][0]['quantity'] = 3;
    foreach ($groups as $group) {
        foreach (['0.10', '0.20'] as $price) {
            $option = ModifierOption::factory()->for($group)->create(['price_delta' => $price]);
            $payload['items'][0]['modifiers'][] = ['group_id' => $group->id, 'option_id' => $option->id];
        }
    }
    $payload['items'][] = ['product_id' => $product->id, 'quantity' => 1, 'modifiers' => []];

    $order = app(CreatePosDraftOrder::class)->execute(posCashier($main), $main, $payload);

    expect($order->total)->toBe('1.80');
    expect($order->items->pluck('unit_price')->all())->toBe(['0.00', '0.00']);
    expect($order->items->sum(fn ($item) => $item->modifiers->count()))->toBe(4);
    $this->assertDatabaseCount('branch_inventory', 0);
});

test('money overflow is rejected before inserting any order rows', function (string $kind) {
    $branch = Branch::factory()->create();
    StoreSession::factory()->for($branch)->create();
    $product = Product::factory()->create(['default_price' => '999999999999.99']);
    $payload = posPayload($product);
    if ($kind === 'subtotal') {
        $payload['items'][0]['quantity'] = 1;
        $payload['items'][] = $payload['items'][0];
    }
    if ($kind === 'modifier') {
        $group = ModifierGroup::factory()->create();
        $product->modifierGroups()->attach($group);
        $option = ModifierOption::factory()->for($group)->create(['price_delta' => '0.01']);
        $payload['items'][0]['modifiers'][] = ['group_id' => $group->id, 'option_id' => $option->id];
    }

    $this->actingAs(posCashier($branch))->post(route('pos.orders.store'), $payload)->assertInvalid('items');

    $this->assertDatabaseCount('orders', 0);
})->with(['quantity', 'subtotal', 'modifier']);

test('closed stores and stale open state cannot create a draft', function () {
    $branch = Branch::factory()->create();
    $user = posCashier($branch);
    $session = StoreSession::factory()->for($branch)->create();
    $this->actingAs($user)->get(route('workspaces.cashier'))->assertOk();
    $session->update(['status' => 'closed']);

    $this->post(route('pos.orders.store'), posPayload(Product::factory()->create()))->assertInvalid(['store' => 'Store is closed. Open the store before creating an order.']);

    $this->assertDatabaseCount('orders', 0);
    $this->assertDatabaseCount('store_sessions', 1);
});

test('non cashier roles cannot create or read POS drafts even when assigned', function (string $role) {
    $branch = Branch::factory()->create();
    StoreSession::factory()->for($branch)->create();
    $order = Order::factory()->for($branch)->create();
    $user = posCashier($branch, $role);

    $this->actingAs($user)->withSession([ActiveBranchContext::SESSION_KEY => $branch->id])->post(route('pos.orders.store'), posPayload(Product::factory()->create()))->assertForbidden();
    $this->get(route('pos.orders.show', $order))->assertForbidden();

    $this->assertDatabaseCount('orders', 1);
})->with(['owner', 'super_admin', 'kitchen_staff']);

test('persisted authorization rejects disabled accounts assignments branches and revoked permissions', function (string $change) {
    $branch = Branch::factory()->create();
    $user = posCashier($branch);
    StoreSession::factory()->for($branch)->create();
    $payload = posPayload(Product::factory()->create());
    $user->load('roles', 'branches');
    match ($change) {
        'user' => User::query()->whereKey($user->id)->update(['is_active' => false]),
        'assignment' => $user->branches()->updateExistingPivot($branch->id, ['is_active' => false]),
        'unassigned' => $user->branches()->detach(),
        'permission' => Role::query()->where('name', 'cashier')->sole()->permissions()->detach(),
        'role' => $user->roles()->detach(),
        'inactive' => Branch::query()->whereKey($branch->id)->update(['status' => BranchStatus::Inactive]),
        'temporary' => Branch::query()->whereKey($branch->id)->update(['status' => BranchStatus::TemporarilyClosed]),
    };

    expect(fn () => app(CreatePosDraftOrder::class)->execute($user, $branch, $payload))->toThrow(AuthorizationException::class);

    $this->assertDatabaseCount('orders', 0);
})->with(['user', 'assignment', 'unassigned', 'permission', 'role', 'inactive', 'temporary']);

test('guest cannot submit or read a draft', function () {
    $order = Order::factory()->create();

    $this->post(route('pos.orders.store'), [])->assertRedirectToRoute('login');
    $this->get(route('pos.orders.show', $order))->assertRedirectToRoute('login');
});

test('inactive accounts cannot reach the draft endpoint', function () {
    $branch = Branch::factory()->create();
    $user = posCashier($branch);
    User::query()->whereKey($user->id)->update(['is_active' => false]);

    $this->actingAs($user)->post(route('pos.orders.store'), posPayload(Product::factory()->create()))->assertRedirectToRoute('login');

    $this->assertDatabaseCount('orders', 0);
});

test('missing or revoked assignments cannot reach the draft endpoint', function (bool $revoked) {
    $branch = Branch::factory()->create();
    $user = posCashier($branch);
    if ($revoked) {
        $user->branches()->updateExistingPivot($branch->id, ['is_active' => false]);
    } else {
        $user->branches()->detach();
    }

    $this->actingAs($user)->withSession([ActiveBranchContext::SESSION_KEY => $branch->id])
        ->post(route('pos.orders.store'), posPayload(Product::factory()->create()))->assertRedirectToRoute('workspace');

    $this->assertDatabaseCount('orders', 0);
})->with([false, true]);

test('direct draft requests reject revoked permission and nonoperational branches', function (string $state) {
    $branch = Branch::factory()->create();
    $user = posCashier($branch);
    StoreSession::factory()->for($branch)->create();
    if ($state === 'permission') {
        Role::query()->where('name', 'cashier')->sole()->permissions()->detach();
    } else {
        $branch->update(['status' => $state]);
    }

    $this->actingAs($user)->withSession([ActiveBranchContext::SESSION_KEY => $branch->id])
        ->post(route('pos.orders.store'), posPayload(Product::factory()->create()))->assertForbidden();

    $this->assertDatabaseCount('orders', 0);
})->with(['permission', 'temporarily_closed', 'inactive']);

test('a forged active branch does not create an order for an unassigned cashier', function () {
    $assigned = Branch::factory()->create();
    $foreign = Branch::factory()->create();
    StoreSession::factory()->for($foreign)->create();

    $this->actingAs(posCashier($assigned))->withSession([ActiveBranchContext::SESSION_KEY => $foreign->id])
        ->post(route('pos.orders.store'), posPayload(Product::factory()->create()))->assertInvalid('store');

    $this->assertDatabaseCount('orders', 0);
});

test('repeated draft creation produces independent branch scoped numbers without stock reservation', function () {
    $main = Branch::factory()->create();
    $qave = Branch::factory()->create();
    $product = Product::factory()->create(['default_price' => '20.00']);
    foreach ([$main, $qave] as $branch) {
        $user = posCashier($branch);
        StoreSession::factory()->for($branch)->create();
        BranchProduct::factory()->for($branch)->for($product)->create(['tracks_inventory' => true]);
        BranchInventory::factory()->for($branch)->for($product)->create(['on_hand' => 2]);
        for ($index = 0; $index < 10; $index++) {
            app(CreatePosDraftOrder::class)->execute($user, $branch, posPayload($product));
        }
        expect($branch->orders()->distinct()->count('order_number'))->toBe(10);
        expect($branch->inventoryBalances()->sole()->on_hand)->toBe(2);
    }

    $this->assertDatabaseCount('orders', 20);
    $this->assertDatabaseCount('inventory_movements', 0);
});

test('POS customization exposes only active assigned groups and active option fields', function () {
    $branch = Branch::factory()->create();
    $product = Product::factory()->create();
    $group = ModifierGroup::factory()->create(['name' => 'Extras', 'min_select' => 0, 'max_select' => 1]);
    $product->modifierGroups()->attach($group);
    $option = ModifierOption::factory()->for($group)->create(['name' => 'Egg', 'price_delta' => '20.00', 'sort_order' => 2]);
    ModifierOption::factory()->for($group)->create(['is_active' => false]);

    $this->actingAs(posCashier($branch))->get(route('workspaces.cashier'))->assertInertia(fn (Assert $page) => $page
        ->where('catalog.products.0.modifier_groups.0.options', [[
            'id' => $option->id, 'name' => 'Egg', 'price_delta' => '20.00', 'sort_order' => 2,
        ]])->missing('catalog.products.0.modifier_groups.0.is_active')
        ->missing('catalog.products.0.modifier_groups.0.pivot'));
});

test('POS modal entry receives real profile store catalog and branch table data without creating an order', function (?string $description) {
    $branch = Branch::factory()->create();
    $user = posCashier($branch);
    StoreSession::factory()->for($branch)->create();
    $table = BranchTable::factory()->for($branch)->create();
    BranchTable::factory()->for($branch)->create(['is_active' => false]);
    BranchTable::factory()->create();
    $product = Product::factory()->create(['description' => $description, 'default_price' => '125.50']);
    BranchProduct::factory()->for($branch)->for($product)->create(['tracks_inventory' => true]);
    $balance = BranchInventory::factory()->for($branch)->for($product)->create(['on_hand' => 8, 'version' => 2]);
    BranchInventory::factory()->for(Branch::factory()->create())->for($product)->create(['on_hand' => 4, 'version' => 99]);
    $originalBalance = $balance->refresh()->getAttributes();

    $this->actingAs($user)->get(route('workspaces.cashier'))->assertInertia(fn (Assert $page) => $page
        ->component('workspaces/show')
        ->where('auth.user.name', $user->name)
        ->where('auth.roles', ['cashier'])
        ->where('storeContext.isOpen', true)
        ->where('store.branchStatus', 'active')
        ->where('tables', [['id' => $table->id, 'name' => $table->name]])
        ->where('catalog.products.0.description', $description)
        ->where('catalog.products.0.effective_price', '125.50')
        ->where('catalog.products.0.tracks_inventory', true)
        ->where('catalog.products.0.on_hand', 8)
        ->missing('catalog.products.0.version'));

    expect($balance->fresh()->getAttributes())->toBe($originalBalance);
    $this->assertDatabaseCount('orders', 0);
    $this->assertDatabaseCount('inventory_movements', 0);
})->with([null, 'Freshly prepared with rice.']);

test('POS customization exposes zero for tracked stock and no quantity for untracked products', function () {
    $branch = Branch::factory()->create();
    $category = Category::factory()->create();
    $tracked = Product::factory()->for($category)->create(['name' => 'A tracked']);
    $untracked = Product::factory()->for($category)->create(['name' => 'B untracked']);
    BranchProduct::factory()->for($branch)->for($tracked)->create(['tracks_inventory' => true]);
    BranchProduct::factory()->for($branch)->for($untracked)->create(['tracks_inventory' => false]);
    BranchInventory::factory()->for($branch)->for($tracked)->create(['on_hand' => 0]);
    BranchInventory::factory()->for($branch)->for($untracked)->create(['on_hand' => 91]);

    $this->actingAs(posCashier($branch))->get(route('workspaces.cashier'))->assertInertia(fn (Assert $page) => $page
        ->where('catalog.products.0.name', 'A tracked')
        ->where('catalog.products.0.stock_status', 'out_of_stock')
        ->where('catalog.products.0.tracks_inventory', true)
        ->where('catalog.products.0.on_hand', 0)
        ->where('catalog.products.1.name', 'B untracked')
        ->where('catalog.products.1.stock_status', 'not_tracked')
        ->where('catalog.products.1.tracks_inventory', false)
        ->where('catalog.products.1.on_hand', null));
});

test('summary is isolated by branch source and draft state', function (string $state) {
    $branch = Branch::factory()->create();
    $order = Order::factory()->for($state === 'branch' ? Branch::factory()->create() : $branch)->create([
        'source' => $state === 'source' ? 'customer_qr' : 'pos',
        'commercial_status' => $state === 'status' ? 'active' : 'draft',
    ]);

    $this->actingAs(posCashier($branch))->get(route('pos.orders.show', $order))->assertNotFound();
})->with(['branch', 'source', 'status']);

test('order creation rolls back all rows on snapshot insertion failure', function () {
    $branch = Branch::factory()->create();
    StoreSession::factory()->for($branch)->create();
    $user = posCashier($branch);
    $payload = posPayload(Product::factory()->create());
    DB::unprepared("CREATE TRIGGER fail_pos_item BEFORE INSERT ON order_items BEGIN SELECT RAISE(ABORT, 'injected snapshot failure'); END");

    try {
        expect(fn () => app(CreatePosDraftOrder::class)->execute($user, $branch, $payload))->toThrow(QueryException::class);
    } finally {
        DB::unprepared('DROP TRIGGER fail_pos_item');
    }

    $this->assertDatabaseCount('orders', 0);
    $this->assertDatabaseCount('order_items', 0);
    $this->assertDatabaseCount('inventory_movements', 0);
});

test('numeric order allocation skips historical collisions and uses the Manila creation date', function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-19 16:30:00 UTC'));
    $branch = Branch::factory()->create(['code' => 'MAIN']);
    StoreSession::factory()->for($branch)->create();
    $legacy = Order::factory()->for($branch)->create(['order_number' => '1001', 'reference_number' => null]);

    $order = app(CreatePosDraftOrder::class)->execute(posCashier($branch), $branch, posPayload(Product::factory()->create()));

    expect($legacy->fresh()->order_number)->toBe('1001')
        ->and($legacy->fresh()->reference_number)->toBeNull()
        ->and($order->order_number)->toBe('1002')
        ->and($order->order_number)->toMatch('/\A[0-9]+\z/')
        ->and($order->reference_number)->toBe('MAIN-260920-1002')
        ->and(DB::table('order_number_counters')->where('branch_id', $branch->id)->value('next_number'))->toBe(1003);
    $this->assertDatabaseCount('orders', 2);
    $this->assertDatabaseCount('order_items', 1);
});

test('order number and reference remain immutable after allocation', function () {
    $branch = Branch::factory()->create(['code' => 'MAIN']);
    StoreSession::factory()->for($branch)->create();
    $order = app(CreatePosDraftOrder::class)->execute(posCashier($branch), $branch, posPayload(Product::factory()->create()));
    $number = $order->order_number;
    $reference = $order->reference_number;

    $order->order_number = '9999';
    expect(fn () => $order->save())->toThrow(LogicException::class, 'Order identifiers are immutable.');
    $order->refresh();
    $order->reference_number = 'MAIN-260919-9999';
    expect(fn () => $order->save())->toThrow(LogicException::class, 'Order identifiers are immutable.');

    expect($order->fresh()->order_number)->toBe($number)
        ->and($order->fresh()->reference_number)->toBe($reference);
});

test('catalog customization and draft reads remain bounded as cart and catalog grow', function (int $count) {
    $branch = Branch::factory()->create();
    $user = posCashier($branch);
    StoreSession::factory()->for($branch)->create();
    $products = Product::factory()->count($count)->create();
    $group = ModifierGroup::factory()->create();
    ModifierOption::factory()->for($group)->create();
    foreach ($products as $product) {
        $product->modifierGroups()->attach($group);
        BranchProduct::factory()->for($branch)->for($product)->create();
        BranchInventory::factory()->for($branch)->for($product)->create();
    }
    DB::enableQueryLog();
    DB::flushQueryLog();

    $plain = app(BranchCatalog::class)->browse($branch);

    expect(count(DB::getQueryLog()))->toBe(4);
    expect($plain['products'])->toHaveCount($count);
    DB::flushQueryLog();

    $catalog = app(BranchCatalog::class)->browse($branch, true);

    expect(count(DB::getQueryLog()))->toBeLessThanOrEqual(6);
    expect($catalog['products'])->toHaveCount($count);
    DB::flushQueryLog();
    $payload = posPayload($products->first());
    $payload['items'] = $products->map(fn ($product) => ['product_id' => $product->id, 'quantity' => 1, 'modifiers' => []])->all();

    $order = app(CreatePosDraftOrder::class)->execute($user, $branch, $payload);

    $reads = collect(DB::getQueryLog())->filter(fn ($query) => str_starts_with(strtolower($query['query']), 'select'));
    DB::disableQueryLog();
    expect($reads->count())->toBeLessThanOrEqual(18);
    expect($order->items)->toHaveCount($count);
})->with([1, 30, 100]);
