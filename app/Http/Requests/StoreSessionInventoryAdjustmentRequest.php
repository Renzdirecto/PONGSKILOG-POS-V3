<?php

namespace App\Http\Requests;

use App\Enums\StoreInventoryAdjustmentReason;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSessionInventoryAdjustmentRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User
            && $user->is_active
            && $user->hasPermission('store_expenses.manage')
            && ($user->hasRole('cashier') || $user->hasRole('cashier_kitchen'));
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return self::adjustmentRules();
    }

    /** @return array<string, list<mixed>> */
    public static function adjustmentRules(): array
    {
        return [
            'idempotency_key' => ['required', 'uuid'],
            'reason_code' => ['required', Rule::enum(StoreInventoryAdjustmentReason::class)],
            'product_id' => ['required', 'uuid'],
            'quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
            'note' => ['nullable', 'required_if:reason_code,other', 'string', 'max:500'],
        ];
    }

    /** @return array<string, string> */
    public static function adjustmentMessages(): array
    {
        return [
            'note.required_if' => 'Explain the adjustment when the reason is Other.',
            'quantity.min' => 'Enter a quantity of at least 1.',
            'quantity.integer' => 'Enter a whole-number quantity.',
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return self::adjustmentMessages();
    }

    protected function prepareForValidation(): void
    {
        $note = $this->input('note');
        $this->merge(['note' => is_string($note) && trim($note) !== '' ? trim($note) : null]);
    }
}
