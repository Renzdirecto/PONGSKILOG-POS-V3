<?php

use App\Actions\Operations\AdjustIngredientStock;
use App\Actions\Orders\CommitPayLaterOrder;
use App\Actions\Orders\CreatePosDraftOrder;
use App\Actions\Orders\SubmitCustomerQrOrder;
use App\Enums\CommercialStatus;
use App\Events\CustomerCatalogChanged;
use App\Events\IngredientStockChanged;
use App\Models\CustomerQrSession;
use App\Models\IngredientMovement;
use App\Models\KitchenTicket;
use App\Models\Order;
use App\Models\Payment;
use App\Support\BranchCatalog;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\OperationsScenario;

beforeEach(function () {
    $this->ops = OperationsScenario::create()->withAddOns();
});

/** @return array{orders: int, payments: int, tickets: int, movements: int} */
function commitEffects(): array
{
    return [
        'orders' => Order::query()->whereNotNull('committed_at')->count(),
        'payments' => Payment::query()->count(),
        'tickets' => KitchenTicket::query()->count(),
        'movements' => IngredientMovement::query()->count(),
    ];
}

function mediumCapacity(OperationsScenario $ops): ?int
{
    $row = collect(app(BranchCatalog::class)->browse($ops->branch, true)['products'])->firstWhere('id', $ops->lemonYakult->id);

    return collect($row['recipe']['sizes'])->firstWhere('name', 'Medium')['capacity'];
}

test('pay now and pay later sell up to the selected configuration capacity and reject more with no partial effects', function (string $flow) {
    $this->ops->setStock('yakult', '5');
    $sell = fn (array $lines) => $flow === 'pay now' ? $this->ops->payNow($lines) : $this->ops->payLater($lines);
    $before = commitEffects();

    /** Medium + Extra Yakult needs 2 Yakult: 5 in stock makes 2, not 3. */
    expect(fn () => $sell([$this->ops->line($this->ops->lemonYakult, 3, 'm', ['extra_yakult'])]))
        ->toThrow(ValidationException::class, 'Not enough ingredient stock for Lemon Yakult');
    expect(commitEffects())->toBe($before)->and($this->ops->stock('yakult'))->toBe('5');

    $sell([$this->ops->line($this->ops->lemonYakult, 2, 'm', ['extra_yakult'])]);
    expect($this->ops->stock('yakult'))->toBe('1')
        ->and(mediumCapacity($this->ops))->toBe(1);
})->with(['pay now', 'pay later']);

test('cart lines sharing an ingredient are validated together across sizes and products', function () {
    $this->ops->setStock('lemon', '1');

    /** Medium (0.5) and Large (1) each fit alone, but together need 1.5 Lemon. */
    expect(fn () => $this->ops->payNow([$this->ops->line($this->ops->lemonYakult, 1, 'm'), $this->ops->line($this->ops->lemonYakult, 1, 'l')]))
        ->toThrow(ValidationException::class, 'Not enough ingredient stock');

    /** Water is shared by Lemon Yakult (250 ml) and Tapsilog (100 ml). */
    $this->ops->setStock('water', '599.5');
    expect(fn () => $this->ops->payNow([$this->ops->line($this->ops->lemonYakult, 2, 'm'), $this->ops->line($this->ops->tapsilog, 1)]))
        ->toThrow(ValidationException::class, 'Lemon Yakult, Tapsilog');

    $this->ops->setStock('water', '600');
    $this->ops->payNow([$this->ops->line($this->ops->lemonYakult, 2, 'm'), $this->ops->line($this->ops->tapsilog, 1)]);
    expect($this->ops->stock('lemon'))->toBe('0')->and($this->ops->stock('water'))->toBe('0');
});

test('the locked commit rejects a saved order whose ingredients were sold meanwhile, without partial effects', function () {
    $this->ops->setStock('yakult', '1');
    $draft = app(CreatePosDraftOrder::class)->execute($this->ops->cashier, $this->ops->branch, [
        'order_type' => 'take_out', 'customer_label' => 'Waiting', 'items' => [$this->ops->line($this->ops->lemonYakult, 1, 'm')],
    ]);
    $this->ops->payNow([$this->ops->line($this->ops->lemonYakult, 1, 'l')]);
    $before = commitEffects();

    expect(fn () => app(CommitPayLaterOrder::class)->execute($this->ops->cashier, $this->ops->branch, $draft, ['idempotency_key' => (string) Str::uuid()]))
        ->toThrow(ValidationException::class, 'Yakult needs 1 pc, 0 pc left');

    expect(commitEffects())->toBe($before)
        ->and($draft->fresh()->commercial_status)->toBe(CommercialStatus::Draft)
        ->and($this->ops->stock('yakult'))->toBe('0');
});

