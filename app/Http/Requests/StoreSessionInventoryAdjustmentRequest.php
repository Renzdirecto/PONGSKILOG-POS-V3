<?php

namespace App\Http\Requests;

use App\Enums\StockCorrectionDirection;
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
            && $user->hasCashierOperationsRole();
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

    /**
     * A Stock Correction: the direction and positive quantity that make the system stock match the physical count.
     * Complimentary and Staff meal are no longer accepted; a free Product is recorded as a Giveaway.
     *
     * @return array<string, list<mixed>>
     */
    public static function adjustmentRules(): array
    {
        return [
            'idempotency_key' => ['required', 'uuid'],
            'direction' => ['required', Rule::enum(StockCorrectionDirection::class)],
            'reason_code' => ['required', 'string', Rule::in(array_map(
                fn (StoreInventoryAdjustmentReason $reason): string => $reason->value,
                StoreInventoryAdjustmentReason::correctionReasons(),
            ))],
            'product_id' => ['required', 'uuid'],
            'quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
            'note' => ['nullable', 'required_if:reason_code,other', 'string', 'max:500'],
        ];
    }

    /** @return array<string, string> */
    public static function adjustmentMessages(): array
    {
        return [
            'direction.required' => 'Choose whether the correction adds or removes stock.',
            'reason_code.in' => 'Choose a Stock Correction reason. Record free items as a Giveaway.',
            'note.required_if' => 'Explain the correction when the reason is Other.',
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
