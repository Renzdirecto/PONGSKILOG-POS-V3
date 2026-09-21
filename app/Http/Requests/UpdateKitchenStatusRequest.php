<?php

namespace App\Http\Requests;

use App\Enums\KitchenStatus;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateKitchenStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User
            && $user->is_active
            && ($user->hasPermission('kitchen.access') || $user->hasPermission('pos.access'));
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'status' => [
                'required',
                'string',
                Rule::in([
                    KitchenStatus::Kitchen->value,
                    KitchenStatus::Preparing->value,
                    KitchenStatus::Ready->value,
                    KitchenStatus::Done->value,
                ]),
            ],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'status.required' => 'Choose a kitchen status.',
            'status.in' => 'Choose a valid kitchen status.',
        ];
    }
}
