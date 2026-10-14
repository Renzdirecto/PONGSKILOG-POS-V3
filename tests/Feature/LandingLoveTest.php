<?php

use App\Support\LandingLove;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

const LANDING_LOVE_BATCH = '6f1c2b0e-8d47-4c53-9a51-2f7d3c1e9b10';

/** One batch of taps from the landing page; `$session` keeps the same browser across requests. */
function landingLove(int $taps, ?string $key = null, ?string $session = null): TestResponse
{
    $request = $session === null ? test() : test()->withCredentials()->withCookie(config('session.cookie'), $session);

    return $request->postJson(route('landing-loves.store'), ['key' => $key ?? Str::uuid()->toString(), 'taps' => $taps]);
}

test('taps from separate visitors add up to the one total the landing page shows', function () {
    $this->get(route('home'))->assertInertia(fn (Assert $page) => $page->where('loveTotal', 0));

    landingLove(3)->assertOk()->assertExactJson(['total' => 3])->assertHeader('Cache-Control', 'no-store, private');
    landingLove(5)->assertOk()->assertExactJson(['total' => 8]);

    $this->get(route('home'))->assertInertia(fn (Assert $page) => $page->where('loveTotal', 8));
});

test('taps are stored as one count per Manila clock hour with nothing about the visitor', function () {
    /** Half past midnight in Manila is still the 13th in UTC. */
    $this->travelTo(CarbonImmutable::parse('2026-10-14 00:30', 'Asia/Manila'));
    landingLove(2);
    landingLove(2);
    $this->travelTo(CarbonImmutable::parse('2026-10-14 01:05', 'Asia/Manila'));
    landingLove(1)->assertExactJson(['total' => 5]);

    $rows = DB::table('landing_loves')->orderBy('hour')->get()
        ->map(fn (object $row): array => [substr((string) $row->occurred_on, 0, 10), (int) $row->hour, (int) $row->taps])->all();
    expect(Schema::getColumnListing('landing_loves'))->toEqualCanonicalizing(['occurred_on', 'hour', 'taps'])
        ->and($rows)->toBe([['2026-10-14', 0, 4], ['2026-10-14', 1, 1]]);
});

test('a batch that is sent twice is counted once', function () {
    landingLove(4, LANDING_LOVE_BATCH)->assertExactJson(['total' => 4]);

    landingLove(4, LANDING_LOVE_BATCH)->assertOk()->assertExactJson(['total' => 4]);
});

test('a batch that could not be saved is counted when the page retries it', function () {
    Schema::rename('landing_loves', 'landing_loves_unavailable');
    landingLove(4, LANDING_LOVE_BATCH)->assertServerError();
    Schema::rename('landing_loves_unavailable', 'landing_loves');

    landingLove(4, LANDING_LOVE_BATCH)->assertOk()->assertExactJson(['total' => 4]);
});

test('an invalid batch of taps returns 422 and adds nothing', function (array $payload, string $field) {
    landingLove(2);

    $this->postJson(route('landing-loves.store'), $payload)->assertUnprocessable()->assertJsonValidationErrors($field);

    expect(app(LandingLove::class)->total())->toBe(2);
})->with([
    'no taps' => [['key' => LANDING_LOVE_BATCH], 'taps'],
    'zero taps' => [['key' => LANDING_LOVE_BATCH, 'taps' => 0], 'taps'],
    'negative taps' => [['key' => LANDING_LOVE_BATCH, 'taps' => -5], 'taps'],
    'more taps than one batch allows' => [['key' => LANDING_LOVE_BATCH, 'taps' => LandingLove::MAX_TAPS + 1], 'taps'],
    'fractional taps' => [['key' => LANDING_LOVE_BATCH, 'taps' => 1.5], 'taps'],
    'taps that are not a number' => [['key' => LANDING_LOVE_BATCH, 'taps' => 'many'], 'taps'],
    'no batch key' => [['taps' => 1], 'key'],
    'a batch key that is not a UUID' => [['key' => 'again', 'taps' => 1], 'key'],
]);

test('the largest batch the page may send is accepted', function () {
    landingLove(LandingLove::MAX_TAPS)->assertOk()->assertExactJson(['total' => 20]);
});

test('one browser session is limited to forty batches a minute and the rest are not counted', function () {
    $session = Str::random(40);
    for ($attempt = 0; $attempt < 40; $attempt++) {
        landingLove(1, session: $session)->assertOk();
    }

    landingLove(1, session: $session)->assertTooManyRequests();

    expect(app(LandingLove::class)->total())->toBe(40);
});
