<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SettlePayLaterOrderRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User && ($user->hasRole('cashier') || $user->hasRole('cashier_kitchen'));
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $money = ['nullable', 'string', 'regex:/\A[0-9]{1,12}(?:\.[0-9]{1,2})?\z/'];

        return [
            'idempotency_key' => ['required', 'uuid'],
            'payment_method' => ['required', Rule::in(['cash', 'cashless', 'split'])],
            'cash_received' => ['required_if:payment_method,cash,split', ...$money],
            'cashless_amount' => ['required_if:payment_method,split', ...$money],
            'branch_id' => ['prohibited'],
            'store_session_id' => ['prohibited'],
            'subtotal' => ['prohibited'],
            'total' => ['prohibited'],
            'commercial_status' => ['prohibited'],
            'payment_status' => ['prohibited'],
            'payment_term' => ['prohibited'],
            'kitchen_status' => ['prohibited'],
            'order_number' => ['prohibited'],
            'reference_number' => ['prohibited'],
        ];
    }
}
