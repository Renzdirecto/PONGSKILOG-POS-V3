<?php

namespace App\Actions\Orders;

use App\Enums\CommercialStatus;
use App\Enums\KitchenStatus;
use App\Enums\OrderSource;
use App\Enums\PaymentStatus;
use App\Enums\StoreSessionStatus;
use App\Events\CustomerTrackingChanged;
use App\Events\QrOrderChanged;
use App\Models\Branch;
use App\Models\CustomerQrSession;
use App\Models\Order;
use App\Models\User;
use App\Support\PosAccess;
use Illuminate\Support\Facades\DB;

class RestoreCustomerQrOrder
{
    public function execute(User $user, Branch $branch, Order $requested): Order
    {
        return DB::transaction(function () use ($user, $branch, $requested): Order {
            $branch = Branch::query()->whereKey($branch->id)->sharedLock()->firstOrFail();
            app(PosAccess::class)->authorize($user, $branch);
            $store = $branch->storeSessions()->where('status', StoreSessionStatus::Open)->sharedLock()->first();
            abort_if($store === null, 409, 'Open the store before restoring a QR order.');
            abort_unless($requested->branch_id === $branch->id, 404);
            $session = CustomerQrSession::query()->whereKey($requested->customer_qr_session_id)->where('branch_id', $branch->id)->lockForUpdate()->first();
            abort_if($session === null || $session->expires_at->lte(now()), 409, 'The customer ordering session has expired.');
            $order = Order::query()->whereKey($requested->id)->where('branch_id', $branch->id)->lockForUpdate()->firstOrFail();
            abort_unless($order->source === OrderSource::CustomerQr
                && $order->commercial_status === CommercialStatus::ArchivedUnclaimed
                && $order->store_session_id === $store->id
                && $order->loaded_by_user_id === null && $order->committed_at === null
                && $order->order_number === null && $order->reference_number === null
                && $order->payment_status === PaymentStatus::Unpaid && $order->payment_term === null
                && $order->kitchen_status === KitchenStatus::NotSent
                && $order->payments()->doesntExist() && $order->kitchenTicket()->doesntExist()
                && $order->inventoryMovements()->doesntExist(), 409, 'This archived QR order cannot be restored.');
            abort_if($session->active_order_id !== null && $session->active_order_id !== $order->id, 409, 'The customer already has another current order.');
            $order->update(['commercial_status' => CommercialStatus::Submitted, 'archived_at' => null,
                'archive_reason' => null, 'loaded_by_user_id' => null, 'submitted_at' => now(), 'version' => $order->version + 1]);
            $session->update(['active_order_id' => $order->id]);
            QrOrderChanged::dispatch($order, 'qr.order_restored');
            CustomerTrackingChanged::dispatch($order);

            return $order;
        }, 3);
    }
}
