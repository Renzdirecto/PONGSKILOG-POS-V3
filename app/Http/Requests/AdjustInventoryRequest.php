<?php

namespace App\Http\Requests;

use Closure;
use Illuminate\Foundation\Http\FormRequest;

class AdjustInventoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('inventory.manage') ?? false;
    }

    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        return [
            'quantity_delta' => [
                'bail', 'required',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (! is_int($value) && ! is_string($value)) {
                        $fail('The adjustment must be a whole number.');
                    }
                },
                'integer', 'not_in:0',
            ],
            'reason' => ['required', 'string', 'max:1000'],
        ];
    }
}
