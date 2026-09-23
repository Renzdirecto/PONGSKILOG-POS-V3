<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class OwnerDashboardRequest extends FormRequest
{
    /** The standalone Dashboard reporting periods: Today, 7 days and 30 days. */
    public const PERIODS = ['today', 'last_7_days', 'last_30_days'];

    /**
     * The business Dashboard is read-only business-wide reporting: Owner and Super Admin only.
     */
    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User
            && $user->is_active
            && $user->hasPermission('reports.view')
            && $user->hasBusinessWideScope();
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'period' => ['nullable', Rule::in(self::PERIODS)],
        ];
    }
}
