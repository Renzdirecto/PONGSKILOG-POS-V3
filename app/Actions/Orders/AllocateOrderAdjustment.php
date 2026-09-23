<?php

namespace App\Actions\Orders;

use App\Actions\Audit\AuditRecorder;
use App\Enums\CommercialStatus;
use App\Enums\StoreSessionStatus;
use App\Events\OrderUpdated;
use App\Http\Requests\AllocateOrderAdjustmentRequest;
use App\Models\Branch;
use App\Models\Order;
use App\Models\OrderAdjustment;
use App\Models\StoreSession;
use App\Models\User;
use App\Support\ExactMoney;
use App\Support\PaymentCorrectionAllocation;
use App\Support\PosAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Records the Cash/Cashless source of a historical mixed-method correction that predates allocation capture.
 * The allocation is written once from the Cashier's explicit answer; it is never inferred.
 */
class AllocateOrderAdjustment
{
    public function __construct(
        private PosAccess $access,
        private PaymentCorrectionAllocation $allocation,
        private AuditRecorder $audit,
    ) {}

    /** @param array<string, mixed> $input */
    public function execute(User $actor, Branch $branch, OrderAdjustment $requested, array $input): OrderAdjustment
    {
        /** @var array{cash_amount: string} $data */
        $data = Validator::make($input, AllocateOrderAdjustmentRequest::allocationRules())->validate();

        return DB::transaction(function () use ($actor, $branch, $requested, $data): OrderAdjustment {
            $actor = $this->access->authorize($actor, $branch);
            $session = StoreSession::query()
                ->where('branch_id', $branch->id)
                ->where('status', StoreSessionStatus::Open)
                ->sharedLock()
                ->first();
            if ($session === null) {
                throw ValidationException::withMessages(['store' => 'Store is closed. Historical transactions are read-only.']);
            }
            $order = Order::query()->where('branch_id', $branch->id)->whereKey($requested->order_id)->lockForUpdate()->firstOrFail();
            $adjustment = OrderAdjustment::query()->where('order_id', $order->id)->whereKey($requested->id)->lockForUpdate()->firstOrFail();
            if ($adjustment->store_session_id !== $session->id || $order->store_session_id !== $session->id) {
                throw ValidationException::withMessages(['cash_amount' => 'Only a correction from the current Store Session can be allocated.']);
            }
            if ($order->commercial_status === CommercialStatus::Voided) {
                throw ValidationException::withMessages(['cash_amount' => 'A voided order is fully reversed and needs no allocation.']);
            }

            $cash = ExactMoney::cents($data['cash_amount']);
            $refund = ExactMoney::cents($adjustment->amount);
            if ($adjustment->isAllocated()) {
                abort_unless(ExactMoney::cents((string) $adjustment->cash_amount) === $cash, 409, 'This correction has already been allocated differently.');

                return $adjustment;
            }

            $order->load('payments', 'adjustments');
            $methods = $this->allocation->available($order)['methods'];
            if ($this->allocation->allocation($adjustment, $methods) !== null) {
                throw ValidationException::withMessages(['cash_amount' => 'This correction is already attributed to a single payment method.']);
            }
            $resolved = $this->allocation->resolve($order, $refund, $data['cash_amount'], $adjustment->id, 'cash_amount');

            $adjustment->update([
                'cash_amount' => ExactMoney::decimal($resolved['cash']),
                'cashless_amount' => ExactMoney::decimal($resolved['cashless']),
            ]);
            $this->audit->record(
                branch: $branch,
                actor: $actor,
                module: 'transactions',
                action: 'payment_correction.allocated',
                auditableType: OrderAdjustment::class,
                auditableId: $adjustment->id,
                before: ['cash_amount' => null, 'cashless_amount' => null],
                after: ['cash_amount' => $adjustment->cash_amount, 'cashless_amount' => $adjustment->cashless_amount],
                metadata: ['order_id' => $order->id, 'amount' => $adjustment->amount, 'store_session_id' => $session->id],
            );
            OrderUpdated::dispatch($order, ['payment']);

            return $adjustment;
        }, attempts: 3);
    }
}
