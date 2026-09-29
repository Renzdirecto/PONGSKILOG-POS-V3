<?php

namespace App\Support;

use App\Jobs\SendPushNotification;
use Illuminate\Support\Facades\Bus;

/**
 * Web Push configuration and the one entry point business code uses to send a push. Push is best effort and never
 * authoritative: it is queued after the business transaction committed, and a queue or push failure is reported,
 * never thrown into the order, Kitchen or admin action that caused it.
 *
 * The VAPID private key stays in the server environment. It is never returned to a browser, logged or audited; only
 * the public key is handed to signed-in staff when they enable notifications.
 */
class PushNotifications
{
    /** @return array{subject: string, publicKey: string, privateKey: string}|null */
    public static function vapid(): ?array
    {
        $subject = config('services.webpush.subject');
        $publicKey = config('services.webpush.public_key');
        $privateKey = config('services.webpush.private_key');

        if (! is_string($subject) || $subject === '' || ! is_string($publicKey) || $publicKey === '' || ! is_string($privateKey) || $privateKey === '') {
            return null;
        }

        return ['subject' => $subject, 'publicKey' => $publicKey, 'privateKey' => $privateKey];
    }

    public static function enabled(): bool
    {
        return self::vapid() !== null;
    }

    public static function publicKey(): ?string
    {
        return self::vapid()['publicKey'] ?? null;
    }

    /**
     * Queue delivery without touching the database in the caller's request. Recipients are resolved inside the job,
     * from current permissions and Branch access, when it runs. The job is dispatched eagerly (not through a pending
     * dispatch that would only run after this method returned), so a queue failure is caught and reported here.
     */
    public static function queue(PushMessage $message): void
    {
        if (! self::enabled()) {
            return;
        }

        rescue(fn () => Bus::dispatch(new SendPushNotification($message)));
    }
}
