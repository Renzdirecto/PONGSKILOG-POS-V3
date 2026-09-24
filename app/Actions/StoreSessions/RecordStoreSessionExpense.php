<?php

namespace App\Actions\StoreSessions;

use App\Actions\Audit\AuditRecorder;
use App\Actions\Inventory\ApplyInventoryMovement;
use App\Enums\InventoryMovementType;
use App\Enums\StoreSessionStatus;
use App\Events\StoreExpenseRecorded;
use App\Http\Requests\StoreSessionExpenseRequest;
use App\Models\Branch;
use App\Models\BranchProduct;
use App\Models\Product;
use App\Models\StoreSession;
use App\Models\StoreSessionExpense;
use App\Models\User;
use App\Support\ExactMoney;
use App\Support\PosAccess;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class RecordStoreSessionExpense
{
    public function __construct(
        private PosAccess $access,
        private ApplyInventoryMovement $inventory,
        private AuditRecorder $audit,
    ) {}

    /** @param array<string, mixed> $input */
    public function execute(User $actor, Branch $branch, array $input): StoreSessionExpense
    {
        $input['description'] = is_string($input['description'] ?? null) ? trim($input['description']) : ($input['description'] ?? null);
        $input['note'] = is_string($input['note'] ?? null) && trim($input['note']) !== '' ? trim($input['note']) : null;
        /** @var array{idempotency_key: string, description: string, amount: string, payment_source: string, note: string|null, restock: bool, product_id?: string|null, quantity?: int|null, receipt?: UploadedFile|null} $data */
        $data = Validator::make($input, StoreSessionExpenseRequest::expenseRules(), [
            'amount.not_regex' => 'Enter an amount greater than ₱0.00.',
            'amount.regex' => 'Enter a valid amount with no more than two decimal places.',
        ])->validate();
        $amount = ExactMoney::decimal(ExactMoney::cents($data['amount']));
        $receipt = $data['receipt'] ?? null;
        $receiptHash = null;
        if ($receipt instanceof UploadedFile) {
            $receiptHash = hash_file('sha256', $receipt->getRealPath());
            if ($receiptHash === false) {
                throw ValidationException::withMessages(['receipt' => 'The receipt image could not be read. Try again.']);
            }
        }
        $storedDisk = null;
        $storedPath = null;

        try {
            return DB::transaction(function () use ($actor, $branch, $data, $amount, $receipt, $receiptHash, &$storedDisk, &$storedPath): StoreSessionExpense {
                $branch = Branch::query()->whereKey($branch->getKey())->firstOrFail();
                $actor = $this->access->authorize($actor, $branch);
                abort_unless($actor->hasPermission('store_expenses.manage'), 403);

                $session = StoreSession::query()
                    ->where('branch_id', $branch->id)
                    ->where('status', StoreSessionStatus::Open)
                    ->sharedLock()
                    ->first();
                if ($session === null) {
                    throw ValidationException::withMessages(['store' => 'This Store Session is no longer open.']);
                }

                if (DB::connection()->getDriverName() === 'pgsql') {
                    DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', [$branch->id.':'.$data['idempotency_key']]);
                }

                $intent = hash('sha256', json_encode([
                    'branch_id' => $branch->id,
                    'store_session_id' => $session->id,
                    'actor_id' => $actor->id,
                    'description' => $data['description'],
                    'amount' => $amount,
                    'payment_source' => $data['payment_source'],
                    'note' => $data['note'],
                    'product_id' => $data['restock'] ? ($data['product_id'] ?? null) : null,
                    'quantity' => $data['restock'] ? ($data['quantity'] ?? null) : null,
                    'receipt_sha256' => $receiptHash,
                ], JSON_THROW_ON_ERROR));

                $existing = StoreSessionExpense::query()->where('idempotency_key', $data['idempotency_key'])->first();
                if ($existing !== null) {
                    if (! hash_equals($existing->intent_hash, $intent)) {
                        abort(409, 'This expense attempt has already been used with different details.');
                    }

                    return $existing->load('createdBy', 'item.product', 'inventoryMovements');
                }

                $product = null;
                if ($data['restock']) {
                    $product = Product::query()->whereKey($data['product_id'])->where('is_active', true)->lockForUpdate()->first();
                    $configuration = $product === null ? null : BranchProduct::query()
                        ->where('branch_id', $branch->id)
                        ->where('product_id', $product->id)
                        ->lockForUpdate()
                        ->first();
                    if ($configuration === null || ! $configuration->tracks_inventory) {
                        throw ValidationException::withMessages(['product_id' => 'This product is not inventory-tracked for this branch.']);
                    }
                }

                $expenseId = (string) Str::uuid();
                $receiptAttributes = [];
                if ($receipt instanceof UploadedFile) {
                    $storedDisk = (string) config('filesystems.store_expense_receipts_disk', 'local');
                    $extension = $receipt->guessExtension() ?: 'jpg';
                    $storedPath = 'store-expense-receipts/'.$branch->id.'/'.$session->id.'/'.$expenseId.'.'.$extension;
                    $stored = Storage::disk($storedDisk)->putFileAs(dirname($storedPath), $receipt, basename($storedPath));
                    if ($stored === false) {
                        throw ValidationException::withMessages(['receipt' => 'The receipt image could not be stored. Try again.']);
                    }
                    $receiptAttributes = [
                        'receipt_disk' => $storedDisk,
                        'receipt_image_path' => $storedPath,
                        'receipt_original_name' => basename($receipt->getClientOriginalName()),
                        'receipt_mime_type' => (string) $receipt->getMimeType(),
                        'receipt_size_bytes' => $receipt->getSize(),
                        'receipt_sha256' => $receiptHash,
                    ];
                }

                $expense = $this->persist($branch, $session, $actor, [
                    'id' => $expenseId,
                    'description' => $data['description'],
                    'amount' => $amount,
                    'payment_source' => $data['payment_source'],
                    'note' => $data['note'],
                    ...$receiptAttributes,
                    'idempotency_key' => $data['idempotency_key'],
                    'intent_hash' => $intent,
                ], $product, $product === null ? null : $data['quantity'], ['receipt_present' => $receipt instanceof UploadedFile]);

                return $expense->load('createdBy', 'item.product', 'inventoryMovements');
            });
        } catch (\Throwable $exception) {
            if ($storedDisk !== null && $storedPath !== null) {
                Storage::disk($storedDisk)->delete($storedPath);
            }
            throw $exception;
        }
    }

    /**
     * The canonical Store Session expense write, shared by the Cashier expense form and Confirm Pamamalengke: one
     * append-only expense row, its optional tracked-Product restock, the audit entry and the after-commit event.
     * Callers authorize the actor, shared-lock the OPEN Store Session and resolve idempotency inside their transaction.
     *
     * @param  array{id?: string, description: string, amount: string, payment_source: string, note: string|null, idempotency_key: string, intent_hash: string, receipt_disk?: string, receipt_image_path?: string, receipt_original_name?: string, receipt_mime_type?: string, receipt_size_bytes?: int|false, receipt_sha256?: string|null}  $attributes
     * @param  array<string, mixed>  $auditDetails
     */
    public function persist(Branch $branch, StoreSession $session, User $actor, array $attributes, ?Product $product = null, ?int $quantity = null, array $auditDetails = []): StoreSessionExpense
    {
        $expense = StoreSessionExpense::query()->create([
            ...$attributes,
            'branch_id' => $branch->id,
            'store_session_id' => $session->id,
            'created_by_user_id' => $actor->id,
        ]);

        if ($product !== null && $quantity !== null) {
            $expense->item()->create(['product_id' => $product->id, 'quantity' => $quantity]);
            $this->inventory->execute(
                $branch,
                $product,
                InventoryMovementType::StorePurchaseRestock,
                $quantity,
                'Store purchase: '.$expense->description,
                $actor,
                storeSessionExpenseId: $expense->id,
            );
        }

        $this->audit->record(
            branch: $branch,
            actor: $actor,
            module: 'store_sessions',
            action: 'store_expense_recorded',
            auditableType: StoreSessionExpense::class,
            auditableId: $expense->id,
            after: [
                'store_session_id' => $session->id,
                'description' => $expense->description,
                'amount' => $expense->amount,
                'payment_source' => $expense->payment_source,
                'note' => $expense->note,
                'inventory_linked' => $product !== null,
                'product_id' => $product?->id,
                'quantity' => $product === null ? null : $quantity,
                'receipt_present' => false,
                ...$auditDetails,
            ],
            metadata: ['request_hash' => $attributes['intent_hash']],
            idempotencyKey: $attributes['idempotency_key'],
        );

        StoreExpenseRecorded::dispatch($expense, $product !== null);

        return $expense;
    }
}
