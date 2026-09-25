<?php

namespace App\Http\Requests;

use App\Enums\ModifierSelectionType;
use App\Enums\ModifierSemanticRole;
use App\Models\ModifierOption;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SaveModifierGroupRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if ($this->input('semantic_role') !== ModifierSemanticRole::Instruction->value) {
            return;
        }

        $this->merge([
            'selection_type' => ModifierSelectionType::Multiple->value,
            'min_select' => 0,
            'max_select' => max(2, $this->integer('max_select', 3)),
        ]);
    }

    public function authorize(): bool
    {
        return $this->user()?->can('catalog.define') ?? false;
    }

    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'semantic_role' => ['nullable', Rule::enum(ModifierSemanticRole::class)],
            'selection_type' => ['required', Rule::enum(ModifierSelectionType::class)],
            'min_select' => ['required', 'integer', 'min:0', 'max:2147483647'],
            'max_select' => ['required', 'integer', 'min:0', 'max:2147483647', 'gte:min_select'],
            'is_active' => ['required', 'boolean'],
            'options' => ['sometimes', 'array', 'list', 'max:50'],
            'options.*.id' => ['nullable', 'uuid', 'distinct', Rule::exists(ModifierOption::class, 'id')],
            'options.*.name' => ['required', 'string', 'max:255'],
            'options.*.price_delta' => ['bail', 'required', 'string', 'regex:/\A[0-9]{1,12}(?:\.[0-9]{1,2})?\z/'],
            'options.*.sort_order' => ['required', 'integer', 'min:0', 'max:2147483647'],
            'options.*.is_active' => ['required', 'boolean'],
        ];
    }

    /** @return array<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($this->input('semantic_role') !== ModifierSemanticRole::Instruction->value) {
                return;
            }

            foreach ($this->input('options', []) as $index => $option) {
                $price = is_array($option) ? ($option['price_delta'] ?? null) : null;
                if (is_string($price) && preg_match('/\A0+(?:\.0{1,2})?\z/', $price) !== 1) {
                    $validator->errors()->add("options.$index.price_delta", 'Instruction options cannot change the price.');
                }
            }
        }];
    }

    /** @return list<array{id?: string|null, name: string, price_delta: string, sort_order: int, is_active: bool}>|null */
    public function options(): ?array
    {
        if (! $this->has('options')) {
            return null;
        }

        $options = $this->validated('options');

        return is_array($options) ? array_values($options) : null;
    }
}
