<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class InventoryIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('inventory.manage') ?? false;
    }

    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        return [
            'branch_id' => ['nullable', 'uuid', Rule::exists('branches', 'id')],
            'search' => ['nullable', 'string', 'max:255'],
            'type' => ['nullable', Rule::in(['all', 'products', 'ingredients'])],
            'stock_status' => ['nullable', Rule::in(['all', 'in_stock', 'low_stock', 'out_of_stock', 'not_tracked'])],
            'category' => ['nullable', 'uuid', Rule::exists('categories', 'id')],
            'history_product' => ['nullable', 'uuid', Rule::exists('products', 'id')],
            'history_page' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
