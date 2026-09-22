<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class VoidOrdersRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user() instanceof User
            && $this->user()->hasPermission('void_orders.manage');
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'branch_id' => ['nullable', 'uuid', 'exists:branches,id'],
            'initiated_by_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'authorized_by_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'reason_code' => ['nullable', 'string', Rule::in([
                'wrong_item',
                'customer_cancelled',
                'duplicate_transaction',
                'price_or_quantity_error',
                'other',
            ])],
            'date' => ['nullable', 'date_format:Y-m-d'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
