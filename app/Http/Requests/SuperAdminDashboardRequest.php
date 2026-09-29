<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SuperAdminDashboardRequest extends FormRequest
{
    /**
     * The Executive Dashboard is the Super Admin Control Center landing page (`access_control.manage`).
     */
    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User && $user->is_active && $user->hasPermission('access_control.manage');
    }

    /**
     * The same periods as the Owner Dashboard: Today, 7 days and 30 days.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'period' => ['nullable', Rule::in(OwnerDashboardRequest::PERIODS)],
        ];
    }
}
