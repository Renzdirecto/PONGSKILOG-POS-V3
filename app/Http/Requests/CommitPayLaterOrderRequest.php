<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class CommitPayLaterOrderRequest extends FormRequest
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
        return [
            'idempotency_key' => ['required', 'uuid'],
            'branch_id' => ['prohibited'],
            'store_session_id' => ['prohibited'],
            'subtotal' => ['prohibited'],
            'total' => ['prohibited'],
            'stock_amount' => ['prohibited'],
            'line_price' => ['prohibited'],
            'commercial_status' => ['prohibited'],
            'payment_status' => ['prohibited'],
            'payment_term' => ['prohibited'],
            'kitchen_status' => ['prohibited'],
            'order_number' => ['prohibited'],
            'reference_number' => ['prohibited'],
        ];
    }
}
