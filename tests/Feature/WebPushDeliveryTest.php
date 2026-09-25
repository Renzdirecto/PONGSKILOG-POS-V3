<?php

use App\Enums\KitchenStatus;
use App\Enums\PermissionOverrideEffect;
use App\Events\KitchenTicketCreated;
use App\Jobs\SendPushNotification;
use App\Models\Branch;
use App\Models\KitchenTicket;
use App\Models\Order;
use App\Models\Permission;
use App\Models\PushSubscription;
use App\Models\Role;
use App\Models\StoreSession;
use App\Models\User;
use App\Models\UserPermissionOverride;
use App\Notifications\AdminAlert;
use App\Support\AdminNotifier;
use App\Support\PushGateway;
use App\Support\PushMessage;
use App\Support\PushRecipients;
use App\Support\WebPushSender;
use Database\Factories\PushSubscriptionFactory;
use Database\Seeders\RbacSeeder;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

/** Records deliveries instead of calling a push service; answers with scripted statuses per subscription. */
class PushGatewayDouble implements PushGateway
{
    /** @var list<array{subscription: int, user: int, type: string, tag: string, payload: array<string, mixed>}> */
    public array $deliveries = [];

    /** @var array<int, list<int|null|Throwable>> */
    public array $responses = [];

    public function deliver(PushSubscription $subscription, PushMessage $message, string $payload): ?int
    {
        $this->deliveries[] = [
            'subscription' => (int) $subscription->id,
            'user' => (int) $subscription->user_id,
            'type' => $message->type->value,
            'tag' => $message->tag,
            'payload' => json_decode($payload, true),
        ];
        $next = ($this->responses[$subscription->id] ?? []) === []
            ? 201
            : array_shift($this->responses[$subscription->id]);
        if ($next instanceof Throwable) {
            throw $next;
        }

        return $next;
    }

    /** @return list<int> */
    public function recipients(): array
    {
        return array_values(array_unique(array_column($this->deliveries, 'user')));
    }
}

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    config(['services.webpush' => [
        'subject' => 'mailto:push@example.com',
        'public_key' => PushSubscriptionFactory::BROWSER_PUBLIC_KEY,
        'private_key' => 'server-only-vapid-private-key',
    ]]);
    $this->gateway = new PushGatewayDouble;
    app()->instance(PushGateway::class, $this->gateway);
    $this->main = Branch::factory()->create(['name' => 'Main', 'code' => 'MAIN']);
    $this->qave = Branch::factory()->create(['name' => 'Qave', 'code' => 'QAVE']);
});

function pushUser(string $role, ?Branch $branch = null, bool $active = true, bool $subscribed = true): User
{
    $user = User::factory()->create(['is_active' => $active]);
    $user->roles()->attach(Role::query()->where('name', $role)->sole());
    if ($branch !== null) {
        $user->branches()->attach($branch, ['is_active' => true]);
    }
    if ($subscribed) {
        PushSubscription::factory()->for($user)->create();
    }

    return $user;
}

function pushKitchenOrder(Branch $branch, KitchenStatus $status = KitchenStatus::Kitchen): Order
{
    $session = StoreSession::factory()->for($branch)->create();
    $order = Order::factory()->for($branch)->for($session)->create([
        'commercial_status' => 'active',
        'payment_status' => 'paid',
        'payment_term' => 'immediate',
        'kitchen_status' => $status,
        'committed_at' => now()->subMinute(),
        'completed_at' => $status === KitchenStatus::Done ? now() : null,
    ]);
    KitchenTicket::factory()->for($branch)->for($order)->create(['status' => $status]);

    return $order;
}

function newKitchenTicket(Order $order): void
{
    DB::transaction(fn () => event(new KitchenTicketCreated($order, $order->kitchenTicket()->sole())));
}

