<?php

use App\Events\AccessControlChanged;
use App\Events\StaffChanged;
use App\Events\UserContextChanged;
use App\Models\Branch;
use App\Models\Role;
use App\Models\User;
use App\Support\ActiveBranchContext;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->superAdmin = contextUser('super_admin');
    $this->main = Branch::factory()->create(['code' => 'MAIN']);
    $this->qave = Branch::factory()->create(['code' => 'QAVE']);
    $this->juan = contextUser('cashier', [$this->main], ['name' => 'Juan', 'position' => 'Cashier']);
});

/**
 * @param  list<Branch>  $branches
 * @param  array<string, mixed>  $attributes
 */
function contextUser(Role|string $role, array $branches = [], array $attributes = []): User
{
    $user = User::factory()->create($attributes);
    $user->roles()->attach($role instanceof Role ? $role : Role::query()->where('name', $role)->sole());
    foreach ($branches as $branch) {
        $user->branches()->attach($branch, ['is_active' => true]);
    }

    return $user;
}

/** @param array<string, mixed> $changes */
function saveJuan(array $changes): void
{
    $juan = test()->juan;
    test()->actingAs(test()->superAdmin)->put(route('super-admin.staff.update', $juan), [
        'name' => $juan->name, 'email' => $juan->email, 'position' => $juan->position, 'role' => 'cashier',
        'branch_ids' => [test()->main->id], 'is_active' => true, ...$changes,
    ])->assertSessionHasNoErrors();
}

/** @return list<array{int, string}> */
function contextSignals(): array
{
    return Event::dispatched(UserContextChanged::class)
        ->map(fn (array $dispatch): array => [$dispatch[0]->userId, $dispatch[0]->broadcastWith()['change_type']])
        ->values()->all();
}

test('a staff edit signals the edited account with the kind of change, after commit', function (array $changes, string $changeType) {
    Event::fake([UserContextChanged::class, StaffChanged::class, AccessControlChanged::class]);

    saveJuan($changes);

    expect(contextSignals())->toBe([[$this->juan->id, $changeType]]);
    Event::assertDispatched(StaffChanged::class, fn (StaffChanged $event): bool => collect($event->broadcastOn())->map->name->contains('private-branch.'.$this->main->id.'.staff'));
    Event::assertDispatched(AccessControlChanged::class);
})->with([
    'position' => [['position' => 'Shift Supervisor'], UserContextChanged::IDENTITY],
    'role' => [['role' => 'kitchen_staff'], UserContextChanged::ACCESS],
    'deactivation' => [['is_active' => false], UserContextChanged::STATUS],
]);

test('a branch assignment change signals the account so its branch selector refreshes', function () {
    Event::fake([UserContextChanged::class, StaffChanged::class, AccessControlChanged::class]);

    saveJuan(['branch_ids' => [$this->main->id, $this->qave->id]]);

    expect(contextSignals())->toBe([[$this->juan->id, UserContextChanged::BRANCHES]]);
    $this->actingAs($this->juan->fresh())->withSession([ActiveBranchContext::SESSION_KEY => $this->main->id])->get(route('workspaces.cashier'))
        ->assertInertia(fn ($page) => $page->has('branchContext.selectableBranches', 2));
});

test('an unchanged staff save sends no signal', function () {
    Event::fake([UserContextChanged::class]);

    saveJuan([]);

    Event::assertNotDispatched(UserContextChanged::class);
});

test('a role baseline change signals every account inheriting it, including the derived role', function () {
    $kitchen = contextUser('cashier_kitchen', [$this->main]);
    $owner = contextUser('owner');
    Event::fake([UserContextChanged::class, AccessControlChanged::class]);

    $this->actingAs($this->superAdmin)->put(route('super-admin.access-control.roles.update', 'cashier'), [
        'permissions' => ['pos.access', 'transactions.view', 'store.open_close', 'store_expenses.manage', 'reports.view'],
    ])->assertSessionHasNoErrors();

    expect(collect(contextSignals())->pluck(0)->sort()->values()->all())->toBe([$this->juan->id, $kitchen->id])
        ->and(collect(contextSignals())->pluck(0))->not->toContain($owner->id);
    Event::assertDispatched(AccessControlChanged::class);
});

