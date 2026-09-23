<?php

use App\Actions\Audit\AuditRecorder;
use App\Actions\Staff\CreateStaffAccount;
use App\Enums\BranchStatus;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->superAdmin = User::factory()->create(['name' => 'Control Admin', 'email' => 'admin@pongskilog.test']);
    $this->superAdmin->roles()->attach(Role::query()->where('name', 'super_admin')->sole());
    $this->branch = Branch::factory()->create(['name' => 'Alpha Branch', 'code' => 'ALPHA']);
});

/** @return array<string, mixed> */
function staffPayload(array $overrides = []): array
{
    return [
        'employee_id' => '09242601',
        'name' => 'Jamie Cruz',
        'email' => 'jamie@pongskilog.test',
        'password' => 'Temporary-Pass-42',
        'password_confirmation' => 'Temporary-Pass-42',
        'role' => 'cashier',
        'branch_ids' => [test()->branch->id],
        'is_active' => true,
        ...$overrides,
    ];
}

function createStaffRequest(array $payload, ?User $actor = null): TestResponse
{
    return test()->actingAs($actor ?? test()->superAdmin)->post(route('super-admin.staff.store'), $payload);
}

test('super admin sees staff accounts with role branch access and status but never credentials', function () {
    $cashier = User::factory()->create(['name' => 'Bea Santos', 'email' => 'bea@pongskilog.test']);
    $cashier->forceFill(['employee_id' => '09242601'])->save();
    $cashier->roles()->attach(Role::query()->where('name', 'cashier')->sole());
    $cashier->branches()->attach($this->branch, ['is_active' => true]);

    $this->actingAs($this->superAdmin)
        ->get(route('super-admin.staff.index', ['search' => '092426']))
        ->assertInertia(fn (Assert $page) => $page
            ->component('super-admin/staff')
            ->has('staff.data', 1)
            ->where('staff.data.0.email', 'bea@pongskilog.test')
            ->where('staff.data.0.employee_id', '09242601')
            ->where('staff.data.0.roles.0.label', 'Cashier')
            ->where('staff.data.0.branches.0.code', 'ALPHA')
            ->where('staff.data.0.business_wide', false)
            ->where('staff.data.0.is_active', true)
            ->missing('staff.data.0.password')
            ->missing('staff.data.0.remember_token')
            ->where('roles', fn ($roles) => collect($roles)->pluck('name')->all() === ['cashier', 'kitchen_staff', 'cashier_kitchen', 'owner', 'super_admin'])
            ->has('branches', 1));
});

test('staff list filters by role and status', function (array $filters, string $expectedEmail) {
    $inactiveKitchen = User::factory()->create(['email' => 'kitchen@pongskilog.test', 'is_active' => false]);
    $inactiveKitchen->roles()->attach(Role::query()->where('name', 'kitchen_staff')->sole());

    $this->actingAs($this->superAdmin)
        ->get(route('super-admin.staff.index', $filters))
        ->assertInertia(fn (Assert $page) => $page
            ->has('staff.data', 1)
            ->where('staff.data.0.email', $expectedEmail));
})->with([
    'role' => [['role' => 'kitchen_staff'], 'kitchen@pongskilog.test'],
    'inactive status' => [['status' => 'inactive'], 'kitchen@pongskilog.test'],
    'active super admin' => [['status' => 'active', 'role' => 'super_admin'], 'admin@pongskilog.test'],
]);

test('roles without access control management cannot open or create staff', function (string $roleName) {
    $user = User::factory()->create();
    $user->roles()->attach(Role::query()->where('name', $roleName)->sole());
    $user->branches()->attach($this->branch, ['is_active' => true]);

    $this->actingAs($user)->get(route('super-admin.staff.index'))->assertForbidden();
    createStaffRequest(staffPayload(), $user)->assertForbidden();

    $this->assertDatabaseMissing('users', ['email' => 'jamie@pongskilog.test']);
})->with(['owner', 'cashier', 'kitchen_staff', 'cashier_kitchen']);

test('guests cannot create staff', function () {
    $this->post(route('super-admin.staff.store'), staffPayload())->assertRedirectToRoute('login');

    $this->assertDatabaseMissing('users', ['email' => 'jamie@pongskilog.test']);
});

