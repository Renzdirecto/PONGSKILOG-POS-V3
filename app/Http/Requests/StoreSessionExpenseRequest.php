<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\File;

class StoreSessionExpenseRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User
            && $user->is_active
            && $user->hasPermission('store_expenses.manage')
            && ($user->hasRole('cashier') || $user->hasRole('cashier_kitchen'));
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return self::expenseRules();
    }

    /** @return array<string, list<mixed>> */
    public static function expenseRules(): array
    {
        return [
            'idempotency_key' => ['required', 'uuid'],
            'description' => ['required', 'string', 'max:150'],
            'amount' => ['required', 'string', 'regex:/\A[0-9]{1,12}(?:\.[0-9]{1,2})?\z/', 'not_regex:/\A0+(?:\.0{1,2})?\z/'],
            'payment_source' => ['required', Rule::in(['cash', 'cashless'])],
            'note' => ['nullable', 'string', 'max:2000'],
            'restock' => ['required', 'boolean'],
            'product_id' => ['nullable', 'required_if:restock,true', 'prohibited_unless:restock,true', 'uuid'],
            'quantity' => ['nullable', 'required_if:restock,true', 'prohibited_unless:restock,true', 'integer', 'min:1'],
            'receipt' => ['nullable', File::image(allowSvg: false)->types(['jpg', 'jpeg', 'png', 'webp'])->max('2mb')->dimensions(
                Rule::dimensions()->minWidth(64)->minHeight(64)->maxWidth(8000)->maxHeight(8000),
            )],
        ];
    }

    protected function prepareForValidation(): void
    {
        $description = $this->input('description');
        $note = $this->input('note');
        $this->merge([
            'description' => is_string($description) ? trim($description) : $description,
            'note' => is_string($note) && trim($note) !== '' ? trim($note) : null,
            'restock' => $this->boolean('restock'),
        ]);
    }
}
