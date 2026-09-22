<?php

use App\Enums\BranchStatus;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Permission;
use App\Models\Role;
use App\Models\StoreSession;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->seed(RbacSeeder::class);
});

function branchManager(string $roleName = 'owner'): User
{
    $user = User::factory()->create();
    $user->roles()->attach(Role::query()->where('name', $roleName)->sole());

    return $user;
}

function branchInput(array $overrides = []): array
{
    return array_replace(['code' => 'NEW-01', 'name' => 'New Branch', 'status' => 'active', 'address' => null, 'contact' => null], $overrides);
}

test('management roles can list create and update branches', function (string $role) {
    $this->actingAs(branchManager($role));
    $this->get(route('branches.index'))->assertInertia(fn (Assert $page) => $page
        ->component('branches/index')->has('branches', 0));

    $this->post(route('branches.store'), branchInput())->assertRedirectToRoute('branches.index');
    $branch = Branch::query()->sole();
    expect($branch->id)->toBeUuid();
    $this->assertDatabaseHas('branches', branchInput());

    $this->put(route('branches.update', $branch), branchInput(['name' => 'Updated Branch', 'contact' => '555-0123', 'address' => '123 Main Street']))
        ->assertRedirectToRoute('branches.index')->assertSessionHasNoErrors();
    $this->assertDatabaseHas('branches', ['id' => $branch->id, 'name' => 'Updated Branch', 'contact' => '555-0123', 'address' => '123 Main Street']);
    expect(AuditLog::query()->where('auditable_id', $branch->id)->pluck('action')->all())
        ->toBe(['branch.created', 'branch.updated']);
})->with(['owner', 'super_admin']);

test('normal staff cannot manage branches even with settings permission', function (string $roleName) {
    $user = branchManager($roleName);
    Role::query()->where('name', $roleName)->sole()->permissions()->attach(Permission::query()->where('name', 'settings.manage')->sole());
    $branch = Branch::factory()->create();
    $original = $branch->fresh()->getAttributes();
    $user->branches()->attach($branch, ['is_active' => true]);

    expect(Gate::forUser($user)->allows('viewAny', Branch::class))->toBeFalse()
        ->and(Gate::forUser($user)->allows('create', Branch::class))->toBeFalse()
        ->and(Gate::forUser($user)->allows('update', $branch))->toBeFalse();
    $this->actingAs($user)->get(route('branches.index'))->assertForbidden();
    $this->post(route('branches.store'), branchInput())->assertForbidden();
    $this->put(route('branches.update', $branch), branchInput())->assertForbidden();
    $this->assertDatabaseCount('branches', 1);
    expect($branch->fresh()->getAttributes())->toBe($original);
})->with(['cashier', 'kitchen_staff', 'cashier_kitchen']);

test('business wide scope alone does not grant branch management', function (string $roleName) {
    $user = branchManager($roleName);
    Role::query()->where('name', $roleName)->sole()->permissions()->detach(Permission::query()->where('name', 'settings.manage')->sole());
    $branch = Branch::factory()->create();

    $this->actingAs($user)->get(route('branches.index'))->assertForbidden();
    $this->post(route('branches.store'), branchInput())->assertForbidden();
    $this->put(route('branches.update', $branch), branchInput())->assertForbidden();
})->with(['owner', 'super_admin']);

test('branch management requires an active authenticated user', function (string $method, string $routeName, bool $disabled) {
    $branch = Branch::factory()->create();
    if ($disabled) {
        $user = branchManager();
        $user->forceFill(['is_active' => false])->save();
        $this->actingAs($user);
        expect(Gate::forUser($user)->allows('viewAny', Branch::class))->toBeFalse();
    }

    $this->{$method}(route($routeName, $routeName === 'branches.update' ? $branch : []), branchInput())
        ->assertRedirectToRoute('login');
    $this->assertDatabaseCount('branches', 1);
})->with([
    'guest list' => ['get', 'branches.index', false],
    'guest create' => ['post', 'branches.store', false],
    'guest update' => ['put', 'branches.update', false],
    'disabled list' => ['get', 'branches.index', true],
    'disabled create' => ['post', 'branches.store', true],
    'disabled update' => ['put', 'branches.update', true],
]);

