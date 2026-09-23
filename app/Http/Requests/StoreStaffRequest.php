<?php

namespace App\Http\Requests;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Enums\BranchStatus;
use App\Models\User;
use App\Support\StaffRoles;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\File;

class StoreStaffRequest extends FormRequest
{
    use PasswordValidationRules, ProfileValidationRules;

    /**
     * Staff account creation is Super Admin access control (every role) or Owner Staff management (operational roles),
     * enforced server-side. Each Staff route additionally requires its own permission middleware.
     */
    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User && StaffRoles::manageableBy($user) !== [];
    }

    /** @return list<string> */
    private function manageableRoles(): array
    {
        $user = $this->user();

        return $user instanceof User ? StaffRoles::manageableBy($user) : [];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => is_string($this->input('name')) ? trim($this->input('name')) : $this->input('name'),
            'employee_id' => is_string($this->input('employee_id')) ? trim($this->input('employee_id')) : $this->input('employee_id'),
            'email' => is_string($this->input('email')) ? mb_strtolower(trim($this->input('email'))) : $this->input('email'),
        ]);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $role = $this->input('role');
        $requiresBranch = is_string($role) && ! StaffRoles::isBusinessWide($role);

        return [
            'name' => $this->nameRules(),
            /** Super Admin assigns it manually as MMDDYY plus a two-digit number, for example 09242601. */
            'employee_id' => [
                'required',
                'string',
                'regex:/\A(0[1-9]|1[0-2])(0[1-9]|[12][0-9]|3[01])[0-9]{2}[0-9]{2}\z/',
                Rule::unique(User::class, 'employee_id'),
            ],
            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (is_string($value) && User::query()->whereRaw('LOWER(email) = ?', [$value])->exists()) {
                        $fail('This email is already used by another account.');
                    }
                },
            ],
            'password' => $this->passwordRules(),
            'role' => ['required', 'string', Rule::in($this->manageableRoles()), Rule::exists('roles', 'name')],
            'branch_ids' => $requiresBranch
                ? ['required', 'array', 'min:1']
                : ['prohibited'],
            'branch_ids.*' => [
                'required',
                'uuid',
                'distinct',
                Rule::exists('branches', 'id')->where('status', BranchStatus::Active->value),
            ],
            'is_active' => ['sometimes', 'boolean'],
            'avatar' => ['nullable', File::image(allowSvg: false)->types(['jpg', 'jpeg', 'png', 'webp'])->max('2mb')->dimensions(
                Rule::dimensions()->minWidth(64)->minHeight(64)->maxWidth(8000)->maxHeight(8000),
            )],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'employee_id.regex' => 'Use MMDDYY followed by a two-digit number, for example 09242601.',
            'employee_id.unique' => 'This Employee ID is already used by another account.',
            'branch_ids.required' => 'Choose at least one active Branch for this role.',
            'branch_ids.min' => 'Choose at least one active Branch for this role.',
            'branch_ids.prohibited' => 'Owner and Super Admin accounts have business-wide access and do not take Branch assignments.',
            'branch_ids.*.exists' => 'Choose active Branches only.',
            'branch_ids.*.uuid' => 'Choose active Branches only.',
            'role.in' => 'Choose a valid role.',
            'role.exists' => 'Choose a valid role.',
        ];
    }
}
