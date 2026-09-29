<?php

use App\Models\User;
use App\Support\ActiveBranchContext;
use App\Support\RateLimits;
use Database\Seeders\RbacSeeder;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\StoreCloseScenario;

beforeEach(function () {
    $this->seed(RbacSeeder::class);
});

/** @return list<string> every throttle middleware of a route */
function routeThrottles(RoutingRoute $route): array
{
    return array_values(array_filter($route->gatherMiddleware(), fn (mixed $middleware): bool => is_string($middleware) && str_starts_with($middleware, 'throttle:')));
}

test('no route uses an un-named throttle and every named limiter exists', function () {
    $named = [];
    foreach (Route::getRoutes() as $route) {
        foreach (routeThrottles($route) as $throttle) {
            $name = substr($throttle, strlen('throttle:'));
            expect($name)->not->toMatch('/^\d/', "Route [{$route->uri()}] uses the shared un-named {$throttle}.");
            $named[$name] = true;
        }
    }

    foreach (array_keys($named) as $name) {
        /** An unknown name would be read as "0 attempts" and reject every request. */
        expect(RateLimiter::limiter($name))->not->toBeNull("Limiter [{$name}] is not registered.");
    }
    expect(array_keys(RateLimits::STAFF))->toContain('void', 'store-close', 'pos-recipe-capacity', 'pos-payments');
});

test('sensitive writes each have their own named limiter', function (string $routeName, string $limiter) {
    expect(routeThrottles(Route::getRoutes()->getByName($routeName)))->toContain('throttle:'.$limiter);
})->with([
    ['pos.payments.store', 'pos-payments'],
    ['pos.orders.pay-later.store', 'pos-payments'],
    ['pos.orders.settlements.store', 'pos-payments'],
    ['pos.transactions.void', 'void'],
    ['pos.transactions.update', 'pos-order-edits'],
    ['store-sessions.open', 'store-open'],
    ['store-sessions.close.store', 'store-close'],
    ['store-session-expenses.store', 'store-expenses'],
    ['store-session-inventory-adjustments.store', 'stock-corrections'],
    ['store-session-giveaways.store', 'giveaways'],
    ['pos.qr-orders.load', 'pos-qr-orders'],
    ['pos.payments.invoice.store', 'invoice-proofs'],
    ['orders.kitchen-status.update', 'kitchen-status'],
    ['inventory.adjustments.store', 'inventory-adjustments'],
    ['operations.pamamalengke.confirm', 'pamamalengke-confirm'],
    ['workspaces.reports.export', 'reports-export'],
    ['super-admin.access-control.roles.update', 'access-control'],
    ['workspaces.void-orders.pin.update', 'void-pin'],
    ['products.image.store', 'product-images'],
    ['branches.update', 'branch-settings'],
    ['qr.orders.store', 'customer-qr-submit'],
    ['receipt.show', 'public-receipt'],
    ['profile.update', 'account'],
    ['user-password.update', 'password-update'],
    ['verification.send', 'verification'],
]);

test('heavy recipe-capacity traffic never consumes the Void, Close Store or expense limits', function () {
    $scenario = StoreCloseScenario::create();
    $scenario->authorizeVoids();
    $order = $scenario->payLater(1);
    $http = $this->actingAs($scenario->cashier)->withSession([ActiveBranchContext::SESSION_KEY => $scenario->branch->id]);

    for ($attempt = 1; $attempt <= RateLimits::STAFF['pos-recipe-capacity']; $attempt++) {
        $http->postJson(route('pos.recipe-capacity'), [])->assertStatus(422);
    }
    $http->postJson(route('pos.recipe-capacity'), [])->assertTooManyRequests();

    /** Each of these answers on its own merits (validation or success), never 429 from the capacity traffic. */
    $http->postJson(route('pos.transactions.void', $order), [
        'reason_code' => 'wrong_item', 'authorization_pin' => '1234', 'idempotency_key' => (string) Str::uuid(), 'expected_version' => $order->fresh()->version,
    ])->assertOk();
    $http->postJson(route('store-sessions.close.store'), [])->assertUnprocessable();
    $http->postJson(route('store-session-expenses.store'), [])->assertUnprocessable();
});

test('one Customer QR phone at its submit limit never blocks another phone on the same store Wi-Fi', function () {
    $scenario = StoreCloseScenario::create();
    $cookie = 'customer_qr_'.$scenario->branch->id;
    [$first, $second] = [bin2hex(random_bytes(32)), bin2hex(random_bytes(32))];
    $submit = fn (string $token) => $this->withCredentials()->withCookie($cookie, $token)->postJson(route('qr.orders.store', $scenario->branch), []);

    for ($attempt = 1; $attempt <= 10; $attempt++) {
        expect($submit($first)->status())->not->toBe(429);
    }
    $submit($first)->assertTooManyRequests();

    expect($submit($second)->status())->not->toBe(429);
});

test('password reset requests are throttled and never reveal whether an account exists', function () {
    $user = User::factory()->create(['email' => 'cashier@pongskilog.test']);

    $known = $this->from(route('password.request'))->post(route('password.email'), ['email' => $user->email]);
    $unknown = $this->from(route('password.request'))->post(route('password.email'), ['email' => 'nobody@pongskilog.test']);

    $known->assertSessionHasNoErrors()->assertSessionHas('status', trans('passwords.sent'));
    $unknown->assertSessionHasNoErrors()->assertSessionHas('status', trans('passwords.sent'));

    /** Five requests per e-mail and address a minute: the unknown address above was the first. */
    for ($attempt = 2; $attempt <= 5; $attempt++) {
        $this->post(route('password.email'), ['email' => 'nobody@pongskilog.test'])->assertRedirect();
    }
    $this->post(route('password.email'), ['email' => 'nobody@pongskilog.test'])->assertTooManyRequests();
});

test('confirming the password is throttled per account', function () {
    $user = User::factory()->create();

    for ($attempt = 1; $attempt <= RateLimits::STAFF['password-confirm']; $attempt++) {
        $this->actingAs($user)->post(route('password.confirm.store'), ['password' => 'wrong-password']);
    }

    $this->actingAs($user)->post(route('password.confirm.store'), ['password' => 'wrong-password'])->assertTooManyRequests();
});
