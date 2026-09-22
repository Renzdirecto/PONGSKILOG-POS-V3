<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SubmitCustomerQrOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        return [...StorePosDraftOrderRequest::draftRules(),
            'reserved_order_id' => ['prohibited'],
            'idempotency_key' => ['required', 'uuid'],
        ];
    }
}
