<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class VoidOrderRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user() instanceof User;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'reason_code' => ['required', 'string', Rule::in([
                'wrong_item',
                'customer_cancelled',
                'duplicate_transaction',
                'price_or_quantity_error',
                'other',
            ])],
            'reason_text' => ['nullable', 'string', 'max:1000', 'required_if:reason_code,other'],
            'authorizer_email' => ['required', 'string', 'email:filter', 'max:255'],
            'authorizer_password' => ['required', 'string', 'max:255'],
            'idempotency_key' => ['required', 'uuid'],
            'expected_version' => ['required', 'integer', 'min:0'],
        ];
    }
}