test('super admin creates operational staff with a hashed temporary password branch access and one audit', function (string $role) {
    Mail::fake();
    Notification::fake();

    createStaffRequest(staffPayload(['role' => $role, 'email' => '  Jamie@PongSkilog.TEST ', 'employee_id' => ' 09242601 ']))
        ->assertRedirectToRoute('super-admin.staff.index')
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Staff account created.']);

    $user = User::query()->where('email', 'jamie@pongskilog.test')->sole();
    expect($user->name)->toBe('Jamie Cruz')
        ->and($user->employee_id)->toBe('09242601')
        ->and($user->is_active)->toBeTrue()
        ->and($user->password)->not->toBe('Temporary-Pass-42')
        ->and(Hash::check('Temporary-Pass-42', $user->password))->toBeTrue()
        ->and($user->roles()->pluck('name')->all())->toBe([$role])
        ->and($user->branches()->wherePivot('is_active', true)->pluck('branches.id')->all())->toBe([$this->branch->id]);
    $audit = AuditLog::query()->where('action', 'staff.created')->sole();
    expect($audit->user_id)->toBe($this->superAdmin->id)
        ->and($audit->module)->toBe('staff')
        ->and($audit->auditable_id)->toBe((string) $user->id)
        ->and($audit->after)->toBe([
            'user_id' => $user->id,
            'employee_id' => '09242601',
            'name' => 'Jamie Cruz',
            'email' => 'jamie@pongskilog.test',
            'role' => $role,
            'branch_access' => 'assigned',
            'branch_ids' => [$this->branch->id],
            'branch_codes' => ['ALPHA'],
            'is_active' => true,
            'has_profile_picture' => false,
        ]);
    expect(json_encode([$audit->before, $audit->after, $audit->metadata]))
        ->not->toContain('Temporary-Pass-42')
        ->not->toContain($user->password)
        ->not->toContain('password');
    Mail::assertNothingOutgoing();
    Notification::assertNothingSent();
})->with(['cashier', 'kitchen_staff', 'cashier_kitchen']);

test('business wide roles are created without fabricated branch assignments', function (string $role) {
    createStaffRequest(staffPayload(['role' => $role, 'branch_ids' => []]))
        ->assertRedirectToRoute('super-admin.staff.index');

    $user = User::query()->where('email', 'jamie@pongskilog.test')->sole();
    expect($user->roles()->pluck('name')->all())->toBe([$role])
        ->and($user->branches()->count())->toBe(0)
        ->and($user->hasBusinessWideScope())->toBeTrue();
    expect(AuditLog::query()->where('action', 'staff.created')->sole()->after['branch_access'])->toBe('business_wide');
})->with(['owner', 'super_admin']);

test('business wide roles reject submitted branch assignments', function (string $role) {
    createStaffRequest(staffPayload(['role' => $role]))
        ->assertInvalid(['branch_ids' => 'Owner and Super Admin accounts have business-wide access and do not take Branch assignments.']);

    $this->assertDatabaseMissing('users', ['email' => 'jamie@pongskilog.test']);
})->with(['owner', 'super_admin']);

test('staff creation defaults to active and can create an inactive account', function (array $overrides, bool $expected) {
    $payload = staffPayload($overrides);
    if ($overrides === []) {
        unset($payload['is_active']);
    }

    createStaffRequest($payload)->assertRedirectToRoute('super-admin.staff.index');

    expect(User::query()->where('email', 'jamie@pongskilog.test')->sole()->is_active)->toBe($expected);
})->with([
    'default' => [[], true],
    'inactive' => [['is_active' => false], false],
]);

