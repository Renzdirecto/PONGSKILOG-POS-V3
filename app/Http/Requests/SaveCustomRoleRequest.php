<?php

namespace App\Http\Requests;

use App\Models\User;
use App\Support\CustomRoles;
use App\Support\PermissionCatalog;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveCustomRoleRequest extends FormRequest
{
    /**
     * The Custom Role Builder is Super Admin only (`access_control.manage`, which never leaves the Super Admin role).
     */
    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User && $user->is_active && $user->hasPermission('access_control.manage');
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['label' => CustomRoles::normalizeLabel($this->input('label'))]);
    }

    /**
     * Shape only; the name's uniqueness and the scope's grant envelope are checked again inside the save transaction.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'label' => ['required', 'string', 'max:'.CustomRoles::LABEL_MAX, 'regex:'.CustomRoles::LABEL_PATTERN],
            'scope' => ['required', 'string', Rule::in(array_keys(PermissionCatalog::CUSTOM_GRANTABLE))],
            'permissions' => ['present', 'array', 'max:'.count(PermissionCatalog::names())],
            'permissions.*' => ['string', 'distinct', Rule::in(PermissionCatalog::names())],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'label.required' => 'Role name is required.',
            'label.max' => 'Use at most '.CustomRoles::LABEL_MAX.' characters.',
            'label.regex' => 'Use letters, numbers, spaces or & + - / ( ) . \' , only.',
            'scope.required' => 'Choose where this role works.',
            'scope.in' => 'Choose Branch or Business-wide scope.',
            'permissions.*.in' => 'Choose permissions from the list only.',
        ];
    }
}
