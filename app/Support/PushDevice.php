<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * The browser a push subscription belongs to, identified by a random HttpOnly cookie (encrypted by the web
 * middleware). Only its SHA-256 is stored, so the server can remove this device's subscription on logout even when
 * the browser's own Push API cleanup fails. It identifies a browser, never an account, and grants nothing.
 */
class PushDevice
{
    public const COOKIE = 'pongskilog_push_device';

    /** Five years: the device id lives as long as the browser profile keeps its cookies. */
    private const LIFETIME_MINUTES = 60 * 24 * 365 * 5;

    public static function idFrom(Request $request): ?string
    {
        $deviceId = $request->cookie(self::COOKIE);

        return is_string($deviceId) && preg_match('/\A[A-Za-z0-9]{40}\z/', $deviceId) === 1 ? $deviceId : null;
    }

    public static function newId(): string
    {
        return Str::random(40);
    }

    public static function hash(string $deviceId): string
    {
        return hash('sha256', $deviceId);
    }

    public static function cookie(string $deviceId): Cookie
    {
        return cookie(
            self::COOKIE,
            $deviceId,
            self::LIFETIME_MINUTES,
            secure: config('session.secure'),
            httpOnly: true,
            sameSite: 'lax',
        );
    }
}
