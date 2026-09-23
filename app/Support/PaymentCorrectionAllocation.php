<?php

namespace App\Support;

use App\Models\Order;
use App\Models\OrderAdjustment;
use App\Models\Payment;
use Illuminate\Validation\ValidationException;

/**
 * Attributes lower-total corrections to the Cash/Cashless payment legs that funded them.
 * A correction is deterministic when it records its allocation or the Order was paid through a single method;
 * a mixed-method correction without an allocation is never guessed.
 */
class PaymentCorrectionAllocation
{
    /**
     * Net refundable amount per method after deterministic corrections.
     *
     * @return array{cash: int, cashless: int, methods: list<string>, unallocated: int}
     */
    public function available(Order $order, ?string $exceptAdjustmentId = null): array
    {
        $order->loadMissing('payments', 'adjustments');
        $paid = ['cash' => 0, 'cashless' => 0];
        foreach ($order->payments as $payment) {
            /** @var Payment $payment */
            $paid[$payment->method->value] += ExactMoney::cents($payment->amount);
        }
        $methods = array_keys(array_filter($paid, fn (int $cents): bool => $cents > 0));
        $unallocated = 0;

        foreach ($order->adjustments as $adjustment) {
            /** @var OrderAdjustment $adjustment */
            if ($adjustment->id === $exceptAdjustmentId) {
                continue;
            }
            $allocation = $this->allocation($adjustment, $methods);
            if ($allocation === null) {
                $unallocated += ExactMoney::cents($adjustment->amount);

                continue;
            }
            $paid['cash'] -= $allocation['cash'];
            $paid['cashless'] -= $allocation['cashless'];
        }

        return [
            'cash' => max(0, $paid['cash']),
            'cashless' => max(0, $paid['cashless']),
            'methods' => $methods,
            'unallocated' => $unallocated,
        ];
    }

    /**
     * The allocation a correction can be deterministically attributed to, or null when it is ambiguous.
     *
     * @param  list<string>  $paymentMethods
     * @return array{cash: int, cashless: int}|null
     */
    public function allocation(OrderAdjustment $adjustment, array $paymentMethods): ?array
    {
        if ($adjustment->isAllocated()) {
            return [
                'cash' => ExactMoney::cents((string) $adjustment->cash_amount),
                'cashless' => ExactMoney::cents((string) $adjustment->cashless_amount),
            ];
        }
        if (count($paymentMethods) !== 1) {
            return null;
        }
        $amount = ExactMoney::cents($adjustment->amount);

        return $paymentMethods[0] === 'cash'
            ? ['cash' => $amount, 'cashless' => 0]
            : ['cash' => 0, 'cashless' => $amount];
    }

    /**
     * @param  array{cash: int, cashless: int}  $available
     * @return array{min_cash: int, max_cash: int}
     */
    public function bounds(array $available, int $refund): array
    {
        $minimum = max(0, $refund - $available['cashless']);
        $maximum = min($refund, $available['cash']);
        if ($minimum > $maximum) {
            throw ValidationException::withMessages([
                'refund_cash_amount' => 'This correction cannot be matched to the recorded Cash and Cashless payments.',
            ]);
        }

        return ['min_cash' => $minimum, 'max_cash' => $maximum];
    }

    /**
     * Resolve the Cash/Cashless split for a refund, requiring an explicit Cash portion only when it is ambiguous.
     *
     * @return array{cash: int, cashless: int}
     */
    public function resolve(Order $order, int $refund, ?string $requestedCash, ?string $exceptAdjustmentId = null, string $field = 'refund_cash_amount'): array
    {
        $bounds = $this->bounds($this->available($order, $exceptAdjustmentId), $refund);
        if ($bounds['min_cash'] === $bounds['max_cash']) {
            $cash = $bounds['min_cash'];
            if ($requestedCash !== null && $requestedCash !== '' && ExactMoney::cents($requestedCash) !== $cash) {
                throw ValidationException::withMessages([$field => 'The Cash portion of this correction must be '.ExactMoney::display($cash).'.']);
            }

            return ['cash' => $cash, 'cashless' => $refund - $cash];
        }
        if ($requestedCash === null || $requestedCash === '') {
            throw ValidationException::withMessages([
                $field => 'Choose how much of the '.ExactMoney::display($refund).' correction was returned in Cash.',
            ]);
        }
        $cash = ExactMoney::cents($requestedCash);
        if ($cash < $bounds['min_cash'] || $cash > $bounds['max_cash']) {
            throw ValidationException::withMessages([
                $field => 'The Cash portion must be between '.ExactMoney::display($bounds['min_cash']).' and '.ExactMoney::display($bounds['max_cash']).'.',
            ]);
        }

        return ['cash' => $cash, 'cashless' => $refund - $cash];
    }
}