test('staff creation rejects invalid input without creating an account', function (array $overrides, string $field, string $message) {
    User::factory()->create(['email' => 'taken@pongskilog.test'])->forceFill(['employee_id' => '09232601'])->save();
    $inactiveBranch = Branch::factory()->create(['status' => BranchStatus::Inactive]);
    $overrides = array_map(fn (mixed $value) => $value === 'INACTIVE_BRANCH' ? [$inactiveBranch->id] : $value, $overrides);

    createStaffRequest(staffPayload($overrides))->assertInvalid([$field => $message]);

    expect(User::query()->count())->toBe(2);
    $this->assertDatabaseCount('audit_logs', 0);
})->with([
    'duplicate email ignoring case' => [['email' => 'TAKEN@pongskilog.test'], 'email', 'This email is already used by another account.'],
    'missing employee id' => [['employee_id' => ''], 'employee_id', 'The employee id field is required.'],
    'employee id not mmddyy plus two digits' => [['employee_id' => '13242601'], 'employee_id', 'Use MMDDYY followed by a two-digit number, for example 09242601.'],
    'employee id too long' => [['employee_id' => '092426001'], 'employee_id', 'Use MMDDYY followed by a two-digit number, for example 09242601.'],
    'duplicate employee id' => [['employee_id' => '09232601'], 'employee_id', 'This Employee ID is already used by another account.'],
    'invalid email' => [['email' => 'not-an-email'], 'email', 'The email field must be a valid email address.'],
    'missing name' => [['name' => '   '], 'name', 'The name field is required.'],
    'password mismatch' => [['password_confirmation' => 'Different-Pass-42'], 'password', 'The password field confirmation does not match.'],
    'short password' => [['password' => 'short', 'password_confirmation' => 'short'], 'password', 'The password field must be at least 8 characters.'],
    'unknown role' => [['role' => 'manager'], 'role', 'Choose a valid role.'],
    'missing operational branch' => [['branch_ids' => []], 'branch_ids', 'Choose at least one active Branch for this role.'],
    'nonexistent branch' => [['branch_ids' => ['9b9e7c1e-0000-4000-8000-000000000000']], 'branch_ids.0', 'Choose active Branches only.'],
    'inactive branch' => [['branch_ids' => 'INACTIVE_BRANCH'], 'branch_ids.0', 'Choose active Branches only.'],
]);

test('created staff sign in immediately with the temporary password and no forced change step', function () {
    createStaffRequest(staffPayload())->assertRedirectToRoute('super-admin.staff.index');
    $this->post(route('logout'));

    $this->post(route('login.store'), ['email' => 'jamie@pongskilog.test', 'password' => 'Temporary-Pass-42'])
        ->assertRedirect('/workspace');

    $this->assertAuthenticatedAs(User::query()->where('email', 'jamie@pongskilog.test')->sole());
    $this->get(route('workspace'))->assertRedirectToRoute('workspaces.cashier');
});

test('a branch that becomes inactive before commit rolls back the whole account', function () {
    $this->branch->update(['status' => BranchStatus::Inactive]);

    expect(fn () => app(CreateStaffAccount::class)->execute($this->superAdmin, [
        'employee_id' => '09242601',
        'name' => 'Jamie Cruz',
        'email' => 'jamie@pongskilog.test',
        'password' => 'Temporary-Pass-42',
        'role' => 'cashier',
        'branch_ids' => [$this->branch->id],
    ]))->toThrow(ValidationException::class);

    $this->assertDatabaseMissing('users', ['email' => 'jamie@pongskilog.test']);
    $this->assertDatabaseCount('user_branch_assignments', 0);
    $this->assertDatabaseCount('audit_logs', 0);
});

test('an audit failure rolls back the user role and branch assignment', function () {
    $this->mock(AuditRecorder::class)->shouldReceive('record')->andThrow(new RuntimeException('audit unavailable'));
    $roleAssignmentsBefore = DB::table('user_roles')->count();

    expect(fn () => app(CreateStaffAccount::class)->execute($this->superAdmin, [
        'employee_id' => '09242601',
        'name' => 'Jamie Cruz',
        'email' => 'jamie@pongskilog.test',
        'password' => 'Temporary-Pass-42',
        'role' => 'cashier',
        'branch_ids' => [$this->branch->id],
    ]))->toThrow(RuntimeException::class, 'audit unavailable');

    $this->assertDatabaseMissing('users', ['email' => 'jamie@pongskilog.test']);
    expect(DB::table('user_roles')->count())->toBe($roleAssignmentsBefore);
    $this->assertDatabaseCount('user_branch_assignments', 0);
});

