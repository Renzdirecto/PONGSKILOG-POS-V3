<?php

namespace App\Http\Requests;

use App\Models\User;
use App\Support\PermissionCatalog;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateRolePermissionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User && $user->is_active && $user->hasPermission('access_control.manage');
    }

    /**
     * Only known catalog names are accepted; the grant envelope is checked again in UpdateRolePermissions.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'permissions' => ['present', 'array', 'max:'.count(PermissionCatalog::names())],
            'permissions.*' => ['string', 'distinct', Rule::in(PermissionCatalog::names())],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['permissions.*.in' => 'Choose permissions from the list only.'];
    }
}
