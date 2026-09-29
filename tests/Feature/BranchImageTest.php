<?php

use App\Http\Controllers\BranchImageController;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    $this->seed(RbacSeeder::class);
});

function branchImageUser(string $role, ?Branch $branch = null): User
{
    $user = User::factory()->create();
    $user->roles()->attach(Role::query()->where('name', $role)->sole());
    if ($branch !== null) {
        $user->branches()->attach($branch, ['is_active' => true]);
    }

    return $user;
}

test('a Branch store photo is re-encoded to WebP, versioned, replaceable and removable, all audited', function () {
    $disk = Storage::fake('s3');
    $branch = Branch::factory()->create();
    $owner = branchImageUser('owner');

    $this->actingAs($owner)->post(route('branches.image.store', $branch), ['image' => UploadedFile::fake()->image('store.jpg', 2400, 1600)], ['Accept' => 'application/json'])
        ->assertOk();
    $first = $branch->fresh()->image_path;
    expect($first)->toStartWith('branch-images/'.$branch->id.'/')->toEndWith('.webp');
    $disk->assertExists($first);
    [$width, $height, $type] = getimagesizefromstring($disk->get($first));
    expect($type)->toBe(IMAGETYPE_WEBP)->and(max($width, $height))->toBe(BranchImageController::BOUND);

    $url = BranchImageController::url($branch->fresh());
    expect($url)->toBe(route('branches.image.show', $branch, false).'?v='.md5($first));
    $this->get($url)->assertOk()->assertHeader('Content-Type', 'image/webp')
        ->assertHeader('Cache-Control', 'immutable, max-age=31536000, private');
    $this->get(route('branches.index'))->assertInertia(fn (AssertableInertia $page) => $page
        ->where('branches.0.image_url', $url)->missing('branches.0.image_path'));

    $this->post(route('branches.image.store', $branch), ['image' => UploadedFile::fake()->image('new.png', 300, 200)], ['Accept' => 'application/json'])->assertOk();
    $second = $branch->fresh()->image_path;
    expect($second)->not->toBe($first);
    $disk->assertMissing($first);
    $disk->assertExists($second);

    $this->deleteJson(route('branches.image.destroy', $branch))->assertOk();
    expect($branch->fresh()->image_path)->toBeNull();
    $disk->assertMissing($second);
    $this->get(route('branches.image.show', $branch))->assertNotFound();

    expect(AuditLog::query()->where('auditable_id', $branch->id)->orderBy('created_at')->pluck('action')->all())
        ->toEqualCanonicalizing(['branch.image_updated', 'branch.image_updated', 'branch.image_removed']);
});

test('Branch photos reject non-images, oversized files and staff without Branch settings access', function () {
    $disk = Storage::fake('s3');
    $branch = Branch::factory()->create();
    $url = route('branches.image.store', $branch);

    $this->postJson($url, ['image' => UploadedFile::fake()->image('store.jpg')])->assertUnauthorized();
    $this->actingAs(branchImageUser('cashier', $branch))->post($url, ['image' => UploadedFile::fake()->image('store.jpg')], ['Accept' => 'application/json'])->assertForbidden();
    $this->actingAs(branchImageUser('cashier', $branch))->deleteJson(route('branches.image.destroy', $branch))->assertForbidden();

    $owner = branchImageUser('owner');
    $this->actingAs($owner)->post($url, ['image' => UploadedFile::fake()->create('menu.pdf', 10, 'application/pdf')], ['Accept' => 'application/json'])->assertUnprocessable();
    $this->actingAs($owner)->post($url, ['image' => UploadedFile::fake()->create('fake.png', 10, 'image/png')], ['Accept' => 'application/json'])->assertUnprocessable();
    $this->actingAs($owner)->post($url, ['image' => UploadedFile::fake()->image('huge.jpg')->size(BranchImageController::MAX_KILOBYTES + 1)], ['Accept' => 'application/json'])->assertUnprocessable();

    expect($branch->fresh()->image_path)->toBeNull();
    expect($disk->allFiles())->toBe([]);
    expect(AuditLog::query()->count())->toBe(0);
});

test('a Branch photo is visible only to staff of that Branch', function () {
    $disk = Storage::fake('s3');
    $branch = Branch::factory()->create(['image_path' => 'branch-images/photo.webp']);
    $disk->put('branch-images/photo.webp', 'webp');
    $url = BranchImageController::url($branch);

    $this->get($url)->assertRedirect(route('login'));
    $this->actingAs(branchImageUser('cashier', Branch::factory()->create()))->get($url)->assertForbidden();
    $this->actingAs(branchImageUser('cashier', $branch))->get($url)->assertOk();
    expect($this->get(route('branches.image.show', $branch).'?v=old')->headers->get('Cache-Control'))->toContain('no-cache');
});