test('branch validation rejects invalid core fields on create and update', function (array $invalid, array $errors) {
    $branch = Branch::factory()->create(['code' => 'CURRENT']);
    $original = $branch->fresh()->getAttributes();
    $this->actingAs(branchManager());

    $this->post(route('branches.store'), branchInput($invalid))->assertSessionHasErrors($errors);
    $this->put(route('branches.update', $branch), branchInput($invalid))->assertSessionHasErrors($errors);
    $this->assertDatabaseCount('branches', 1);
    expect($branch->fresh()->getAttributes())->toBe($original);
})->with([
    'required' => [['code' => '', 'name' => '', 'status' => ''], ['code', 'name', 'status']],
    'lowercase' => [['code' => 'main'], ['code' => 'Use uppercase letters, numbers, underscores or hyphens, starting with a letter or number.']],
    'ambiguous spaces' => [['code' => 'MAIN BRANCH'], ['code']],
    'punctuation' => [['code' => 'MAIN/1'], ['code']],
    'long code' => [['code' => str_repeat('A', 33)], ['code']],
    'long name' => [['name' => str_repeat('a', 151)], ['name']],
    'invalid status' => [['status' => 'open'], ['status']],
    'long address' => [['address' => str_repeat('a', 501)], ['address']],
    'long contact' => [['contact' => str_repeat('1', 101)], ['contact']],
    'non string optional fields' => [['contact' => [], 'address' => []], ['contact', 'address']],
]);

test('duplicate codes cannot be created or assigned to another branch', function () {
    Branch::factory()->create(['code' => 'MAIN']);
    $branch = Branch::factory()->create(['code' => 'QAVE']);
    $this->actingAs(branchManager());

    $this->post(route('branches.store'), branchInput(['code' => 'MAIN']))->assertSessionHasErrors(['code']);
    $this->put(route('branches.update', $branch), branchInput(['code' => 'MAIN', 'id' => $branch->id]))->assertSessionHasErrors(['code']);
    $this->assertDatabaseCount('branches', 2);
    expect($branch->fresh()->code)->toBe('QAVE');
});

test('status changes preserve identity hours and session truth', function (BranchStatus $status) {
    $hours = ['monday' => ['open' => '08:00', 'close' => '21:00']];
    $branch = Branch::factory()->create(['code' => 'MAIN', 'operating_hours' => $hours]);
    $session = StoreSession::factory()->for($branch)->create();
    $originalSession = $session->fresh()->getAttributes();

    $this->actingAs(branchManager())->put(route('branches.update', $branch), branchInput([
        'code' => 'MAIN', 'status' => $status->value,
        'id' => fake()->uuid(), 'operating_hours' => null,
        'store_is_open' => false,
    ]))->assertRedirectToRoute('branches.index')->assertSessionHasNoErrors();

    expect($branch->fresh()->status)->toBe($status)
        ->and($branch->fresh()->operating_hours)->toBe($hours)
        ->and($session->fresh()->getAttributes())->toBe($originalSession);
    $this->assertDatabaseCount('branches', 1);
    $this->assertDatabaseCount('store_sessions', 1);
    $this->get(route('branches.index'))->assertInertia(fn (Assert $page) => $page
        ->where('branches.0.status', $status->value)->where('branches.0.store_is_open', true));
})->with(BranchStatus::cases());

test('branch listing uses bounded queries and exposes only core fields and current state', function () {
    $open = Branch::factory()->create(['name' => 'Alpha', 'code' => 'A']);
    $closed = Branch::factory()->create(['name' => 'Beta', 'code' => 'B']);
    StoreSession::factory()->for($open)->create();
    StoreSession::factory()->closed()->for($closed)->count(3)->create();
    $this->actingAs(branchManager());
    DB::enableQueryLog();

    $response = $this->get(route('branches.index'));
    $initialQueryCount = count(DB::getQueryLog());
    DB::disableQueryLog();
    $response->assertInertia(fn (Assert $page) => $page->has('branches', 2)
        ->where('branches.0.id', $open->id)->where('branches.0.name', 'Alpha')
        ->where('branches.0.qr_url', route('kiosk.show', ['branch' => $open->kiosk_code]))
        ->where('branches.0.qr_image', fn (string $image): bool => str_starts_with($image, 'data:image/svg+xml;base64,') && str_contains(base64_decode(substr($image, strlen('data:image/svg+xml;base64,'))), '<svg'))
        ->where('branches.0.store_is_open', true)->missing('branches.0.opening_cash_amount')
        ->where('branches.1.store_is_open', false));

    Branch::factory()->count(12)->create();
    DB::flushQueryLog();
    DB::enableQueryLog();
    $this->get(route('branches.index'))->assertOk();
    expect(count(DB::getQueryLog()))->toBe($initialQueryCount);
    DB::disableQueryLog();
});

test('branch management has no deletion route and retains the branch', function () {
    $branch = Branch::factory()->create();
    expect(Route::has('branches.destroy'))->toBeFalse();

    $this->actingAs(branchManager())->delete(route('branches.update', $branch))->assertMethodNotAllowed();
    $this->assertModelExists($branch);
});
