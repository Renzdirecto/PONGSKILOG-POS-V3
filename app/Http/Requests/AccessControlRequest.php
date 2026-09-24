<?php

namespace App\Http\Requests;

use App\Models\User;
use App\Support\PermissionCatalog;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AccessControlRequest extends FormRequest
{
    /**
     * Access Control is Super Admin only (`access_control.manage`, which never leaves the Super Admin role).
     */
    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User && $user->is_active && $user->hasPermission('access_control.manage');
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'tab' => ['nullable', Rule::in(['roles', 'staff'])],
            'role' => ['nullable', Rule::in(PermissionCatalog::ROLES)],
            'user' => ['nullable', 'integer', 'min:1'],
            'search' => ['nullable', 'string', 'max:150'],
        ];
    }
}
