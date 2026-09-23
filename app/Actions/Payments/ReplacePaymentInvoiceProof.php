<?php

namespace App\Actions\Payments;

use App\Actions\Audit\AuditRecorder;
use App\Enums\PaymentMethod;
use App\Enums\StoreSessionStatus;
use App\Models\Branch;
use App\Models\Payment;
use App\Models\PaymentInvoiceProof;
use App\Models\User;
use App\Support\PosAccess;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ReplacePaymentInvoiceProof
{
    public function __construct(private PosAccess $access, private AuditRecorder $audit) {}

    public function execute(User $actor, Branch $branch, Payment $payment, UploadedFile $invoice): PaymentInvoiceProof
    {
        $actor = $this->access->authorize($actor, $branch);
        $payment = Payment::query()->where('branch_id', $branch->id)->whereKey($payment->id)->firstOrFail();
        if ($payment->method !== PaymentMethod::Cashless) {
            throw ValidationException::withMessages(['invoice' => 'Invoice proof is only available for cashless payment rows.']);
        }
        $openSession = $branch->storeSessions()->where('status', StoreSessionStatus::Open)->first();
        if ($openSession === null || $payment->store_session_id !== $openSession->id) {
            throw ValidationException::withMessages(['invoice' => 'Proofs for an earlier or closed store session are read-only.']);
        }

        $disk = (string) config('filesystems.payment_proofs_disk', 'local');
        $extension = $invoice->guessExtension() ?: 'jpg';
        $path = 'payment-invoices/'.$branch->id.'/'.$payment->order_id.'/'.Str::uuid().'.'.$extension;
        $stored = Storage::disk($disk)->putFileAs(dirname($path), $invoice, basename($path));
        if ($stored === false) {
            throw ValidationException::withMessages(['invoice' => 'The invoice image could not be stored. Try again.']);
        }

        try {
            return DB::transaction(function () use ($actor, $branch, $payment, $invoice, $disk, $path): PaymentInvoiceProof {
                $locked = Payment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();
                $old = PaymentInvoiceProof::query()->where('payment_id', $locked->id)->lockForUpdate()->first();
                $proof = PaymentInvoiceProof::query()->updateOrCreate(['payment_id' => $locked->id], [
                    'branch_id' => $branch->id, 'order_id' => $locked->order_id, 'disk' => $disk, 'path' => $path,
                    'original_name' => basename($invoice->getClientOriginalName()),
                    'mime_type' => (string) $invoice->getMimeType(), 'size_bytes' => $invoice->getSize(),
                    'uploaded_by_user_id' => $actor->id,
                ]);
                $this->audit->record(
                    branch: $branch,
                    actor: $actor,
                    module: 'transactions',
                    action: $old === null ? 'invoice_proof_added' : 'invoice_proof_replaced',
                    auditableType: Payment::class,
                    auditableId: $locked->id,
                    before: $old === null ? null : ['name' => $old->original_name, 'mime_type' => $old->mime_type, 'size_bytes' => $old->size_bytes],
                    after: ['name' => $proof->original_name, 'mime_type' => $proof->mime_type, 'size_bytes' => $proof->size_bytes],
                );
                if ($old !== null) {
                    DB::afterCommit(fn () => Storage::disk($old->disk)->delete($old->path));
                }

                return $proof;
            });
        } catch (\Throwable $exception) {
            Storage::disk($disk)->delete($path);
            throw $exception;
        }
    }

    public function delete(User $actor, Branch $branch, Payment $payment): void
    {
        $actor = $this->access->authorize($actor, $branch);
        $payment = Payment::query()->where('branch_id', $branch->id)->whereKey($payment->id)->firstOrFail();
        $openSession = $branch->storeSessions()->where('status', StoreSessionStatus::Open)->first();
        if ($openSession === null || $payment->store_session_id !== $openSession->id) {
            throw ValidationException::withMessages(['invoice' => 'Proofs for an earlier or closed store session are read-only.']);
        }
        DB::transaction(function () use ($actor, $branch, $payment): void {
            $proof = PaymentInvoiceProof::query()->where('payment_id', $payment->id)->lockForUpdate()->firstOrFail();
            $snapshot = ['name' => $proof->original_name, 'mime_type' => $proof->mime_type, 'size_bytes' => $proof->size_bytes];
            $disk = $proof->disk;
            $path = $proof->path;
            $proof->delete();
            $this->audit->record(
                branch: $branch,
                actor: $actor,
                module: 'transactions',
                action: 'invoice_proof_removed',
                auditableType: Payment::class,
                auditableId: $payment->id,
                before: $snapshot,
            );
            DB::afterCommit(fn () => Storage::disk($disk)->delete($path));
        });
    }
}
