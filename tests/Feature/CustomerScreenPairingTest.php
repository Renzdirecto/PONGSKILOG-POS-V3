<?php

use App\Enums\CustomerScreenMode;
use App\Events\CustomerScreenChanged;
use App\Models\Branch;
use App\Models\CustomerScreen;
use App\Models\Role;
use App\Models\User;
use App\Support\ActiveBranchContext;
use App\Support\CustomerScreens;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    /** The screen's device cookie travels with JSON requests, like a browser's `fetch` with credentials. */
    $this->withCredentials();
    $this->main = Branch::factory()->create(['name' => 'Main Branch', 'code' => 'MAIN']);
    $this->qave = Branch::factory()->create(['name' => 'Qave Branch', 'code' => 'QAVE']);
});

function pairingStaff(?Branch $branch, string $role = 'cashier'): User
{
    $user = User::factory()->create();
    $user->roles()->attach(Role::query()->where('name', $role)->sole());
    if ($branch !== null) {
        $user->branches()->attach($branch, ['is_active' => true]);
    }

    return $user;
}

/**
 * A customer screen device: asks for a pairing code and returns its device cookie value and the code it shows.
 *
 * @return array{token: string, code: string}
 */
function pairingScreenDevice(): array
{
    /** An empty device cookie is a new browser, even after earlier requests of the test sent one. */
    $response = test()->withCookie(CustomerScreens::COOKIE, '')->postJson(route('customer-screen.pairing-code'));
    $response->assertOk()->assertJsonPath('screen.status', 'unpaired');

    return [
        'token' => $response->getCookie(CustomerScreens::COOKIE)->getValue(),
        'code' => $response->json('code'),
    ];
}

function pairingAsScreen(string $token): TestResponse
{
    return test()->withCookie(CustomerScreens::COOKIE, $token)->getJson(route('customer-screen.state'));
}

function pairingPair(User $user, string $station, string $code): TestResponse
{
    return test()->actingAs($user)->withHeader(CustomerScreens::STATION_HEADER, $station)
        ->postJson(route('pos.customer-screen.pair'), ['code' => $code]);
}

test('a screen shows a one-time code and pairs with the cashier station at the selected Branch', function () {
    Event::fake([CustomerScreenChanged::class]);
    $cashier = pairingStaff($this->main);
    $station = (string) Str::uuid();
    $device = pairingScreenDevice();

    expect($device['code'])->toMatch('/\A[ABCDEFGHJKMNPQRSTUVWXYZ23456789]{6}\z/')
        ->and(CustomerScreen::query()->sole()->pairing_code_hash)->not->toBe($device['code']);

    pairingPair($cashier, $station, strtolower(substr($device['code'], 0, 3).'-'.substr($device['code'], 3)))
        ->assertOk()->assertJsonPath('paired', true)->assertJsonPath('mode', 'ads')
        ->assertJsonStructure(['paired', 'mode', 'paired_at', 'last_seen_at']);

    $screen = CustomerScreen::query()->sole();
    expect($screen->branch_id)->toBe($this->main->id)
        ->and($screen->station_hash)->toBe(hash('sha256', $station))
        ->and($screen->paired_by_user_id)->toBe($cashier->id)
        ->and($screen->pairing_code_hash)->toBeNull()
        ->and($screen->mode)->toBe(CustomerScreenMode::Ads);
    $this->assertDatabaseHas('audit_logs', ['action' => 'customer_screen.paired', 'branch_id' => $this->main->id, 'user_id' => $cashier->id]);
    Event::assertDispatched(CustomerScreenChanged::class, fn (CustomerScreenChanged $event): bool => $event->broadcastWith()['reason'] === 'pairing'
        && array_keys($event->broadcastWith()) === ['event_id', 'event_type', 'reason', 'occurred_at']);

    $state = pairingAsScreen($device['token'])->assertOk()
        ->assertJsonPath('screen.status', 'paired')
        ->assertJsonPath('screen.branch', ['name' => 'Main Branch'])
        ->assertJsonPath('screen.mode', 'ads')
        ->assertJsonPath('screen.cart', null)
        ->assertJsonPath('screen.takeover', null);
    expect($state->getContent())->not->toContain($screen->id)
        ->not->toContain($screen->token_hash)
        ->not->toContain((string) $screen->station_hash)
        ->not->toContain($cashier->name)
        ->and($state->headers->get('Cache-Control'))->toContain('no-store');
});

