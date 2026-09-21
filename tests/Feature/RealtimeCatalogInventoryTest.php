<?php

use App\Actions\Inventory\ApplyInventoryMovement;
use App\Enums\InventoryMovementType;
use App\Events\InventoryChanged;
use App\Events\ProductAvailabilityChanged;
use App\Events\ProductBranchConfigurationChanged;
use App\Models\Branch;
use App\Models\BranchProduct;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    $this->seed(RbacSeeder::class);
});

function realtimeUser(string $role, ?Branch $branch = null): User
{
    $user = User::factory()->create();
    $user->roles()->attach(Role::query()->where('name', $role)->sole());

    if ($branch !== null) {
        $user->branches()->attach($branch, ['is_active' => true]);
    }

    return $user;
}

/** @return list<object> */
function scheduledRealtimeEvents(): array
{
    return collect([
        InventoryChanged::class,
        ProductAvailabilityChanged::class,
        ProductBranchConfigurationChanged::class,
    ])->flatMap(fn (string $event) => Event::dispatched($event))
        ->map(fn (array $arguments): object => $arguments[0])
        ->values()
        ->all();
}

test('realtime events use compact branch scoped payloads and wait for commit', function () {
    $inventory = new InventoryChanged('branch-1', 'product-1', 2, 3, 'low_stock', 4);
    $availability = new ProductAvailabilityChanged('branch-1', 'product-1', false, '95.00', 5);
    $configuration = new ProductBranchConfigurationChanged('branch-1', 'product-1', true, '99.00', 6);

    foreach ([$inventory, $availability, $configuration] as $event) {
        expect($event)->toBeInstanceOf(ShouldBroadcast::class)
            ->and($event)->toBeInstanceOf(ShouldDispatchAfterCommit::class)
            ->and($event->broadcastOn())->toHaveCount(1)
            ->and($event->broadcastOn()[0]->name)->toBe('private-branch.branch-1.inventory')
            ->and($event->broadcastWith()['branch_id'])->toBe('branch-1')
            ->and($event->broadcastWith()['product_id'])->toBe('product-1')
            ->and($event->broadcastWith())->toHaveKeys(['event_id', 'event_type', 'entity_id', 'occurred_at', 'version']);
    }

    expect($inventory->broadcastAs())->toBe('inventory.changed')
        ->and($inventory->broadcastWith()['on_hand'])->toBe(2)
        ->and($inventory->broadcastWith()['availability_state'])->toBe('low_stock')
        ->and($availability->broadcastAs())->toBe('product.availability_changed')
        ->and($configuration->broadcastAs())->toBe('product.branch_configuration_changed');
});

test('each committed inventory mutation schedules one branch event with stock transitions', function () {
    Event::fake([InventoryChanged::class]);
    $configuration = BranchProduct::factory()->create([
        'tracks_inventory' => true,
        'low_stock_threshold' => 2,
    ]);
    $action = app(ApplyInventoryMovement::class);

    $action->execute($configuration->branch, $configuration->product, InventoryMovementType::ManualAdjustment, 1);
    $action->execute($configuration->branch, $configuration->product, InventoryMovementType::ManualAdjustment, -1);
    $action->execute($configuration->branch, $configuration->product, InventoryMovementType::ManualAdjustment, 3);

    $events = collect(scheduledRealtimeEvents())->filter(fn (object $event): bool => $event instanceof InventoryChanged)->values();
    expect($events)->toHaveCount(3)
        ->and($events->map(fn (InventoryChanged $event): string => $event->broadcastWith()['availability_state'])->all())
        ->toBe(['low_stock', 'out_of_stock', 'in_stock']);

    foreach ($events as $index => $event) {
        expect($event->broadcastWith()['branch_id'])->toBe($configuration->branch_id)
            ->and($event->broadcastWith()['product_id'])->toBe($configuration->product_id)
            ->and($event->broadcastWith()['version'])->toBe($index + 1);
    }
});

test('a rolled back inventory workflow does not retain a success broadcast', function () {
    Event::fake([InventoryChanged::class]);
    $configuration = BranchProduct::factory()->create(['tracks_inventory' => true]);
    $before = scheduledRealtimeEvents();

    expect(fn () => DB::transaction(function () use ($configuration): void {
        app(ApplyInventoryMovement::class)->execute(
            $configuration->branch,
            $configuration->product,
            InventoryMovementType::ManualAdjustment,
            2,
        );
        throw new RuntimeException('Force rollback');
    }))->toThrow(RuntimeException::class, 'Force rollback');

    expect(scheduledRealtimeEvents())->toHaveCount(count($before));
    $this->assertDatabaseCount('branch_inventory', 0);
    $this->assertDatabaseCount('inventory_movements', 0);
});

