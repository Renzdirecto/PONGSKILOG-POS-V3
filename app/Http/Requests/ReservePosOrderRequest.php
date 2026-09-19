<?php

namespace App\Http\Requests;

use App\Enums\OrderType;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReservePosOrderRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User && ($user->hasRole('cashier') || $user->hasRole('cashier_kitchen'));
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return [
            'order_type' => ['required', Rule::enum(OrderType::class)],
        ];
    }
}
