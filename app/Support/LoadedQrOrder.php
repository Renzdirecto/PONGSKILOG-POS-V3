<?php

namespace App\Support;

use App\Enums\CommercialStatus;
use App\Enums\KitchenStatus;
use App\Enums\OrderSource;
use App\Enums\PaymentStatus;
use App\Models\Branch;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class LoadedQrOrder
{
    public function __construct(private OrderSnapshots $snapshots) {}

    public function eligible(Order $order, User $user): bool
    {
        return $order->source === OrderSource::CustomerQr
            && $order->commercial_status === CommercialStatus::Submitted
            && $order->loaded_by_user_id === $user->id && $order->archived_at === null
            && $order->payment_status === PaymentStatus::Unpaid && $order->payment_term === null
            && $order->kitchen_status === KitchenStatus::NotSent && $order->committed_at === null;
    }

    /** @return array<string, array<mixed>> */
    public static function metadataRules(): array
    {
        return ['qr_metadata' => ['sometimes', 'array:customer_label,branch_table_id'],
            'qr_metadata.customer_label' => ['nullable', 'string', 'max:150'],
            'qr_metadata.branch_table_id' => ['nullable', 'uuid'],
            /** Items the Cashier adds to a loaded QR order; the customer's submitted items stay as submitted. */
            'qr_additional_items' => ['sometimes', 'array', 'list', 'max:100'],
            'qr_additional_items.*.product_id' => ['required', 'uuid'],
            'qr_additional_items.*.quantity' => ['required', 'integer', 'min:1', 'max:999'],
            'qr_additional_items.*.notes' => ['nullable', 'string', 'max:1000'],
            'qr_additional_items.*.modifiers' => ['present', 'array', 'list', 'max:50'],
            'qr_additional_items.*.modifiers.*.group_id' => ['required', 'uuid'],
            'qr_additional_items.*.modifiers.*.option_id' => ['required', 'uuid'],
        ];
    }

    /**
     * Appends the Cashier's additional items to the locked, loaded QR order inside the commit transaction, through the
     * canonical order snapshot engine (availability, Groups, Instruction pricing, Product stock and Recipe pre-check;
     * the authoritative stock checks still run when the order commits). Submitted items and their prices never change.
     *
     * @param  array{qr_additional_items?: list<array{product_id: string, quantity: int|string, notes?: string|null, modifiers: list<array{group_id: string, option_id: string}>}>}  $data  validated by metadataRules()
     */
    public function appendItems(Order $order, Branch $branch, array $data): void
    {
        $lines = $data['qr_additional_items'] ?? [];
        if ($lines === []) {
            return;
        }
        abort_unless($order->source === OrderSource::CustomerQr, 422);
        $snapshot = $this->snapshots->prepare($branch, [
            'order_type' => $order->order_type->value,
            'items' => array_map(fn (array $line): array => [
                'product_id' => strtolower($line['product_id']),
                'quantity' => (int) $line['quantity'],
                'notes' => is_string($line['notes'] ?? null) && trim($line['notes']) !== '' ? trim($line['notes']) : null,
                'modifiers' => $line['modifiers'],
            ], $lines),
        ], $order->id, lockCatalog: true);
        $this->snapshots->persist($snapshot);
        $added = ExactMoney::cents((string) $snapshot['attributes']['subtotal']);
        $order->subtotal = ExactMoney::decimal(ExactMoney::add(ExactMoney::cents((string) $order->subtotal), $added));
        $order->total = ExactMoney::decimal(ExactMoney::add(ExactMoney::cents((string) $order->total), $added));
        $order->unsetRelation('items');
    }

    /** @param array<string, mixed> $data */
    public function applyMetadata(Order $order, Branch $branch, array $data): void
    {
        if (isset($data['qr_metadata'])) {
            abort_unless($order->source === OrderSource::CustomerQr, 422);
            $metadata = $data['qr_metadata'];
            $order->customer_label = trim($metadata['customer_label'] ?? '') ?: null;
            $order->branch_table_id = $metadata['branch_table_id'] ?? null;
        }
        $this->validateTable($order, $branch);
        $order->table_name_snapshot = $order->branch_table_id === null ? null : $branch->tables()->whereKey($order->branch_table_id)->value('name');
    }

    /** @param array<string, mixed> $data */
    public function validateReplay(Order $order, array $data): void
    {
        if (isset($data['qr_metadata'])) {
            abort_unless(($order->customer_label ?? '') === trim($data['qr_metadata']['customer_label'] ?? '')
                && $order->branch_table_id === ($data['qr_metadata']['branch_table_id'] ?? null), 409, 'This attempt belongs to different order information.');
        }
        /** A replay may only recover a commit whose Order already holds every additional item it sends. */
        if (empty($data['qr_additional_items'])) {
            return;
        }
        $available = $order->items()->with('modifiers')->get()->map(fn (OrderItem $item): string => self::lineSignature(
            (string) $item->product_id, $item->quantity, $item->notes, $item->modifiers->pluck('modifier_option_id')->all(),
        ))->all();
        foreach ($data['qr_additional_items'] as $line) {
            $index = array_search(self::lineSignature(strtolower($line['product_id']), (int) $line['quantity'], $line['notes'] ?? null, array_column($line['modifiers'], 'option_id')), $available, true);
            abort_if($index === false, 409, 'This attempt belongs to different order items.');
            unset($available[$index]);
        }
    }

    /** @param array<int, mixed> $optionIds */
    private static function lineSignature(string $productId, int $quantity, ?string $notes, array $optionIds): string
    {
        $options = array_map(fn (mixed $id): string => strtolower((string) $id), $optionIds);
        sort($options);

        return json_encode([strtolower($productId), $quantity, trim($notes ?? ''), $options], JSON_THROW_ON_ERROR);
    }

    public function validateTable(Order $order, Branch $branch): void
    {
        if ($order->branch_table_id !== null && ! $branch->tables()->whereKey($order->branch_table_id)->where('is_active', true)->sharedLock()->first()) {
            throw ValidationException::withMessages(['table' => 'The selected table is no longer active in this branch.']);
        }
    }
}
