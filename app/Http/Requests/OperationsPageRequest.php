<?php

namespace App\Http\Requests;

use App\Support\OperationsAccess;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class OperationsPageRequest extends FormRequest
{
    /**
     * Operations pages belong to active business-wide users holding operations.manage (Owner, Super Admin, business-wide
     * Custom Roles). Product inventory (inventory.manage) is a separate permission.
     */
    public function authorize(OperationsAccess $access): bool
    {
        return $access->allows($this->user());
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'plan' => ['nullable', 'uuid'],
            'product' => ['nullable', 'uuid'],
            'mode' => ['nullable', Rule::in(['plan', 'shop'])],
            'scope' => ['nullable', Rule::in(['plan', 'all'])],
            'page' => ['nullable', 'integer', 'min:1', 'max:100000'],
        ];
    }
}