test('a new Kitchen order is pushed to the accounts that may see that Branch Kitchen, with a minimal payload', function () {
    $kitchen = pushUser('kitchen_staff', $this->main);
    $both = pushUser('cashier_kitchen', $this->main);
    $superAdmin = pushUser('super_admin');
    pushUser('kitchen_staff', $this->qave);
    pushUser('cashier', $this->main);
    pushUser('owner');
    pushUser('kitchen_staff', $this->main, active: false);
    pushUser('kitchen_staff', $this->main, subscribed: false);
    $order = pushKitchenOrder($this->main);

    newKitchenTicket($order);

    expect($this->gateway->recipients())->toEqualCanonicalizing([$kitchen->id, $both->id, $superAdmin->id])
        ->and($this->gateway->deliveries[0]['payload'])->toBe([
            'v' => 1,
            'type' => 'kitchen.new_order',
            'tag' => 'kitchen-new-order:'.$order->id,
            'url' => '/workspaces/kitchen',
            'branch' => 'Main',
        ]);
});

test('Order Ready is pushed to the Branch POS accounts, and undoing a served order does not push again', function () {
    $cashier = pushUser('cashier', $this->main);
    pushUser('kitchen_staff', $this->main);
    pushUser('cashier', $this->qave);
    $order = pushKitchenOrder($this->main, KitchenStatus::Preparing);
    $cook = pushUser('kitchen_staff', $this->main, subscribed: false);

    $this->actingAs($cook)->patchJson(route('orders.kitchen-status.update', $order), ['status' => 'ready'])->assertOk();
    $this->actingAs($cook)->patchJson(route('orders.kitchen-status.update', $order), ['status' => 'done'])->assertOk();
    $this->actingAs($cook)->patchJson(route('orders.kitchen-status.update', $order), ['status' => 'ready'])->assertOk();

    expect($this->gateway->deliveries)->toHaveCount(1)
        ->and($this->gateway->recipients())->toBe([$cashier->id])
        ->and($this->gateway->deliveries[0]['payload'])->toMatchArray([
            'type' => 'order.ready',
            'tag' => 'order-ready:'.$order->id,
            'url' => '/workspaces/cashier',
        ]);
});

test('recipients are decided when the push is delivered, from current permissions and Branch access', function (Closure $revoke) {
    $kitchen = pushUser('kitchen_staff', $this->main);
    $order = pushKitchenOrder($this->main);
    Queue::fake([SendPushNotification::class]);

    newKitchenTicket($order);
    $revoke($kitchen, $this->main);
    Queue::pushed(SendPushNotification::class)->sole()->handle(app(PushRecipients::class), app(WebPushSender::class));

    expect($this->gateway->deliveries)->toBe([]);
})->with([
    'permission revoked' => [function (User $user): void {
        UserPermissionOverride::query()->create([
            'user_id' => $user->id,
            'permission_id' => Permission::query()->where('name', 'kitchen.access')->sole()->id,
            'effect' => PermissionOverrideEffect::Deny,
        ]);
    }],
    'Branch assignment removed' => [fn (User $user, Branch $branch) => $user->branches()->updateExistingPivot($branch->id, ['is_active' => false])],
    'account deactivated' => [fn (User $user) => $user->forceFill(['is_active' => false])->save()],
]);

test('important alerts reach the Super Admins AdminNotifier notified, tagged by the stored notification', function () {
    $admin = pushUser('super_admin');
    $actor = pushUser('super_admin');
    pushUser('owner');

    DB::transaction(fn () => AdminNotifier::superAdmins(new AdminAlert('staff', 'New staff account', 'Juan Dela Cruz joined'), except: $actor));

    $notification = $admin->notifications()->sole();
    expect($this->gateway->recipients())->toBe([$admin->id])
        ->and($this->gateway->deliveries[0]['payload'])->toBe([
            'v' => 1,
            'type' => 'admin.alert',
            'tag' => 'admin-alert:'.$notification->id,
            'url' => '/workspaces/super-admin/notifications',
            'branch' => null,
        ])
        ->and(json_encode($this->gateway->deliveries))->not->toContain('Juan');
});

test('an admin demoted or deactivated before delivery gets no queued alert', function (Closure $change) {
    $admin = pushUser('super_admin');
    Queue::fake([SendPushNotification::class]);

    DB::transaction(fn () => AdminNotifier::superAdmins(new AdminAlert('access', 'Role changed', 'Details')));
    $change($admin);
    Queue::pushed(SendPushNotification::class)->sole()->handle(app(PushRecipients::class), app(WebPushSender::class));

    expect($this->gateway->deliveries)->toBe([]);
})->with([
    'deactivated' => [fn (User $user) => $user->forceFill(['is_active' => false])->save()],
    'no longer Super Admin' => [fn (User $user) => $user->roles()->sync([Role::query()->where('name', 'owner')->sole()->id])],
]);

