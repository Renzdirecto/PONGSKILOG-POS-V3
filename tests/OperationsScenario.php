<?php

namespace Tests;

use App\Actions\Operations\SaveIngredient;
use App\Actions\Operations\SaveOperationPlan;
use App\Actions\Operations\SaveRecipe;
use App\Actions\Orders\CommitPayLaterOrder;
use App\Actions\Orders\CreatePosDraftOrder;
use App\Actions\Orders\EditCommittedOrder;
use App\Actions\Orders\PayNowOrder;
use App\Actions\Orders\SettlePayLaterOrder;
use App\Actions\Orders\VoidOrder;
use App\Enums\ModifierSelectionType;
use App\Enums\ModifierSemanticRole;
use App\Models\Branch;
use App\Models\BranchIngredientStock;
use App\Models\BranchInventory;
use App\Models\BranchProduct;
use App\Models\Ingredient;
use App\Models\ModifierGroup;
use App\Models\ModifierOption;
use App\Models\OperationPlan;
use App\Models\Order;
use App\Models\Product;
use App\Models\Role;
use App\Models\StoreSession;
use App\Models\User;
use App\Models\VoidAuthorizationSetting;
use App\Support\ActiveBranchContext;
use App\Support\ExactQuantity;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * A Drinks + Silog business built through the production actions: Lemon Yakult (S/M/L sizes, recipe per size),
 * Tapsilog (no sizes), Coke (direct resale using Product stock) and shared Purified Water.
 */
final class OperationsScenario
{
    public Branch $branch;

    public User $cashier;

    public User $owner;

    public User $superAdmin;

    public StoreSession $session;

    public Product $lemonYakult;

    public Product $tapsilog;

    public Product $coke;

    /** @var array<string, ModifierOption> */
    public array $sizes = [];

    public ModifierGroup $sizeGroup;

    /** @var array<string, Ingredient> */
    public array $ingredients = [];

    public OperationPlan $drinks;

    public OperationPlan $silog;

    public static function create(): self
    {
        (new RbacSeeder)->run();
        $scenario = new self;
        $scenario->branch = Branch::factory()->create(['code' => 'MAIN', 'name' => 'Main']);
        $scenario->cashier = $scenario->user('cashier', $scenario->branch);
        $scenario->owner = $scenario->user('owner');
        $scenario->superAdmin = $scenario->user('super_admin');
        VoidAuthorizationSetting::query()->create([
            'pin_hash' => Hash::make('1234'),
            'configured_by_user_id' => $scenario->superAdmin->id,
            'configured_at' => now(),
        ]);
        $scenario->session = StoreSession::factory()->for($scenario->branch)->create(['opened_by_user_id' => $scenario->cashier->id]);

        $scenario->sizeGroup = ModifierGroup::factory()->create([
            'name' => 'Size', 'semantic_role' => ModifierSemanticRole::Size, 'selection_type' => ModifierSelectionType::Single,
            'min_select' => 1, 'max_select' => 1,
        ]);
        foreach (['s' => ['Small', '0.00', 0], 'm' => ['Medium', '10.00', 1], 'l' => ['Large', '20.00', 2]] as $key => [$name, $delta, $order]) {
            $scenario->sizes[$key] = ModifierOption::factory()->create([
                'modifier_group_id' => $scenario->sizeGroup->id, 'name' => $name, 'price_delta' => $delta, 'sort_order' => $order,
            ]);
        }
        $scenario->lemonYakult = Product::factory()->create(['name' => 'Lemon Yakult', 'default_price' => '60.00']);
        $scenario->lemonYakult->modifierGroups()->attach($scenario->sizeGroup);
        $scenario->tapsilog = Product::factory()->create(['name' => 'Tapsilog', 'default_price' => '120.00']);
        $scenario->coke = Product::factory()->create(['name' => 'Coke Mismo', 'default_price' => '25.00']);
        BranchProduct::factory()->for($scenario->branch)->for($scenario->lemonYakult)->create(['tracks_inventory' => false]);
        BranchProduct::factory()->for($scenario->branch)->for($scenario->tapsilog)->create(['tracks_inventory' => false]);
        BranchProduct::factory()->for($scenario->branch)->for($scenario->coke)->create(['tracks_inventory' => true]);
        BranchInventory::factory()->for($scenario->branch)->for($scenario->coke)->create(['on_hand' => 20]);

        $scenario->drinks = app(SaveOperationPlan::class)->execute($scenario->owner, null, [
            'name' => 'Drinks', 'icon' => 'glass', 'product_ids' => [$scenario->lemonYakult->id, $scenario->coke->id],
        ]);
        $scenario->silog = app(SaveOperationPlan::class)->execute($scenario->owner, null, [
            'name' => 'Silog', 'icon' => 'meal', 'product_ids' => [$scenario->tapsilog->id],
        ]);

        $scenario->actAsOwnerOn($scenario->branch);
        $scenario->ingredients['lemon'] = $scenario->ingredient('Lemon', 'pc', '30', 'pc', '1', '10.00', 'top_up', null, [$scenario->drinks], '29.5');
        $scenario->ingredients['yakult'] = $scenario->ingredient('Yakult', 'pc', '5', 'pack', '5', '55.00', 'reorder', '2', [$scenario->drinks], '10');
        $scenario->ingredients['syrup'] = $scenario->ingredient('Syrup', 'ml', '2000', 'bottle', '1000', '150.00', 'reorder', '500', [$scenario->drinks], '1000');
        $scenario->ingredients['water'] = $scenario->ingredient('Purified Water', 'ml', '10000', 'gallon', '5000', '40.00', 'top_up', null, [$scenario->drinks, $scenario->silog], '8000');
        $scenario->ingredients['rice'] = $scenario->ingredient('Rice', 'g', '5000', 'kg', '1000', '54.00', 'top_up', null, [$scenario->silog], '3000');
        $scenario->ingredients['egg'] = $scenario->ingredient('Egg', 'pc', '30', 'tray', '30', '240.00', 'reorder', '10', [$scenario->silog], '30');

        $scenario->recipe($scenario->lemonYakult, 'm', ['lemon' => '0.5', 'yakult' => '1', 'syrup' => '30', 'water' => '250']);
        $scenario->recipe($scenario->lemonYakult, 'l', ['lemon' => '1', 'yakult' => '1', 'syrup' => '45', 'water' => '350']);
        $scenario->recipe($scenario->tapsilog, null, ['rice' => '200', 'egg' => '1', 'water' => '100']);

        return $scenario;
    }

