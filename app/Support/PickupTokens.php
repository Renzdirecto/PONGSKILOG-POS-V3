<?php

namespace App\Support;

use App\Enums\CommercialStatus;
use App\Enums\OrderType;
use App\Models\Order;
use App\Models\OrderPickupToken;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;

/**
 * Takeout pickup capabilities (Phase 19.6B). Every committed Take Out order gets exactly one token, issued after the
 * commit (never inside the payment transaction, so it can never affect payment or Kitchen correctness) whether or not
 * the QR is ever scanned. Dine In never gets one.
 *
 * The raw token is 32 random bytes (URL-safe base64, 43 characters). It is looked up only by its SHA-256; a raw order
 * id, a hash or anything shorter is never accepted. The token is placed only in the QR / link and in the customer's own
 * end-to-end encrypted push; never in logs, audit rows or realtime payloads (those use a separate `channel_key`).
 */
class PickupTokens
{
    /** A pickup link outlives any realistic wait, then stops answering. */
    public const LIFETIME_HOURS = 12;

    private const TOKEN_PATTERN = '/\A[A-Za-z0-9_-]{43}\z/';

    public function eligible(Order $order): bool
    {
        return $order->order_type === OrderType::TakeOut
            && $order->committed_at !== null
            && in_array($order->commercial_status, [CommercialStatus::Active, CommercialStatus::Completed], true);
    }

    /**
     * The order's token, issuing it once when the order is an eligible Take Out order. Idempotent under concurrency:
     * `order_id` is unique, so a racing second issue re-reads the winner.
     */
    public function ensureFor(Order $order): ?OrderPickupToken
    {
        $existing = OrderPickupToken::query()->where('order_id', $order->getKey())->first();

        return $existing !== null ? $existing : $this->issue($order);
    }

    /** Issues the token of an eligible order known to have none yet (a racing duplicate re-reads the winner). */
    public function issue(Order $order): ?OrderPickupToken
    {
        if (! $this->eligible($order)) {
            return null;
        }

        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

        try {
            return OrderPickupToken::query()->create([
                'order_id' => $order->getKey(),
                'branch_id' => $order->branch_id,
                'token_hash' => $this->hash($token),
                'token_ciphertext' => $token,
                'channel_key' => Str::random(40),
                'expires_at' => ($order->committed_at ?? now())->addHours(self::LIFETIME_HOURS),
            ]);
        } catch (UniqueConstraintViolationException) {
            return OrderPickupToken::query()->where('order_id', $order->getKey())->first();
        }
    }

    /** The token record for a raw token, or null for anything malformed or unknown. Expiry is the caller's decision. */
    public function find(string $token): ?OrderPickupToken
    {
        if (preg_match(self::TOKEN_PATTERN, $token) !== 1) {
            return null;
        }

        return OrderPickupToken::query()->where('token_hash', $this->hash($token))->first();
    }

    public function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    public function rawToken(OrderPickupToken $pickup): ?string
    {
        try {
            /** The `encrypted` cast decrypts here; a changed application key makes the stored value unreadable. */
            $token = $pickup->getAttribute('token_ciphertext');
        } catch (DecryptException) {
            return null;
        }

        return is_string($token) && preg_match(self::TOKEN_PATTERN, $token) === 1 ? $token : null;
    }

    /** The relative pickup page path (the scanning phone resolves it against the host that rendered the QR). */
    public function path(string $token): string
    {
        return route('pickup.show', ['token' => $token], false);
    }

    /** @return array{url: string, qr_image: string}|null */
    public function qr(OrderPickupToken $pickup, string $origin): ?array
    {
        $token = $this->rawToken($pickup);
        if ($token === null) {
            return null;
        }
        $url = rtrim($origin, '/').$this->path($token);
        $writer = new Writer(new ImageRenderer(new RendererStyle(360, 2), new SvgImageBackEnd));

        return ['url' => $url, 'qr_image' => 'data:image/svg+xml;base64,'.base64_encode($writer->writeString($url))];
    }

    public function channelName(OrderPickupToken $pickup): string
    {
        return 'pickup.'.$pickup->channel_key;
    }
}
