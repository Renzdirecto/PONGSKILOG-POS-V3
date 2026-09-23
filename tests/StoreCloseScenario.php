<?php

namespace Tests;

use App\Actions\Orders\CommitPayLaterOrder;
use App\Actions\Orders\CreatePosDraftOrder;
use App\Actions\Orders\EditCommittedOrder;
use App\Actions\Orders\PayNowOrder;
use App\Actions\Orders\SettlePayLaterOrder;
use App\Actions\Orders\SubmitCustomerQrOrder;
use App\Actions\Orders\TransitionKitchenOrder;
use App\Actions\Orders\VoidOrder;
use App\Actions\StoreSessions\RecordStoreSessionExpense;
use App\Enums\KitchenStatus;
use App\Models\Branch;
use App\Models\BranchInventory;
use App\Models\BranchProduct;
use App\Models\CustomerQrSession;
use App\Models\Order;
use App\Models\Product;
use App\Models\Role;
use App\Models\StoreSession;
use App\Models\StoreSessionExpense;
use App\Models\User;
use App\Models\VoidAuthorizationSetting;
use App\Support\StoreSessionReconciliation;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/** Builds real current-session history through the production actions for Close Store tests. */
final class StoreCloseScenario
{
    public Branch $branch;

    public User $cashier;

    public User $kitchen;

    public StoreSession $session;

    public Product $product;

    public BranchInventory $stock;

    private ?User $superAdmin = null;

    public static function create(string $openingCash = '1000.00', string $openingCashless = '0.00'): self
    {
        $scenario = new self;
        $scenario->branch = Branch::factory()->create();
        $scenario->cashier = $scenario->user('cashier');
        $scenario->kitchen = $scenario->user('kitchen_staff');
        $scenario->session = StoreSession::factory()->for($scenario->branch)->create([
            'opened_by_user_id' => $scenario->cashier->id,
            'opening_cash_amount' => $openingCash,
            'opening_cashless_amount' => $openingCashless,
        ]);
        $scenario->product = Product::factory()->create(['default_price' => '100.00']);
        BranchProduct::factory()->for($scenario->branch)->for($scenario->product)->create(['tracks_inventory' => true]);
        $scenario->stock = BranchInventory::factory()->for($scenario->branch)->for($scenario->product)->create(['on_hand' => 200]);

        return $scenario;
    }

    public function user(string $role, ?Branch $branch = null, bool $assignmentActive = true, bool $active = true): User
    {
        $user = User::factory()->create(['is_active' => $active]);
        $user->roles()->attach(Role::query()->where('name', $role)->sole());
        $user->branches()->attach($branch ?? $this->branch, ['is_active' => $assignmentActive]);

        return $user;
    }

    /** @return list<array<string, mixed>> */
    public function items(int $quantity): array
    {
        return [['product_id' => $this->product->id, 'quantity' => $quantity, 'notes' => null, 'modifiers' => []]];
    }

    /**
     * Pay Now at 100.00 per unit; cash is tendered exactly unless overridden.
     *
     * @param  array<string, mixed>  $overrides
     */
    public function payNow(int $quantity, string $method, ?string $cashlessAmount = null, array $overrides = []): Order
    {
        $total = $quantity * 100;
        $cashDue = $method === 'cash' ? $total : ($method === 'split' ? $total - (int) $cashlessAmount : 0);

        return app(PayNowOrder::class)->execute($this->cashier, $this->branch, [
            'order_type' => 'take_out',
            'customer_label' => 'Walk-in',
            'items' => $this->items($quantity),
            'idempotency_key' => (string) Str::uuid(),
            'payment_method' => $method,
            'cash_received' => $method === 'cashless' ? null : $cashDue.'.00',
            'cashless_amount' => $method === 'split' ? $cashlessAmount : null,
            ...$overrides,
        ])->fresh();
    }

    public function payLater(int $quantity): Order
    {
        $draft = app(CreatePosDraftOrder::class)->execute($this->cashier, $this->branch, [
            'order_type' => 'take_out',
            'customer_label' => 'Tab',
            'items' => $this->items($quantity),
        ]);

        return app(CommitPayLaterOrder::class)->execute($this->cashier, $this->branch, $draft, [
            'idempotency_key' => (string) Str::uuid(),
        ])->fresh();
    }

