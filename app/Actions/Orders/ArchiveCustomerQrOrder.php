<?php

namespace App\Actions\Orders;

use App\Enums\CommercialStatus;
use App\Enums\KitchenStatus;
use App\Enums\OrderSource;
use App\Enums\PaymentStatus;
use App\Events\CustomerTrackingChanged;
use App\Events\QrOrderChanged;
use App\Models\Order;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

class ArchiveCustomerQrOrder
{
    public function execute(Order $requested, string $reason = 'stale_30_minutes', ?CarbonInterface $archivedAt = null): bool
    {
        return DB::transaction(function () use ($requested, $reason, $archivedAt): bool {
            $order = Order::query()->whereKey($requested->id)->lockForUpdate()->firstOrFail();
            if ($order->source !== OrderSource::CustomerQr || $order->commercial_status !== CommercialStatus::Submitted
                || $order->loaded_by_user_id !== null || $order->committed_at !== null || $order->archived_at !== null
                || $order->payment_status !== PaymentStatus::Unpaid || $order->payment_term !== null || $order->kitchen_status !== KitchenStatus::NotSent
                || ($reason === 'stale_30_minutes' && ($order->submitted_at === null || $order->submitted_at->gt(now()->subMinutes(30))))) {
                return false;
            }
            $order->update(['commercial_status' => CommercialStatus::ArchivedUnclaimed,
                'archive_reason' => $reason, 'archived_at' => $archivedAt ?? now(), 'version' => $order->version + 1]);
            QrOrderChanged::dispatch($order, 'qr.order_archived');
            CustomerTrackingChanged::dispatch($order);

            return true;
        }, 3);
    }
}
