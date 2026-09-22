<?php

namespace App\Support;

use App\Enums\CommercialStatus;
use App\Enums\KitchenStatus;
use App\Enums\OrderSource;
use App\Enums\PaymentStatus;
use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class LoadedQrOrder
{
    public function eligible(Order $order, User $user): bool
    {
        return $order->source === OrderSource::CustomerQr
            && $order->commercial_status === CommercialStatus::Submitted
            && $order->loaded_by_user_id === $user->id && $order->archived_at === null
            && $order->payment_status === PaymentStatus::Unpaid && $order->payment_term === null
            && $order->kitchen_status === KitchenStatus::NotSent && $order->committed_at === null;
    }

    public function validateTable(Order $order, Branch $branch): void
    {
        if ($order->branch_table_id !== null && ! $branch->tables()->whereKey($order->branch_table_id)->where('is_active', true)->sharedLock()->first()) {
            throw ValidationException::withMessages(['table' => 'The selected table is no longer active in this branch.']);
        }
    }
}
