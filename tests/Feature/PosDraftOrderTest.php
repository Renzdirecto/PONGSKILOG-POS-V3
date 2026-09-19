<?php

use App\Actions\Orders\CreatePosDraftOrder;
use App\Enums\BranchStatus;
use App\Enums\CommercialStatus;
use App\Enums\KitchenStatus;
use App\Enums\OrderSource;
use App\Enums\OrderType;
use App\Enums\PaymentStatus;
use App\Models\Branch;
use App\Models\BranchInventory;
use App\Models\BranchProduct;
use App\Models\BranchTable;
use App\Models\ModifierGroup;
use App\Models\ModifierOption;
use App\Models\Order;
use App\Models\Product;
use App\Models\Role;
use App\Models\StoreSession;
use App\Models\User;
use App\Support\ActiveBranchContext;
use App\Support\BranchCatalog;
use App\Support\OrderNumber;
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
        ->where('order.items.0.modifiers.0.group_name', 'Original group')
        ->where('order.items.0.modifiers.0.name', 'Original option')->where('order.items.0.modifiers.0.price_delta', '20.00'));
});

test('dine in accepts only an active table from the current branch', function (string $tableState) {
    $branch = Branch::factory()->create();
    $user = posCashier($branch);
    StoreSession::factory()->for($branch)->create();
    $table = BranchTable::factory()->for($tableState === 'foreign' ? Branch::factory()->create() : $branch)->create(['is_active' => $tableState !== 'inactive']);
    $payload = posPayload(Product::factory()->create());
    $payload['order_type'] = 'dine_in';
    $payload['customer_label'] = null;
    $payload['branch_table_id'] = $tableState === 'missing' ? null : $table->id;

    $response = $this->actingAs($user)->post(route('pos.orders.store'), $payload);

    if ($tableState === 'active') {
        $response->assertRedirectToRoute('workspaces.cashier')->assertInertiaFlash('posDraft.table_name', $table->name);
        $this->assertDatabaseHas('orders', ['branch_table_id' => $table->id, 'order_type' => 'dine_in']);
    } else {
        $response->assertInvalid('branch_table_id');
        $this->assertDatabaseCount('orders', 0);
    }
})->with(['active', 'foreign', 'inactive', 'missing']);

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
    'missing label' => ['customer_label', null], 'blank label' => ['customer_label', '   '],
    'long label' => ['customer_label', str_repeat('x', 151)],
    'table smuggling' => ['branch_table_id', '11111111-1111-4111-8111-111111111111'],
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
    $originalBalance = $balance->refresh()->getAttributes();

    $this->actingAs($user)->get(route('workspaces.cashier'))->assertInertia(fn (Assert $page) => $page
        ->component('workspaces/show')
        ->where('auth.user.name', $user->name)
        ->where('auth.roles', ['cashier'])
        ->where('storeContext.isOpen', true)
        ->where('store.branchStatus', 'active')
        ->where('tables', [['id' => $table->id, 'name' => $table->name]])
        ->where('catalog.products.0.description', $description)
        ->where('catalog.products.0.effective_price', '125.50'));

    expect($balance->fresh()->getAttributes())->toBe($originalBalance);
    $this->assertDatabaseCount('orders', 0);
    $this->assertDatabaseCount('inventory_movements', 0);
})->with([null, 'Freshly prepared with rice.']);

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

test('order number collision retries only the expected unique constraint', function () {
    $branch = Branch::factory()->create();
    StoreSession::factory()->for($branch)->create();
    Order::factory()->for($branch)->create(['order_number' => '260919-DUPLICAT']);
    $this->mock(OrderNumber::class, function ($mock) {
        $mock->shouldReceive('generate')->once()->ordered()->andReturn('260919-DUPLICAT');
        $mock->shouldReceive('generate')->once()->ordered()->andReturn('260919-UNIQUE01');
    });

    $order = app(CreatePosDraftOrder::class)->execute(posCashier($branch), $branch, posPayload(Product::factory()->create()));

    expect($order->order_number)->toBe('260919-UNIQUE01');
    $this->assertDatabaseCount('orders', 2);
    $this->assertDatabaseCount('order_items', 1);
});

test('persistent order number conflicts exhaust a bounded retry without partial rows', function () {
    $branch = Branch::factory()->create();
    StoreSession::factory()->for($branch)->create();
    Order::factory()->for($branch)->create(['order_number' => '260919-DUPLICAT']);
    $this->mock(OrderNumber::class, fn ($mock) => $mock->shouldReceive('generate')->times(5)->andReturn('260919-DUPLICAT'));
    $user = posCashier($branch);
    $payload = posPayload(Product::factory()->create());

    expect(fn () => app(CreatePosDraftOrder::class)->execute($user, $branch, $payload))->toThrow(QueryException::class);

    $this->assertDatabaseCount('orders', 1);
    $this->assertDatabaseCount('order_items', 0);
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
