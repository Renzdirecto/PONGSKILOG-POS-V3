<?php

use App\Enums\PermissionOverrideEffect;
use App\Models\Branch;
use App\Models\CustomerReview;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\UserPermissionOverride;
use App\Models\WebsiteEvent;
use App\Support\ActiveBranchContext;
use App\Support\WebsiteAnalytics;
use Carbon\CarbonImmutable;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

const WEBSITE_BROWSER = 'Mozilla/5.0 (Linux; Android 14) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Mobile Safari/537.36';

beforeEach(function () {
    $this->seed(RbacSeeder::class);
});

function websiteAt(string $manila): void
{
    test()->travelTo(CarbonImmutable::parse($manila, 'Asia/Manila'));
}

function websiteUser(string $role): User
{
    $user = User::factory()->create();
    $user->roles()->attach(Role::query()->where('name', $role)->sole());

    return $user;
}

/** One landing-page event from a browser; `$session` keeps the same anonymous visitor across requests. */
function websiteEvent(string $event, ?string $session = null, string $agent = WEBSITE_BROWSER): TestResponse
{
    $request = test()->withHeader('User-Agent', $agent);
    if ($session !== null) {
        $request = $request->withCredentials()->withCookie(config('session.cookie'), $session);
    }

    return $request->postJson(route('website-events.store'), ['event' => $event]);
}

/** @param array<string, string> $query */
function websiteAnalytics(array $query = [], ?User $user = null): TestResponse
{
    return test()->actingAs($user ?? websiteUser('owner'))->get(route('workspaces.website-analytics', $query));
}

test('a landing page event is counted under its Manila date without an address, agent or account', function () {
    websiteAt('2026-10-14 00:30');

    websiteEvent('page_view')->assertNoContent();

    $event = WebsiteEvent::query()->sole();
    expect($event->event)->toBe('page_view')
        ->and($event->occurred_on)->toStartWith('2026-10-14')
        ->and($event->visitor)->toMatch('/\A[a-f0-9]{64}\z/')
        ->and(Schema::getColumnListing('website_events'))->toEqualCanonicalizing(['id', 'event', 'visitor', 'occurred_on', 'created_at']);
});

test('an event the landing page cannot report is rejected', function (mixed $event) {
    websiteEvent($event)->assertUnprocessable()->assertJsonValidationErrors('event');

    $this->assertDatabaseCount('website_events', 0);
})->with(['unknown' => 'order_placed', 'server-only' => 'review_submission', 'missing' => '']);

test('bots and requests without a user agent are answered but never counted', function (string $agent) {
    websiteEvent('page_view', agent: $agent)->assertNoContent();

    $this->assertDatabaseCount('website_events', 0);
})->with([
    'search crawler' => 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)',
    'link preview' => 'facebookexternalhit/1.1',
    'headless browser' => 'Mozilla/5.0 HeadlessChrome/126.0',
    'script' => 'curl/8.4.0',
    'no agent' => '',
]);

test('signed-in staff looking at the landing page are not counted as visitors', function () {
    $this->actingAs(websiteUser('cashier'));

    websiteEvent('page_view')->assertNoContent();

    $this->assertDatabaseCount('website_events', 0);
});

test('one browser session is one estimated visitor per Manila day', function () {
    $suki = Str::random(40);
    websiteAt('2026-10-13 19:00');
    websiteEvent('page_view', $suki);
    websiteEvent('page_view', $suki);
    websiteEvent('page_view', Str::random(40));
    websiteAt('2026-10-14 19:00');
    websiteEvent('page_view', $suki);

    websiteAnalytics(['period' => 'last_7_days'])->assertInertia(fn (Assert $page) => $page
        ->where('report.totals.page_views', 4)
        ->where('report.totals.visitors', 3));
    expect(WebsiteEvent::query()->distinct()->count('visitor'))->toBe(3);
});

test('events from one browser session are rate limited', function () {
    $session = Str::random(40);
    for ($attempt = 0; $attempt < 60; $attempt++) {
        websiteEvent('menu_click', $session)->assertNoContent();
    }

    websiteEvent('menu_click', $session)->assertTooManyRequests();

    $this->assertDatabaseCount('website_events', 60);
});

