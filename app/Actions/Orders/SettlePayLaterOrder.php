<?php

namespace App\Actions\Orders;

use App\Actions\Audit\AuditRecorder;
use App\Enums\CommercialStatus;
use App\Enums\PaymentStatus;
use App\Enums\PaymentTerm;
use App\Enums\StoreSessionStatus;
use App\Events\CustomerTrackingChanged;
use App\Events\OrderUpdated;
use App\Http\Requests\SettlePayLaterOrderRequest;
use App\Models\Branch;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Support\ExactMoney;
use App\Support\OrderMoney;
use App\Support\OrderPaymentLegs;
use App\Support\PosAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class SettlePayLaterOrder
{
    public function __construct(
        private OrderPaymentLegs $paymentLegs,
        private OrderMoney $money,
        private PosAccess $access,
        private AuditRecorder $audit,
    ) {}

    /** @param array<string, mixed> $input */
    public function execute(User $user, Branch $branch, Order $requestedOrder, array $input): Order
    {
        $data = Validator::make($input, (new SettlePayLaterOrderRequest)->rules())->validate();
        $data['idempotency_key'] = strtolower($data['idempotency_key']);

        return DB::transaction(function () use ($user, $branch, $requestedOrder, $data): Order {
            if (DB::getDriverName() === 'pgsql') {
                DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', [$data['idempotency_key']]);
            }

            $branch = Branch::query()->whereKey($branch->getKey())->firstOrFail();
            $user = $this->access->authorize($user, $branch);
            if ($replay = $this->replay($user, $branch, $requestedOrder, $data)) {
                return $replay;
            }

            $session = $branch->storeSessions()
                ->where('status', StoreSessionStatus::Open)
                ->sharedLock()
                ->first();
            if ($session === null) {
                throw ValidationException::withMessages(['store' => 'Store is closed. Open the original store session before settling this order.']);
            }
            $order = Order::query()
                ->where('branch_id', $branch->id)
                ->whereKey($requestedOrder->id)
                ->lockForUpdate()
                ->firstOrFail();
            $order->load('payments', 'adjustments');
            $money = $this->money->totals($order);
            if ($money['outstanding'] === 0) {
                throw ValidationException::withMessages(['order' => 'This order has no outstanding balance.']);
            }
            if ($order->commercial_status !== CommercialStatus::Active
                || $order->committed_at === null) {
                throw ValidationException::withMessages(['order' => 'Only a committed active order can be settled.']);
            }
            if ($order->store_session_id !== $session->id) {
                throw ValidationException::withMessages(['store_session' => 'This order belongs to a different store session and cannot be settled here.']);
            }

            $paidAt = now();
            $outstanding = ExactMoney::decimal($money['outstanding']);
            foreach ($this->paymentLegs->forAmount($outstanding, $data) as $method => $leg) {
                Payment::query()->create([
                    ...$leg,
                    'method' => $method,
                    'branch_id' => $branch->id,
                    'store_session_id' => $session->id,
                    'order_id' => $order->id,
                    'created_by_user_id' => $user->id,
                    'idempotency_key' => $data['idempotency_key'].':'.$method,
                    'payment_group_id' => $data['idempotency_key'],
                    'payment_context' => $order->payments->isEmpty() && $order->payment_term === PaymentTerm::PayLater
                        ? 'pay_later_settlement' : 'edit_balance_settlement',
                    'paid_at' => $paidAt,
                ]);
            }
            $before = $this->auditSnapshot($order);
            $order->update([
                'payment_status' => PaymentStatus::Paid,
                'version' => $order->version + 1,
            ]);
            $this->audit->record(
                branch: $branch,
                actor: $user,
                module: 'transactions',
                action: 'order.settled',
                auditableType: Order::class,
                auditableId: $order->id,
                before: $before,
                after: $this->auditSnapshot($order),
                metadata: ['payment_method' => $data['payment_method'], 'store_session_id' => $session->id],
                idempotencyKey: $data['idempotency_key'],
            );

            CustomerTrackingChanged::dispatch($order);
            OrderUpdated::dispatch($order, ['payment']);

            return $order;
        });
    }

    /** @return array<string, string|int|null> */
    private function auditSnapshot(Order $order): array
    {
        return [
            'commercial_status' => $order->commercial_status->value,
            'payment_status' => $order->payment_status->value,
            'payment_term' => $order->payment_term?->value,
            'kitchen_status' => $order->kitchen_status->value,
            'version' => $order->version,
        ];
    }

    /** @param array<string, mixed> $data */
    private function replay(User $user, Branch $branch, Order $requestedOrder, array $data): ?Order
    {
        $payments = Payment::query()
            ->whereIn('idempotency_key', [$data['idempotency_key'].':cash', $data['idempotency_key'].':cashless'])
            ->get();
        if ($payments->isEmpty()) {
            return null;
        }

        $first = $payments->first();
        if ($first->branch_id !== $branch->id || $first->created_by_user_id !== $user->id
            || $first->order_id !== $requestedOrder->id
            || $payments->contains(fn (Payment $payment): bool => $payment->order_id !== $first->order_id)) {
            abort(409, 'This settlement attempt belongs to another order or cashier.');
        }
        $order = Order::query()->where('branch_id', $branch->id)->with('payments', 'adjustments')->findOrFail($first->order_id);
        $paidByAttempt = $payments->sum(fn (Payment $payment): int => ExactMoney::cents($payment->amount));
        $legs = $this->paymentLegs->forAmount(ExactMoney::decimal($paidByAttempt), $data);
        if ($order->commercial_status !== CommercialStatus::Active
            || $order->payment_status !== PaymentStatus::Paid
            || count($legs) !== $payments->count()) {
            abort(409, 'This settlement attempt has already been used with different details.');
        }
        foreach ($payments as $payment) {
            if (($legs[$payment->method->value] ?? null) !== [
                'amount' => $payment->amount,
                'amount_received' => $payment->amount_received,
                'change_amount' => $payment->change_amount,
            ]) {
                abort(409, 'This settlement attempt has already been used with different details.');
            }
        }

        return $order;
    }
}
