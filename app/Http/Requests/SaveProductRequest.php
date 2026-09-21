<?php

namespace App\Http\Requests;

use App\Enums\ModifierSelectionType;
use App\Enums\ModifierSemanticRole;
use App\Models\Branch;
use App\Models\ModifierGroup;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('products.manage') ?? false;
    }

    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        return [
            'modifier_group_ids' => ['present', 'array', 'list'],
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
}
