<?php

namespace App\Http\Requests;

use App\Models\User;
use App\Support\SalesAnalytics;
use App\Support\StoreSessionSalesReport;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ReportsRequest extends FormRequest
{
    /**
     * Read-only reporting for any account whose effective permissions include Reports. Owner and Super Admin report on
     * every Branch; a Branch-scoped account (custom Reports access) is limited to its selected assigned Branch by
     * ReportsController, never All Branches.
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
            'date' => ['nullable', Rule::in(StoreSessionSalesReport::PRESETS)],
            'from' => ['nullable', 'required_if:date,custom', 'date_format:Y-m-d'],
            'to' => ['nullable', 'required_if:date,custom', 'date_format:Y-m-d', 'after_or_equal:from'],
            'session' => ['nullable', 'uuid'],
            'order_types' => ['nullable', 'array', 'max:'.count(SalesAnalytics::ORDER_TYPES)],
            'order_types.*' => ['string', 'distinct', Rule::in(array_keys(SalesAnalytics::ORDER_TYPES))],
            'payment_methods' => ['nullable', 'array', 'max:'.count(SalesAnalytics::PAYMENT_METHODS)],
            'payment_methods.*' => ['string', 'distinct', Rule::in(array_keys(SalesAnalytics::PAYMENT_METHODS))],
            'cashiers' => ['nullable', 'array', 'max:50'],
            'cashiers.*' => ['integer', 'distinct', 'min:1'],
            'categories' => ['nullable', 'array', 'max:100'],
            'categories.*' => ['string', 'distinct', 'regex:'.SalesAnalytics::CATEGORY_PATTERN],
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
            'categories.*.regex' => 'Choose a category from the list.',
        ];
    }
}
