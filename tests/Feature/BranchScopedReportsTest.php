<?php

use App\Enums\PermissionOverrideEffect;
use App\Models\Branch;
use App\Models\Permission;
use App\Models\Role;
use App\Models\StoreSession;
use App\Models\User;
use App\Models\UserPermissionOverride;
use App\Support\ActiveBranchContext;
use Database\Seeders\RbacSeeder;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->main = Branch::factory()->create(['name' => 'Main', 'code' => 'MAIN']);
    $this->qave = Branch::factory()->create(['name' => 'Qave', 'code' => 'QAVE']);
    $this->mainSession = StoreSession::factory()->for($this->main)->create();
    $this->qaveSession = StoreSession::factory()->for($this->qave)->create();
    $this->juan = scopedReportsUser('cashier', [$this->main]);
    UserPermissionOverride::query()->create([
        'user_id' => $this->juan->id,
        'permission_id' => Permission::query()->where('name', 'reports.view')->value('id'),
        'effect' => PermissionOverrideEffect::Allow,
    ]);
});

/** @param list<Branch> $branches */
function scopedReportsUser(string $role, array $branches = []): User
{
    $user = User::factory()->create();
    $user->roles()->attach(Role::query()->where('name', $role)->sole());
    foreach ($branches as $branch) {
        $user->branches()->attach($branch, ['is_active' => true]);
    }

    return $user;
}

test('a cashier with custom reports access sees reports for the assigned branch only', function () {
    $this->actingAs($this->juan)
        ->withSession([ActiveBranchContext::SESSION_KEY => $this->main->id])
        ->get(route('workspaces.reports'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('workspaces/reports')
            ->where('auth.permissions', fn ($permissions): bool => collect($permissions)->contains('reports.view'))
            ->where('branchContext.businessWide', false)
            ->where('branchContext.selectableBranches.0.code', 'MAIN')
            ->has('branchContext.selectableBranches', 1)
            ->where('report.scope.code', 'MAIN')
            ->has('report.sessions', 1)
            ->where('report.sessions.0.id', $this->mainSession->id)
            ->where('analytics.branches', null));
});

test('another cashier of the same role without custom access cannot reach reports', function () {
    $pedro = scopedReportsUser('cashier', [$this->main]);

    $this->actingAs($pedro)
        ->withSession([ActiveBranchContext::SESSION_KEY => $this->main->id])
        ->get(route('workspaces.reports'))
        ->assertForbidden();
});

test('a forged foreign branch in the session falls back to the assigned branch, never qave', function () {
    $this->actingAs($this->juan)
        ->withSession([ActiveBranchContext::SESSION_KEY => $this->qave->id])
        ->get(route('workspaces.reports'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('report.scope.code', 'MAIN')
            ->where('report.sessions', fn ($sessions): bool => collect($sessions)->pluck('id')->doesntContain($this->qaveSession->id)));

    $this->actingAs($this->juan)->put(route('branch-context.update', $this->qave))->assertForbidden();
});

test('a forged foreign store session filter is ignored', function () {
    $this->actingAs($this->juan)
        ->withSession([ActiveBranchContext::SESSION_KEY => $this->main->id])
        ->get(route('workspaces.reports', ['session' => $this->qaveSession->id, 'date' => 'today']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('report.scope.code', 'MAIN')
            ->where('report.sessions', fn ($sessions): bool => collect($sessions)->pluck('id')->all() === [$this->mainSession->id]));
});

test('a branch-scoped account without a selected branch never receives all branches', function () {
    $multi = scopedReportsUser('cashier', [$this->main, $this->qave]);
    UserPermissionOverride::query()->create([
        'user_id' => $multi->id,
        'permission_id' => Permission::query()->where('name', 'reports.view')->value('id'),
        'effect' => PermissionOverrideEffect::Allow,
    ]);

    $this->actingAs($multi)->get(route('workspaces.reports'))->assertRedirectToRoute('workspace');
    $this->actingAs($multi)->get(route('workspaces.reports.export'))->assertRedirectToRoute('workspace');
});

test('the csv export follows the same assigned branch scope', function () {
    $response = $this->actingAs($this->juan)
        ->withSession([ActiveBranchContext::SESSION_KEY => $this->main->id])
        ->get(route('workspaces.reports.export'))
        ->assertOk();

    expect($response->headers->get('Content-Disposition'))->toContain('pongskilog-report-main-')
        ->and($response->getContent())->not->toContain('QAVE');
});

test('custom reports access does not open the business-wide owner dashboard', function () {
    $this->actingAs($this->juan)
        ->withSession([ActiveBranchContext::SESSION_KEY => $this->main->id])
        ->get(route('workspaces.owner'))
        ->assertForbidden();
});

test('owner and super admin keep business-wide all branches reports', function (string $role) {
    $this->actingAs(scopedReportsUser($role))
        ->get(route('workspaces.reports'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('report.scope', null)
            ->has('report.sessions', 2));
})->with(['owner', 'super_admin']);

test('branch report signals reach only accounts with reports access at that branch', function () {
    config(['broadcasting.default' => 'pusher', 'broadcasting.connections.pusher' => [
        'driver' => 'pusher', 'key' => 'test-key', 'secret' => 'test-secret',
        'app_id' => 'test-app', 'options' => ['cluster' => 'mt1', 'useTLS' => true],
    ]]);
    (static function (): void {
        require base_path('routes/channels.php');
    })();
    $channel = fn (string $name) => ['socket_id' => '123.456', 'channel_name' => $name];

    $this->actingAs($this->juan)->postJson('/broadcasting/auth', $channel('private-branch.'.$this->main->id.'.reports'))->assertOk();
    $this->actingAs($this->juan)->postJson('/broadcasting/auth', $channel('private-branch.'.$this->qave->id.'.reports'))->assertForbidden();
    $this->actingAs($this->juan)->postJson('/broadcasting/auth', $channel('private-reports'))->assertForbidden();

    $pedro = scopedReportsUser('cashier', [$this->main]);
    $this->actingAs($pedro)->postJson('/broadcasting/auth', $channel('private-branch.'.$this->main->id.'.reports'))->assertForbidden();
});

test('a cashier whose baseline loses pos lands on the first workspace still allowed', function () {
    $this->actingAs(scopedReportsUser('super_admin'))
        ->put(route('super-admin.access-control.users.update', $this->juan), ['overrides' => ['pos.access' => 'deny', 'reports.view' => 'allow']]);

    $this->actingAs($this->juan)
        ->withSession([ActiveBranchContext::SESSION_KEY => $this->main->id])
        ->get(route('workspace'))
        ->assertRedirectToRoute('workspaces.transaction-history');
});
