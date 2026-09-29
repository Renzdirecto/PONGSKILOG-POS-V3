<?php

namespace App\Support;

use App\Enums\ModifierSemanticRole;
use App\Enums\PaymentStatus;
use App\Enums\ReceiptAudience;
use App\Models\Branch;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemModifier;
use App\Models\Payment;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

/**
 * THE receipt (Phase 20): one data contract for every receipt surface — POS print, Transaction History reprint, the
 * shared receipt link, the Customer QR receipt and the Take Out pickup receipt — rendered by one component
 * (`ReceiptDocument` in React). Branch Receipt Settings (`ReceiptLayout` + the receipt_* columns) decide which blocks
 * show and in what order; the audience only removes what that viewer may not see.
 *
 * Every amount, item, option and note comes from the Order's stored snapshots and Payment rows, never today's catalog.
 * Items are listed in the order they were added, options Size → Add-ons → Instructions (each kind in the order chosen), payments by time. The cashier
 * is shown by customer-facing name (Preferred Name, else first name); audit identity is untouched.
 */
class ReceiptDocument
{
    public function __construct(private OrderMoney $money) {}

    /**
     * Relations are always re-read: a receipt is often built right after an edit, payment or settlement changed them.
     *
     * @return array<string, mixed>
     */
    public function for(Order $order, ReceiptAudience $audience): array
    {
        $order->load([
            'branch', 'branchTable', 'adjustments', 'items.modifiers', 'payments.createdBy',
            ...($audience === ReceiptAudience::Staff ? ['payments.invoiceProof'] : []),
        ]);
        $branch = $order->branch;
        $layout = ReceiptLayout::normalize($branch->receipt_layout);
        $blocks = ReceiptLayout::blocks($layout, (bool) $branch->receipt_show_logo);
        $shows = fn (string $block): bool => in_array($block, $blocks, true);
        $money = $this->money->totals($order);
        $payments = $order->payments->sortBy([['paid_at', 'asc'], ['id', 'asc']])->values();
        $items = $order->items->sortBy([['created_at', 'asc'], ['id', 'asc']])->values();
        $customer = $audience->showsCustomerDetails();
        $staff = $audience === ReceiptAudience::Staff;

        $document = [
            'order_number' => $order->order_number,
            'reference_number' => $order->reference_number,
            'order_type' => $order->order_type->value,
            'customer_label' => $customer ? $order->customer_label : null,
            'table_name' => $customer ? ($order->table_name_snapshot ?? $order->branchTable?->name) : null,
            'commercial_status' => $order->commercial_status->value,
            'payment_status' => $order->payment_status->value,
            'committed_at' => $order->committed_at?->toIso8601String(),
            /** When the order became fully paid (its receipt moment), else null. */
            'paid_at' => $order->payment_status === PaymentStatus::Paid ? $payments->max('paid_at')?->toIso8601String() : null,
            'cashier' => $shows('cashier') ? $payments->first()?->createdBy?->customerFacingName() : null,
            'subtotal' => $order->subtotal,
            'total' => $order->total,
            'money' => [
                'paid' => ExactMoney::decimal($money['settled']),
                'refunded' => ExactMoney::decimal($money['adjustments']),
                'balance' => ExactMoney::decimal($money['outstanding']),
            ],
            'branch' => [
                'name' => $branch->receipt_name ?? $branch->name,
                'code' => $branch->code,
                'address' => $shows('address') ? ($branch->receipt_address ?? $branch->address) : null,
                'contact' => $shows('contact') ? ($branch->receipt_contact ?? $branch->contact) : null,
                'footer' => $shows('footer') ? $branch->receipt_footer : null,
                'show_logo' => (bool) $branch->receipt_show_logo,
                'logo_url' => self::logoUrl($branch),
            ],
            'layout' => [
                'blocks' => $blocks,
                'separator' => $layout['separator'],
                'header_text' => $shows('header_text') ? $layout['header_text'] : null,
                'custom_rows' => $shows('custom_rows') ? $layout['custom_rows'] : [],
                'order_qr' => $shows('order_qr') ? self::orderQr($branch) : null,
            ],
            'items' => $items->map(fn (OrderItem $item): array => [
                ...($staff ? ['id' => $item->id] : []),
                ...OperationalItemName::fromOrderItem($item),
                'quantity' => $item->quantity,
                'unit_price' => $item->unit_price,
                'line_total' => $item->line_total,
                'notes' => $customer ? $item->notes : null,
                'modifiers' => $item->modifiers->sort(fn (OrderItemModifier $a, OrderItemModifier $b): int => [self::modifierRank($a), $a->id] <=> [self::modifierRank($b), $b->id])
                    ->values()->map(fn (OrderItemModifier $modifier): array => [
                        ...($staff ? ['id' => $modifier->id] : []),
                        'group_name' => $modifier->group_name_snapshot,
                        'semantic_role' => $modifier->semantic_role_snapshot,
                        'name' => $modifier->option_name_snapshot,
                        'price_delta' => $modifier->price_delta_snapshot,
                        'quantity' => $modifier->quantity,
                    ])->all(),
            ])->all(),
            'payments' => $payments->map(fn (Payment $payment): array => [
                ...($staff ? ['id' => $payment->id] : []),
                'method' => $payment->method->value,
                'amount' => $payment->amount,
                'amount_received' => $payment->amount_received,
                'change_amount' => $payment->change_amount,
                ...($staff ? [
                    'payment_group_id' => $payment->payment_group_id ?? preg_replace('/:(cash|cashless)$/', '', $payment->idempotency_key),
                    'payment_context' => $payment->payment_context,
                    'invoice' => $payment->invoiceProof === null ? null : [
                        'name' => $payment->invoiceProof->original_name,
                        'url' => route('pos.payments.invoice.show', $payment, false),
                    ],
                ] : []),
            ])->all(),
        ];

        if (! $staff) {
            return $document;
        }

        return [
            'id' => $order->id,
            ...$document,
            'voided_at' => $order->voided_at?->toIso8601String(),
            'amount_paid' => ExactMoney::decimal($money['payments']),
            'adjustment_total' => ExactMoney::decimal($money['adjustments']),
            'outstanding' => ExactMoney::decimal($money['outstanding']),
            'store_session_id' => $order->store_session_id,
        ];
    }

    /** Size, then add-ons, then instructions; within a kind the order they were chosen (time-ordered ids). */
    private static function modifierRank(OrderItemModifier $modifier): int
    {
        return match ($modifier->semantic_role_snapshot) {
            ModifierSemanticRole::Size->value => 0,
            ModifierSemanticRole::Instruction->value => 2,
            default => 1,
        };
    }

    /** The Branch receipt logo (versioned by its stored path) or the PONGSKILOG logo. */
    public static function logoUrl(Branch $branch): string
    {
        return $branch->receipt_logo_path
            ? route('branches.receipt-logo', $branch, false).'?v='.md5($branch->receipt_logo_path)
            : '/images/branding/logo.png';
    }

    /**
     * "Order again" QR: the Branch's own Customer QR menu, only while QR ordering is on.
     *
     * @return array{url: string, image: string}|null
     */
    public static function orderQr(Branch $branch): ?array
    {
        if (! $branch->qr_ordering_enabled || blank($branch->kiosk_code)) {
            return null;
        }
        $url = route('kiosk.show', ['branch' => $branch->kiosk_code]);
        $writer = new Writer(new ImageRenderer(new RendererStyle(240), new SvgImageBackEnd));

        return ['url' => $url, 'image' => 'data:image/svg+xml;base64,'.base64_encode($writer->writeString($url))];
    }
}
