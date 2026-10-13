<?php

namespace App\Http\Requests;

use App\Models\LandingTeamMember;
use App\Models\User;
use App\Support\ReportPeriod;
use App\Support\WebsiteAnalytics;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class WebsiteAnalyticsRequest extends FormRequest
{
    /**
     * Website is business-wide: an active account with a business-wide scope that either reads Reports (the Analytics
     * tab: Owner, Super Admin, business-wide Custom Role) or manages website content (Reviews, Team and Shippy: Owner
     * and Super Admin). A Branch-scoped account never opens it; each tab's own data keeps its own check.
     */
    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User
            && $user->is_active
            && $user->hasBusinessWideScope()
            && ($user->hasPermission('reports.view') || $user->can('viewAny', LandingTeamMember::class));
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'period' => ['nullable', Rule::in(WebsiteAnalytics::PERIODS)],
            'from' => ['nullable', 'required_if:period,custom', 'date_format:Y-m-d'],
            'to' => ['nullable', 'required_if:period,custom', 'date_format:Y-m-d', 'after_or_equal:from'],
        ];
    }

    /** @return array<int, \Closure(Validator): void> */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($this->input('period') !== 'custom' || $validator->errors()->hasAny(['from', 'to'])) {
                    return;
                }
                $from = ReportPeriod::day((string) $this->input('from'));
                $to = ReportPeriod::day((string) $this->input('to'));
                if ($from !== null && $to !== null && (int) $from->diffInDays($to) + 1 > ReportPeriod::MAX_CUSTOM_DAYS) {
                    $validator->errors()->add('to', 'Choose a custom range of '.ReportPeriod::MAX_CUSTOM_DAYS.' days or fewer.');
                }
            },
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'from.required_if' => 'Choose a start date for the custom range.',
            'to.required_if' => 'Choose an end date for the custom range.',
            'to.after_or_equal' => 'The end date must be on or after the start date.',
        ];
    }
}
