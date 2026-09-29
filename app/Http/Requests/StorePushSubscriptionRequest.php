<?php

namespace App\Http\Requests;

use App\Models\User;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The browser's own `PushSubscription.toJSON()`: an https endpoint on a known push service, its P-256 key and auth
 * secret. The owner is always the signed-in account; a submitted user id is never read.
 */
class StorePushSubscriptionRequest extends FormRequest
{
    /**
     * Browser push services. The server POSTs to a stored endpoint, so only these hosts are accepted: an arbitrary
     * URL would let a client point server-side requests at internal hosts.
     */
    public const PUSH_SERVICE_HOSTS = [
        'fcm.googleapis.com',
        'android.googleapis.com',
        'push.services.mozilla.com',
        'push.apple.com',
        'notify.windows.com',
    ];

    public function authorize(): bool
    {
        return $this->user() instanceof User && $this->user()->is_active;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'endpoint' => ['required', 'string', 'max:2048', 'url:https', $this->pushServiceEndpoint(...)],
            'keys' => ['required', 'array:p256dh,auth'],
            'keys.p256dh' => ['required', 'string', 'max:128', $this->decodesTo(65, "\x04")],
            'keys.auth' => ['required', 'string', 'max:64', $this->decodesTo(16)],
            'content_encoding' => ['sometimes', 'string', 'in:aes128gcm,aesgcm'],
        ];
    }

    /**
     * The validated browser subscription.
     *
     * @return array{endpoint: string, keys: array{p256dh: string, auth: string}, content_encoding: string}
     */
    public function subscription(): array
    {
        return [
            'endpoint' => $this->string('endpoint')->value(),
            'keys' => [
                'p256dh' => $this->string('keys.p256dh')->value(),
                'auth' => $this->string('keys.auth')->value(),
            ],
            'content_encoding' => $this->string('content_encoding', 'aes128gcm')->value(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'endpoint.url' => 'The browser returned an invalid notification endpoint.',
            'keys.required' => 'The browser returned an incomplete notification subscription.',
        ];
    }

    private function pushServiceEndpoint(string $attribute, mixed $value, Closure $fail): void
    {
        $host = is_string($value) ? strtolower((string) parse_url($value, PHP_URL_HOST)) : '';
        $userInfo = is_string($value) ? parse_url($value, PHP_URL_USER) : null;
        $port = is_string($value) ? parse_url($value, PHP_URL_PORT) : null;

        foreach (self::PUSH_SERVICE_HOSTS as $allowed) {
            if (($host === $allowed || str_ends_with($host, '.'.$allowed)) && $userInfo === null && ($port === null || $port === 443)) {
                return;
            }
        }

        $fail('This browser uses a notification service PONGSKILOG does not support.');
    }

    /** A URL-safe base64 value (padding optional) that decodes to exactly $length bytes, optionally with a prefix. */
    private function decodesTo(int $length, string $prefix = ''): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($length, $prefix): void {
            $decoded = is_string($value) && preg_match('/\A[A-Za-z0-9_-]+={0,2}\z/', $value) === 1
                ? base64_decode(strtr(rtrim($value, '='), '-_', '+/'), true)
                : false;

            if ($decoded === false || strlen($decoded) !== $length || ($prefix !== '' && ! str_starts_with($decoded, $prefix))) {
                $fail('The browser returned an invalid notification key.');
            }
        };
    }
}
