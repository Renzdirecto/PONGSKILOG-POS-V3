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
     * The Dashboard is read-only reporting for every account with Reports access. Business-wide accounts read All
     * Branches or the selected Branch; a Branch-scoped account reads only its selected assigned Branch (the controller
     * sends it to pick one first and never shows All Branches).
     */
    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User
            && $user->is_active
            && $user->hasPermission('reports.view');
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