test('a subscription the push service rejects for good is removed, without logging its endpoint', function (int $status) {
    $kitchen = pushUser('kitchen_staff', $this->main);
    $subscription = $kitchen->pushSubscriptions()->sole();
    $this->gateway->responses[$subscription->id] = [$status];
    Log::spy();

    newKitchenTicket(pushKitchenOrder($this->main));

    expect(PushSubscription::query()->count())->toBe(0);
    Log::shouldHaveReceived('info')->withArgs(fn (string $message, array $context): bool => $context === [
        'subscription_id' => $subscription->id,
        'type' => 'kitchen.new_order',
        'reason' => 'status '.$status,
    ]);
})->with([404, 410, 400, 401, 403]);

test('transient failures retry only the failed subscription, at most three attempts', function () {
    $flaky = pushUser('kitchen_staff', $this->main)->pushSubscriptions()->sole();
    $healthy = pushUser('kitchen_staff', $this->main)->pushSubscriptions()->sole();
    $this->gateway->responses[$flaky->id] = [503, 429, null, 201];
    Log::spy();

    newKitchenTicket(pushKitchenOrder($this->main));

    $attempts = array_count_values(array_column($this->gateway->deliveries, 'subscription'));
    expect($attempts)->toBe([$flaky->id => 3, $healthy->id => 1])
        ->and(PushSubscription::query()->count())->toBe(2);
    Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context): bool => $context === [
        'subscription_id' => $flaky->id,
        'type' => 'kitchen.new_order',
        'status' => 503,
    ]);
});

test('an unexpected local error keeps the subscription; undecryptable material removes it', function () {
    $kept = pushUser('kitchen_staff', $this->main)->pushSubscriptions()->sole();
    $broken = pushUser('kitchen_staff', $this->main)->pushSubscriptions()->sole();
    $this->gateway->responses[$kept->id] = [new RuntimeException('Unable to create the local key.'), 201];
    $this->gateway->responses[$broken->id] = [new DecryptException('The payload is invalid.')];

    newKitchenTicket(pushKitchenOrder($this->main));

    expect(PushSubscription::query()->pluck('id')->all())->toBe([$kept->id]);
});

test('pushes are queued only after the business transaction commits', function () {
    pushUser('kitchen_staff', $this->main);
    $order = pushKitchenOrder($this->main);
    $ticket = $order->kitchenTicket()->sole();
    Queue::fake([SendPushNotification::class]);

    DB::beginTransaction();
    event(new KitchenTicketCreated($order, $ticket));
    DB::rollBack();
    Queue::assertNothingPushed();

    DB::transaction(fn () => event(new KitchenTicketCreated($order, $ticket)));
    Queue::assertPushed(SendPushNotification::class, fn (SendPushNotification $job): bool => $job->message->tag === 'kitchen-new-order:'.$order->id);
});

test('nothing is queued while Web Push is not configured', function () {
    config(['services.webpush.public_key' => null]);
    pushUser('kitchen_staff', $this->main);
    Queue::fake([SendPushNotification::class]);

    newKitchenTicket(pushKitchenOrder($this->main));

    Queue::assertNothingPushed();
});

test('a failed push never fails the Kitchen action that caused it', function () {
    pushUser('cashier', $this->main);
    $order = pushKitchenOrder($this->main, KitchenStatus::Preparing);
    $cook = pushUser('kitchen_staff', $this->main, subscribed: false);
    config(['queue.default' => 'unreachable', 'queue.connections.unreachable' => ['driver' => 'push-test-broken']]);
    Queue::extend('push-test-broken', fn () => throw new RuntimeException('Queue unavailable'));
    Exceptions::fake();

    $this->actingAs($cook)->patchJson(route('orders.kitchen-status.update', $order), ['status' => 'ready'])
        ->assertOk()
        ->assertJsonPath('kitchenTransition.changed', true);

    expect($order->fresh()->kitchen_status)->toBe(KitchenStatus::Ready);
    Exceptions::assertReported(fn (RuntimeException $exception): bool => $exception->getMessage() === 'Queue unavailable');
});
