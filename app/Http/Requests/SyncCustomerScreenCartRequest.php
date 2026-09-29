<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The POS station's current cart for its paired customer screen: ids and quantities only (every visible text and price
 * is derived on the server), numbered per POS page instance so the newest send always wins.
 */
class SyncCustomerScreenCartRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() instanceof User;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'instance' => ['required', 'string', 'regex:/\A[A-Za-z0-9-]{8,64}\z/'],
            'sequence' => ['required', 'integer', 'min:1', 'max:2147483647'],
            'order_type' => ['nullable', 'in:dine_in,take_out'],
            'saved_order_id' => ['nullable', 'uuid'],
            'items' => ['present', 'array', 'max:60'],
            'items.*.key' => ['required', 'string', 'max:64'],
            'items.*.product_id' => ['required', 'uuid'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:999'],
            'items.*.modifiers' => ['present', 'array', 'max:30'],
            'items.*.modifiers.*.group_id' => ['required', 'uuid'],
            'items.*.modifiers.*.option_id' => ['required', 'uuid'],
        ];
    }

    /**
     * The validated lines in the shape the projection reads (ids as strings, quantity as an integer).
     *
     * @return list<array{key: string, product_id: string, quantity: int, modifiers: list<array{group_id: string, option_id: string}>}>
     */
    public function items(): array
    {
        $items = [];
        foreach ((array) $this->validated('items') as $item) {
            if (! is_array($item) || ! is_string($item['key'] ?? null) || ! is_string($item['product_id'] ?? null) || ! is_numeric($item['quantity'] ?? null)) {
                continue;
            }
            $modifiers = [];
            foreach ((array) ($item['modifiers'] ?? []) as $modifier) {
                if (is_array($modifier) && is_string($modifier['group_id'] ?? null) && is_string($modifier['option_id'] ?? null)) {
                    $modifiers[] = ['group_id' => $modifier['group_id'], 'option_id' => $modifier['option_id']];
                }
            }
            $items[] = ['key' => $item['key'], 'product_id' => $item['product_id'], 'quantity' => intval($item['quantity']), 'modifiers' => $modifiers];
        }

        return $items;
    }
}
