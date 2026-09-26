<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Turn notifications off for this browser. The endpoint is optional: the device cookie already identifies the
 * browser, and only the signed-in account's own subscriptions are ever removed.
 */
class DestroyPushSubscriptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() instanceof User && $this->user()->is_active;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'endpoint' => ['sometimes', 'nullable', 'string', 'max:2048'],
        ];
    }
}
