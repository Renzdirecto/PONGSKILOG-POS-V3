<?php

namespace App\Http\Requests;

use App\Enums\BranchStatus;
use App\Models\Branch;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBranchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Branch::class) ?? false;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:32', 'regex:/\A[A-Z0-9][A-Z0-9_-]*\z/', Rule::unique('branches', 'code')],
            'name' => ['required', 'string', 'max:150'],
            'status' => ['required', Rule::enum(BranchStatus::class)],
            'address' => ['nullable', 'string', 'max:500'],
            'contact' => ['nullable', 'string', 'max:100'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['code.regex' => 'Use uppercase letters, numbers, underscores or hyphens, starting with a letter or number.'];
    }
}
