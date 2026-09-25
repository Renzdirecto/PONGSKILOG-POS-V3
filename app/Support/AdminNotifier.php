<?php

namespace App\Support;

use App\Events\NotificationsChanged;
use App\Models\User;
use App\Notifications\AdminAlert;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Delivers Control Center notifications to every active Super Admin (optionally except the actor who caused them).
 * Delivery runs after the surrounding transaction commits, so a rolled-back change never notifies, and it is rescued:
 * a notification failure never undoes or fails the business change that caused it.
 */
class AdminNotifier
{
    /**
     * @param  AdminAlert|Closure(): ?AdminAlert  $alert  a closure is resolved after commit (for example to read names lazily)
     */
    public static function superAdmins(AdminAlert|Closure $alert, ?User $except = null): void
    {
        DB::afterCommit(function () use ($alert, $except): void {
            try {
                $alert = $alert instanceof Closure ? $alert() : $alert;
                if ($alert === null) {
                    return;
                }
                $recipients = User::query()
                    ->where('is_active', true)
                    ->whereHas('roles', fn (Builder $roles) => $roles->where('roles.name', PermissionCatalog::SUPER_ADMIN))
                    ->when($except !== null, fn (Builder $query) => $query->whereKeyNot($except?->getKey()))
                    ->orderBy('id')
                    ->get();
                if ($recipients->isEmpty()) {
                    return;
                }
                Notification::send($recipients, $alert);
                foreach ($recipients as $recipient) {
                    NotificationsChanged::dispatch((int) $recipient->id);
                }
            } catch (Throwable $exception) {
                report($exception);
            }
        });
    }
}
