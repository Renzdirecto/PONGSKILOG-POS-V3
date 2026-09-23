<?php

use App\Enums\BranchStatus;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->owner = User::factory()->create(['name' => 'Olivia Owner']);
    $this->owner->roles()->attach(Role::query()->where('name', 'owner')->sole());
    $this->branch = Branch::factory()->create(['name' => 'Alpha Branch', 'code' => 'ALPHA']);
});

function staffAccount(string $role, string $email, ?Branch $branch = null): User
{
    $user = User::factory()->create(['email' => $email]);
    $user->roles()->attach(Role::query()->where('name', $role)->sole());
    if ($branch !== null) {
        $user->branches()->attach($branch, ['is_active' => true]);
    }

    return $user;
}

/** @return array<string, mixed> */
function ownerStaffPayload(array $overrides = []): array
{
    return [
        'employee_id' => '09242601',
        'name' => 'Jamie Cruz',
        'email' => 'jamie@pongskilog.test',
        'password' => 'Temporary-Pass-42',
        'password_confirmation' => 'Temporary-Pass-42',
        'role' => 'cashier',
        'branch_ids' => [test()->branch->id],
        ...$overrides,
    ];
}

function ownerCreatesStaff(array $payload, ?User $actor = null): TestResponse
{
    return test()->actingAs($actor ?? test()->owner)->post(route('staff.store'), $payload);
}

test('the owner staff page lists operational staff only with operational role options', function () {
    staffAccount('cashier', 'cashier@pongskilog.test', $this->branch);
    staffAccount('kitchen_staff', 'kitchen@pongskilog.test', $this->branch);
    staffAccount('super_admin', 'admin@pongskilog.test');
    staffAccount('owner', 'partner@pongskilog.test');

    $this->actingAs($this->owner)
        ->get(route('staff.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('super-admin/staff')
            ->where('surface', 'owner')
            ->where('staff.data', fn ($rows) => collect($rows)->pluck('email')->sort()->values()->all() === ['cashier@pongskilog.test', 'kitchen@pongskilog.test'])
            ->where('roles', fn ($roles) => collect($roles)->pluck('name')->all() === ['cashier', 'kitchen_staff', 'cashier_kitchen']));
});

test('operational staff cannot open or create owner staff', function (string $role) {
    $user = staffAccount($role, "{$role}@pongskilog.test", $this->branch);

    $this->actingAs($user)->get(route('staff.index'))->assertForbidden();
    ownerCreatesStaff(ownerStaffPayload(), $user)->assertForbidden();

    $this->assertDatabaseMissing('users', ['email' => 'jamie@pongskilog.test']);
})->with(['cashier', 'kitchen_staff', 'cashier_kitchen']);

test('the owner creates operational staff with a hashed password branch access and one audit', function (string $role) {
    ownerCreatesStaff(ownerStaffPayload(['role' => $role]))->assertRedirectToRoute('staff.index');

    $user = User::query()->where('email', 'jamie@pongskilog.test')->sole();
    $audit = AuditLog::query()->where('action', 'staff.created')->sole();

    expect($user->roles()->pluck('name')->all())->toBe([$role])
        ->and($user->branches()->pluck('branches.code')->all())->toBe(['ALPHA'])
        ->and(Hash::check('Temporary-Pass-42', $user->password))->toBeTrue()
        ->and($user->password)->not->toBe('Temporary-Pass-42')
        ->and($audit->user_id)->toBe($this->owner->id)
        ->and($audit->after['role'])->toBe($role)
        ->and(json_encode($audit->after))->not->toContain('Temporary-Pass-42');
})->with(['cashier', 'kitchen_staff', 'cashier_kitchen']);

test('the owner cannot create owner or super admin accounts', function (string $role) {
    ownerCreatesStaff(ownerStaffPayload(['role' => $role, 'branch_ids' => []]))
        ->assertSessionHasErrors(['role' => 'Choose a valid role.']);

    $this->assertDatabaseMissing('users', ['email' => 'jamie@pongskilog.test']);
    expect(AuditLog::query()->where('action', 'staff.created')->exists())->toBeFalse();
})->with(['owner', 'super_admin']);

test('owner created staff still need an active branch assignment', function (Closure $branchIds, string $field, string $message) {
    ownerCreatesStaff(ownerStaffPayload(['branch_ids' => $branchIds()]))->assertSessionHasErrors([$field => $message]);

    $this->assertDatabaseMissing('users', ['email' => 'jamie@pongskilog.test']);
})->with([
    'no branch' => [fn () => [], 'branch_ids', 'Choose at least one active Branch for this role.'],
    'inactive branch' => [fn () => [Branch::factory()->create(['status' => BranchStatus::Inactive])->id], 'branch_ids.0', 'Choose active Branches only.'],
]);

test('the owner sees private avatars of operational staff only', function () {
    Storage::fake('local');
    $admin = staffAccount('super_admin', 'admin@pongskilog.test');
    ownerCreatesStaff(ownerStaffPayload(['avatar' => UploadedFile::fake()->image('jamie.png', 200, 200)]))->assertRedirectToRoute('staff.index');
    $cashier = User::query()->where('email', 'jamie@pongskilog.test')->sole();
    $admin->forceFill(['avatar_path' => 'staff-avatars/'.$admin->id.'/admin.png'])->save();
    Storage::disk('local')->put($admin->avatar_path, 'image');

    $this->actingAs($this->owner)
        ->get(route('staff.index', ['search' => 'jamie@']))
        ->assertInertia(fn (Assert $page) => $page->where('staff.data.0.avatar_url', fn (string $url) => str_starts_with($url, '/workspaces/staff/'.$cashier->id.'/avatar?v=')));
    $this->actingAs($this->owner)->get(route('staff.avatar', $cashier))->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
    $this->actingAs($this->owner)->get(route('staff.avatar', $admin))->assertNotFound();
    $this->actingAs($this->owner)->get(route('super-admin.staff.avatar', $cashier))->assertForbidden();
});

test('super admin keeps full staff access through both staff surfaces', function () {
    $admin = staffAccount('super_admin', 'admin@pongskilog.test');
    staffAccount('owner', 'partner@pongskilog.test');

    $this->actingAs($admin)
        ->get(route('super-admin.staff.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('surface', 'super_admin')
            ->where('roles', fn ($roles) => collect($roles)->pluck('name')->all() === ['cashier', 'kitchen_staff', 'cashier_kitchen', 'owner', 'super_admin'])
            ->where('staff.data', fn ($rows) => collect($rows)->pluck('email')->contains('partner@pongskilog.test')));
    $this->actingAs($admin)->post(route('super-admin.staff.store'), ownerStaffPayload(['role' => 'owner', 'branch_ids' => []]))
        ->assertRedirectToRoute('super-admin.staff.index');

    expect(User::query()->where('email', 'jamie@pongskilog.test')->sole()->roles()->pluck('name')->all())->toBe(['owner']);
});
