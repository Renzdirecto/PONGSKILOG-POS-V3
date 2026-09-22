<?php

namespace App\Actions\Orders;

use App\Enums\BranchStatus;
use App\Enums\CommercialStatus;
use App\Enums\KitchenStatus;
use App\Enums\OrderSource;
use App\Enums\PaymentStatus;
use App\Enums\StoreSessionStatus;
use App\Events\QrOrderChanged;
use App\Http\Requests\SubmitCustomerQrOrderRequest;
use App\Models\Branch;
use App\Models\CustomerQrSession;
use App\Models\Order;
use App\Support\OrderNumber;
use App\Support\OrderSnapshots;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SubmitCustomerQrOrder
{
    public function __construct(private OrderSnapshots $snapshots, private OrderNumber $numbers) {}

    /** @param array<string, mixed> $input */
    public function execute(Branch $branch, CustomerQrSession $session, array $input): Order
    {
        /** @var array{order_type: string, idempotency_key: string, branch_table_id?: string|null, customer_label?: string|null, items: list<array{product_id: string, quantity: int, notes?: string|null, modifiers: list<array{group_id: string, option_id: string}>}>} $data */
        $data = Validator::make($input, (new SubmitCustomerQrOrderRequest)->rules())->validate();
        $key = strtolower($data['idempotency_key']);
        $intent = hash('sha256', json_encode([
            $data['order_type'], trim($data['customer_label'] ?? ''), $data['branch_table_id'] ?? null,
            array_map(fn (array $line): array => [$line['product_id'], $line['quantity'], $line['notes'] ?? '',
                array_map(fn (array $option): array => [$option['group_id'], $option['option_id']], $line['modifiers'])], $data['items']),
        ], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($branch, $session, $data, $key, $intent): Order {
            $branch = Branch::query()->whereKey($branch->id)->lockForUpdate()->firstOrFail();
            $store = $branch->storeSessions()->where('status', StoreSessionStatus::Open)->sharedLock()->first();
            $session = CustomerQrSession::query()->whereKey($session->id)->where('branch_id', $branch->id)->lockForUpdate()->firstOrFail();
            abort_if($session->expires_at->lte(now()), 419, 'Your ordering session expired.');
            $existing = Order::query()->where('customer_qr_session_id', $session->id)->where('qr_idempotency_key', $key)->first();
            if ($existing !== null) {
                abort_unless(hash_equals($existing->qr_intent_hash, $intent), 409, 'This submission attempt already belongs to different order details.');

                return $existing;
            }
            if ($branch->status !== BranchStatus::Active || $store === null) {
                throw ValidationException::withMessages(['store' => 'STORE IS CURRENTLY CLOSED']);
            }
            abort_if($session->active_order_id !== null, 409, 'You already have a current order. Return to tracking before starting another order.');
            $orderId = (string) Str::uuid();
            $snapshot = $this->snapshots->prepare($branch, $data, $orderId, lockCatalog: true);
            $order = new Order;
            $order->forceFill([
                ...$snapshot['attributes'], ...$this->numbers->allocate($branch, now()),
                'id' => $orderId, 'branch_id' => $branch->id, 'store_session_id' => $store->id,
                'source' => OrderSource::CustomerQr, 'commercial_status' => CommercialStatus::Submitted,
                'payment_status' => PaymentStatus::Unpaid, 'payment_term' => null,
                'kitchen_status' => KitchenStatus::NotSent, 'submitted_at' => now(), 'committed_at' => null,
                'customer_qr_session_id' => $session->id, 'public_tracking_id' => bin2hex(random_bytes(32)),
                'qr_idempotency_key' => $key, 'qr_intent_hash' => $intent,
            ])->save();
            $this->snapshots->persist($snapshot);
            $session->update(['active_order_id' => $order->id]);
            QrOrderChanged::dispatch($order, 'qr.order_submitted');

            return $order;
        }, 3);
    }
}
