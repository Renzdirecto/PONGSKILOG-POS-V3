<?php

use App\Models\AuditLog;
use App\Models\PushSubscription;
use App\Models\Role;
use App\Models\User;
use App\Support\PushDevice;
use App\Support\UserSessions;
use Database\Factories\PushSubscriptionFactory;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Facades\DB;

const PUSH_PRIVATE_KEY = 'server-only-vapid-private-key';

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    config(['services.webpush' => [
        'subject' => 'mailto:push@example.com',
        'public_key' => PushSubscriptionFactory::BROWSER_PUBLIC_KEY,
        'private_key' => PUSH_PRIVATE_KEY,
    ]]);
});

function pushAccount(string $role = 'cashier', bool $active = true): User
{
    $user = User::factory()->create(['is_active' => $active]);
    $user->roles()->attach(Role::query()->where('name', $role)->sole());

    return $user;
}

/** @return array<string, mixed> */
function browserSubscription(string $endpoint = 'https://fcm.googleapis.com/fcm/send/device-one'): array
{
    return [
        'endpoint' => $endpoint,
        'keys' => [
            'p256dh' => PushSubscriptionFactory::BROWSER_PUBLIC_KEY,
            'auth' => PushSubscriptionFactory::BROWSER_AUTH_SECRET,
        ],
        'content_encoding' => 'aes128gcm',
    ];
}

test('enabling stores this browser for the signed-in account and never echoes the subscription material', function () {
    $user = pushAccount();
    $subscription = browserSubscription();

    $response = $this->actingAs($user)->postJson(route('pwa.push-subscription.store'), $subscription);

    $response->assertOk()->assertExactJson(['enabled' => true]);
    $deviceId = $response->getCookie(PushDevice::COOKIE)?->getValue();
    expect($deviceId)->toMatch('/\A[A-Za-z0-9]{40}\z/')
        ->and($response->getCookie(PushDevice::COOKIE)?->isHttpOnly())->toBeTrue();
    $row = PushSubscription::query()->sole();
    expect($row->user_id)->toBe($user->id)
        ->and($row->endpoint)->toBe($subscription['endpoint'])
        ->and($row->endpoint_hash)->toBe(hash('sha256', $subscription['endpoint']))
        ->and($row->device_hash)->toBe(PushDevice::hash((string) $deviceId))
        ->and($row->content_encoding)->toBe('aes128gcm')
        ->and($row->toArray())->not->toHaveKeys(['endpoint', 'public_key', 'auth_token', 'endpoint_hash', 'device_hash']);
    foreach ([$subscription['endpoint'], PushSubscriptionFactory::BROWSER_PUBLIC_KEY, PushSubscriptionFactory::BROWSER_AUTH_SECRET, PUSH_PRIVATE_KEY] as $secret) {
        expect($response->getContent())->not->toContain($secret);
    }
});

test('subscription material is encrypted at rest', function () {
    $this->actingAs(pushAccount())->postJson(route('pwa.push-subscription.store'), browserSubscription())->assertOk();

    $raw = DB::table('push_subscriptions')->sole();

    expect($raw->endpoint)->not->toContain('fcm.googleapis.com')
        ->and($raw->public_key)->not->toBe(PushSubscriptionFactory::BROWSER_PUBLIC_KEY)
        ->and($raw->auth_token)->not->toBe(PushSubscriptionFactory::BROWSER_AUTH_SECRET)
        ->and(decrypt($raw->auth_token, false))->toBe(PushSubscriptionFactory::BROWSER_AUTH_SECRET);
});

test('the status endpoint reports this browser and returns only the public key', function () {
    $user = pushAccount();

    $this->actingAs($user)->getJson(route('pwa.push-subscription.show'))
        ->assertOk()
        ->assertExactJson(['available' => true, 'public_key' => PushSubscriptionFactory::BROWSER_PUBLIC_KEY, 'enabled' => false]);

    $deviceId = $this->actingAs($user)->postJson(route('pwa.push-subscription.store'), browserSubscription())
        ->getCookie(PushDevice::COOKIE)?->getValue();
    $status = $this->actingAs($user)->withCredentials()->withCookie(PushDevice::COOKIE, (string) $deviceId)
        ->getJson(route('pwa.push-subscription.show'));

    $status->assertOk()->assertJsonPath('enabled', true);
    expect($status->getContent())->not->toContain(PUSH_PRIVATE_KEY)->not->toContain('fcm.googleapis.com');
});

