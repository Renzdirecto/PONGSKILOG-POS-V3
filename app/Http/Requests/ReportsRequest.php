<?php

namespace App\Http\Requests;

use App\Models\User;
use App\Support\StoreSessionSalesReport;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ReportsRequest extends FormRequest
{
    /**
     * Reporting is business-wide and read-only: Owner and Super Admin only.
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
            'date' => ['nullable', Rule::in(StoreSessionSalesReport::PRESETS)],
            'from' => ['nullable', 'required_if:date,custom', 'date_format:Y-m-d'],
            'to' => ['nullable', 'required_if:date,custom', 'date_format:Y-m-d', 'after_or_equal:from'],
            'session' => ['nullable', 'uuid'],
        ];
    }

    /** @return array<int, \Closure(Validator): void> */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($this->input('date') !== 'custom' || $validator->errors()->hasAny(['from', 'to'])) {
                    return;
                }
                $from = CarbonImmutable::createFromFormat('!Y-m-d', (string) $this->input('from'), StoreSessionSalesReport::TIMEZONE);
                $to = CarbonImmutable::createFromFormat('!Y-m-d', (string) $this->input('to'), StoreSessionSalesReport::TIMEZONE);
                if ($from instanceof CarbonImmutable && $to instanceof CarbonImmutable
                    && (int) $from->diffInDays($to) + 1 > StoreSessionSalesReport::MAX_CUSTOM_DAYS) {
                    $validator->errors()->add('to', 'Choose a custom range of '.StoreSessionSalesReport::MAX_CUSTOM_DAYS.' days or fewer.');
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
