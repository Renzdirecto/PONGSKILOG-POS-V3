<?php

use App\Enums\BranchStatus;
use App\Events\IngredientStockChanged;
use App\Models\Branch;
use App\Models\BranchProduct;
use App\Models\CustomerQrSession;
use App\Models\PamamalengkeListEntry;
use App\Models\Product;
use App\Support\ActiveBranchContext;
use App\Support\BranchCatalog;
use App\Support\CustomerQrAccess;
use App\Support\OperationsSummary;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\OperationsScenario;

beforeEach(function () {
    $this->ops = OperationsScenario::create()->withAddOns();
});

test('customer qr capacity answers nothing for an inactive branch or disabled qr ordering', function (string $case) {
    $token = bin2hex(random_bytes(32));
    CustomerQrSession::factory()->for($this->ops->branch)->create(['token_hash' => hash('sha256', $token)]);
    match ($case) {
        'inactive branch' => $this->ops->branch->update(['status' => BranchStatus::Inactive]),
        'qr disabled' => $this->ops->branch->update(['qr_ordering_enabled' => false]),
    };

    $this->withCredentials()->withCookie(app(CustomerQrAccess::class)->cookieName($this->ops->branch), $token)
        ->postJson(route('qr.recipe-capacity', $this->ops->branch), [
            'lines' => [],
            'focus' => ['product_id' => $this->ops->lemonYakult->id, 'modifiers' => [['group_id' => $this->ops->sizeGroup->id, 'option_id' => $this->ops->sizes['m']->id]]],
        ])->assertNotFound();
})->with(['inactive branch', 'qr disabled']);

test('recipe edits invalidate active branch catalogs only when something changed', function () {
    $inactive = Branch::factory()->create(['status' => BranchStatus::Inactive]);
    Event::fake([IngredientStockChanged::class]);
    $save = fn (array $lines) => $this->ops->recipe($this->ops->lemonYakult, 'm', $lines);

    $save(['lemon' => '0.5', 'yakult' => '1', 'syrup' => '30', 'water' => '250']);
    $this->ops->effect($this->ops->lemonYakult, 'extra_yakult', ['yakult' => '1']);
    Event::assertNotDispatched(IngredientStockChanged::class);

    $save(['lemon' => '0.75', 'yakult' => '1', 'syrup' => '30', 'water' => '250']);
    Event::assertDispatched(IngredientStockChanged::class, fn (IngredientStockChanged $event): bool => $event->broadcastWith()['branch_id'] === $this->ops->branch->id);
    Event::assertNotDispatched(IngredientStockChanged::class, fn (IngredientStockChanged $event): bool => $event->broadcastWith()['branch_id'] === $inactive->id);
});

test('pamamalengke skip marks accept only active plan ingredients and cannot be deleted as manual items', function () {
    $as = fn () => $this->actingAs($this->ops->owner)->withSession([ActiveBranchContext::SESSION_KEY => $this->ops->branch->id]);
    $rice = $this->ops->ingredients['rice'];

    /** Rice belongs to the Silog plan, not Drinks. */
    $as()->put(route('operations.pamamalengke.skip', [$this->ops->drinks, $rice]), ['skipped' => true])->assertSessionHasErrors('ingredient');
    expect(PamamalengkeListEntry::query()->where('ingredient_id', $rice->id)->exists())->toBeFalse();

    $as()->put(route('operations.pamamalengke.skip', [$this->ops->drinks, $this->ops->ingredients['lemon']]), ['skipped' => true])->assertSessionHasNoErrors();
    $skip = PamamalengkeListEntry::query()->where('entry_type', 'skip')->sole();
    $as()->delete(route('operations.pamamalengke.manual.destroy', $skip))->assertNotFound();
    expect($skip->fresh())->not->toBeNull();
});

test('the pos catalog stays at a fixed query count however many recipe-backed products it has', function () {
    $count = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        app(BranchCatalog::class)->browse($this->ops->branch, customization: true);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };
    $before = $count();
    foreach (range(1, 12) as $index) {
        $product = Product::factory()->create(['name' => 'Recipe drink '.$index, 'category_id' => $this->ops->lemonYakult->category_id]);
        $product->modifierGroups()->attach([$this->ops->sizeGroup->id, $this->ops->addOnGroup->id]);
        BranchProduct::factory()->for($this->ops->branch)->for($product)->create(['tracks_inventory' => false]);
        $this->ops->recipe($product, 'm', ['lemon' => '0.5', 'water' => '100']);
        $this->ops->effect($product, 'nata', ['nata' => '10']);
    }

    expect($count())->toBe($before);
});

test('the operations summary uses a fixed number of queries however many orders were sold', function () {
    $count = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        app(OperationsSummary::class)->today($this->ops->branch);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };
    $this->ops->payNow([$this->ops->line($this->ops->lemonYakult, 1, 'm')]);
    $before = $count();
    foreach (range(1, 8) as $index) {
        $this->ops->payNow([$this->ops->line($this->ops->lemonYakult, 1, 'm', ['nata']), $this->ops->line($this->ops->tapsilog, 1)]);
    }

    expect($count())->toBe($before);
});
