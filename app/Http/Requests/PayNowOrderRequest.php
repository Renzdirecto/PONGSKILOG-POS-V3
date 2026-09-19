<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

class PayNowOrderRequest extends StorePosDraftOrderRequest
{
    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        return self::paymentRules($this->filled('draft_order_id'));
    }

    /** @return array<string, array<mixed>> */
    public static function paymentRules(bool $existingDraft): array
    {
        $money = ['nullable', 'string', 'regex:/\A[0-9]{1,12}(?:\.[0-9]{1,2})?\z/'];

        return [
            ...($existingDraft ? [] : self::draftRules()),
            'draft_order_id' => ['nullable', 'uuid'],
            'reserved_order_id' => ['nullable', 'uuid'],
            'idempotency_key' => ['required', 'uuid'],
            'payment_method' => ['required', Rule::in(['cash', 'cashless', 'split'])],
            'cash_received' => ['required_if:payment_method,cash,split', ...$money],
            'cashless_amount' => ['required_if:payment_method,split', ...$money],
        ];
    }
}
