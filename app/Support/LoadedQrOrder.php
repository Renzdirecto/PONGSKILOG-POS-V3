<?php

namespace App\Support;

use App\Enums\CommercialStatus;
use App\Enums\KitchenStatus;
use App\Enums\OrderSource;
use App\Enums\PaymentStatus;
use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class LoadedQrOrder
{
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
            'qr_metadata.branch_table_id' => ['nullable', 'uuid']];
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
    }

    public function validateTable(Order $order, Branch $branch): void
    {
        if ($order->branch_table_id !== null && ! $branch->tables()->whereKey($order->branch_table_id)->where('is_active', true)->sharedLock()->first()) {
            throw ValidationException::withMessages(['table' => 'The selected table is no longer active in this branch.']);
        }
    }
}