test('enabling and disabling are audited without any subscription material', function () {
    $user = pushAccount();
    $deviceId = $this->actingAs($user)->postJson(route('pwa.push-subscription.store'), browserSubscription())
        ->getCookie(PushDevice::COOKIE)?->getValue();
    $this->actingAs($user)->withCredentials()->withCookie(PushDevice::COOKIE, (string) $deviceId)
        ->deleteJson(route('pwa.push-subscription.destroy'))
        ->assertOk()->assertExactJson(['enabled' => false]);

    $logs = AuditLog::query()->where('module', 'notifications')->orderBy('created_at')->get();

    expect($logs->pluck('action')->all())->toBe(['notifications.push_enabled', 'notifications.push_disabled'])
        ->and($logs->pluck('user_id')->unique()->all())->toBe([$user->id]);
    $stored = json_encode(DB::table('audit_logs')->where('module', 'notifications')->get());
    foreach (['fcm.googleapis.com', PushSubscriptionFactory::BROWSER_PUBLIC_KEY, PushSubscriptionFactory::BROWSER_AUTH_SECRET, (string) $deviceId] as $secret) {
        expect($stored)->not->toContain($secret);
    }
});

test('guests cannot manage push subscriptions', function () {
    $this->postJson(route('pwa.push-subscription.store'), browserSubscription())->assertUnauthorized();
    $this->getJson(route('pwa.push-subscription.show'))->assertUnauthorized();
    $this->deleteJson(route('pwa.push-subscription.destroy'))->assertUnauthorized();

    expect(PushSubscription::query()->count())->toBe(0);
});

test('an inactive account cannot enable notifications', function () {
    $this->actingAs(pushAccount(active: false))
        ->postJson(route('pwa.push-subscription.store'), browserSubscription())
        ->assertUnauthorized();

    expect(PushSubscription::query()->count())->toBe(0);
});

test('a malformed or foreign subscription is rejected', function (array $override, string $field) {
    $this->actingAs(pushAccount())
        ->postJson(route('pwa.push-subscription.store'), array_replace_recursive(browserSubscription(), $override))
        ->assertUnprocessable()
        ->assertJsonValidationErrors($field);

    expect(PushSubscription::query()->count())->toBe(0);
})->with([
    'missing endpoint' => [['endpoint' => ''], 'endpoint'],
    'plain http' => [['endpoint' => 'http://fcm.googleapis.com/fcm/send/x'], 'endpoint'],
    'internal host' => [['endpoint' => 'https://169.254.169.254/latest/meta-data'], 'endpoint'],
    'look-alike host' => [['endpoint' => 'https://fcm.googleapis.com.evil.example/x'], 'endpoint'],
    'credentials in url' => [['endpoint' => 'https://user:pass@fcm.googleapis.com/fcm/send/x'], 'endpoint'],
    'non-standard port' => [['endpoint' => 'https://fcm.googleapis.com:8443/fcm/send/x'], 'endpoint'],
    'short p256dh key' => [['keys' => ['p256dh' => 'BCVxsr7N']], 'keys.p256dh'],
    'p256dh not a curve point' => [['keys' => ['p256dh' => rtrim(strtr(base64_encode(str_repeat("\x05", 65)), '+/', '-_'), '=')]], 'keys.p256dh'],
    'auth secret of the wrong length' => [['keys' => ['auth' => 'AAAA']], 'keys.auth'],
    'unexpected key material' => [['keys' => ['extra' => 'x']], 'keys'],
    'unknown content encoding' => [['content_encoding' => 'gzip'], 'content_encoding'],
]);

test('the owner is always the signed-in account, never a submitted user id', function () {
    $user = pushAccount();
    $other = pushAccount();

    $this->actingAs($user)
        ->postJson(route('pwa.push-subscription.store'), [...browserSubscription(), 'user_id' => $other->id])
        ->assertOk();

    expect(PushSubscription::query()->sole()->user_id)->toBe($user->id);
});