test('the owner reads today by Manila hour with real totals', function () {
    websiteAt('2026-10-13 21:00');
    WebsiteEvent::factory()->at('2026-10-13 18:05')->count(2)->create(['visitor' => 'a']);
    WebsiteEvent::factory()->at('2026-10-13 20:40')->create(['visitor' => 'b']);
    WebsiteEvent::factory()->at('2026-10-13 18:10')->create(['event' => 'facebook_click']);
    WebsiteEvent::factory()->at('2026-10-13 18:11')->count(2)->create(['event' => 'directions_click']);
    WebsiteEvent::factory()->at('2026-10-13 18:12')->count(3)->create(['event' => 'menu_click']);
    WebsiteEvent::factory()->at('2026-10-13 18:20')->create(['event' => 'review_submission']);
    WebsiteEvent::factory()->at('2026-10-12 18:00')->count(5)->create();

    websiteAnalytics()->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('workspaces/website-analytics')
        ->where('report.period.preset', 'today')
        ->where('report.period.granularity', 'hour')
        ->where('report.totals', [
            'page_views' => 3, 'visitors' => 2, 'facebook_clicks' => 1,
            'directions_clicks' => 2, 'menu_clicks' => 3, 'review_submissions' => 1,
        ])
        ->has('report.series', 24)
        ->where('report.series.18', ['key' => '18', 'label' => '6 PM', 'full' => '6 PM – 7 PM', 'page_views' => 2, 'visitors' => 1])
        ->where('report.series.20.page_views', 1)
        ->where('report.series.19.page_views', 0)
        ->where('report.tracking_since', 'Oct 12, 2026'));
});

test('seven days, thirty days and a custom range report one bucket per Manila date', function (array $query, int $buckets, int $views) {
    websiteAt('2026-10-13 21:00');
    WebsiteEvent::factory()->at('2026-10-13 09:00')->create();
    WebsiteEvent::factory()->at('2026-10-07 23:59')->count(2)->create();
    WebsiteEvent::factory()->at('2026-10-06 23:59')->count(4)->create();
    WebsiteEvent::factory()->at('2026-09-13 12:00')->count(8)->create();

    websiteAnalytics($query)->assertInertia(fn (Assert $page) => $page
        ->where('report.period.granularity', 'day')
        ->has('report.series', $buckets)
        ->where('report.totals.page_views', $views));
})->with([
    '7 days' => [['period' => 'last_7_days'], 7, 3],
    '30 days' => [['period' => 'last_30_days'], 30, 7],
    'custom' => [['period' => 'custom', 'from' => '2026-10-06', 'to' => '2026-10-07'], 2, 6],
]);

test('a period without visits reports zeroes instead of invented figures', function () {
    websiteAt('2026-10-13 21:00');

    websiteAnalytics(['period' => 'last_7_days'])->assertInertia(fn (Assert $page) => $page
        ->where('report.totals', [
            'page_views' => 0, 'visitors' => 0, 'facebook_clicks' => 0,
            'directions_clicks' => 0, 'menu_clicks' => 0, 'review_submissions' => 0,
        ])
        ->where('report.series.6', ['key' => '2026-10-13', 'label' => 'Oct 13', 'full' => 'Oct 13, 2026', 'page_views' => 0, 'visitors' => 0])
        ->where('report.tracking_since', null));
});

test('an invalid period or custom range is rejected', function (array $query, string $field) {
    websiteAnalytics($query)->assertSessionHasErrors($field);
})->with([
    'unknown period' => [['period' => 'last_12_months'], 'period'],
    'custom without dates' => [['period' => 'custom'], 'from'],
    'end before start' => [['period' => 'custom', 'from' => '2026-10-07', 'to' => '2026-10-06'], 'to'],
    'longer than 31 days' => [['period' => 'custom', 'from' => '2026-09-01', 'to' => '2026-10-02'], 'to'],
]);

test('business-wide accounts with Reports open Website Analytics', function (string $role) {
    websiteAnalytics(user: websiteUser($role))->assertOk();
})->with(['owner', 'super_admin']);

test('guests and inactive owners are sent to login', function () {
    $this->get(route('workspaces.website-analytics'))->assertRedirectToRoute('login');

    $owner = websiteUser('owner');
    $owner->forceFill(['is_active' => false])->save();
    websiteAnalytics(user: $owner)->assertRedirectToRoute('login');
    $this->assertGuest();
});

test('operational staff cannot open Website Analytics', function (string $role) {
    websiteAnalytics(user: websiteUser($role))->assertForbidden();
})->with(['cashier', 'kitchen_staff', 'cashier_kitchen']);

test('a Branch-scoped account with Reports access cannot read the business-wide website figures', function () {
    $branch = Branch::factory()->create();
    $cashier = websiteUser('cashier');
    $cashier->branches()->attach($branch, ['is_active' => true]);
    UserPermissionOverride::query()->create([
        'user_id' => $cashier->id,
        'permission_id' => Permission::query()->where('name', 'reports.view')->value('id'),
        'effect' => PermissionOverrideEffect::Allow,
    ]);

    $this->actingAs($cashier)->withSession([ActiveBranchContext::SESSION_KEY => $branch->id])
        ->get(route('workspaces.reports'))->assertOk();
    $this->actingAs($cashier)->withSession([ActiveBranchContext::SESSION_KEY => $branch->id])
        ->get(route('workspaces.website-analytics'))->assertForbidden();
});

