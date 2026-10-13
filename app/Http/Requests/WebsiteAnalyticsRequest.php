<?php

namespace App\Http\Requests;

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
     * Website Analytics is business-wide reporting: an active account with Reports access and a business-wide scope
     * (Owner, Super Admin, business-wide Custom Role). A Branch-scoped account with Reports never reads it.
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
