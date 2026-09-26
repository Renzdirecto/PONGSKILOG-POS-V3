<?php

namespace App\Support;

use App\Enums\PushMessageType;

/**
 * One Web Push message, before recipients are resolved. It carries identifiers only: recipients are decided from the
 * current server authority when it is delivered, and the lock-screen text is fixed per type in the service worker.
 * The tag is a stable event id, so a retry or a repeated transition replaces the visible notification instead of
 * stacking a duplicate.
 */
final readonly class PushMessage
{
    public function __construct(
        public PushMessageType $type,
        public string $tag,
        public ?string $branchId = null,
        public ?int $userId = null,
    ) {}

    /** A committed order entered the Branch Kitchen (Pay Now or Pay Later created its ticket). */
    public static function kitchenNewOrder(string $branchId, string $orderId): self
    {
        return new self(PushMessageType::KitchenNewOrder, 'kitchen-new-order:'.$orderId, $branchId);
    }

    /** The Kitchen marked an order Ready; the POS serves it. */
    public static function orderReady(string $branchId, string $orderId): self
    {
        return new self(PushMessageType::OrderReady, 'order-ready:'.$orderId, $branchId);
    }

    /** One Control Center notification was delivered to this account. */
    public static function adminAlert(int $userId, string $notificationId): self
    {
        return new self(PushMessageType::AdminAlert, 'admin-alert:'.$notificationId, userId: $userId);
    }

    /**
     * The encrypted payload: type, tag, the same-app page to open and, for Branch signals, the Branch name. Nothing
     * else — no customer, item, money, Staff or audit detail ever reaches a lock screen.
     *
     * @return array{v: int, type: string, tag: string, url: string, branch: string|null}
     */
    public function payload(?string $branchName): array
    {
        return [
            'v' => 1,
            'type' => $this->type->value,
            'tag' => $this->tag,
            'url' => $this->type->url(),
            'branch' => $branchName,
        ];
    }

    /**
     * RFC 8030 Topic: a push service replaces an undelivered message with the same topic, so a device that was
     * offline receives one notification per event. At most 32 URL-safe base64 characters.
     */
    public function topic(): string
    {
        return substr(rtrim(strtr(base64_encode(hash('sha256', $this->tag, true)), '+/', '-_'), '='), 0, 32);
    }
}
