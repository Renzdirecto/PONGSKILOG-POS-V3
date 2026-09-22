<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class EditCommittedOrderRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User && $user->hasPermission('transactions.view')
            && ($user->hasRole('cashier') || $user->hasRole('cashier_kitchen'));
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            ...StorePosDraftOrderRequest::draftRules(),
            'idempotency_key' => ['required', 'uuid'],
            'expected_version' => ['required', 'integer:strict', 'min:0'],
            'reason' => ['nullable', 'string', 'max:1000'],
            'items.*.existing_order_item_id' => ['nullable', 'uuid', 'distinct'],
        ];
    }
}
