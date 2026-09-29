<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A customization dialog's configuration (focus) and the other cart lines it shares Branch Ingredient stock with.
 * Callers authorize the POS user or the Customer QR session themselves.
 */
class RecipeCapacityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        return [
            'lines' => ['present', 'array', 'list', 'max:100'],
            'lines.*.product_id' => ['required', 'uuid'],
            'lines.*.quantity' => ['required', 'integer:strict', 'min:1', 'max:999'],
            'lines.*.modifiers' => ['present', 'array', 'list', 'max:100'],
            'lines.*.modifiers.*.group_id' => ['required', 'uuid'],
            'lines.*.modifiers.*.option_id' => ['required', 'uuid'],
            'focus' => ['required', 'array'],
            'focus.product_id' => ['required', 'uuid'],
            'focus.quantity' => ['sometimes', 'integer:strict', 'min:1', 'max:999'],
            'focus.modifiers' => ['present', 'array', 'list', 'max:100'],
            'focus.modifiers.*.group_id' => ['required', 'uuid'],
            'focus.modifiers.*.option_id' => ['required', 'uuid'],
        ];
    }

    /** @return list<array{product_id: string, quantity: int, modifiers: list<array{group_id: string, option_id: string}>}> */
    public function lines(): array
    {
        /** @var list<array{product_id: string, quantity: int, modifiers: list<array{group_id: string, option_id: string}>}> $lines */
        $lines = array_values(array_map(fn (array $line): array => [
            'product_id' => strtolower($line['product_id']),
            'quantity' => (int) $line['quantity'],
            'modifiers' => array_values(array_map(fn (array $modifier): array => [
                'group_id' => strtolower($modifier['group_id']), 'option_id' => strtolower($modifier['option_id']),
            ], $line['modifiers'])),
        ], $this->validated('lines')));

        return $lines;
    }

    /** @return array{product_id: string, modifiers: list<array{group_id: string, option_id: string}>} */
    public function focus(): array
    {
        return [
            'product_id' => strtolower($this->validated('focus.product_id')),
            'modifiers' => array_values(array_map(fn (array $modifier): array => [
                'group_id' => strtolower($modifier['group_id']), 'option_id' => strtolower($modifier['option_id']),
            ], $this->validated('focus.modifiers'))),
        ];
    }
}