test('a repeated submission cannot create a duplicate account or audit', function () {
    createStaffRequest(staffPayload())->assertRedirectToRoute('super-admin.staff.index');

    createStaffRequest(staffPayload())->assertInvalid(['email' => 'This email is already used by another account.']);

    expect(User::query()->where('email', 'jamie@pongskilog.test')->count())->toBe(1);
    expect(AuditLog::query()->where('action', 'staff.created')->count())->toBe(1);
});

test('an inactive super admin cannot create staff through the action', function () {
    $this->superAdmin->forceFill(['is_active' => false])->save();

    expect(fn () => app(CreateStaffAccount::class)->execute($this->superAdmin, [
        'employee_id' => '09242601',
        'name' => 'Jamie Cruz',
        'email' => 'jamie@pongskilog.test',
        'password' => 'Temporary-Pass-42',
        'role' => 'cashier',
        'branch_ids' => [$this->branch->id],
    ]))->toThrow(AuthorizationException::class);

    $this->assertDatabaseMissing('users', ['email' => 'jamie@pongskilog.test']);
});

test('a profile picture is stored privately and served only to super admin access control', function () {
    Storage::fake('local');
    $owner = User::factory()->create();
    $owner->roles()->attach(Role::query()->where('name', 'owner')->sole());

    createStaffRequest(staffPayload(['avatar' => UploadedFile::fake()->image('jamie.png', 200, 200)]))
        ->assertRedirectToRoute('super-admin.staff.index');

    $user = User::query()->where('email', 'jamie@pongskilog.test')->sole();
    expect($user->avatar_path)->toStartWith('staff-avatars/'.$user->id.'/');
    Storage::disk('local')->assertExists($user->avatar_path);
    expect(AuditLog::query()->where('action', 'staff.created')->sole()->after['has_profile_picture'])->toBeTrue();
    $this->actingAs($this->superAdmin)
        ->get(route('super-admin.staff.index'))
        ->assertInertia(fn (Assert $page) => $page->where('staff.data.1.avatar_url', fn (string $url) => str_starts_with($url, '/workspaces/super-admin/staff/'.$user->id.'/avatar?v=')));
    $this->get(route('super-admin.staff.avatar', $user))->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
    $this->actingAs($owner)->get(route('super-admin.staff.avatar', $user))->assertForbidden();
});

test('staff without a profile picture have no avatar image', function () {
    createStaffRequest(staffPayload())->assertRedirectToRoute('super-admin.staff.index');

    $user = User::query()->where('email', 'jamie@pongskilog.test')->sole();
    expect($user->avatar_path)->toBeNull();
    $this->get(route('super-admin.staff.avatar', $user))->assertNotFound();
});

test('a profile picture must be a real jpg png or webp image up to 2 MB', function (UploadedFile $file) {
    Storage::fake('local');

    createStaffRequest(staffPayload(['avatar' => $file]))->assertInvalid(['avatar']);

    $this->assertDatabaseMissing('users', ['email' => 'jamie@pongskilog.test']);
    expect(Storage::disk('local')->allFiles())->toBe([]);
})->with([
    'pdf' => fn () => UploadedFile::fake()->create('photo.pdf', 100, 'application/pdf'),
    'svg' => fn () => UploadedFile::fake()->create('photo.svg', 10, 'image/svg+xml'),
    'too large' => fn () => UploadedFile::fake()->image('photo.jpg', 800, 800)->size(3000),
]);

test('a failed account creation removes the stored profile picture', function () {
    Storage::fake('local');
    $this->mock(AuditRecorder::class)->shouldReceive('record')->andThrow(new RuntimeException('audit unavailable'));

    expect(fn () => app(CreateStaffAccount::class)->execute($this->superAdmin, [
        'employee_id' => '09242601',
        'name' => 'Jamie Cruz',
        'email' => 'jamie@pongskilog.test',
        'password' => 'Temporary-Pass-42',
        'role' => 'cashier',
        'branch_ids' => [$this->branch->id],
        'avatar' => UploadedFile::fake()->image('jamie.png', 200, 200),
    ]))->toThrow(RuntimeException::class, 'audit unavailable');

    $this->assertDatabaseMissing('users', ['email' => 'jamie@pongskilog.test']);
    expect(Storage::disk('local')->allFiles())->toBe([]);
});
