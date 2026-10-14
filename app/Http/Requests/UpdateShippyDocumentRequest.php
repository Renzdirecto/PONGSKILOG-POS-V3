<?php

namespace App\Http\Requests;

use App\Models\ShippySetting;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateShippyDocumentRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user() instanceof User && $this->user()->can('update', ShippySetting::class);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return ['is_enabled' => ['required', 'boolean']];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['is_enabled' => 'document switch'];
    }
}