test('expired, malformed and already used pairing codes are rejected', function () {
    $cashier = pairingStaff($this->main);
    $station = (string) Str::uuid();

    $expired = pairingScreenDevice();
    $this->travel(CustomerScreens::CODE_TTL_SECONDS + 1)->seconds();
    pairingPair($cashier, $station, $expired['code'])->assertUnprocessable()->assertJsonValidationErrors('code');
    pairingPair($cashier, $station, 'NOT-A-CODE!')->assertUnprocessable()->assertJsonValidationErrors('code');

    $fresh = pairingScreenDevice();
    pairingPair($cashier, $station, $fresh['code'])->assertOk();
    pairingPair($cashier, (string) Str::uuid(), $fresh['code'])->assertUnprocessable()->assertJsonValidationErrors('code');

    expect(CustomerScreen::query()->whereNotNull('branch_id')->count())->toBe(1);
});

test('one station drives one screen and pairing a new screen releases the previous one', function () {
    Event::fake([CustomerScreenChanged::class]);
    $cashier = pairingStaff($this->main);
    $station = (string) Str::uuid();
    $first = pairingScreenDevice();
    pairingPair($cashier, $station, $first['code'])->assertOk();
    $second = pairingScreenDevice();

    pairingPair($cashier, $station, $second['code'])->assertOk();

    pairingAsScreen($first['token'])->assertJsonPath('screen.status', 'unpaired')->assertJsonPath('screen.branch', null);
    pairingAsScreen($second['token'])->assertJsonPath('screen.status', 'paired');
    expect(CustomerScreen::query()->where('branch_id', $this->main->id)->count())->toBe(1);
    Event::assertDispatched(CustomerScreenChanged::class, fn (CustomerScreenChanged $event): bool => count($event->broadcastOn()) === 2);
});

test('a cashier change on the same station keeps the pairing and its controls', function () {
    $morning = pairingStaff($this->main);
    $evening = pairingStaff($this->main, 'cashier_kitchen');
    $station = (string) Str::uuid();
    $device = pairingScreenDevice();
    pairingPair($morning, $station, $device['code'])->assertOk();

    $this->actingAs($evening)->withHeader(CustomerScreens::STATION_HEADER, $station)
        ->getJson(route('pos.customer-screen.status'))->assertOk()->assertJsonPath('paired', true);
    $this->actingAs($evening)->withHeader(CustomerScreens::STATION_HEADER, $station)
        ->putJson(route('pos.customer-screen.mode'), ['control' => 'menu'])->assertOk()->assertJsonPath('mode', 'menu');

    pairingAsScreen($device['token'])->assertJsonPath('screen.mode', 'menu');
});

test('MENU and CUSTOMER DISPLAY are mutually exclusive and both off means Ads', function () {
    Event::fake([CustomerScreenChanged::class]);
    $cashier = pairingStaff($this->main);
    $station = (string) Str::uuid();
    pairingPair($cashier, $station, pairingScreenDevice()['code'])->assertOk();
    $press = fn (string $control) => $this->actingAs($cashier)->withHeader(CustomerScreens::STATION_HEADER, $station)
        ->putJson(route('pos.customer-screen.mode'), ['control' => $control]);

    $press('menu')->assertJsonPath('mode', 'menu');
    $press('customer_display')->assertJsonPath('mode', 'customer_display');
    $press('customer_display')->assertJsonPath('mode', 'ads');
    $press('menu')->assertJsonPath('mode', 'menu');
    $press('menu')->assertJsonPath('mode', 'ads');
    $press('ads')->assertUnprocessable()->assertJsonValidationErrors('control');

    expect(CustomerScreen::query()->sole()->mode)->toBe(CustomerScreenMode::Ads)
        ->and(CustomerScreenMode::Menu->toggled(CustomerScreenMode::CustomerDisplay))->toBe(CustomerScreenMode::CustomerDisplay);
    Event::assertDispatchedTimes(CustomerScreenChanged::class, 6);
});

