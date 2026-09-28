<?php

use App\Jobs\RecordQueueHeartbeat;
use App\Models\User;
use App\Support\HealthChecks;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    /** Never reach the real object storage or Reverb from the test suite. */
    config(['filesystems.disks.s3.bucket' => null, 'broadcasting.default' => 'null']);
});

test('health reports each dependency without exposing configuration and degrades without a queue heartbeat', function () {
    $response = $this->getJson(route('health'))->assertOk()->assertHeader('Cache-Control', 'no-store, private');

    $response->assertJsonPath('status', 'degraded')
        ->assertJsonPath('checks.database.status', 'ok')
        ->assertJsonPath('checks.cache.status', 'ok')
        ->assertJsonPath('checks.queue.status', 'stale')
        ->assertJsonPath('checks.reverb.status', 'skipped')
        ->assertJsonPath('checks.storage.status', 'skipped')
        ->assertJsonPath('release.name', 'PONGSKILOG POS');
    expect(array_keys($response->json()))->toBe(['status', 'release', 'checked_at', 'checks'])
        ->and($response->getContent())->not->toContain((string) config('app.key'))
        ->and($response->getContent())->not->toContain('password');
});

test('a queue worker heartbeat makes health fully ok until it goes stale', function () {
    (new RecordQueueHeartbeat)->handle();

    $this->getJson(route('health'))->assertOk()->assertJsonPath('status', 'ok')->assertJsonPath('checks.queue.status', 'ok');

    $this->travel(HealthChecks::QUEUE_STALE_SECONDS + 1)->seconds();
    $this->getJson(route('health'))->assertOk()->assertJsonPath('status', 'degraded')->assertJsonPath('checks.queue.status', 'stale');
});

test('a failing critical dependency answers 503', function () {
    $this->withoutMiddleware(ThrottleRequests::class);
    Cache::shouldReceive('get')->andThrow(new RuntimeException('redis down'));

    $this->getJson(route('health'))->assertStatus(503)
        ->assertJsonPath('status', 'fail')
        ->assertJsonPath('checks.cache.status', 'fail')
        ->assertJsonMissing(['message' => 'redis down']);
});

test('the Reverb check probes the Reverb server health route with a short timeout', function () {
    config(['broadcasting.default' => 'reverb', 'broadcasting.connections.reverb.options' => ['host' => 'reverb.internal', 'port' => 8080, 'scheme' => 'http']]);
    Http::fake(['http://reverb.internal:8080/up' => Http::sequence()->push(['health' => 'OK'])->push(null, 500)]);

    $this->getJson(route('health'))->assertJsonPath('checks.reverb.status', 'ok');
    $this->getJson(route('health'))->assertJsonPath('checks.reverb.status', 'fail')->assertJsonPath('status', 'degraded');
});

test('staff pages share only the safe release identity from deployment configuration', function (?string $sha, ?string $build) {
    config(['app.version' => 'v1.0.0-rc.1', 'app.build_sha' => $sha]);

    $this->actingAs(User::factory()->create())->get(route('profile.edit'))
        ->assertInertia(fn (Assert $page) => $page->where('release', ['name' => 'PONGSKILOG POS', 'version' => 'v1.0.0-rc.1', 'build' => $build]));
})->with([
    'deployed commit' => ['3F9C2AB5D41E7C0B9A3F5E6D7C8B9A0F1E2D3C4B', '3f9c2ab'],
    'not a commit' => ['secret=abc; rm -rf', null],
    'unset' => [null, null],
]);

test('the scheduler runs the queue heartbeat and bounds the stale QR archive lock', function () {
    $events = collect(app(Schedule::class)->events());
    $heartbeat = $events->first(fn (ScheduledEvent $event): bool => $event->description === 'queue-heartbeat');
    $archive = $events->first(fn (ScheduledEvent $event): bool => str_contains((string) $event->command, 'qr:archive-stale'));

    expect($heartbeat)->not->toBeNull()
        ->and($heartbeat->expression)->toBe('* * * * *')
        ->and($archive->withoutOverlapping)->toBeTrue()
        ->and($archive->expiresAt)->toBe(10)
        ->and($archive->onOneServer)->toBeTrue();
});
