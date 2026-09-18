<?php

namespace App\Http\Requests;

use App\Models\Branch;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

class UpdateBranchRequest extends StoreBranchRequest
{
    public function authorize(): bool
    {
        $branch = $this->route('branch');

        return $branch instanceof Branch && ($this->user()?->can('update', $branch) ?? false);
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        $branch = $this->route('branch');
        abort_unless($branch instanceof Branch, 404);
        $rules = parent::rules();
        $rules['code'] = ['required', 'string', 'max:32', 'regex:/\A[A-Z0-9][A-Z0-9_-]*\z/', Rule::unique('branches', 'code')->ignore($branch)];

        return $rules;
    }
}
