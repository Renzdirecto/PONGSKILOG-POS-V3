<?php

namespace App\Http\Requests\Settings;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Self-service profile (Phase 20): only the display-oriented Preferred Name. The full name, sign-in e-mail, Employee
 * ID, Position, Role, Branch assignments and status stay admin-managed in Staff administration.
 */
class ProfileUpdateRequest extends FormRequest
{
    /** Letters (any script), spaces, periods, apostrophes and hyphens: a name, never markup or a URL. */
    public const PREFERRED_NAME_PATTERN = "/\A[\pL\pM][\pL\pM .'’-]*\z/u";

    /** Whitespace is collapsed; a blank value clears the Preferred Name. */
    protected function prepareForValidation(): void
    {
        $value = $this->input('preferred_name');
        if (is_string($value)) {
            $value = trim((string) preg_replace('/\s+/u', ' ', $value));
            $this->merge(['preferred_name' => $value === '' ? null : $value]);
        }
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'preferred_name' => ['present', 'nullable', 'string', 'max:60', 'regex:'.self::PREFERRED_NAME_PATTERN],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'preferred_name.regex' => 'Use letters, spaces, periods, apostrophes or hyphens only.',
        ];
    }
}
