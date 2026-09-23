<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class CloseStoreSessionRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User
            && $user->is_active
            && $user->hasPermission('pos.access')
            && $user->hasPermission('store.open_close')
            && $user->hasCashierOperationsRole();
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return self::closeRules();
    }

    /** @return array<string, list<mixed>> */
    public static function closeRules(): array
    {
        /** Unsigned fixed-point strings fit numeric(14,2) without float conversion or rounding. */
        $money = ['required', 'string', 'regex:/\A[0-9]{1,12}(?:\.[0-9]{1,2})?\z/'];

        return [
            'idempotency_key' => ['required', 'uuid'],
            'store_session_id' => ['required', 'uuid'],
            'closing_cash_amount' => $money,
            'closing_cashless_amount' => $money,
            'closing_note' => ['nullable', 'string', 'max:1000'],
            'branch_id' => ['prohibited'],
            'expected_cash_amount' => ['prohibited'],
            'expected_cashless_amount' => ['prohibited'],
            'cash_variance' => ['prohibited'],
            'cashless_variance' => ['prohibited'],
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return self::closeMessages();
    }

    /** @return array<string, string> */
    public static function closeMessages(): array
    {
        return [
            'closing_cash_amount.required' => 'Enter the counted Closing Cash.',
            'closing_cashless_amount.required' => 'Enter the confirmed Closing Cashless balance.',
            'closing_cash_amount.regex' => 'Enter Closing Cash as a non-negative amount with up to 2 decimal places.',
            'closing_cashless_amount.regex' => 'Enter Closing Cashless as a non-negative amount with up to 2 decimal places.',
        ];
    }
}
