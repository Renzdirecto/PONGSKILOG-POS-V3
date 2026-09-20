<?php

namespace App\Support;

use App\Models\Order;
use Illuminate\Validation\ValidationException;

class OrderPaymentLegs
{
    /**
     * @param  array<string, mixed>  $data
     * @return array<string, array{amount: string, amount_received: string|null, change_amount: string|null}>
     */
    public function for(Order $order, array $data): array
    {
        $total = ExactMoney::cents($order->total);
        $method = $data['payment_method'];
        $cashless = $method === 'cashless' ? $total : ($method === 'split' ? ExactMoney::cents($data['cashless_amount']) : 0);
        if ($method === 'split' && ($cashless <= 0 || $cashless >= $total)) {
            throw ValidationException::withMessages(['cashless_amount' => 'Cashless amount must be greater than zero and less than the order total.']);
        }

        $legs = [];
        if ($method !== 'cashless') {
            $received = ExactMoney::cents($data['cash_received']);
            $due = $total - $cashless;
            if ($received < $due) {
                throw ValidationException::withMessages(['cash_received' => 'Cash amount is insufficient. Enter at least '.ExactMoney::decimal($due).'.']);
            }
            $legs['cash'] = [
                'amount' => ExactMoney::decimal($due),
                'amount_received' => ExactMoney::decimal($received),
                'change_amount' => ExactMoney::decimal($received - $due),
            ];
        }
        if ($method !== 'cash') {
            $legs['cashless'] = [
                'amount' => ExactMoney::decimal($cashless),
                'amount_received' => null,
                'change_amount' => null,
            ];
        }

        return $legs;
    }
}
