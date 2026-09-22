<?php

use App\Enums\BranchStatus;
use App\Enums\StoreSessionStatus;
use App\Models\Branch;
use App\Models\StoreSession;
use App\Models\User;
use App\Support\ActiveBranchContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use Inertia\Testing\AssertableInertia as Assert;

test('public QR availability follows branch status and its current session', function (BranchStatus $status, bool $open, string $expected) {
    $branch = Branch::factory()->create(['status' => $status]);
    StoreSession::factory()->closed()->for($branch)->create();
    StoreSession::factory()->create();
    if ($open) {
        StoreSession::factory()->for($branch)->create();
    }
    $before = StoreSession::query()->orderBy('id')->get()->toArray();

    $this->get(route('kiosk.show', ['branch' => $branch->kiosk_code]))->assertInertia(fn (Assert $page) => $page
        ->component('qr/show')
        ->where('branch', $branch->only(['id', 'name', 'code', 'facebook_url', 'website_url']))
        ->where('store', ['status' => $expected, 'is_open' => $expected === 'open'])
        ->missing('auth')->missing('branchContext')->missing('storeContext'));

    expect(StoreSession::query()->orderBy('id')->get()->toArray())->toBe($before);
})->with([
    'active closed' => [BranchStatus::Active, false, 'closed'],
    'active open' => [BranchStatus::Active, true, 'open'],
    'temporarily closed no session' => [BranchStatus::TemporarilyClosed, false, 'closed'],
    'temporarily closed open session' => [BranchStatus::TemporarilyClosed, true, 'closed'],
    'inactive no session' => [BranchStatus::Inactive, false, 'closed'],
    'inactive open session' => [BranchStatus::Inactive, true, 'closed'],
]);

test('QR refresh sees current availability and the UUID link survives a code change', function () {
    $branch = Branch::factory()->create(['code' => 'MAIN']);
    $url = route('qr.show', $branch);
    $this->get($url)->assertRedirect(route('kiosk.show', ['branch' => $branch->kiosk_code]));
    $this->get(route('kiosk.show', ['branch' => $branch->kiosk_code]))->assertInertia(fn (Assert $page) => $page->where('store.status', 'closed'));

    $session = StoreSession::factory()->for($branch)->create();
    $this->get($url)->assertRedirect(route('kiosk.show', ['branch' => $branch->kiosk_code]));
    $this->get(route('kiosk.show', ['branch' => $branch->kiosk_code]))->assertInertia(fn (Assert $page) => $page->where('store.status', 'open'));

    $branch->update(['code' => 'MAIN-NEW', 'status' => BranchStatus::TemporarilyClosed]);
    $this->get($url)->assertRedirect(route('kiosk.show', ['branch' => $branch->kiosk_code]));
    $this->get(route('kiosk.show', ['branch' => $branch->kiosk_code]))->assertInertia(fn (Assert $page) => $page->where('branch.code', 'MAIN-NEW')->where('store.status', 'closed'));
    expect($session->fresh()->status)->toBe(StoreSessionStatus::Open);
});

test('QR never shares internal props even for a signed in user with private session data', function () {
    $branch = Branch::factory()->create();
    $staff = User::factory()->create();
    $staff->branches()->attach($branch, ['is_active' => true]);
    StoreSession::factory()->for($branch)->create([
        'opened_by_user_id' => $staff->id,
        'opening_cash_amount' => '12345.67',
        'opening_cashless_amount' => '76543.21',
        'closing_note' => 'Private reconciliation',
    ]);

    $response = $this->actingAs($staff)->withSession([
        ActiveBranchContext::SESSION_KEY => $branch->id,
        'errors' => (new ViewErrorBag)->put('default', new MessageBag(['private' => 'Internal error'])),
    ])->get(route('kiosk.show', ['branch' => $branch->kiosk_code]));

    $props = $response->viewData('page')['props'];
    expect(array_keys($props))->toEqualCanonicalizing(['branch', 'store', 'catalog', 'order'])
        ->and($props['branch'])->toBe($branch->only(['id', 'name', 'code', 'facebook_url', 'website_url']))
        ->and($props['store'])->toBe(['status' => 'open', 'is_open' => true]);
});

test('missing and malformed QR branches return a safe 404', function (string $id) {
    $this->get(route('qr.show', $id))->assertNotFound();
})->with(['00000000-0000-4000-8000-000000000000', 'not-a-uuid']);

test('QR entry creates only anonymous identity and exposes the protected submission route without operational effects', function () {
    $branch = Branch::factory()->create();
    StoreSession::factory()->for($branch)->create();
    Queue::fake();
    DB::enableQueryLog();

    $this->get(route('kiosk.show', ['branch' => $branch->kiosk_code]))->assertOk();

    $queries = collect(DB::getQueryLog())->pluck('query');
    DB::disableQueryLog();
    expect($queries->filter(fn (string $query): bool => preg_match('/^\s*(insert|update|delete|replace|alter|drop)\b/i', $query) === 1 && ! str_contains($query, 'customer_qr_sessions') && ! str_contains($query, 'customer_qr_visits')))->toBeEmpty();
    $this->assertDatabaseCount('customer_qr_sessions', 1);
    foreach (['orders', 'payments', 'inventory_movements', 'kitchen_tickets'] as $table) {
        $this->assertDatabaseCount($table, 0);
    }
    Queue::assertNothingPushed();
    $qrRoutes = collect(Route::getRoutes()->getRoutes())->filter(fn ($route): bool => str_starts_with($route->uri(), 'qr/'));
    expect($qrRoutes->firstWhere(fn ($route): bool => $route->getName() === 'qr.orders.store')->methods())->toBe(['POST']);
    $this->post(route('kiosk.show', ['branch' => $branch->kiosk_code]))->assertMethodNotAllowed();
});
