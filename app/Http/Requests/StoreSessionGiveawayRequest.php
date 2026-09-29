<?php

namespace App\Http\Requests;

use App\Enums\GiveawayReason;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSessionGiveawayRequest extends FormRequest
{
    /** The POS line limit: a Giveaway is one customized line of one Product. */
    public const MAX_QUANTITY = 999;

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
        return self::giveawayRules();
    }

    /** @return array<string, list<mixed>> */
    public static function giveawayRules(): array
    {
        return [
            'idempotency_key' => ['required', 'uuid'],
            'product_id' => ['required', 'uuid'],
            'quantity' => ['required', 'integer', 'min:1', 'max:'.self::MAX_QUANTITY],
            'modifiers' => ['present', 'array', 'max:50'],
            'modifiers.*.group_id' => ['required', 'uuid'],
            'modifiers.*.option_id' => ['required', 'uuid'],
            'reason_code' => ['required', Rule::enum(GiveawayReason::class)],
            'note' => ['nullable', 'required_if:reason_code,other', 'string', 'max:500'],
        ];
    }

    /** @return array<string, list<mixed>> */
    public static function reversalRules(): array
    {
        return [
            'idempotency_key' => ['required', 'uuid'],
            'reason' => ['required', 'string', 'max:500'],
        ];
    }

    /** @return array<string, string> */
    public static function giveawayMessages(): array
    {
        return [
            'product_id.required' => 'Choose the product that was given away.',
            'quantity.min' => 'Enter a quantity of at least 1.',
            'quantity.max' => 'Enter a quantity of at most '.self::MAX_QUANTITY.'.',
            'quantity.integer' => 'Enter a whole-number quantity.',
            'reason_code.required' => 'Choose why this item was given away.',
            'note.required_if' => 'Explain the giveaway when the reason is Other.',
            'reason.required' => 'Explain why this giveaway is being reversed.',
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return self::giveawayMessages();
    }

    protected function prepareForValidation(): void
    {
        $note = $this->input('note');
        $this->merge(['note' => is_string($note) && trim($note) !== '' ? trim($note) : null]);
    }
}
