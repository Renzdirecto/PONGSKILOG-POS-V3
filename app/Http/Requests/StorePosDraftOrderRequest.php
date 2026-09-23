<?php

namespace App\Http\Requests;

use App\Enums\OrderType;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePosDraftOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User && $user->hasCashierOperationsRole();
    }

    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        return self::draftRules();
    }

    /** Shared with the action so non-HTTP callers cannot bypass input validation.
     * @return array<string, array<mixed>>
     */
    public static function draftRules(): array
    {
        return [
            'reserved_order_id' => ['nullable', 'uuid'],
            'order_type' => ['required', Rule::enum(OrderType::class)],
            'branch_table_id' => ['nullable', 'uuid'],
            'customer_label' => ['nullable', 'string', 'max:150'],
            'items' => ['required', 'array', 'list', 'min:1', 'max:100'],
            'items.*.product_id' => ['required', 'uuid'],
            'items.*.quantity' => ['required', 'integer:strict', 'min:1', 'max:999'],
            'items.*.notes' => ['nullable', 'string', 'max:1000'],
            'items.*.modifiers' => ['present', 'array', 'list', 'max:100'],
            'items.*.modifiers.*.group_id' => ['required', 'uuid'],
            'items.*.modifiers.*.option_id' => ['required', 'uuid'],
        ];
    }
}
