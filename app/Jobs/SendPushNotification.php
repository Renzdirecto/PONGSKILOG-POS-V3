<?php

namespace App\Jobs;

use App\Support\PushMessage;
use App\Support\PushRecipients;
use App\Support\WebPushSender;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Bus;

/**
 * Best-effort, non-authoritative Web Push delivery, queued after the business transaction committed.
 *
 * Recipients are resolved when the job runs (and again on every retry), so an account deactivated, stripped of the
 * permission or removed from the Branch in the meantime receives nothing new. Subscriptions that failed transiently are
 * retried on their own, at most MAX_ATTEMPTS times with increasing delays; the job itself is never retried, so a
 * retry never repeats delivery to browsers that already received it. The message tag makes any duplicate replace the
 * visible notification instead of stacking.
 */
class SendPushNotification implements ShouldQueue
{
    use Queueable;

    public const MAX_ATTEMPTS = 3;

    /** Seconds before the second and third attempt. */
    public const RETRY_DELAYS = [30, 120];

    public int $tries = 1;

    /**
     * @param  list<int>|null  $subscriptionIds  the subscriptions still to retry, or null for the first delivery
     */
    public function __construct(
        public PushMessage $message,
        public ?array $subscriptionIds = null,
        public int $attempt = 1,
    ) {}

    public function handle(PushRecipients $recipients, WebPushSender $sender): void
    {
        $branch = $recipients->branchFor($this->message);
        $subscriptions = $recipients->subscriptionsFor($this->message, $branch, $this->subscriptionIds);

        if ($subscriptions->isEmpty()) {
            return;
        }

        $retry = $sender->send($this->message, $subscriptions, $branch?->name);

        if ($retry !== [] && $this->attempt < self::MAX_ATTEMPTS) {
            Bus::dispatch(
                (new self($this->message, $retry, $this->attempt + 1))
                    ->delay(now()->addSeconds(self::RETRY_DELAYS[$this->attempt - 1])),
            );
        }
    }
}
