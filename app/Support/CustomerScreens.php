<?php

namespace App\Support;

use App\Models\Branch;
use App\Models\CustomerScreen;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * Identity rules of the customer-facing screen (Phase 19.6A).
 *
 * - The display device proves itself with a random 64-hex HttpOnly cookie (encrypted by the web middleware); only its
 *   SHA-256 is stored. The cookie grants read access to that one screen's customer-safe projection and nothing else.
 * - A POS station is one browser installation, identified by a random UUID kept in that browser's local storage and
 *   sent in the `X-POS-Station` header. Only its SHA-256 is stored, together with the Branch: pairing, the live cart
 *   and the takeover all look a screen up by (selected Branch, station hash), so a cashier account change on the same
 *   station keeps the pairing and a forged Branch or station id simply finds nothing.
 * - Pairing codes are short-lived (5 minutes), one-time, keyed-hashed at rest and typed at the POS.
 */
class CustomerScreens
{
    public const COOKIE = 'pongskilog_customer_screen';

    public const STATION_HEADER = 'X-POS-Station';

    public const CODE_LENGTH = 6;

    public const CODE_TTL_SECONDS = 300;

    /** No 0/O, 1/I/L: a code read across a counter is typed correctly. 32^6 ≈ 1.07 billion codes. */
    private const CODE_ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    /** Five years: the device keeps its pairing as long as the browser profile keeps its cookies. */
    private const COOKIE_MINUTES = 60 * 24 * 365 * 5;

    public function resolve(Request $request): ?CustomerScreen
    {
        $token = $request->cookie(self::COOKIE);
        if (! is_string($token) || preg_match('/\A[a-f0-9]{64}\z/', $token) !== 1) {
            return null;
        }

        return CustomerScreen::query()->where('token_hash', hash('sha256', $token))->first();
    }

    /**
     * The screen this browser already is, or a new unpaired one with a fresh device cookie (queued on the response).
     *
     * @return array{0: CustomerScreen, 1: Cookie|null}
     */
    public function resolveOrCreate(Request $request): array
    {
        if ($screen = $this->resolve($request)) {
            return [$screen, null];
        }

        $token = bin2hex(random_bytes(32));
        $screen = CustomerScreen::query()->create([
            'token_hash' => hash('sha256', $token),
            'channel_key' => Str::random(40),
        ]);

        return [$screen, cookie(self::COOKIE, $token, self::COOKIE_MINUTES, '/', null, $request->isSecure(), true, false, 'lax')];
    }

    /** A POS station id from the request header, or null when missing or malformed (never trusted beyond its hash). */
    public function stationId(Request $request): ?string
    {
        $station = $request->header(self::STATION_HEADER);

        return is_string($station) && preg_match('/\A[A-Za-z0-9-]{16,64}\z/', $station) === 1 ? strtolower($station) : null;
    }

    public function stationHash(string $stationId): string
    {
        return hash('sha256', strtolower($stationId));
    }

    /** The screen paired to this station at this Branch, if any. */
    public function forStation(Branch $branch, string $stationId): ?CustomerScreen
    {
        return CustomerScreen::query()
            ->where('branch_id', $branch->getKey())
            ->where('station_hash', $this->stationHash($stationId))
            ->first();
    }

    public function newCode(): string
    {
        $code = '';
        for ($index = 0; $index < self::CODE_LENGTH; $index++) {
            $code .= self::CODE_ALPHABET[random_int(0, strlen(self::CODE_ALPHABET) - 1)];
        }

        return $code;
    }

    /** Normalizes what a cashier typed ("ab3-k7q" → "AB3K7Q"); null when it cannot be a code. */
    public function normalizeCode(mixed $code): ?string
    {
        if (! is_string($code)) {
            return null;
        }
        $normalized = strtoupper((string) preg_replace('/[\s-]+/', '', $code));

        return preg_match('/\A['.self::CODE_ALPHABET.']{'.self::CODE_LENGTH.'}\z/', $normalized) === 1 ? $normalized : null;
    }

    /** Keyed with the application key, so a leaked table cannot be matched against the small code space offline. */
    public function codeHash(string $code): string
    {
        return hash_hmac('sha256', 'customer-screen-pairing:'.$code, (string) config('app.key'));
    }

    public function channelName(CustomerScreen $screen): string
    {
        return 'customer-screen.'.$screen->channel_key;
    }
}
