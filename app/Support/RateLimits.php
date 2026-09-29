<?php

namespace App\Support;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * Every route throttle in the application is a NAMED limiter with its own counter (Phase 20).
 *
 * An un-named `throttle:X,Y` keys every route on the same user id (or IP), so heavy use of one endpoint (e.g. the
 * POS recipe-capacity check on every modifier tap) exhausted unrelated low limits such as Void or Close Store. A named
 * limiter's key is prefixed with its name, so each abuse domain below is counted separately:
 *
 * - staff actions are limited per account (IP only for a request without an account);
 * - public customer surfaces are limited per anonymous device capability (the Customer QR session cookie, the screen
 *   device cookie, the pickup link) with a generous per-IP ceiling, because many phones share one store Wi-Fi or
 *   carrier NAT address. No fingerprinting: only capabilities the application itself issued are used as keys.
 *
 * Limits are abuse ceilings, not business rules: idempotency keys, row locks and validation stay the real protection.
 */
class RateLimits
{
    /**
     * Staff limiters: name => requests per minute per account.
     *
     * @var array<string, int>
     */
    public const STAFF = [
        /** POS money: Pay Now, Pay Later commit, settlement, correction allocation. */
        'pos-payments' => 60,
        /** POS order drafts and early reservations. */
        'pos-orders' => 120,
        'pos-recipe-capacity' => 240,
        'pos-order-edits' => 30,
        'pos-qr-orders' => 60,
        'receipt-share' => 30,
        'invoice-proofs' => 30,
        'kitchen-status' => 180,
        'void' => 5,
        'store-open' => 10,
        'store-close' => 10,
        'store-expenses' => 20,
        'stock-corrections' => 30,
        'giveaways' => 30,
        'inventory-adjustments' => 30,
        'catalog-writes' => 60,
        'product-images' => 20,
        'branch-assortment' => 30,
        'branch-copy' => 20,
        'copy-previews' => 60,
        'branch-settings' => 20,
        'operations-writes' => 60,
        'operations-setup-copy' => 20,
        'pamamalengke-confirm' => 20,
        'staff-admin' => 30,
        'staff-password' => 10,
        'access-control' => 30,
        'void-pin' => 5,
        'reports-export' => 20,
        'notifications' => 120,
        'push-subscription' => 30,
        'account' => 20,
        'password-update' => 6,
        'password-confirm' => 6,
        'verification' => 6,
        'branch-context' => 60,
        /** Phase 19.6 station, pairing, Buzz and customer-screen media limits (unchanged). */
        'pos-customer-screen' => 240,
        'pos-customer-screen-pairing' => 10,
        'pickup-buzz' => 30,
        'customer-screen-media' => 60,
    ];

    public static function register(): void
    {
        foreach (self::STAFF as $name => $perMinute) {
            RateLimiter::for($name, fn (Request $request) => Limit::perMinute($perMinute)->by(self::account($request)));
        }

        /** Account recovery: per e-mail and address, with an address ceiling, so nobody can spray reset mails. */
        RateLimiter::for('password-email', fn (Request $request) => [
            Limit::perMinute(5)->by('email|'.(is_string($email = $request->input('email')) ? Str::lower($email) : '').'|'.$request->ip()),
            Limit::perMinute(20)->by('ip|'.$request->ip()),
        ]);
        RateLimiter::for('password-reset', fn (Request $request) => Limit::perMinute(10)->by('ip|'.$request->ip()));
        /** Monitoring polls /health about once a minute; the ceiling keeps it from being used to load the dependencies. */
        RateLimiter::for('health', fn (Request $request) => Limit::perMinute(30)->by('ip|'.$request->ip()));

        /** Customer QR: per QR ordering session of this browser, then a high ceiling for a shared store Wi-Fi. */
        RateLimiter::for('customer-qr-page', fn (Request $request) => self::perDevice($request, 120, 3000));
        RateLimiter::for('customer-qr', fn (Request $request) => self::perDevice($request, 240, 6000));
        RateLimiter::for('customer-qr-submit', fn (Request $request) => [
            Limit::perMinute(10)->by('device|'.(self::qrDevice($request) ?? 'none|'.$request->ip())),
            Limit::perMinute(120)->by('branch|'.self::branchKey($request).'|ip|'.$request->ip()),
        ]);
        /** A shared receipt link: per signed receipt, with an address ceiling. */
        RateLimiter::for('public-receipt', fn (Request $request) => [
            Limit::perMinute(60)->by('receipt|'.self::routeKey($request, 'order')),
            Limit::perMinute(600)->by('ip|'.$request->ip()),
        ]);

        /**
         * Public customer screen and pickup pages are limited per capability (screen device cookie / pickup link),
         * with a generous per-IP ceiling: many phones or screens behind one store Wi-Fi share an IP.
         */
        $screen = fn (Request $request): string => sha1(is_string($cookie = $request->cookie(CustomerScreens::COOKIE)) ? $cookie : '');
        RateLimiter::for('customer-screen', fn (Request $request) => [
            Limit::perMinute(120)->by('device|'.$request->ip().'|'.$screen($request)),
            Limit::perMinute(1200)->by('ip|'.$request->ip()),
        ]);
        RateLimiter::for('customer-screen-pairing-code', fn (Request $request) => Limit::perMinute(10)->by((string) $request->ip()));
        RateLimiter::for('customer-screen-mode', fn (Request $request) => Limit::perMinute(30)->by((string) $request->ip()));
        RateLimiter::for('pickup', fn (Request $request) => [
            Limit::perMinute(120)->by('link|'.sha1((string) $request->route('token'))),
            Limit::perMinute(1200)->by('ip|'.$request->ip()),
        ]);
        RateLimiter::for('pickup-subscription', fn (Request $request) => [
            Limit::perMinute(10)->by('link|'.sha1((string) $request->route('token'))),
            Limit::perMinute(30)->by('ip|'.$request->ip()),
        ]);
    }

    private static function account(Request $request): string
    {
        return (string) ($request->user()?->getAuthIdentifier() ?? 'ip|'.$request->ip());
    }

    /**
     * The Customer QR ordering session of this browser: a random server-issued cookie (`customer_qr_{branch}`), hashed.
     * Null before the first menu visit issues one.
     */
    private static function qrDevice(Request $request): ?string
    {
        foreach ($request->cookies->all() as $name => $value) {
            if (str_starts_with($name, 'customer_qr_') && is_string($value) && preg_match('/\A[a-f0-9]{64}\z/', $value) === 1) {
                return sha1($name.'|'.$value);
            }
        }

        return null;
    }

    /** @return list<Limit> */
    private static function perDevice(Request $request, int $perDevice, int $perAddress): array
    {
        $device = self::qrDevice($request);

        return [
            $device !== null
                ? Limit::perMinute($perDevice)->by('device|'.$device)
                : Limit::perMinute($perDevice)->by('anonymous|'.self::branchKey($request).'|'.$request->ip()),
            Limit::perMinute($perAddress)->by('ip|'.$request->ip()),
        ];
    }

    private static function branchKey(Request $request): string
    {
        return self::routeKey($request, 'branch');
    }

    /** A route parameter as a string, before or after route-model binding. */
    private static function routeKey(Request $request, string $parameter): string
    {
        $value = $request->route($parameter);

        return $value instanceof Model ? (string) $value->getKey() : (string) (is_scalar($value) ? $value : '');
    }
}