test('product disable and enable schedule one availability and configuration event per branch', function () {
    Event::fake([ProductAvailabilityChanged::class, ProductBranchConfigurationChanged::class]);
    $user = realtimeUser('owner');
    $branches = Branch::factory()->count(2)->create();
    $product = Product::factory()->create(['name' => 'Bangsilog', 'default_price' => '105.00']);
    $payload = [
        'name' => 'Bangsilog',
        'category_id' => $product->category_id,
        'description' => null,
        'default_price' => '105.00',
        'is_active' => false,
        'modifier_group_ids' => [],
    ];

    $this->actingAs($user)->put(route('products.update', $product), $payload)->assertSessionHasNoErrors();
    $disabledEvents = collect(scheduledRealtimeEvents());

    expect($disabledEvents)->toHaveCount(4)
        ->and($disabledEvents->filter(fn (object $event): bool => $event instanceof ProductAvailabilityChanged))->toHaveCount(2)
        ->and($disabledEvents->filter(fn (object $event): bool => $event instanceof ProductBranchConfigurationChanged))->toHaveCount(2)
        ->and($disabledEvents->map(fn (object $event): string => $event->broadcastWith()['branch_id'])->unique()->sort()->values()->all())
        ->toBe($branches->pluck('id')->sort()->values()->all())
        ->and($disabledEvents->every(fn (object $event): bool => $event->broadcastWith()['product_id'] === $product->id
            && $event->broadcastWith()['is_available'] === false))->toBeTrue();

    $payload['is_active'] = true;
    $this->put(route('products.update', $product), $payload)->assertSessionHasNoErrors();
    $allEvents = collect(scheduledRealtimeEvents());
    $enabledEvents = $allEvents->filter(fn (object $event): bool => $event->broadcastWith()['is_available'] === true);

    expect($allEvents)->toHaveCount(8)
        ->and($enabledEvents)->toHaveCount(4)
        ->and($enabledEvents->every(fn (object $event): bool => $event->broadcastWith()['is_available'] === true))->toBeTrue()
        ->and($allEvents->map(fn (object $event): string => $event::class.':'.$event->broadcastWith()['branch_id'].':'.($event->broadcastWith()['is_available'] ? 'enabled' : 'disabled'))->unique())
        ->toHaveCount(8);
});

test('branch availability changes schedule only the affected branch events without duplicates', function () {
    Event::fake([ProductAvailabilityChanged::class, ProductBranchConfigurationChanged::class]);
    $user = realtimeUser('owner');
    $main = Branch::factory()->create(['code' => 'MAIN']);
    Branch::factory()->create(['code' => 'QAVE']);
    $product = Product::factory()->create(['default_price' => '105.00']);

    $this->actingAs($user)->put(route('products.branches.update', [$product, $main]), [
        'price_override' => '99.00',
        'is_available' => false,
        'tracks_inventory' => false,
        'low_stock_threshold' => null,
    ])->assertSessionHasNoErrors();

    $events = collect(scheduledRealtimeEvents());
    expect($events)->toHaveCount(2)
        ->and($events->filter(fn (object $event): bool => $event instanceof ProductAvailabilityChanged))->toHaveCount(1)
        ->and($events->filter(fn (object $event): bool => $event instanceof ProductBranchConfigurationChanged))->toHaveCount(1)
        ->and($events->every(fn (object $event): bool => $event->broadcastWith()['branch_id'] === $main->id
            && $event->broadcastWith()['product_id'] === $product->id
            && $event->broadcastWith()['is_available'] === false
            && $event->broadcastWith()['effective_price'] === '99.00'))->toBeTrue();
});

test('inventory private channels require an active authorized branch user', function (string $role, bool $assigned, bool $allowed) {
    $branch = Branch::factory()->create();
    $otherBranch = Branch::factory()->create();
    $user = realtimeUser($role, $assigned ? $branch : null);
    config(['broadcasting.default' => 'pusher', 'broadcasting.connections.pusher' => [
        'driver' => 'pusher',
        'key' => 'test-key',
        'secret' => 'test-secret',
        'app_id' => 'test-app',
        'options' => ['cluster' => 'ap1'],
    ]]);
    (static function (): void {
        require base_path('routes/channels.php');
    })();

    $response = $this->actingAs($user)->postJson('/broadcasting/auth', [
        'socket_id' => '123.456',
        'channel_name' => 'private-branch.'.$branch->id.'.inventory',
    ]);
    $allowed ? $response->assertOk() : $response->assertForbidden();

    if ($role !== 'owner') {
        $this->postJson('/broadcasting/auth', [
            'socket_id' => '123.456',
            'channel_name' => 'private-branch.'.$otherBranch->id.'.inventory',
        ])->assertForbidden();
    }
})->with([
    'assigned cashier' => ['cashier', true, true],
    'unassigned cashier' => ['cashier', false, false],
    'assigned kitchen-only user' => ['kitchen_staff', true, false],
    'business-wide owner' => ['owner', false, true],
]);
