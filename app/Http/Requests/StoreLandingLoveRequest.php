<?php

namespace App\Http\Requests;

use App\Support\LandingLove;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreLandingLoveRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            /** Identifies one batch of taps, so a retried or doubled request is counted once. */
            'key' => ['required', 'uuid'],
            'taps' => ['required', 'integer', 'min:1', 'max:'.LandingLove::MAX_TAPS],
        ];
    }
}