    public function settle(Order $order, string $method, ?string $cashReceived = null, ?string $cashlessAmount = null): Order
    {
        return app(SettlePayLaterOrder::class)->execute($this->cashier, $this->branch, $order->fresh(), [
            'idempotency_key' => (string) Str::uuid(),
            'payment_method' => $method,
            'cash_received' => $cashReceived,
            'cashless_amount' => $cashlessAmount,
        ])->fresh();
    }

    /** @param array<string, mixed> $overrides */
    public function edit(Order $order, int $quantity, array $overrides = []): Order
    {
        $order = $order->fresh();
        $item = $order->items()->firstOrFail();

        return app(EditCommittedOrder::class)->execute($this->cashier, $this->branch, $order, [
            'idempotency_key' => (string) Str::uuid(),
            'expected_version' => $order->version,
            'order_type' => 'take_out',
            'customer_label' => $order->customer_label,
            'branch_table_id' => null,
            'reason' => 'Quantity changed',
            'items' => [['existing_order_item_id' => $item->id, 'product_id' => $this->product->id, 'quantity' => $quantity, 'notes' => null, 'modifiers' => []]],
            ...$overrides,
        ])->fresh();
    }

    /** Configures the global Void PIN (1234) once for this scenario. */
    public function authorizeVoids(): void
    {
        if ($this->superAdmin !== null || VoidAuthorizationSetting::query()->where('scope', 'global')->exists()) {
            return;
        }
        $this->superAdmin = User::factory()->create();
        $this->superAdmin->roles()->attach(Role::query()->where('name', 'super_admin')->sole());
        VoidAuthorizationSetting::query()->create([
            'pin_hash' => Hash::make('1234'),
            'configured_by_user_id' => $this->superAdmin->id,
            'configured_at' => now(),
        ]);
    }

    public function void(Order $order): Order
    {
        $this->authorizeVoids();
        $order = $order->fresh();

        return app(VoidOrder::class)->execute($this->cashier, $this->branch, $order, [
            'reason_code' => 'wrong_item',
            'reason_text' => null,
            'authorization_pin' => '1234',
            'idempotency_key' => (string) Str::uuid(),
            'expected_version' => $order->version,
        ])->fresh();
    }

    public function kitchenStatus(Order $order, KitchenStatus $status): Order
    {
        return app(TransitionKitchenOrder::class)->executeWithResult($this->kitchen, $this->branch, $order->fresh(), $status)['order']->fresh();
    }

    public function done(Order ...$orders): void
    {
        foreach ($orders as $order) {
            $this->kitchenStatus($order, KitchenStatus::Done);
        }
    }

    public function expense(string $amount, string $source): StoreSessionExpense
    {
        return app(RecordStoreSessionExpense::class)->execute($this->cashier, $this->branch, [
            'idempotency_key' => (string) Str::uuid(),
            'description' => 'Supplies',
            'amount' => $amount,
            'payment_source' => $source,
            'note' => null,
            'restock' => false,
        ]);
    }

    public function submitQr(int $quantity = 1): Order
    {
        $customer = CustomerQrSession::factory()->for($this->branch)->create();

        return app(SubmitCustomerQrOrder::class)->execute($this->branch, $customer, [
            'idempotency_key' => (string) Str::uuid(),
            'order_type' => 'take_out',
            'customer_label' => 'QR guest',
            'items' => $this->items($quantity),
        ]);
    }

    /** @return array<string, array<string, int|string>> */
    public function reconciliation(): array
    {
        $service = app(StoreSessionReconciliation::class);

        return $service->present($service->calculate($this->branch, $this->session->fresh()));
    }

    /** @return array<string, mixed> */
    public function preview(): array
    {
        return app(StoreSessionReconciliation::class)->preview($this->branch, $this->session->fresh());
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public function closePayload(string $cash, string $cashless, array $overrides = []): array
    {
        return [
            'idempotency_key' => (string) Str::uuid(),
            'store_session_id' => $this->session->id,
            'closing_cash_amount' => $cash,
            'closing_cashless_amount' => $cashless,
            'closing_note' => null,
            ...$overrides,
        ];
    }
}
