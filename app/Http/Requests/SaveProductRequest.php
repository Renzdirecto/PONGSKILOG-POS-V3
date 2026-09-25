<?php

namespace App\Http\Requests;

use App\Enums\ModifierSelectionType;
use App\Enums\ModifierSemanticRole;
use App\Models\Branch;
use App\Models\ModifierGroup;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SaveProductRequest extends FormRequest
{
    /**
     * The editor submits multipart form data (for the optional image), which cannot carry an empty list, so a Product
     * saved with zero Groups arrives without `modifier_group_ids`. An absent key means "no Groups"; any value that is
     * sent (a string, null, a map) is still validated and rejected as malformed.
     */
    protected function prepareForValidation(): void
    {
        if (! $this->exists('modifier_group_ids')) {
            $this->merge(['modifier_group_ids' => []]);
        }

        $groups = $this->input('inline_groups');

        if (! is_array($groups)) {
            return;
        }

        foreach ($groups as &$group) {
            if (is_array($group) && ($group['semantic_role'] ?? null) === ModifierSemanticRole::Instruction->value) {
                $group['selection_type'] = ModifierSelectionType::Multiple->value;
                $group['min_select'] = 0;
                $group['max_select'] = max(2, (int) ($group['max_select'] ?? 3));
            }
        }

        $this->merge(['inline_groups' => $groups]);
    }

    public function authorize(): bool
    {
        return $this->user()?->can('catalog.define') ?? false;
    }

    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        return [
            'modifier_group_ids' => ['array', 'list'],
            'modifier_group_ids.*' => ['bail', 'required', 'uuid', 'distinct', Rule::exists(ModifierGroup::class, 'id')],
            'inline_groups' => ['sometimes', 'array', 'list', 'max:20'],
            'inline_groups.*.name' => ['required', 'string', 'max:255'],
            'inline_groups.*.semantic_role' => ['nullable', Rule::enum(ModifierSemanticRole::class)],
            'inline_groups.*.selection_type' => ['required', Rule::enum(ModifierSelectionType::class)],
            'inline_groups.*.min_select' => ['required', 'integer', 'min:0', 'max:2147483647'],
            'inline_groups.*.max_select' => ['required', 'integer', 'min:0', 'max:2147483647', 'gte:inline_groups.*.min_select'],
            'inline_groups.*.is_active' => ['required', 'boolean'],
            'inline_groups.*.options' => ['required', 'array', 'list', 'min:1', 'max:50'],
            'inline_groups.*.options.*.name' => ['required', 'string', 'max:255'],
            'inline_groups.*.options.*.price_delta' => ['bail', 'required', 'string', 'regex:/\A[0-9]{1,12}(?:\.[0-9]{1,2})?\z/'],
            'inline_groups.*.options.*.sort_order' => ['required', 'integer', 'min:0', 'max:2147483647'],
            'inline_groups.*.options.*.is_active' => ['required', 'boolean'],
            'branch_configs' => ['sometimes', 'array', 'list'],
            'branch_configs.*.branch_id' => ['required', 'uuid', 'distinct', Rule::exists(Branch::class, 'id')],
            'branch_configs.*.price_override' => ['present', 'nullable', 'string', 'regex:/\A[0-9]{1,12}(?:\.[0-9]{1,2})?\z/'],
            'branch_configs.*.is_available' => ['required', 'boolean'],
            'branch_configs.*.tracks_inventory' => ['required', 'boolean'],
            'branch_configs.*.low_stock_threshold' => ['present', 'nullable', 'integer', 'min:0', 'max:2147483647'],
            'image' => ['sometimes', 'nullable', 'file'],
        ];
    }

    /** @return array<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach ($this->input('inline_groups', []) as $groupIndex => $group) {
                if (! is_array($group) || ($group['semantic_role'] ?? null) !== ModifierSemanticRole::Instruction->value) {
                    continue;
                }

                foreach ($group['options'] ?? [] as $optionIndex => $option) {
                    $price = is_array($option) ? ($option['price_delta'] ?? null) : null;
                    if (is_string($price) && preg_match('/\A0+(?:\.0{1,2})?\z/', $price) !== 1) {
                        $validator->errors()->add(
                            "inline_groups.$groupIndex.options.$optionIndex.price_delta",
                            'Instruction options cannot change the price.',
                        );
                    }
                }
            }
        }];
    }
}
