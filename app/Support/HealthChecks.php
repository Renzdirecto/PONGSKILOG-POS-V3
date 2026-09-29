<?php

namespace App\Support;

use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Production readiness checks (Phase 20). Read-only and cheap: one `SELECT 1`, one cache read, a heartbeat read, a
 * Reverb `/up` request and an S3 `HeadBucket`, each with short timeouts. Results carry only a status and a latency —
 * never hostnames, credentials or error messages.
 *
 * - critical (the web app cannot serve safely without them): database, cache (Redis: sessions, locks, rate limits);
 * - degraded (the app still serves, some realtime / push / media features fail): queue worker + scheduler heartbeat,
 *   Reverb, object storage, the private upload disk.
 *
 * @phpstan-type Check array{status: 'ok'|'fail'|'stale'|'skipped', ms?: int, age_seconds?: int}
 */
class HealthChecks
{
    public const QUEUE_HEARTBEAT_KEY = 'health:queue-heartbeat';

    /** The scheduler dispatches a heartbeat every minute; older than this means no worker or no scheduler. */
    public const QUEUE_STALE_SECONDS = 180;

    /** @var list<string> */
    public const CRITICAL = ['database', 'cache'];

    /** @return array{status: 'ok'|'degraded'|'fail', checks: array<string, Check>} */
    public function run(): array
    {
        $checks = [
            'database' => $this->timed(fn () => DB::select('SELECT 1')),
            'cache' => $this->timed(fn () => Cache::get('health:probe')),
            'queue' => $this->queue(),
            'reverb' => $this->reverb(),
            'storage' => $this->storage(),
            'private_files' => is_dir($root = storage_path('app/private')) && is_writable($root) ? ['status' => 'ok'] : ['status' => 'fail'],
        ];
        $failedCritical = array_filter(self::CRITICAL, fn (string $name): bool => $checks[$name]['status'] !== 'ok');
        $degraded = array_filter($checks, fn (array $check): bool => in_array($check['status'], ['fail', 'stale'], true));

        return [
            'status' => $failedCritical !== [] ? 'fail' : ($degraded !== [] ? 'degraded' : 'ok'),
            'checks' => $checks,
        ];
    }

    /** @return Check */
    private function queue(): array
    {
        try {
            $beat = Cache::get(self::QUEUE_HEARTBEAT_KEY);
        } catch (Throwable) {
            return ['status' => 'fail'];
        }
        if (! is_int($beat)) {
            return ['status' => 'stale'];
        }
        $age = max(0, now()->getTimestamp() - $beat);

        return ['status' => $age > self::QUEUE_STALE_SECONDS ? 'stale' : 'ok', 'age_seconds' => $age];
    }

    /** @return Check */
    private function reverb(): array
    {
        if (config('broadcasting.default') !== 'reverb') {
            return ['status' => 'skipped'];
        }
        $options = (array) config('broadcasting.connections.reverb.options');
        $url = sprintf('%s://%s:%s%s/up', $options['scheme'] ?? 'https', $options['host'] ?? '', $options['port'] ?? 443, rtrim((string) ($options['path'] ?? ''), '/'));

        return $this->timed(fn () => Http::timeout(2)->connectTimeout(2)->get($url)->throw());
    }

    /** @return Check */
    private function storage(): array
    {
        $bucket = config('filesystems.disks.s3.bucket');
        if (! is_string($bucket) || $bucket === '') {
            return ['status' => 'skipped'];
        }
        $disk = Storage::disk('s3');
        if (! $disk instanceof AwsS3V3Adapter) {
            return ['status' => 'skipped'];
        }

        return $this->timed(fn () => $disk->getClient()->headBucket(['Bucket' => $bucket, '@http' => ['timeout' => 2, 'connect_timeout' => 2]]));
    }

    /** @return Check */
    private function timed(callable $probe): array
    {
        $started = hrtime(true);
        try {
            $probe();
        } catch (Throwable) {
            return ['status' => 'fail', 'ms' => (int) ((hrtime(true) - $started) / 1_000_000)];
        }

        return ['status' => 'ok', 'ms' => (int) ((hrtime(true) - $started) / 1_000_000)];
    }
}
