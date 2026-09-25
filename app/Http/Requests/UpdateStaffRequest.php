<?php

namespace App\Http\Requests;

use App\Concerns\ProfileValidationRules;
use App\Models\User;
use App\Support\StaffRoles;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\File;

class UpdateStaffRequest extends FormRequest
{
    use ProfileValidationRules;

    /**
     * Super Admin access control edits every account; Owner Staff management edits operational Staff only. The
     * current roles of the account are checked here and again, under row locks, in UpdateStaffAccount.
     */
    public function authorize(): bool
    {
        $actor = $this->user();
        $staff = $this->route('user');
        if (! $actor instanceof User || ! $staff instanceof User) {
            return false;
        }
        $manageable = StaffRoles::manageableBy($actor);

        return $manageable !== [] && (StaffRoles::managesEveryAccount($actor) || StaffRoles::canManage($actor, $staff));
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => is_string($this->input('name')) ? trim($this->input('name')) : $this->input('name'),
            'email' => is_string($this->input('email')) ? mb_strtolower(trim($this->input('email'))) : $this->input('email'),
        ]);
    }

    /**
     * The Employee ID is a stable identity and is not accepted here.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $role = $this->input('role');
        $requiresBranch = is_string($role) && ! StaffRoles::isBusinessWide($role);
        $staff = $this->route('user');
        $staffId = $staff instanceof User ? $staff->id : null;
        $actor = $this->user();

        return [
            'name' => $this->nameRules(),
            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                function (string $attribute, mixed $value, Closure $fail) use ($staffId): void {
                    if (is_string($value) && User::query()->whereRaw('LOWER(email) = ?', [$value])->whereKeyNot($staffId)->exists()) {
                        $fail('This email is already used by another account.');
                    }
                },
            ],
            'role' => ['required', 'string', Rule::in($actor instanceof User ? StaffRoles::manageableBy($actor) : []), Rule::exists('roles', 'name')->whereNull('archived_at')],
            'branch_ids' => $requiresBranch ? ['required', 'array', 'min:1'] : ['prohibited'],
            'branch_ids.*' => ['required', 'uuid', 'distinct', Rule::exists('branches', 'id')],
            'is_active' => ['required', 'boolean'],
            'avatar' => ['nullable', File::image(allowSvg: false)->types(['jpg', 'jpeg', 'png', 'webp'])->max('2mb')->dimensions(
                Rule::dimensions()->minWidth(64)->minHeight(64)->maxWidth(8000)->maxHeight(8000),
            )],
            'remove_avatar' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        $role = $this->input('role');

        return [
            'branch_ids.required' => 'Choose at least one active Branch for this role.',
            'branch_ids.min' => 'Choose at least one active Branch for this role.',
            'branch_ids.prohibited' => StaffRoles::branchesProhibitedMessage($role),
            'branch_ids.*.exists' => 'Choose active Branches only.',
            'branch_ids.*.uuid' => 'Choose active Branches only.',
            'role.in' => 'Choose a valid role.',
            'role.exists' => 'Choose a valid role.',
        ];
    }
}
