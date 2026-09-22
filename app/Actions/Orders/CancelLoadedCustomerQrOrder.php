<?php

namespace App\Actions\Orders;

use App\Events\CustomerTrackingChanged;
use App\Events\QrOrderChanged;
use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use App\Support\LoadedQrOrder;
use App\Support\PosAccess;
use Illuminate\Support\Facades\DB;

class CancelLoadedCustomerQrOrder
{
    public function execute(User $user, Branch $branch, Order $requested): Order
    {
        return DB::transaction(function () use ($user, $branch, $requested): Order {
            app(PosAccess::class)->authorize($user, $branch);
            $order = Order::query()->where('branch_id', $branch->id)->whereKey($requested->id)->lockForUpdate()->firstOrFail();
            abort_unless(app(LoadedQrOrder::class)->eligible($order, $user), 409, 'This loaded QR order can no longer be cancelled.');
            $order->update(['loaded_by_user_id' => null, 'version' => $order->version + 1]);
            QrOrderChanged::dispatch($order, 'qr.order_released');
            CustomerTrackingChanged::dispatch($order);

            return $order;
        }, 3);
    }
}
