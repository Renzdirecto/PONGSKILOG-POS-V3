<?php

namespace App\Support;

use App\Actions\Audit\AuditRecorder;
use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use App\Models\VoidAuthorizationSetting;
use App\Notifications\AdminAlert;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Brute-force protection for the global 4-digit Void PIN (Phase 20).
 *
 * The PIN is checked before any Void work, outside the Void transaction, so a wrong PIN is always counted: it is
 * audited (`void.authorization_failed`, never the PIN itself) and counts toward a lockout per initiating account and
 * across all accounts. While locked, no PIN is accepted — not even the right one — and every Super Admin is alerted
 * once when a lockout begins. The lock lifts by itself after the window; a Super Admin can also set a new PIN.
 */
class VoidPinGuard
{
    public const ACCOUNT_ATTEMPTS = 5;

    public const ALL_ACCOUNTS_ATTEMPTS = 15;

    public const WINDOW_SECONDS = 900;

    private const ALL_ACCOUNTS_KEY = 'void-pin-failures|all';

    public function __construct(private AuditRecorder $audit) {}

    public function verify(User $initiator, Branch $branch, Order $order, string $pin): void
    {
        $accountKey = self::accountKey($initiator);
        if (RateLimiter::tooManyAttempts($accountKey, self::ACCOUNT_ATTEMPTS)
            || RateLimiter::tooManyAttempts(self::ALL_ACCOUNTS_KEY, self::ALL_ACCOUNTS_ATTEMPTS)) {
            $minutes = (int) ceil(max(RateLimiter::availableIn($accountKey), RateLimiter::availableIn(self::ALL_ACCOUNTS_KEY)) / 60);

            throw ValidationException::withMessages(['authorization' => 'Too many incorrect Void PINs. Void approval is locked for '.max(1, $minutes).' minute'.($minutes === 1 ? '' : 's').'.']);
        }

        $setting = VoidAuthorizationSetting::query()->where('scope', 'global')->first();
        /** No PIN configured yet is reported by the Void itself; only a real mismatch is a failed attempt. */
        if ($setting === null || Hash::check($pin, $setting->pin_hash)) {
            return;
        }

        $accountFailures = RateLimiter::hit($accountKey, self::WINDOW_SECONDS);
        $allFailures = RateLimiter::hit(self::ALL_ACCOUNTS_KEY, self::WINDOW_SECONDS);
        $this->audit->record(
            branch: $branch,
            actor: $initiator,
            module: 'transactions',
            action: 'void.authorization_failed',
            auditableType: Order::class,
            auditableId: $order->id,
            metadata: [
                'account_failures' => $accountFailures,
                'all_accounts_failures' => $allFailures,
                'locked' => $accountFailures >= self::ACCOUNT_ATTEMPTS || $allFailures >= self::ALL_ACCOUNTS_ATTEMPTS,
            ],
        );
        if ($accountFailures === self::ACCOUNT_ATTEMPTS || $allFailures === self::ALL_ACCOUNTS_ATTEMPTS) {
            AdminNotifier::superAdmins(new AdminAlert(
                'security',
                'Void PIN locked',
                ($accountFailures === self::ACCOUNT_ATTEMPTS
                    ? $initiator->name.' entered '.self::ACCOUNT_ATTEMPTS.' incorrect Void PINs at '.$branch->name.'.'
                    : self::ALL_ACCOUNTS_ATTEMPTS.' incorrect Void PINs were entered across accounts.')
                    .' Void approval is locked for '.(self::WINDOW_SECONDS / 60).' minutes. Review the Audit Trail and set a new PIN if it may be known.',
                '/workspaces/audit-trail',
            ));
        }

        throw ValidationException::withMessages(['authorization' => 'Authorization could not be verified.']);
    }

    /** A new PIN ends every lockout: the old one can no longer be guessed. */
    public static function reset(): void
    {
        RateLimiter::clear(self::ALL_ACCOUNTS_KEY);
        foreach (User::query()->pluck('id') as $id) {
            RateLimiter::clear('void-pin-failures|account|'.$id);
        }
    }

    private static function accountKey(User $user): string
    {
        return 'void-pin-failures|account|'.$user->id;
    }
}