test('the Customer Display mode shows the existing order-number board', function () {
    $cashier = pairingStaff($this->main);
    $station = (string) Str::uuid();
    $device = pairingScreenDevice();
    pairingPair($cashier, $station, $device['code'])->assertOk();
    $this->actingAs($cashier)->withHeader(CustomerScreens::STATION_HEADER, $station)
        ->putJson(route('pos.customer-screen.mode'), ['control' => 'customer_display'])->assertOk();

    pairingAsScreen($device['token'])->assertJsonPath('screen.mode', 'customer_display')
        ->assertJsonPath('screen.board', ['is_open' => false, 'preparing' => [], 'ready' => []]);
});

test('pairing and controls require POS access at the selected Branch and never cross Branches', function () {
    $mainCashier = pairingStaff($this->main);
    $qaveCashier = pairingStaff($this->qave);
    $station = (string) Str::uuid();
    pairingPair($mainCashier, $station, pairingScreenDevice()['code'])->assertOk();

    pairingPair(pairingStaff($this->main, 'kitchen_staff'), (string) Str::uuid(), pairingScreenDevice()['code'])->assertForbidden();
    pairingPair(pairingStaff(null, 'owner'), (string) Str::uuid(), pairingScreenDevice()['code'])->assertForbidden();

    /** The same station id at another Branch finds nothing and changes nothing. */
    $this->actingAs($qaveCashier)->withHeader(CustomerScreens::STATION_HEADER, $station)
        ->getJson(route('pos.customer-screen.status'))->assertOk()->assertJsonPath('paired', false);
    $this->actingAs($qaveCashier)->withHeader(CustomerScreens::STATION_HEADER, $station)
        ->putJson(route('pos.customer-screen.mode'), ['control' => 'menu'])->assertNotFound();
    $this->actingAs($qaveCashier)->withHeader(CustomerScreens::STATION_HEADER, $station)
        ->deleteJson(route('pos.customer-screen.unpair'))->assertOk();
    expect(CustomerScreen::query()->where('branch_id', $this->main->id)->where('mode', 'ads')->count())->toBe(1);

    /** A Super Admin operates the Branch it selected, never a forged one. */
    $admin = pairingStaff(null, 'super_admin');
    $this->actingAs($admin)->withSession([ActiveBranchContext::SESSION_KEY => $this->main->id])
        ->withHeader(CustomerScreens::STATION_HEADER, $station)
        ->getJson(route('pos.customer-screen.status'))->assertOk()->assertJsonPath('paired', true);

    $this->actingAs($mainCashier)->withHeader(CustomerScreens::STATION_HEADER, 'bad station!')
        ->getJson(route('pos.customer-screen.status'))->assertUnprocessable()->assertJsonValidationErrors('station');
});

test('the public screen cannot reach any staff mutation and staff routes need a signed-in account', function () {
    $device = pairingScreenDevice();
    $station = (string) Str::uuid();

    foreach ([
        ['postJson', route('pos.customer-screen.pair'), ['code' => $device['code']]],
        ['putJson', route('pos.customer-screen.mode'), ['control' => 'menu']],
        ['postJson', route('pos.customer-screen.cart'), ['instance' => 'abcdefgh', 'sequence' => 1, 'items' => []]],
        ['postJson', route('pos.customer-screen.takeover'), ['order_id' => (string) Str::uuid()]],
    ] as [$method, $url, $data]) {
        $this->withCookie(CustomerScreens::COOKIE, $device['token'])->withHeader(CustomerScreens::STATION_HEADER, $station)
            ->{$method}($url, $data)->assertUnauthorized();
    }
    $this->postJson(route('customer-screen.menu'))->assertMethodNotAllowed();
    expect(CustomerScreen::query()->sole()->isPaired())->toBeFalse();
});

