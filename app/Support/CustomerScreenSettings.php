<?php

namespace App\Support;

use App\Enums\OrderType;
use App\Models\Branch;

/**
 * A Branch's Customer Screen settings (Phase 19.6 manual-QA fixes), the one source both the server and the screen use:
 * how long the successful-order confirmation stays up once it is on screen (Dine In / Take Out, 3–15 s, default 5 s)
 * and the optional customer links (Facebook and Website are the Branch's existing Customer QR links; Maps is new).
 * A link reaches a customer only when it is a well-formed http(s) URL, whatever the stored value is.
 */
class CustomerScreenSettings
{
    public const DEFAULT_SUCCESS_SECONDS = 5;

    public const MIN_SUCCESS_SECONDS = 3;

    public const MAX_SUCCESS_SECONDS = 15;

    public const LINK_MAX_LENGTH = 500;

    /** How long the confirmation of a committed order of this type stays on screen once it is actually showing. */
    public function successDurationMs(Branch $branch, OrderType $type): int
    {
        $seconds = $type === OrderType::TakeOut
            ? $branch->customer_screen_take_out_success_seconds
            : $branch->customer_screen_dine_in_success_seconds;

        return $this->boundedSeconds($seconds) * 1000;
    }

    /**
     * The settings form of Settings › Customer Screen.
     *
     * @return array{dine_in_success_seconds: int, take_out_success_seconds: int, facebook_url: string|null, website_url: string|null, maps_url: string|null, limits: array{min_seconds: int, max_seconds: int}}
     */
    public function forManagement(Branch $branch): array
    {
        return [
            'dine_in_success_seconds' => $this->boundedSeconds($branch->customer_screen_dine_in_success_seconds),
            'take_out_success_seconds' => $this->boundedSeconds($branch->customer_screen_take_out_success_seconds),
            'facebook_url' => $branch->facebook_url,
            'website_url' => $branch->website_url,
            'maps_url' => $branch->maps_url,
            'limits' => ['min_seconds' => self::MIN_SUCCESS_SECONDS, 'max_seconds' => self::MAX_SUCCESS_SECONDS],
        ];
    }

    /**
     * The customer-facing buttons: only configured, safe links (a blank or unsafe value is simply absent).
     *
     * @return array{facebook: string|null, website: string|null, maps: string|null}
     */
    public function customerLinks(Branch $branch): array
    {
        return [
            'facebook' => self::safeUrl($branch->facebook_url),
            'website' => self::safeUrl($branch->website_url),
            'maps' => self::safeUrl($branch->maps_url),
        ];
    }

    /** An absolute http(s) URL with a host and no whitespace or control characters, or null. */
    public static function safeUrl(mixed $url): ?string
    {
        if (! is_string($url) || $url === '' || strlen($url) > self::LINK_MAX_LENGTH || preg_match('/[\s\x00-\x1F\x7F]/', $url) === 1) {
            return null;
        }
        $parts = parse_url($url);
        if (! is_array($parts) || ! in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true) || ($parts['host'] ?? '') === '') {
            return null;
        }

        return $url;
    }

    private function boundedSeconds(mixed $seconds): int
    {
        return is_int($seconds) ? max(self::MIN_SUCCESS_SECONDS, min(self::MAX_SUCCESS_SECONDS, $seconds)) : self::DEFAULT_SUCCESS_SECONDS;
    }
}