test('customer qr submission is refused when the order cannot be made and accepted when it fits', function () {
    $this->ops->setStock('nata', '50');
    $session = CustomerQrSession::factory()->for($this->ops->branch)->create();
    $submit = fn (int $quantity) => app(SubmitCustomerQrOrder::class)->execute($this->ops->branch, $session, [
        'idempotency_key' => (string) Str::uuid(), 'order_type' => 'take_out', 'customer_label' => 'QR',
        'items' => [$this->ops->line($this->ops->lemonYakult, $quantity, 'm', ['nata'])],
    ]);

    expect(fn () => $submit(2))->toThrow(ValidationException::class, 'Not enough ingredient stock for Lemon Yakult');
    expect(Order::query()->count())->toBe(0);

    $order = $submit(1);
    expect($order->commercial_status)->toBe(CommercialStatus::Submitted)
        ->and(IngredientMovement::query()->where('order_id', $order->id)->exists())->toBeFalse();
});

test('an edit validates only the additional ingredient usage; reducing usage never needs stock', function () {
    $this->ops->setStock('yakult', '3');
    $order = $this->ops->payLater([$this->ops->line($this->ops->lemonYakult, 2, 'm')]);
    expect($this->ops->stock('yakult'))->toBe('1');

    $order = $this->ops->edit($order, [$this->ops->line($this->ops->lemonYakult, 3, 'm')]);
    expect($this->ops->stock('yakult'))->toBe('0');

    $version = $order->fresh()->version;
    expect(fn () => $this->ops->edit($order, [$this->ops->line($this->ops->lemonYakult, 3, 'm', ['extra_yakult'])]))
        ->toThrow(ValidationException::class, 'Yakult needs 3 pc, 0 pc left');
    expect($order->fresh()->version)->toBe($version)->and($this->ops->stock('yakult'))->toBe('0');

    /** Changing size and quantity nets the delta first: Large ×1 releases more than it needs. */
    $order = $this->ops->edit($order, [$this->ops->line($this->ops->lemonYakult, 1, 'l')]);
    expect($this->ops->stock('yakult'))->toBe('2')->and($this->ops->stock('lemon'))->toBe('28.5');
});

test('existing negative ingredient balances sell nothing until restored and a void restores exact usage once', function () {
    $order = $this->ops->payNow([$this->ops->line($this->ops->lemonYakult, 2, 'm', ['extra_yakult'])]);
    $this->ops->setStock('yakult', '-1');

    expect(mediumCapacity($this->ops))->toBe(0);
    expect(fn () => $this->ops->payNow([$this->ops->line($this->ops->lemonYakult, 1, 'm')]))->toThrow(ValidationException::class);

    $this->ops->void($order);
    expect($this->ops->stock('yakult'))->toBe('3')
        ->and(mediumCapacity($this->ops))->toBe(3);
});

test('committed ingredient changes broadcast a compact invalidation without quantities or money', function () {
    Event::fake([IngredientStockChanged::class, CustomerCatalogChanged::class]);

    $order = $this->ops->payNow([$this->ops->line($this->ops->lemonYakult, 1, 'm')]);
    app(AdjustIngredientStock::class)->execute($this->ops->owner, $this->ops->ingredients['lemon'], [
        'mode' => 'wastage', 'quantity' => '1', 'reason' => 'Spoiled', 'note' => null, 'idempotency_key' => (string) Str::uuid(),
    ]);
    $this->ops->void($order);

    $reasons = [];
    Event::assertDispatched(IngredientStockChanged::class, function (IngredientStockChanged $event) use (&$reasons): bool {
        $payload = $event->broadcastWith();
        $reasons[] = $payload['reason'];

        return $event->broadcastOn()[0]->name === 'private-branch.'.$this->ops->branch->id.'.inventory'
            && array_keys($payload) === ['event_id', 'event_type', 'branch_id', 'reason', 'occurred_at'];
    });
    expect($reasons)->toContain('sale', 'wastage', 'void');
    Event::assertDispatched(CustomerCatalogChanged::class);
});
