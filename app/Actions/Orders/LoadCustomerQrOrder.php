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
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Support\BranchCatalog;
use App\Support\LoadedQrOrder;
use App\Support\PosAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LoadCustomerQrOrder
{
    public function __construct(private PosAccess $access, private BranchCatalog $catalog, private LoadedQrOrder $loaded) {}

    public function execute(User $user, Branch $branch, Order $requested): Order
    {
        return DB::transaction(function () use ($user, $branch, $requested): Order {
            $branch = Branch::query()->whereKey($branch->id)->lockForUpdate()->firstOrFail();
            $user = $this->access->authorize($user, $branch);
            $store = $branch->storeSessions()->where('status', StoreSessionStatus::Open)->sharedLock()->first();
            if ($store === null) {
                throw ValidationException::withMessages(['store' => 'Store is closed. Open the store before loading a QR order.']);
            }
            $order = Order::query()->where('branch_id', $branch->id)->whereKey($requested->id)->lockForUpdate()->firstOrFail();
            abort_unless($order->source === OrderSource::CustomerQr && $order->commercial_status === CommercialStatus::Submitted
                && $order->archived_at === null && $order->committed_at === null
                && $order->payment_status === PaymentStatus::Unpaid && $order->payment_term === null && $order->kitchen_status === KitchenStatus::NotSent, 409, 'This QR order is no longer waiting.');
            abort_if($order->loaded_by_user_id !== null, 409, 'This QR order has already been loaded.');
            abort_unless($order->store_session_id === $store->id, 409, 'This QR order belongs to an earlier store session.');
            abort_if(Order::query()->where('branch_id', $branch->id)->where('source', OrderSource::CustomerQr)
                ->where('commercial_status', CommercialStatus::Submitted)->where('loaded_by_user_id', $user->id)->exists(),
                409, 'Finish your currently loaded QR order before loading another.');
            $this->loaded->validateTable($order, $branch);
            $order->load('items');
            $products = $this->catalog->productsForOrder($branch, array_values($order->items->map(fn (OrderItem $item): ?string => $item->product_id)->filter(fn (?string $id): bool => $id !== null)->all()))->keyBy('id');
            foreach ($order->items->groupBy('product_id') as $productId => $items) {
                $product = $products->get($productId);
                if ($product === null || ! $this->catalog->resolveLoaded($product)['is_available']) {
                    throw ValidationException::withMessages(['items' => 'A submitted product is no longer available. The submitted total has not changed.']);
                }
                $state = $this->catalog->resolveLoaded($product);
                if ($state['tracked'] && $items->sum('quantity') > $state['on_hand']) {
                    throw ValidationException::withMessages(['items' => 'Insufficient stock for this submitted order.']);
                }
            }
            $order->update(['loaded_by_user_id' => $user->id, 'version' => $order->version + 1]);
            QrOrderChanged::dispatch($order, 'qr.order_loaded');
            CustomerTrackingChanged::dispatch($order);

            return $order;
        }, 3);
    }
}
