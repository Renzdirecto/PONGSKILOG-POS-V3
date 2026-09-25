<?php

namespace App\Http\Requests;

use App\Models\User;
use App\Support\PermissionCatalog;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateUserPermissionOverridesRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User && $user->is_active && $user->hasPermission('access_control.manage');
    }

    /**
     * A map of catalog permission => inherit|allow|deny. Unknown keys are rejected, never ignored.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'overrides' => ['present', 'array', 'max:'.count(PermissionCatalog::names())],
            'overrides.*' => ['required', Rule::in(['inherit', 'allow', 'deny'])],
        ];
    }

    /** @return array<int, Closure(Validator): void> */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $overrides = $this->input('overrides');
                if (is_array($overrides) && array_diff(array_keys($overrides), PermissionCatalog::names()) !== []) {
                    $validator->errors()->add('overrides', 'Choose permissions from the list only.');
                }
            },
        ];
    }
}
