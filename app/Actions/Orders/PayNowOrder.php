<?php

namespace App\Actions\Orders;

use App\Actions\Inventory\ApplyInventoryMovement;
use App\Enums\CommercialStatus;
use App\Enums\InventoryMovementType;
use App\Enums\KitchenStatus;
use App\Enums\OrderSource;
use App\Enums\PaymentStatus;
use App\Enums\PaymentTerm;
use App\Enums\StoreSessionStatus;
use App\Events\KitchenTicketCreated;
use App\Events\OrderCommitted;
use App\Http\Requests\PayNowOrderRequest;
use App\Models\Branch;
use App\Models\BranchProduct;
use App\Models\Category;
use App\Models\KitchenTicket;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\User;
use App\Support\BranchCatalog;
use App\Support\ExactMoney;
use App\Support\PosAccess;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class PayNowOrder
{
    public function __construct(private CreatePosDraftOrder $drafts, private ApplyInventoryMovement $inventory, private PosAccess $access, private BranchCatalog $catalog) {}

    /** @param array<string, mixed> $input */
    public function execute(User $user, Branch $branch, array $input): Order
    {
        $data = Validator::make($input, PayNowOrderRequest::paymentRules(! empty($input['draft_order_id'])))->validate();
        $data['idempotency_key'] = strtolower($data['idempotency_key']);

        try {
            return DB::transaction(function () use ($user, $branch, $data): Order {
                /** A root may claim different method legs; serialize it across branches too. */
                if (DB::getDriverName() === 'pgsql') {
                    DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', [$data['idempotency_key']]);
                }
                /** Same branch-first lock order as draft creation and Store Open. */
                $branch = Branch::query()->whereKey($branch->getKey())->lockForUpdate()->firstOrFail();
                $user = $this->access->authorize($user, $branch);
                if ($replay = $this->replay($user, $branch, $data)) {
                    return $replay;
                }
                $session = $branch->storeSessions()->where('status', StoreSessionStatus::Open)->lockForUpdate()->first();
                if ($session === null) {
                    throw ValidationException::withMessages(['store' => 'Store is closed. Open the store before confirming payment.']);
                }
                $order = ! empty($data['draft_order_id'])
                    ? Order::query()->where('branch_id', $branch->id)->whereKey($data['draft_order_id'])->lockForUpdate()->firstOrFail()
                    : $this->drafts->execute($user, $branch, $data);
                $order = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
                if ($order->payment_status === PaymentStatus::Paid) {
                    throw ValidationException::withMessages(['order' => 'Order has already been paid.']);
                }
                if ($order->source !== OrderSource::Pos || $order->commercial_status !== CommercialStatus::Draft
                    || $order->payment_status !== PaymentStatus::Unpaid || $order->payment_term !== null
                    || $order->kitchen_status !== KitchenStatus::NotSent || $order->committed_at !== null) {
                    throw ValidationException::withMessages(['order' => 'Only an unpaid, uncommitted POS draft can be paid here.']);
                }
                $paidAt = now();
                foreach ($this->legs($order, $data) as $method => $leg) {
                    Payment::query()->create([
                        ...$leg, 'method' => $method, 'branch_id' => $branch->id, 'store_session_id' => $session->id,
                        'order_id' => $order->id, 'created_by_user_id' => $user->id,
                        'idempotency_key' => $data['idempotency_key'].':'.$method, 'paid_at' => $paidAt,
                    ]);
                }

                $order->load('items');
                $quantities = [];
                foreach ($order->items as $item) {
                    if ($item->product_id === null) {
                        throw ValidationException::withMessages(['items' => 'A product is no longer available.']);
                    }
                    $quantities[$item->product_id] = ($quantities[$item->product_id] ?? 0) + $item->quantity;
                }
                if ($quantities === []) {
                    throw ValidationException::withMessages(['items' => 'The order must contain items.']);
                }
                ksort($quantities);
                $ids = array_keys($quantities);
                /** Hold current availability stable while committing, without repricing snapshots. */
                Category::query()->whereIn('id', Product::query()->whereKey($ids)->select('category_id'))->orderBy('id')->sharedLock()->get();
                Product::query()->whereKey($ids)->orderBy('id')->sharedLock()->get();
                BranchProduct::query()->where('branch_id', $branch->id)->whereIn('product_id', $ids)->orderBy('product_id')->lockForUpdate()->get();
                $products = $this->catalog->productsForOrder($branch, $ids)->keyBy('id');
                foreach ($quantities as $id => $quantity) {
                    $product = $products->get($id);
                    if ($product === null || ! $product->is_active || ! $product->category->is_active
                        || $product->branchProducts->first()?->is_available === false) {
                        throw ValidationException::withMessages(['items' => 'A product is no longer available. Refresh the catalog before trying again.']);
                    }
                    if ($this->catalog->resolveLoaded($product)['tracked']) {
                        $this->inventory->execute($branch, $product, InventoryMovementType::Sale, -$quantity,
                            'Pay Now order '.$order->order_number, $user, $order->id);
                    }
                }
                $ticket = KitchenTicket::query()->create(['branch_id' => $branch->id, 'order_id' => $order->id, 'status' => KitchenStatus::Kitchen]);
                $order->update([
                    'store_session_id' => $session->id, 'commercial_status' => CommercialStatus::Active,
                    'payment_status' => PaymentStatus::Paid, 'payment_term' => PaymentTerm::Immediate,
                    'kitchen_status' => KitchenStatus::Kitchen, 'committed_at' => $paidAt, 'version' => $order->version + 1,
                ]);
                OrderCommitted::dispatch($order);
                KitchenTicketCreated::dispatch($order, $ticket);

                return $order;
            });
        } catch (UniqueConstraintViolationException $exception) {
            $detail = $exception->errorInfo[2] ?? '';
            $expected = (($exception->errorInfo[0] ?? '') === '23505' && str_contains($detail, '"payments_idempotency_key_unique"'))
                || (DB::getDriverName() === 'sqlite' && str_contains($detail, 'UNIQUE constraint failed: payments.idempotency_key'));
            if (! $expected) {
                throw $exception;
            }
            /** Reload only after the entire losing transaction has rolled back. */
            $branch = $branch->fresh() ?? abort(403);
            $user = $this->access->authorize($user, $branch);

            return $this->replay($user, $branch, $data) ?? throw $exception;
        }
    }

    /** @param array<string, mixed> $data
     * @return array<string, array{amount: string, amount_received: string|null, change_amount: string|null}>
     */
    private function legs(Order $order, array $data): array
    {
        $total = ExactMoney::cents($order->total);
        $method = $data['payment_method'];
        $cashless = $method === 'cashless' ? $total : ($method === 'split' ? ExactMoney::cents($data['cashless_amount']) : 0);
        if ($method === 'split' && ($cashless <= 0 || $cashless >= $total)) {
            throw ValidationException::withMessages(['cashless_amount' => 'Cashless amount must be greater than zero and less than the order total.']);
        }
        $legs = [];
        if ($method !== 'cashless') {
            $received = ExactMoney::cents($data['cash_received']);
            $due = $total - $cashless;
            if ($received < $due) {
                throw ValidationException::withMessages(['cash_received' => 'Cash amount is insufficient. Enter at least '.ExactMoney::decimal($due).'.']);
            }
            $legs['cash'] = ['amount' => ExactMoney::decimal($due), 'amount_received' => ExactMoney::decimal($received), 'change_amount' => ExactMoney::decimal($received - $due)];
        }
        if ($method !== 'cash') {
            $legs['cashless'] = ['amount' => ExactMoney::decimal($cashless), 'amount_received' => null, 'change_amount' => null];
        }

        return $legs;
    }

    /** @param array<string, mixed> $data */
    private function replay(User $user, Branch $branch, array $data): ?Order
    {
        $payments = Payment::query()->whereIn('idempotency_key', [$data['idempotency_key'].':cash', $data['idempotency_key'].':cashless'])->get();
        if ($payments->isEmpty()) {
            return null;
        }
        $first = $payments->first();
        if ($first->branch_id !== $branch->id || $first->created_by_user_id !== $user->id
            || (! empty($data['draft_order_id']) && $first->order_id !== $data['draft_order_id'])
            || $payments->contains(fn (Payment $payment): bool => $payment->order_id !== $first->order_id)) {
            abort(409, 'This payment attempt belongs to another order or cashier.');
        }
        $order = Order::query()->where('branch_id', $branch->id)->findOrFail($first->order_id);
        if (empty($data['draft_order_id']) && ! $this->matchesCart($order, $data)) {
            abort(409, 'This payment attempt belongs to another order.');
        }
        $legs = $this->legs($order, $data);
        if ($order->payment_status !== PaymentStatus::Paid || count($legs) !== $payments->count()) {
            abort(409, 'This payment attempt has already been used with different details.');
        }
        foreach ($payments as $payment) {
            if (($legs[$payment->method->value] ?? null) !== [
                'amount' => $payment->amount, 'amount_received' => $payment->amount_received, 'change_amount' => $payment->change_amount,
            ]) {
                abort(409, 'This payment attempt has already been used with different details.');
            }
        }

        return $order;
    }

    /** Compare identity and selections, never today's catalog prices.
     * @param  array<string, mixed>  $data
     */
    private function matchesCart(Order $order, array $data): bool
    {
        if ($order->order_type->value !== $data['order_type'] || ($order->customer_label ?? '') !== trim($data['customer_label'] ?? '')
            || $order->branch_table_id !== (($data['branch_table_id'] ?? null) ?: null)) {
            return false;
        }
        $order->load('items.modifiers');
        $actual = $order->items->map(fn (OrderItem $item): array => [
            $item->product_id, $item->quantity, $item->notes ?? '', $item->modifiers->pluck('modifier_option_id')->sort()->values()->all(),
        ])->all();
        $expected = array_map(function (array $line): array {
            $options = array_column($line['modifiers'], 'option_id');
            sort($options);

            return [$line['product_id'], $line['quantity'], $line['notes'] ?? '', $options];
        }, $data['items']);
        sort($actual);
        sort($expected);

        return $actual === $expected;
    }
}