    public function user(string $role, ?Branch $branch = null): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::query()->where('name', $role)->sole());
        if ($branch !== null) {
            $user->branches()->attach($branch, ['is_active' => true]);
        }

        return $user;
    }

    public function actAsOwnerOn(?Branch $branch): void
    {
        session([ActiveBranchContext::SESSION_KEY => $branch?->id]);
    }

    /** @param list<OperationPlan> $plans */
    public function ingredient(string $name, string $unit, string $target, ?string $purchaseUnit, ?string $size, ?string $cost, string $rule, ?string $reorder, array $plans, string $initial = '0'): Ingredient
    {
        return app(SaveIngredient::class)->execute($this->owner, null, [
            'name' => $name, 'icon' => 'box', 'base_unit' => $unit, 'target_quantity' => $target,
            'purchase_unit_name' => $purchaseUnit, 'purchase_unit_size' => $size, 'purchase_unit_cost' => $cost,
            'replenishment_rule' => $rule, 'reorder_point' => $reorder,
            'plan_ids' => array_map(fn (OperationPlan $plan): string => $plan->id, $plans),
            'initial_quantity' => $initial,
        ]);
    }

    /** @param array<string, string> $lines */
    public function recipe(Product $product, ?string $size, array $lines): void
    {
        app(SaveRecipe::class)->execute($this->owner, $product, [
            'size_option_id' => $size === null ? null : $this->sizes[$size]->id,
            'lines' => array_map(fn (string $key, string $quantity): array => ['ingredient_id' => $this->ingredients[$key]->id, 'quantity' => $quantity], array_keys($lines), $lines),
        ]);
    }

    /** @return array{product_id: string, quantity: int, notes: null, modifiers: list<array{group_id: string, option_id: string}>} */
    public function line(Product $product, int $quantity, ?string $size = null): array
    {
        return [
            'product_id' => $product->id,
            'quantity' => $quantity,
            'notes' => null,
            'modifiers' => $size === null ? [] : [['group_id' => $this->sizeGroup->id, 'option_id' => $this->sizes[$size]->id]],
        ];
    }

    /** @param list<array<string, mixed>> $lines */
    public function payNow(array $lines, ?User $cashier = null): Order
    {
        return app(PayNowOrder::class)->execute($cashier ?? $this->cashier, $this->branch, [
            'order_type' => 'take_out', 'customer_label' => 'Ops QA', 'items' => $lines,
            'payment_method' => 'cash', 'cash_received' => '9999.00', 'cashless_amount' => null,
            'idempotency_key' => (string) Str::uuid(),
        ]);
    }

    /** @param list<array<string, mixed>> $lines */
    public function payLater(array $lines): Order
    {
        $draft = app(CreatePosDraftOrder::class)->execute($this->cashier, $this->branch, [
            'order_type' => 'take_out', 'customer_label' => 'Ops QA', 'items' => $lines,
        ]);

        return app(CommitPayLaterOrder::class)->execute($this->cashier, $this->branch, $draft, ['idempotency_key' => (string) Str::uuid()]);
    }

    public function settle(Order $order): Order
    {
        return app(SettlePayLaterOrder::class)->execute($this->cashier, $this->branch, $order->fresh(), [
            'idempotency_key' => (string) Str::uuid(), 'payment_method' => 'cash', 'cash_received' => '9999.00', 'cashless_amount' => null,
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @return array<string, mixed>
     */
    public function editInput(Order $order, array $lines, ?string $key = null): array
    {
        return [
            'idempotency_key' => $key ?? (string) Str::uuid(), 'expected_version' => $order->fresh()->version,
            'order_type' => 'take_out', 'customer_label' => 'Ops QA', 'branch_table_id' => null, 'reason' => 'Customer changed the order',
            'refund_cash_amount' => null, 'items' => $lines,
        ];
    }

    /** @param list<array<string, mixed>> $lines */
    public function edit(Order $order, array $lines, ?string $key = null): Order
    {
        return app(EditCommittedOrder::class)->execute($this->cashier, $this->branch, $order->fresh(), $this->editInput($order, $lines, $key));
    }

    public function void(Order $order, ?string $key = null, ?int $expectedVersion = null): Order
    {
        return app(VoidOrder::class)->execute($this->cashier, $this->branch, $order->fresh(), [
            'reason_code' => 'wrong_item', 'reason_text' => null, 'authorization_pin' => '1234',
            'idempotency_key' => $key ?? (string) Str::uuid(), 'expected_version' => $expectedVersion ?? $order->fresh()->version,
        ]);
    }

    /** Exact current Branch balance, as a decimal display string such as "29.5". */
    public function stock(string $ingredient, ?Branch $branch = null): string
    {
        $row = BranchIngredientStock::query()->where('branch_id', ($branch ?? $this->branch)->id)
            ->where('ingredient_id', $this->ingredients[$ingredient]->id)->first();

        return ExactQuantity::display(ExactQuantity::parse($row?->on_hand));
    }
}
