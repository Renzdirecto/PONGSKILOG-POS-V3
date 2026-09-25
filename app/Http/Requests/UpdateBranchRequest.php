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

    /**
     * A Branch-scoped Settings role edits only the Branch's contact details: its code, name and status stay as they
     * are (business-wide administration), so a changed value is rejected rather than silently ignored.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $branch = $this->route('branch');
        abort_unless($branch instanceof Branch, 404);
        $rules = parent::rules();
        $rules['code'] = ['required', 'string', 'max:32', 'regex:/\A[A-Z0-9][A-Z0-9_-]*\z/', Rule::unique('branches', 'code')->ignore($branch)];
        if (! ($this->user()?->can('updateIdentity', $branch) ?? false)) {
            $unchanged = fn (string $field, string $value): array => [...(array) $rules[$field], Rule::in([$value])];
            $rules['code'] = $unchanged('code', $branch->code);
            $rules['name'] = $unchanged('name', $branch->name);
            $rules['status'] = $unchanged('status', $branch->status->value);
        }

        return $rules;
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        $identity = 'Only a business-wide Settings role can change a Branch code, name or status.';

        return [...parent::messages(), 'code.in' => $identity, 'name.in' => $identity, 'status.in' => $identity];
    }
}