test('repeated enables and a second tab keep one row per browser endpoint', function () {
    $user = pushAccount();
    $deviceId = $this->actingAs($user)->postJson(route('pwa.push-subscription.store'), browserSubscription())
        ->getCookie(PushDevice::COOKIE)?->getValue();
    $this->actingAs($user)->withCredentials()->withCookie(PushDevice::COOKIE, (string) $deviceId)
        ->postJson(route('pwa.push-subscription.store'), browserSubscription())->assertOk();
    $this->actingAs($user)->postJson(route('pwa.push-subscription.store'), browserSubscription())->assertOk();

    expect(PushSubscription::query()->count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'notifications.push_enabled')->count())->toBe(1);
});

test('a browser enabled by another account moves to the account now signed in there', function () {
    $first = pushAccount();
    $second = pushAccount();
    $this->actingAs($first)->postJson(route('pwa.push-subscription.store'), browserSubscription())->assertOk();

    $this->actingAs($second)->postJson(route('pwa.push-subscription.store'), browserSubscription())->assertOk();

    expect(PushSubscription::query()->sole()->user_id)->toBe($second->id)
        ->and(AuditLog::query()->where('action', 'notifications.push_enabled')->latest('created_at')->first()?->metadata)
        ->toBe(['moved_from_another_account' => true]);
});

test('a new endpoint from the same browser replaces its stale one', function () {
    $user = pushAccount();
    $deviceId = $this->actingAs($user)
        ->postJson(route('pwa.push-subscription.store'), browserSubscription('https://fcm.googleapis.com/fcm/send/old'))
        ->getCookie(PushDevice::COOKIE)?->getValue();

    $this->actingAs($user)->withCredentials()->withCookie(PushDevice::COOKIE, (string) $deviceId)
        ->postJson(route('pwa.push-subscription.store'), browserSubscription('https://web.push.apple.com/new-endpoint'))
        ->assertOk();

    expect(PushSubscription::query()->sole()->endpoint)->toBe('https://web.push.apple.com/new-endpoint');
});

test('disabling removes only the signed-in account own subscription', function () {
    $user = pushAccount();
    $other = pushAccount();
    $deviceId = $this->actingAs($user)->postJson(route('pwa.push-subscription.store'), browserSubscription())
        ->getCookie(PushDevice::COOKIE)?->getValue();
    $foreign = PushSubscription::factory()->for($other)->create();

    $this->actingAs($user)->withCredentials()->withCookie(PushDevice::COOKIE, (string) $deviceId)
        ->deleteJson(route('pwa.push-subscription.destroy'), ['endpoint' => $foreign->endpoint])
        ->assertOk();
    $this->actingAs($user)->deleteJson(route('pwa.push-subscription.destroy'), ['endpoint' => $foreign->endpoint])->assertOk();

    expect(PushSubscription::query()->pluck('id')->all())->toBe([$foreign->id]);
});

test('enabling answers 503 while Web Push is not configured on the server', function () {
    config(['services.webpush.private_key' => null]);

    $this->actingAs(pushAccount())->postJson(route('pwa.push-subscription.store'), browserSubscription())
        ->assertServiceUnavailable();
    $this->actingAs(pushAccount())->getJson(route('pwa.push-subscription.show'))
        ->assertExactJson(['available' => false, 'public_key' => null, 'enabled' => false]);

    expect(PushSubscription::query()->count())->toBe(0);
});

test('logging out removes this browser subscription on the server and keeps the account other devices', function () {
    $user = pushAccount();
    $deviceId = $this->actingAs($user)->postJson(route('pwa.push-subscription.store'), browserSubscription())
        ->getCookie(PushDevice::COOKIE)?->getValue();
    $otherDevice = PushSubscription::factory()->for($user)->create();

    $this->actingAs($user)->withCredentials()->withCookie(PushDevice::COOKIE, (string) $deviceId)
        ->post(route('logout'))
        ->assertRedirect();

    expect(PushSubscription::query()->pluck('id')->all())->toBe([$otherDevice->id]);
    $this->assertGuest();
});

test('ending an account sessions (password reset, deactivation) removes its push subscriptions', function () {
    $user = pushAccount();
    $other = PushSubscription::factory()->create();
    PushSubscription::factory()->count(2)->for($user)->create();

    DB::transaction(fn () => UserSessions::invalidate($user));

    expect(PushSubscription::query()->pluck('id')->all())->toBe([$other->id]);
});