test('a saved review is counted once and a retry is not counted again', function () {
    $branch = Branch::factory()->create();
    $this->withCredentials()->withCookie(config('session.cookie'), Str::random(40));
    $payload = [
        'branch_id' => $branch->id, 'submission_key' => Str::uuid()->toString(),
        'rating' => 5, 'preferred_name' => 'Suki', 'description' => 'Loved the garlic rice!',
        'visibility' => 'private', 'acknowledgment' => true, 'notice_version' => CustomerReview::NOTICE_VERSION,
    ];

    $this->postJson(route('reviews.store'), $payload)->assertOk();
    $this->postJson(route('reviews.store'), $payload)->assertOk();
    $this->postJson(route('reviews.store'), [...$payload, 'rating' => 9])->assertUnprocessable();

    expect(WebsiteEvent::query()->where('event', 'review_submission')->count())->toBe(1);
});

test('events older than a year are pruned', function () {
    $this->freezeTime();
    $expired = WebsiteEvent::factory()->create(['created_at' => now()->subDays(WebsiteEvent::RETENTION_DAYS + 1)]);
    $kept = WebsiteEvent::factory()->create(['created_at' => now()->subDays(WebsiteEvent::RETENTION_DAYS - 1)]);

    $this->artisan('model:prune', ['--model' => [WebsiteEvent::class]])->assertSuccessful();

    $this->assertModelMissing($expired);
    $this->assertModelExists($kept);
});

test('a failing counter never blocks a guest review', function () {
    $this->mock(WebsiteAnalytics::class, fn ($mock) => $mock->shouldReceive('record')->once()->andThrow(new RuntimeException('counter down')));
    $branch = Branch::factory()->create();

    $this->postJson(route('reviews.store'), [
        'branch_id' => $branch->id, 'submission_key' => Str::uuid()->toString(),
        'rating' => 4, 'preferred_name' => 'Suki', 'description' => 'Sulit!',
        'visibility' => 'private', 'acknowledgment' => true, 'notice_version' => CustomerReview::NOTICE_VERSION,
    ])->assertOk()->assertJson(['saved' => true]);

    $this->assertDatabaseCount('customer_reviews', 1);
});

test('a partial refresh returns fresh totals and chart together with a successful Manila timestamp', function (array $query) {
    websiteAt('2026-10-13 21:00:00');
    $owner = websiteUser('owner');
    $initial = websiteAnalytics($query, $owner)->assertInertia(fn (Assert $page) => $page
        ->where('report.totals.page_views', 0)
        ->where('report.refreshed_at', '2026-10-13T21:00:00+08:00'));

    auth()->logout();
    websiteEvent('page_view')->assertNoContent();
    websiteEvent('menu_click')->assertNoContent();
    websiteEvent('directions_click')->assertNoContent();
    websiteEvent('facebook_click')->assertNoContent();
    websiteAt('2026-10-13 21:00:30');

    DB::enableQueryLog();
    $response = $this->actingAs($owner)->withHeaders([
        'X-Inertia' => 'true',
        'X-Inertia-Version' => $initial->viewData('page')['version'],
        'X-Inertia-Partial-Component' => 'workspaces/website-analytics',
        'X-Inertia-Partial-Data' => 'report',
    ])->get(route('workspaces.website-analytics', $query))
        ->assertOk()
        ->assertJsonPath('props.report.period.preset', $query['period'] ?? 'today')
        ->assertJsonPath('props.report.totals.page_views', 1)
        ->assertJsonPath('props.report.totals.visitors', 1)
        ->assertJsonPath('props.report.totals.menu_clicks', 1)
        ->assertJsonPath('props.report.totals.directions_clicks', 1)
        ->assertJsonPath('props.report.totals.facebook_clicks', 1)
        ->assertJsonPath('props.report.refreshed_at', '2026-10-13T21:00:30+08:00')
        ->assertJsonMissingPath('props.auth')->assertJsonMissingPath('props.branchContext');
    expect(collect($response->json('props.report.series'))->sum('page_views'))->toBe(1);
    $queries = collect(DB::getQueryLog())->filter(fn (array $entry): bool => str_contains($entry['query'], 'website_events'));
    DB::disableQueryLog();
    expect($queries)->toHaveCount(3);
})->with([
    'today' => [[]],
    'custom' => [['period' => 'custom', 'from' => '2026-10-12', 'to' => '2026-10-13']],
]);
