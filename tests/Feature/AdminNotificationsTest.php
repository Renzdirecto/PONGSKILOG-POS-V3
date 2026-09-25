<?php

use App\Actions\Inventory\ApplyInventoryMovement;
use App\Actions\Operations\ApplyIngredientMovement;
use App\Enums\IngredientMovementType;
use App\Enums\InventoryMovementType;
use App\Events\NotificationsChanged;
use App\Models\Branch;
use App\Models\BranchInventory;
use App\Models\BranchProduct;
use App\Models\Ingredient;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use App\Notifications\AdminAlert;
use App\Support\ExactQuantity;
use Database\Seeders\RbacSeeder;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->branch = Branch::factory()->create(['name' => 'Main', 'code' => 'MAIN']);
    $this->admin = notificationUser('super_admin', 'First Admin');
    $this->otherAdmin = notificationUser('super_admin', 'Second Admin');
});

function notificationUser(string $role, string $name = 'Someone', bool $active = true, ?Branch $branch = null): User
{
    $user = User::factory()->create(['name' => $name, 'is_active' => $active]);
    $user->roles()->attach(Role::query()->where('name', $role)->sole());
    if ($branch !== null) {
        $user->branches()->attach($branch, ['is_active' => true]);
    }

    return $user;
}

function alertTrackedProduct(Branch $branch, int $onHand, string $name = 'Lemon Soda'): Product
{
    $product = Product::factory()->create(['name' => $name]);
    BranchProduct::factory()->for($branch)->for($product)->create(['tracks_inventory' => true]);
    BranchInventory::factory()->for($branch)->for($product)->create(['on_hand' => $onHand]);

    return $product;
}

function alertSell(Branch $branch, Product $product, int $quantity): void
{
    app(ApplyInventoryMovement::class)->execute($branch, $product, InventoryMovementType::ManualAdjustment, -$quantity, 'test');
}

function alertRestock(Branch $branch, Product $product, int $quantity): void
{
    app(ApplyInventoryMovement::class)->execute($branch, $product, InventoryMovementType::ManualAdjustment, $quantity, 'test');
}

function alertCount(User $user): int
{
    return $user->notifications()->where('type', 'admin.stock')->count();
}

