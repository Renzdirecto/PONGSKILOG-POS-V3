<?php

namespace App\Listeners;

use App\Models\User;
use App\Notifications\AdminAlert;
use App\Support\PushMessage;
use App\Support\PushNotifications;
use Illuminate\Notifications\Events\NotificationSent;

/**
 * Every Control Center alert AdminNotifier stored for an account also becomes a generic "Important Alert" push to that
 * account's devices. Recipients are therefore exactly AdminNotifier's (after commit, active Super Admins except the
 * actor), and the stored notification id is the push tag, so a retry never shows the alert twice.
 */
class QueueAdminAlertPushNotification
{
    public function handle(NotificationSent $event): void
    {
        if ($event->channel !== 'database' || ! $event->notification instanceof AdminAlert || ! $event->notifiable instanceof User) {
            return;
        }

        PushNotifications::queue(PushMessage::adminAlert((int) $event->notifiable->getKey(), (string) $event->notification->id));
    }
}