test('the screen authorizes only its own channel and, once paired, its Branch catalog and board signals', function () {
    config(['broadcasting.default' => 'pusher', 'broadcasting.connections.pusher' => [
        'driver' => 'pusher', 'key' => 'test-key', 'secret' => 'test-secret', 'app_id' => 'test-app', 'options' => ['cluster' => 'ap1'],
    ]]);
    $cashier = pairingStaff($this->main);
    $device = pairingScreenDevice();
    $other = pairingScreenDevice();
    $screen = CustomerScreen::query()->where('token_hash', hash('sha256', $device['token']))->sole();
    $otherScreen = CustomerScreen::query()->where('token_hash', hash('sha256', $other['token']))->sole();
    $authorize = fn (string $token, string $channel) => $this->withCookie(CustomerScreens::COOKIE, $token)
        ->postJson(route('customer-screen.broadcasting.auth'), ['socket_id' => '123.456', 'channel_name' => $channel]);

    $authorize($device['token'], 'private-customer-screen.'.$screen->channel_key)->assertOk()->assertJsonStructure(['auth']);
    $authorize($device['token'], 'private-qr-catalog.'.$this->main->id)->assertForbidden();

    pairingPair($cashier, (string) Str::uuid(), $device['code'])->assertOk();

    $authorize($device['token'], 'private-qr-catalog.'.$this->main->id)->assertOk();
    $authorize($device['token'], 'private-branch.'.$this->main->id.'.customer-display')->assertOk();
    $authorize($device['token'], 'private-branch.'.$this->main->id.'.pos')->assertForbidden();
    $authorize($device['token'], 'private-qr-catalog.'.$this->qave->id)->assertForbidden();
    $authorize($device['token'], 'private-customer-screen.'.$otherScreen->channel_key)->assertForbidden();
    $this->withCookie(CustomerScreens::COOKIE, '')
        ->postJson(route('customer-screen.broadcasting.auth'), ['socket_id' => '123.456', 'channel_name' => 'private-customer-screen.'.$screen->channel_key])->assertForbidden();
});

test('the screen can reset its own pairing and then asks for a new code', function () {
    $cashier = pairingStaff($this->main);
    $device = pairingScreenDevice();
    pairingPair($cashier, (string) Str::uuid(), $device['code'])->assertOk();

    $this->withCookie(CustomerScreens::COOKIE, $device['token'])->postJson(route('customer-screen.reset'))->assertOk();

    pairingAsScreen($device['token'])->assertJsonPath('screen.status', 'unpaired');
    $this->assertDatabaseHas('audit_logs', ['action' => 'customer_screen.reset_on_screen', 'branch_id' => $this->main->id, 'user_id' => null]);
    $this->withCookie(CustomerScreens::COOKIE, $device['token'])->postJson(route('customer-screen.pairing-code'))
        ->assertOk()->assertJsonPath('screen.status', 'unpaired')->assertJson(fn ($json) => $json->whereType('code', 'string')->etc());
});

test('the customer screen page is a public kiosk page without staff shared props or the staff app manifest', function () {
    $response = $this->get(route('customer-screen.show'))->assertOk();

    $response->assertInertia(fn ($page) => $page->component('customer-screen')
        ->where('screen.status', 'unpaired')
        ->missing('auth')->missing('branchContext')->missing('storeContext'));
    expect($response->getContent())->not->toContain('manifest.webmanifest');
});