test('the notification center lists only the viewer own real notifications, newest first', function () {
    $this->admin->notify(new AdminAlert('staff', 'Older', 'first body', '/workspaces/super-admin/staff'));
    $this->travel(1)->minutes();
    $this->admin->notify(new AdminAlert('stock', 'Newer', 'second body'));
    $this->otherAdmin->notify(new AdminAlert('access', 'Not yours', 'private'));

    $this->actingAs($this->admin)
        ->get(route('super-admin.notifications'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('super-admin/notifications')
            ->has('notifications.data', 2)
            ->where('notifications.data.0.title', 'Newer')
            ->where('notifications.data.0.read', false)
            ->where('notifications.data.1.url', '/workspaces/super-admin/staff')
            ->where('unreadCount', 2)
            ->where('notificationCenter.unread', 2));
});

test('zero notifications show zero, never a placeholder count', function () {
    $this->actingAs($this->admin)
        ->get(route('super-admin.notifications'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('notifications.data', 0)
            ->where('unreadCount', 0)
            ->where('notificationCenter.unread', 0));

    $this->actingAs($this->admin)->getJson(route('super-admin.notifications.unread-count'))->assertExactJson(['unread' => 0]);
});

test('the notification list is paginated', function () {
    foreach (range(1, 25) as $index) {
        $this->admin->notify(new AdminAlert('stock', 'Alert '.$index, 'body'));
    }

    $this->actingAs($this->admin)
        ->get(route('super-admin.notifications', ['filter' => 'unread']))
        ->assertInertia(fn (Assert $page) => $page
            ->has('notifications.data', 20)
            ->where('notifications.total', 25));
});

test('marking one notification read updates the unread count and signals the owner', function () {
    Event::fake([NotificationsChanged::class]);
    $this->admin->notify(new AdminAlert('stock', 'One', 'body', '/workspaces/operations/stock'));
    $this->admin->notify(new AdminAlert('stock', 'Two', 'body'));
    $first = $this->admin->notifications()->where('data->title', 'One')->sole();

    $this->actingAs($this->admin)
        ->post(route('super-admin.notifications.read', $first->id), ['open' => true])
        ->assertRedirect('/workspaces/operations/stock');

    expect($first->fresh()->read_at)->not->toBeNull();
    $this->actingAs($this->admin)->getJson(route('super-admin.notifications.unread-count'))->assertExactJson(['unread' => 1]);
    Event::assertDispatched(NotificationsChanged::class, fn (NotificationsChanged $event): bool => $event->userId === $this->admin->id);
});

test('mark all read clears every unread notification of the viewer only', function () {
    $this->admin->notify(new AdminAlert('stock', 'One', 'body'));
    $this->admin->notify(new AdminAlert('staff', 'Two', 'body'));
    $this->otherAdmin->notify(new AdminAlert('staff', 'Theirs', 'body'));

    $this->actingAs($this->admin)->post(route('super-admin.notifications.read-all'))->assertRedirect();

    expect($this->admin->unreadNotifications()->count())->toBe(0)
        ->and($this->otherAdmin->unreadNotifications()->count())->toBe(1);
});

test('one account cannot read or mark another account notification', function () {
    $this->otherAdmin->notify(new AdminAlert('staff', 'Theirs', 'body'));
    $theirs = $this->otherAdmin->notifications()->sole();

    $this->actingAs($this->admin)->post(route('super-admin.notifications.read', $theirs->id))->assertNotFound();

    expect($theirs->fresh()->read_at)->toBeNull();
});

test('the notification center is super admin only', function (string $role) {
    $user = notificationUser($role, branch: $role === 'owner' ? null : $this->branch);

    $this->actingAs($user)->get(route('super-admin.notifications'))->assertForbidden();
    $this->actingAs($user)->getJson(route('super-admin.notifications.unread-count'))->assertForbidden();
})->with(['owner', 'cashier', 'kitchen_staff', 'cashier_kitchen']);

test('only the account itself may join its private notification channel', function () {
    config(['broadcasting.default' => 'pusher', 'broadcasting.connections.pusher' => [
        'driver' => 'pusher', 'key' => 'test-key', 'secret' => 'test-secret',
        'app_id' => 'test-app', 'options' => ['cluster' => 'mt1', 'useTLS' => true],
    ]]);
    (static function (): void {
        require base_path('routes/channels.php');
    })();

    $payload = fn (User $user) => ['socket_id' => '123.456', 'channel_name' => 'private-App.Models.User.'.$user->id];

    $this->actingAs($this->admin)->postJson('/broadcasting/auth', $payload($this->admin))->assertOk();
    $this->actingAs($this->admin)->postJson('/broadcasting/auth', $payload($this->otherAdmin))->assertForbidden();
});

test('the realtime signal carries no notification content', function () {
    $event = new NotificationsChanged($this->admin->id);

    expect(array_keys($event->broadcastWith()))->toBe(['event_id', 'event_type', 'occurred_at'])
        ->and($event->broadcastOn()->name)->toBe('private-App.Models.User.'.$this->admin->id)
        ->and($event->broadcastAs())->toBe('notifications.changed');
});

test('a staff deactivation notifies the other active super admins but not the actor or inactive admins', function () {
    $inactiveAdmin = notificationUser('super_admin', 'Retired Admin', active: false);
    $cashier = notificationUser('cashier', 'Juan', branch: $this->branch);

    $this->actingAs($this->admin)->put(route('super-admin.staff.update', $cashier), [
        'name' => 'Juan',
        'email' => $cashier->email,
        'role' => 'cashier',
        'branch_ids' => [$this->branch->id],
        'is_active' => false,
    ])->assertRedirect();

    $notification = $this->otherAdmin->notifications()->sole();
    expect($notification->type)->toBe('admin.staff')
        ->and($notification->data['title'])->toBe('Juan was deactivated')
        ->and($this->admin->notifications()->count())->toBe(0)
        ->and($inactiveAdmin->notifications()->count())->toBe(0)
        ->and($cashier->notifications()->count())->toBe(0);
});

test('a new staff account notifies the other active super admins without credentials', function () {
    $this->actingAs($this->admin)->post(route('super-admin.staff.store'), [
        'employee_id' => '09252601', 'name' => 'New Admin', 'email' => 'new.admin@pongskilog.test',
        'password' => 'Temporary-Pass-42', 'password_confirmation' => 'Temporary-Pass-42',
        'role' => 'super_admin', 'is_active' => true,
    ])->assertSessionHasNoErrors();

    $notification = $this->otherAdmin->notifications()->sole();
    expect($notification->type)->toBe('admin.staff')
        ->and($notification->data['title'])->toBe('New staff account: New Admin')
        ->and($notification->data['body'])->toContain('09252601')->toContain('business-wide')
        ->and(json_encode($notification->data))->not->toContain('Temporary-Pass-42')
        ->and($this->admin->notifications()->count())->toBe(0);
});

test('role baseline and custom access changes notify the other super admins without credentials', function () {
    $cashier = notificationUser('cashier', 'Juan', branch: $this->branch);

    $this->actingAs($this->admin)->put(route('super-admin.access-control.roles.update', 'kitchen_staff'), ['permissions' => ['kitchen.access']]);
    $this->actingAs($this->admin)->put(route('super-admin.access-control.users.update', $cashier), ['overrides' => ['reports.view' => 'allow']]);
    $this->actingAs($this->admin)->put(route('super-admin.staff.password', $cashier), ['password' => 'Secret-Temp-99', 'password_confirmation' => 'Secret-Temp-99']);

    $titles = $this->otherAdmin->notifications()->reorder()->orderBy('created_at')->orderBy('id')->get()->pluck('data.title')->all();
    expect($titles)->toContain('Kitchen Staff role access changed')
        ->and($titles)->toContain('Custom access changed for Juan')
        ->and($titles)->toContain('Password reset for Juan');

    $stored = DatabaseNotification::query()->pluck('data')->implode(' ');
    expect($stored)->not->toContain('Secret-Temp-99')
        ->and($stored)->not->toContain((string) $cashier->fresh()->password);
});

test('a notification is not sent when the business change rolls back', function () {
    try {
        DB::transaction(function (): void {
            alertSell($this->branch, alertTrackedProduct($this->branch, 1), 1);
            throw new RuntimeException('rollback');
        });
    } catch (RuntimeException) {
    }

    expect(DatabaseNotification::query()->count())->toBe(0);
});

test('a product running out notifies once per real in-stock to out-of-stock transition', function () {
    Event::fake([NotificationsChanged::class]);
    $product = alertTrackedProduct($this->branch, 3);

    alertSell($this->branch, $product, 2);
    expect(alertCount($this->admin))->toBe(0);

    alertSell($this->branch, $product, 1);
    expect(alertCount($this->admin))->toBe(1)
        ->and(alertCount($this->otherAdmin))->toBe(1);
    $alert = $this->admin->notifications()->sole();
    expect($alert->data['title'])->toBe('Lemon Soda is out of stock')
        ->and($alert->data['body'])->toContain('Main (MAIN)');
    Event::assertDispatched(NotificationsChanged::class, 2);

    try {
        alertSell($this->branch, $product, 1);
    } catch (ValidationException) {
    }
    expect(alertCount($this->admin))->toBe(1);

    alertRestock($this->branch, $product, 2);
    expect(alertCount($this->admin))->toBe(1);

    alertSell($this->branch, $product, 2);
    expect(alertCount($this->admin))->toBe(2);
});

test('an ingredient running out notifies once and not again while it stays empty', function () {
    $ingredient = Ingredient::factory()->create(['name' => 'Calamansi']);
    $movement = app(ApplyIngredientMovement::class);
    $movement->execute($this->branch, $ingredient->id, IngredientMovementType::OpeningBalance, ExactQuantity::parse('2'));
    expect(alertCount($this->admin))->toBe(0);

    $movement->execute($this->branch, $ingredient->id, IngredientMovementType::Wastage, -ExactQuantity::parse('2'));
    expect(alertCount($this->admin))->toBe(1);

    $movement->execute($this->branch, $ingredient->id, IngredientMovementType::CountCorrection, -ExactQuantity::parse('1'));
    expect(alertCount($this->admin))->toBe(1)
        ->and($this->admin->notifications()->sole()->data['title'])->toBe('Ingredient Calamansi ran out');
});

test('stock alerts never reach inactive super admins or other roles', function () {
    $inactive = notificationUser('super_admin', 'Retired', active: false);
    $owner = notificationUser('owner');

    alertSell($this->branch, alertTrackedProduct($this->branch, 1), 1);

    expect(alertCount($inactive))->toBe(0)
        ->and(alertCount($owner))->toBe(0)
        ->and(alertCount($this->admin))->toBe(1);
});

test('notification links must be same-app relative paths', function (string $url) {
    expect(fn () => new AdminAlert('staff', 'Title', 'Body', $url))->toThrow(InvalidArgumentException::class);
})->with(['https://evil.example', '//evil.example', '/\evil.example']);
