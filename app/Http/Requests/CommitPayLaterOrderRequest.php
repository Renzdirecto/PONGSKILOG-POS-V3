<?php

namespace App\Http\Requests;

use App\Models\User;
use App\Support\LoadedQrOrder;
use Illuminate\Contracts\Validation\ValidationRule;

class CommitPayLaterOrderRequest extends StorePosDraftOrderRequest
{
    /** @var list<string> */
    private const CART_FIELDS = ['order_type', 'branch_table_id', 'customer_label', 'items'];

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User && $user->hasCashierOperationsRole();
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return self::commitRules(self::hasCartData($this->all()));
    }

    /** @param array<string, mixed> $input */
    public static function hasCartData(array $input): bool
    {
        return array_intersect(self::CART_FIELDS, array_keys($input)) !== [];
    }

    /** @return array<string, array<mixed>> */
    public static function commitRules(bool $localCart): array
    {
        return [
            ...($localCart ? self::draftRules() : [
                'order_type' => ['prohibited'],
                'branch_table_id' => ['prohibited'],
                'customer_label' => ['prohibited'],
                'items' => ['prohibited'],
            ]),
            'reserved_order_id' => ['prohibited'],
            'idempotency_key' => ['required', 'uuid'],
            ...LoadedQrOrder::metadataRules(),
            'branch_id' => ['prohibited'],
            'store_session_id' => ['prohibited'],
            'subtotal' => ['prohibited'],
            'total' => ['prohibited'],
            'stock_amount' => ['prohibited'],
            'line_price' => ['prohibited'],
            'commercial_status' => ['prohibited'],
            'payment_status' => ['prohibited'],
            'payment_term' => ['prohibited'],
            'kitchen_status' => ['prohibited'],
            'order_number' => ['prohibited'],
            'reference_number' => ['prohibited'],
        ];
    }
}
