<?php

namespace App\Http\Requests;

use App\Enums\KitchenStatus;
use App\Enums\OrderType;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TransactionHistoryRequest extends FormRequest
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
            'search' => ['nullable', 'string', 'max:150'],
            'date' => ['nullable', Rule::in(['today', 'yesterday', 'last_7_days', 'month', 'custom'])],
            'from' => ['nullable', 'required_if:date,custom', 'date_format:Y-m-d'],
            'to' => ['nullable', 'required_if:date,custom', 'date_format:Y-m-d', 'after_or_equal:from'],
            'kitchen_status' => ['nullable', Rule::enum(KitchenStatus::class)],
            'payment_status' => ['nullable', Rule::in(['paid', 'pending', 'balance'])],
            'order_type' => ['nullable', Rule::enum(OrderType::class)],
            'payment_method' => ['nullable', Rule::in(['cash', 'cashless', 'split'])],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