test('a custom role change signals its members and a per-user override signals only that account', function () {
    $this->actingAs($this->superAdmin)->post(route('super-admin.access-control.custom-roles.store'), [
        'label' => 'Branch Manager', 'scope' => 'branch', 'permissions' => ['pos.access'],
    ]);
    $role = Role::query()->where('label', 'Branch Manager')->sole();
    $maria = contextUser($role, [$this->main]);
    Event::fake([UserContextChanged::class, AccessControlChanged::class, StaffChanged::class]);

    $this->actingAs($this->superAdmin)->put(route('super-admin.access-control.custom-roles.update', $role), [
        'label' => 'Branch Manager', 'scope' => 'branch', 'permissions' => ['pos.access', 'inventory.manage'],
    ])->assertSessionHasNoErrors();
    $this->actingAs($this->superAdmin)->put(route('super-admin.access-control.users.update', $this->juan), [
        'overrides' => ['reports.view' => 'allow'],
    ])->assertSessionHasNoErrors();

    expect(contextSignals())->toBe([[$maria->id, UserContextChanged::ACCESS], [$this->juan->id, UserContextChanged::ACCESS]]);
    Event::assertDispatchedTimes(AccessControlChanged::class, 2);
});

test('the signal is compact and addressed only to the affected account', function () {
    $event = new UserContextChanged($this->juan->id, UserContextChanged::ACCESS);

    expect($event->broadcastOn()->name)->toBe('private-App.Models.User.'.$this->juan->id)
        ->and($event->broadcastAs())->toBe('user.context_changed')
        ->and(array_keys($event->broadcastWith()))->toBe(['event_id', 'event_type', 'user_id', 'change_type', 'occurred_at'])
        ->and((new StaffChanged([]))->broadcastWith())->not->toHaveKey('user_id');
});

test('private realtime channels admit only their own audience', function () {
    $pedro = contextUser('cashier', [$this->main]);
    $inactive = contextUser('cashier', [$this->main], ['is_active' => false]);
    $this->actingAs($this->superAdmin)->post(route('super-admin.access-control.custom-roles.store'), [
        'label' => 'Branch Manager', 'scope' => 'branch', 'permissions' => ['staff.manage'],
    ]);
    $manager = contextUser(Role::query()->where('label', 'Branch Manager')->sole(), [$this->main]);
    config(['broadcasting.default' => 'pusher', 'broadcasting.connections.pusher' => [
        'driver' => 'pusher', 'key' => 'test-key', 'secret' => 'test-secret',
        'app_id' => 'test-app', 'options' => ['cluster' => 'mt1', 'useTLS' => true],
    ]]);
    (static function (): void {
        require base_path('routes/channels.php');
    })();
    $channel = fn (string $name) => ['socket_id' => '123.456', 'channel_name' => $name];

    $this->actingAs($this->juan)->postJson('/broadcasting/auth', $channel('private-App.Models.User.'.$this->juan->id))->assertOk();
    $this->actingAs($this->juan)->postJson('/broadcasting/auth', $channel('private-App.Models.User.'.$pedro->id))->assertForbidden();
    /** A deactivated account's session ends before any channel is authorized. */
    $this->actingAs($inactive)->postJson('/broadcasting/auth', $channel('private-App.Models.User.'.$inactive->id))->assertUnauthorized();
    $this->actingAs($this->superAdmin)->postJson('/broadcasting/auth', $channel('private-access-control'))->assertOk();
    $this->actingAs($manager)->postJson('/broadcasting/auth', $channel('private-access-control'))->assertForbidden();
    $this->actingAs($manager)->postJson('/broadcasting/auth', $channel('private-branch.'.$this->main->id.'.staff'))->assertOk();
    $this->actingAs($manager)->postJson('/broadcasting/auth', $channel('private-branch.'.$this->qave->id.'.staff'))->assertForbidden();
    $this->actingAs($manager)->postJson('/broadcasting/auth', $channel('private-staff'))->assertForbidden();
    $this->actingAs($this->juan)->postJson('/broadcasting/auth', $channel('private-branch.'.$this->main->id.'.staff'))->assertForbidden();
});

test('a deactivated account loses access on its next request after the signal', function () {
    saveJuan(['is_active' => false]);

    $this->actingAs($this->juan->fresh())->get(route('workspaces.cashier'))->assertRedirect(route('login'));
});

test('the shared identity carries the current position and picture for the sidebar', function () {
    $this->juan->forceFill(['avatar_path' => 'staff-avatars/1/picture.jpg'])->save();

    $this->actingAs($this->juan)->withSession([ActiveBranchContext::SESSION_KEY => $this->main->id])->get(route('workspaces.cashier'))
        ->assertInertia(fn ($page) => $page
            ->where('auth.user.position', 'Cashier')
            ->where('auth.user.avatarUrl', fn (string $url): bool => str_starts_with($url, '/settings/profile/avatar?v=')));
});
