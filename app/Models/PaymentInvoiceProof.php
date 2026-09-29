<?php

namespace App\Models;

use Database\Factories\PaymentInvoiceProofFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['branch_id', 'order_id', 'payment_id', 'disk', 'path', 'original_name', 'mime_type', 'size_bytes', 'uploaded_by_user_id'])]
class PaymentInvoiceProof extends Model
{
    /** @use HasFactory<PaymentInvoiceProofFactory> */
    use HasFactory, HasUuids;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['size_bytes' => 'integer'];
    }

    /** @return BelongsTo<Payment, $this> */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}
