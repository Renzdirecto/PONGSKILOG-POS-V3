<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class AllocateOrderAdjustmentRequest extends FormRequest
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
            && $user->hasPermission('transactions.view')
            && ($user->hasRole('cashier') || $user->hasRole('cashier_kitchen'));
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return self::allocationRules();
    }

    /** @return array<string, list<string>> */
    public static function allocationRules(): array
    {
        return [
            'cash_amount' => ['required', 'string', 'regex:/\A[0-9]{1,12}(?:\.[0-9]{1,2})?\z/'],
        ];
    }
}
