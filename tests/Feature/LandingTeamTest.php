<?php

use App\Enums\PermissionOverrideEffect;
use App\Http\Controllers\LandingTeamController;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\LandingTeamMember;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\UserPermissionOverride;
use Database\Seeders\RbacSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->seed(RbacSeeder::class);
});

function landingTeamUser(string $role): User
{
    $user = User::factory()->create();
    $user->roles()->attach(Role::query()->where('name', $role)->sole());

    return $user;
}

/** One of the six cards the migration creates, by its corner label. */
function landingTeamMember(string $label): LandingTeamMember
{
    return LandingTeamMember::query()->where('role_label', $label)->sole();
}

/** @return array<string, mixed> */
function landingTeamCard(array $overrides = []): array
{
    return array_replace([
        'role_label' => 'CEO', 'role_title' => 'Chief Executive Officer', 'name' => 'Juan Dela Cruz',
        'bio' => 'Nagluluto mula 2022.', 'is_visible' => true,
    ], $overrides);
}

test('the landing page starts with six role cards that publish only what the business supplied', function () {
    $this->get(route('home'))->assertInertia(fn (Assert $page) => $page
        ->has('team', 6)
        ->where('team', fn ($team) => collect($team)->pluck('code')->all() === ['CEO', 'COO', 'CTO', 'CFO', 'CHEF', 'CMO'])
        ->where('team.0.name', null)->where('team.0.bio', null)->where('team.0.img', null)
        ->where('team.1.name', 'Shamarra Faustino')->where('team.1.title', 'Chief Operating Officer')
        ->where('team.1.img', '/images/landing-v130-a82ec97c.webp')
        ->missing('team.1.image_path'));

    foreach (LandingTeamMember::query()->whereNotNull('image_path')->pluck('image_path') as $bundled) {
        expect(file_get_contents(public_path($bundled), false, null, 8, 4))->toBe('WEBP');
    }
    /** The sample people of the V6 mock-up were never real staff. */
    expect(LandingTeamMember::query()->whereNotNull('name')->pluck('name')->all())
        ->toBe(['Shamarra Faustino', 'Samantha Faustino', 'Sabrina Cheang']);
});

test('a card edited in Website is on the landing page at the next visit and the edit is audited', function (string $role) {
    $member = landingTeamMember('CEO');
    $manager = landingTeamUser($role);

    $this->actingAs($manager)->putJson(route('workspaces.website.team.update', $member), landingTeamCard(['role_label' => 'BOSS']))
        ->assertOk()->assertExactJson(['saved' => true]);

    expect($member->fresh()->only(['role_label', 'role_title', 'name', 'bio', 'is_visible']))->toBe([
        'role_label' => 'BOSS', 'role_title' => 'Chief Executive Officer', 'name' => 'Juan Dela Cruz',
        'bio' => 'Nagluluto mula 2022.', 'is_visible' => true,
    ]);
    $audit = AuditLog::query()->where('action', 'landing_team.updated')->sole();
    expect($audit->user_id)->toBe($manager->id)->and($audit->branch_id)->toBeNull()
        ->and($audit->before['name'])->toBeNull()->and($audit->after['name'])->toBe('Juan Dela Cruz');
    auth()->logout();
    $this->get(route('home'))->assertInertia(fn (Assert $page) => $page
        ->where('team.0.code', 'BOSS')->where('team.0.name', 'Juan Dela Cruz')->where('team.0.bio', 'Nagluluto mula 2022.'));
})->with(['owner', 'super_admin']);

test('a name and bio can be cleared so the card shows as still to follow', function () {
    $member = landingTeamMember('COO');

    $this->actingAs(landingTeamUser('owner'))->putJson(route('workspaces.website.team.update', $member), landingTeamCard(['role_label' => 'COO', 'name' => '', 'bio' => null]))->assertOk();

    expect($member->fresh()->name)->toBeNull()->and($member->fresh()->bio)->toBeNull();
});

test('hiding a card takes it off the landing page and showing it brings it back', function () {
    $member = landingTeamMember('CHEF');
    $owner = landingTeamUser('owner');

    $this->actingAs($owner)->putJson(route('workspaces.website.team.update', $member), landingTeamCard(['role_label' => 'CHEF', 'is_visible' => false]))->assertOk();
    $this->get(route('home'))->assertInertia(fn (Assert $page) => $page
        ->has('team', 5)
        ->where('team', fn ($team) => ! collect($team)->pluck('code')->contains('CHEF')));
    /** The Owner still sees the hidden card in Website, marked as hidden. */
    $this->get(route('workspaces.website-analytics'))->assertInertia(fn (Assert $page) => $page
        ->has('team', 6)->where('team.4.code', 'CHEF')->where('team.4.visible', false));

    $this->putJson(route('workspaces.website.team.update', $member), landingTeamCard(['role_label' => 'CHEF', 'is_visible' => true]))->assertOk();
    $this->get(route('home'))->assertInertia(fn (Assert $page) => $page->has('team', 6)->where('team.4.code', 'CHEF'));
});

test('an invalid card returns 422 and changes nothing', function (string $field, mixed $value) {
    $member = landingTeamMember('COO');

    $this->actingAs(landingTeamUser('owner'))->putJson(route('workspaces.website.team.update', $member), landingTeamCard([$field => $value]))
        ->assertUnprocessable()->assertJsonValidationErrors($field);

    expect($member->fresh()->only(['role_label', 'name']))->toBe(['role_label' => 'COO', 'name' => 'Shamarra Faustino']);
})->with([
    'no role label' => ['role_label', ''],
    'role label over 12 characters' => ['role_label', str_repeat('x', 13)],
    'no role' => ['role_title', ''],
    'role over 60 characters' => ['role_title', str_repeat('x', 61)],
    'name over 60 characters' => ['name', str_repeat('x', 61)],
    'bio over 120 characters' => ['bio', str_repeat('x', 121)],
    'no visibility' => ['is_visible', null],
    'visibility that is not yes or no' => ['is_visible', 'sometimes'],
]);

test('an uploaded portrait is re-encoded to a bounded WebP on object storage, replaced, removed and audited', function () {
    $disk = Storage::fake('s3');
    $member = landingTeamMember('CEO');
    $owner = landingTeamUser('owner');

    $this->actingAs($owner)->post(route('workspaces.website.team.photo.store', $member), ['image' => UploadedFile::fake()->image('boss.jpg', 2400, 3200)], ['Accept' => 'application/json'])
        ->assertOk()->assertExactJson(['saved' => true]);
    $first = $member->fresh()->image_path;
    expect($first)->toStartWith('landing-team/'.$member->id.'/')->toEndWith('.webp');
    [$width, $height, $type] = getimagesizefromstring($disk->get($first));
    expect($type)->toBe(IMAGETYPE_WEBP)->and([$width, $height])->toBe([810, LandingTeamController::BOUND]);

    /** The landing page links a versioned URL, never the storage path, and anyone may load it. */
    $url = route('landing-team.photo', $member, false).'?v='.md5($first);
    auth()->logout();
    $this->get(route('home'))->assertInertia(fn (Assert $page) => $page->where('team.0.img', $url)->missing('team.0.image_path'));
    $this->get($url)->assertOk()->assertHeader('Content-Type', 'image/webp')->assertHeader('Cache-Control', 'immutable, max-age=31536000, public');
    $this->get(route('landing-team.photo', $member))->assertOk()->assertHeader('Cache-Control', 'no-cache, private');

    $this->actingAs($owner)->post(route('workspaces.website.team.photo.store', $member), ['image' => UploadedFile::fake()->image('new.png', 300, 400)], ['Accept' => 'application/json'])->assertOk();
    $second = $member->fresh()->image_path;
    expect($second)->not->toBe($first);
    $disk->assertMissing($first);
    $disk->assertExists($second);

    $this->deleteJson(route('workspaces.website.team.photo.destroy', $member))->assertOk()->assertExactJson(['saved' => true]);
    expect($member->fresh()->image_path)->toBeNull();
    $disk->assertMissing($second);
    $this->get($url)->assertNotFound();
    $this->get(route('home'))->assertInertia(fn (Assert $page) => $page->where('team.0.img', null));
    expect(AuditLog::query()->where('auditable_type', LandingTeamMember::class)->orderBy('id')->pluck('action')->all())
        ->toBe(['landing_team.photo_updated', 'landing_team.photo_updated', 'landing_team.photo_removed']);
});

test('replacing or removing a portrait that shipped with the release never deletes the bundled file', function () {
    Storage::fake('s3');
    $member = landingTeamMember('COO');
    $bundled = public_path($member->image_path);
    $owner = landingTeamUser('owner');

    $this->actingAs($owner)->post(route('workspaces.website.team.photo.store', $member), ['image' => UploadedFile::fake()->image('new.jpg', 600, 800)], ['Accept' => 'application/json'])->assertOk();
    expect($member->fresh()->hasUploadedPhoto())->toBeTrue()->and(file_exists($bundled))->toBeTrue();

    $cfo = landingTeamMember('CFO');
    $this->deleteJson(route('workspaces.website.team.photo.destroy', $cfo))->assertOk();
    expect($cfo->fresh()->image_path)->toBeNull()->and(file_exists(public_path('images/landing-v130-c719a715.webp')))->toBeTrue();
});

test('a file that is not a real image, or is too large, returns 422 and stores nothing', function (Closure $file) {
    $disk = Storage::fake('s3');
    $member = landingTeamMember('CEO');

    $this->actingAs(landingTeamUser('owner'))->post(route('workspaces.website.team.photo.store', $member), ['image' => $file()], ['Accept' => 'application/json'])
        ->assertUnprocessable()->assertJsonValidationErrors('image');

    expect($member->fresh()->image_path)->toBeNull()->and($disk->allFiles())->toBe([]);
})->with([
    'a document' => [fn () => UploadedFile::fake()->create('menu.pdf', 10, 'application/pdf')],
    'a file only named like an image' => [fn () => UploadedFile::fake()->create('fake.png', 10, 'image/png')],
    'an image over 5 MB' => [fn () => UploadedFile::fake()->image('huge.jpg')->size(LandingTeamController::MAX_KILOBYTES + 1)],
    'no file' => [fn () => null],
]);

test('the portrait of a hidden card is served only to the accounts that manage the team', function () {
    $disk = Storage::fake('s3');
    $member = landingTeamMember('CEO');
    $owner = landingTeamUser('owner');
    $this->actingAs($owner)->post(route('workspaces.website.team.photo.store', $member), ['image' => UploadedFile::fake()->image('boss.jpg', 600, 800)], ['Accept' => 'application/json'])->assertOk();
    $member->update(['is_visible' => false]);
    $url = route('landing-team.photo', $member, false).'?v='.md5($member->fresh()->image_path);

    $this->get($url)->assertOk()->assertHeader('Cache-Control', 'no-cache, private');
    $this->actingAs(landingTeamUser('cashier'))->get($url)->assertNotFound();
    auth()->logout();
    $this->get($url)->assertNotFound();
});

test('guests are refused and staff without website access are forbidden from every team change', function (string $role) {
    Storage::fake('s3');
    $member = landingTeamMember('COO');
    $photo = fn () => ['image' => UploadedFile::fake()->image('me.jpg', 300, 400)];

    $this->putJson(route('workspaces.website.team.update', $member), landingTeamCard())->assertUnauthorized();
    $this->postJson(route('workspaces.website.team.photo.store', $member), $photo())->assertUnauthorized();
    $this->deleteJson(route('workspaces.website.team.photo.destroy', $member))->assertUnauthorized();

    $this->actingAs(landingTeamUser($role));
    $this->putJson(route('workspaces.website.team.update', $member), landingTeamCard())->assertForbidden();
    $this->post(route('workspaces.website.team.photo.store', $member), $photo(), ['Accept' => 'application/json'])->assertForbidden();
    $this->deleteJson(route('workspaces.website.team.photo.destroy', $member))->assertForbidden();

    expect($member->fresh()->only(['name', 'image_path']))->toBe(['name' => 'Shamarra Faustino', 'image_path' => 'images/landing-v130-a82ec97c.webp']);
})->with(['cashier', 'kitchen_staff', 'cashier_kitchen']);

test('an Owner who is inactive or whose Settings access was removed cannot change the team', function (Closure $restrict) {
    $member = landingTeamMember('COO');
    $owner = landingTeamUser('owner');
    $restrict($owner);

    $response = $this->actingAs($owner)->putJson(route('workspaces.website.team.update', $member), landingTeamCard());

    expect($response->status())->toBeIn([401, 403])->and($member->fresh()->name)->toBe('Shamarra Faustino');
})->with([
    'inactive' => [fn (User $owner) => $owner->forceFill(['is_active' => false])->save()],
    'Settings denied' => [fn (User $owner) => UserPermissionOverride::query()->create([
        'user_id' => $owner->id, 'permission_id' => Permission::query()->where('name', 'settings.manage')->value('id'),
        'effect' => PermissionOverrideEffect::Deny,
    ])],
]);

test('Website gives each account only the tabs it may use', function () {
    Branch::factory()->create(['name' => 'Adarna', 'code' => 'MAIN']);

    $this->actingAs(landingTeamUser('owner'))->get(route('workspaces.website-analytics'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('can', ['analytics' => true, 'reviews' => true, 'team' => true, 'shippy' => true])
        ->has('team', 6)->has('reviewBranches', 1)->where('reviewBranches.0.code', 'MAIN')
        ->where('report.totals.heart_taps', 0));

    /** An Owner without Reports still manages Reviews and Team here, where Settings used to hold Reviews. */
    $content = landingTeamUser('owner');
    UserPermissionOverride::query()->create([
        'user_id' => $content->id, 'permission_id' => Permission::query()->where('name', 'reports.view')->value('id'),
        'effect' => PermissionOverrideEffect::Deny,
    ]);
    $this->actingAs($content)->get(route('workspaces.website-analytics'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('can', ['analytics' => false, 'reviews' => true, 'team' => true, 'shippy' => true])
        ->where('report', null)->has('team', 6));

    /** An Owner without Settings keeps Analytics and gets no website content. */
    $reader = landingTeamUser('owner');
    UserPermissionOverride::query()->create([
        'user_id' => $reader->id, 'permission_id' => Permission::query()->where('name', 'settings.manage')->value('id'),
        'effect' => PermissionOverrideEffect::Deny,
    ]);
    $this->actingAs($reader)->get(route('workspaces.website-analytics'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('can', ['analytics' => true, 'reviews' => false, 'team' => false, 'shippy' => false])
        ->where('team', [])->where('reviewBranches', []));
});

/** The team in landing page order, by corner label. */
function landingTeamOrder(): array
{
    return LandingTeamMember::query()->orderBy('position')->orderBy('id')->pluck('role_label')->all();
}

test('a member added in Website joins the end of the team on the landing page and the addition is audited', function (string $role) {
    $manager = landingTeamUser($role);

    $this->actingAs($manager)->postJson(route('workspaces.website.team.store'), landingTeamCard(['role_label' => 'CREW', 'role_title' => 'Kitchen Crew', 'name' => 'Ana Reyes']))
        ->assertOk()->assertExactJson(['saved' => true]);

    $member = LandingTeamMember::query()->where('role_label', 'CREW')->sole();
    expect($member->only(['position', 'role_title', 'name', 'bio', 'is_visible', 'image_path']))->toBe([
        'position' => 7, 'role_title' => 'Kitchen Crew', 'name' => 'Ana Reyes', 'bio' => 'Nagluluto mula 2022.', 'is_visible' => true, 'image_path' => null,
    ]);
    $audit = AuditLog::query()->where('action', 'landing_team.created')->sole();
    expect($audit->user_id)->toBe($manager->id)->and($audit->module)->toBe('settings')->and($audit->before)->toBeNull()
        ->and($audit->after)->toMatchArray(['role_label' => 'CREW', 'name' => 'Ana Reyes', 'has_photo' => false]);
    auth()->logout();
    $this->get(route('home'))->assertInertia(fn (Assert $page) => $page
        ->has('team', 7)->where('team.6.code', 'CREW')->where('team.6.name', 'Ana Reyes')->where('team.6.img', null));
})->with(['owner', 'super_admin']);

test('a member can be added with a portrait and can be added hidden', function () {
    $disk = Storage::fake('s3');
    $owner = landingTeamUser('owner');

    $this->actingAs($owner)->post(route('workspaces.website.team.store'), [...landingTeamCard(['role_label' => 'CREW', 'is_visible' => '0']), 'image' => UploadedFile::fake()->image('ana.jpg', 600, 800)], ['Accept' => 'application/json'])
        ->assertOk()->assertExactJson(['saved' => true]);

    $member = LandingTeamMember::query()->where('role_label', 'CREW')->sole();
    expect($member->is_visible)->toBeFalse()->and($member->image_path)->toStartWith('landing-team/'.$member->id.'/')->toEndWith('.webp');
    [, , $type] = getimagesizefromstring($disk->get($member->image_path));
    expect($type)->toBe(IMAGETYPE_WEBP)
        ->and(AuditLog::query()->where('action', 'landing_team.created')->sole()->after['has_photo'])->toBeTrue();
    /** A hidden member is in Website but not on the landing page. */
    $this->get(route('workspaces.website-analytics'))->assertInertia(fn (Assert $page) => $page->has('team', 7)->where('team.6.visible', false));
    auth()->logout();
    $this->get(route('home'))->assertInertia(fn (Assert $page) => $page->has('team', 6));
});

test('an invalid new member returns 422 and adds nobody', function (array $overrides, string $field) {
    $disk = Storage::fake('s3');

    $this->actingAs(landingTeamUser('owner'))->post(route('workspaces.website.team.store'), landingTeamCard(['role_label' => 'CREW', ...$overrides]), ['Accept' => 'application/json'])
        ->assertUnprocessable()->assertJsonValidationErrors($field);

    expect(LandingTeamMember::query()->count())->toBe(6)->and($disk->allFiles())->toBe([]);
})->with([
    'no role label' => [['role_label' => ''], 'role_label'],
    'no role' => [['role_title' => ''], 'role_title'],
    'a bio over 120 characters' => [['bio' => str_repeat('x', 121)], 'bio'],
    'no visibility' => [['is_visible' => null], 'is_visible'],
    'a portrait that is not a real image' => [['image' => UploadedFile::fake()->create('fake.png', 10, 'image/png')], 'image'],
]);

test('the team stops at thirty members with a message that says what to do', function () {
    LandingTeamMember::factory()->count(LandingTeamMember::MAX_MEMBERS - 6)->create();

    $this->actingAs(landingTeamUser('owner'))->postJson(route('workspaces.website.team.store'), landingTeamCard(['role_label' => 'CREW']))
        ->assertUnprocessable()->assertJsonPath('errors.name.0', 'The team already has 30 members. Delete one before adding another.');

    expect(LandingTeamMember::query()->count())->toBe(LandingTeamMember::MAX_MEMBERS);
});

test('a deleted member leaves the landing page, the members after them move up and the deletion is audited', function () {
    $disk = Storage::fake('s3');
    $owner = landingTeamUser('owner');
    $member = landingTeamMember('CTO');
    $this->actingAs($owner)->post(route('workspaces.website.team.photo.store', $member), ['image' => UploadedFile::fake()->image('cto.jpg', 600, 800)], ['Accept' => 'application/json'])->assertOk();
    $uploaded = $member->fresh()->image_path;
    $url = route('landing-team.photo', $member, false).'?v='.md5($uploaded);

    $this->deleteJson(route('workspaces.website.team.destroy', $member))->assertOk()->assertExactJson(['saved' => true]);

    expect(LandingTeamMember::query()->find($member->id))->toBeNull()
        ->and(landingTeamOrder())->toBe(['CEO', 'COO', 'CFO', 'CHEF', 'CMO'])
        ->and(LandingTeamMember::query()->orderBy('position')->pluck('position')->all())->toBe([1, 2, 3, 4, 5]);
    $disk->assertMissing($uploaded);
    /** The portrait that shipped with the release is a public file other installs still use. */
    expect(file_exists(public_path('images/landing-v130-cdf1eb05.webp')))->toBeTrue();
    $audit = AuditLog::query()->where('action', 'landing_team.deleted')->sole();
    expect($audit->user_id)->toBe($owner->id)->and($audit->after)->toBeNull()
        ->and($audit->before)->toMatchArray(['role_label' => 'CTO', 'name' => 'Samantha Faustino', 'has_photo' => true]);
    auth()->logout();
    $this->get($url)->assertNotFound();
    $this->get(route('home'))->assertInertia(fn (Assert $page) => $page
        ->has('team', 5)->where('team', fn ($team) => ! collect($team)->pluck('code')->contains('CTO')));
});

test('deleting a member that is already gone returns 404', function () {
    $member = landingTeamMember('CMO');
    $member->delete();

    $this->actingAs(landingTeamUser('owner'))->deleteJson(route('workspaces.website.team.destroy', $member->id))->assertNotFound();
});

test('the team is shown in the order saved in Website and the reorder is audited', function () {
    $owner = landingTeamUser('owner');
    $ids = LandingTeamMember::query()->orderBy('position')->pluck('id', 'role_label');
    $order = [$ids['CHEF'], $ids['CEO'], $ids['CMO'], $ids['COO'], $ids['CTO'], $ids['CFO']];

    $this->actingAs($owner)->putJson(route('workspaces.website.team.reorder'), ['ids' => $order])->assertOk()->assertExactJson(['saved' => true]);

    expect(landingTeamOrder())->toBe(['CHEF', 'CEO', 'CMO', 'COO', 'CTO', 'CFO']);
    $audit = AuditLog::query()->where('action', 'landing_team.reordered')->sole();
    expect($audit->user_id)->toBe($owner->id)->and($audit->before)->toBe(['order' => $ids->values()->all()])->and($audit->after)->toBe(['order' => $order]);
    $this->get(route('workspaces.website-analytics'))->assertInertia(fn (Assert $page) => $page
        ->where('team', fn ($team) => collect($team)->pluck('code')->all() === ['CHEF', 'CEO', 'CMO', 'COO', 'CTO', 'CFO']));
    auth()->logout();
    $this->get(route('home'))->assertInertia(fn (Assert $page) => $page
        ->where('team', fn ($team) => collect($team)->pluck('code')->all() === ['CHEF', 'CEO', 'CMO', 'COO', 'CTO', 'CFO']));

    /** Saving the same order again changes nothing and is not audited twice. */
    $this->actingAs($owner)->putJson(route('workspaces.website.team.reorder'), ['ids' => $order])->assertOk();
    expect(AuditLog::query()->where('action', 'landing_team.reordered')->count())->toBe(1);
});

test('an order that does not name every member exactly once returns 422 and keeps the order', function (Closure $ids) {
    $current = LandingTeamMember::query()->orderBy('position')->pluck('id')->all();

    $this->actingAs(landingTeamUser('owner'))->putJson(route('workspaces.website.team.reorder'), ['ids' => $ids($current)])
        ->assertUnprocessable();

    expect(landingTeamOrder())->toBe(['CEO', 'COO', 'CTO', 'CFO', 'CHEF', 'CMO'])
        ->and(AuditLog::query()->where('action', 'landing_team.reordered')->count())->toBe(0);
})->with([
    'a member left out' => [fn (array $ids) => array_slice($ids, 1)],
    'a member that does not exist' => [fn (array $ids) => [...array_slice($ids, 1), 999_999]],
    'a member named twice' => [fn (array $ids) => [...array_slice($ids, 1), $ids[1]]],
    'an empty list' => [fn (array $ids) => []],
    'ids that are not numbers' => [fn (array $ids) => array_map(fn (int $id) => 'member-'.$id, $ids)],
]);

test('a stale order tells the manager to refresh', function () {
    $ids = LandingTeamMember::query()->orderBy('position')->pluck('id')->all();
    LandingTeamMember::factory()->create();

    $this->actingAs(landingTeamUser('owner'))->putJson(route('workspaces.website.team.reorder'), ['ids' => array_reverse($ids)])
        ->assertUnprocessable()->assertJsonPath('errors.ids.0', 'The team changed since this page was loaded. Refresh and try again.');
});

test('guests are refused and staff without website access are forbidden from adding, deleting and reordering', function (string $role) {
    $member = landingTeamMember('COO');
    $ids = array_reverse(LandingTeamMember::query()->orderBy('position')->pluck('id')->all());

    $this->postJson(route('workspaces.website.team.store'), landingTeamCard())->assertUnauthorized();
    $this->deleteJson(route('workspaces.website.team.destroy', $member))->assertUnauthorized();
    $this->putJson(route('workspaces.website.team.reorder'), ['ids' => $ids])->assertUnauthorized();

    $this->actingAs(landingTeamUser($role));
    $this->postJson(route('workspaces.website.team.store'), landingTeamCard())->assertForbidden();
    $this->deleteJson(route('workspaces.website.team.destroy', $member))->assertForbidden();
    $this->putJson(route('workspaces.website.team.reorder'), ['ids' => $ids])->assertForbidden();

    expect(landingTeamOrder())->toBe(['CEO', 'COO', 'CTO', 'CFO', 'CHEF', 'CMO']);
})->with(['cashier', 'kitchen_staff', 'cashier_kitchen']);

test('an Owner whose Settings access was removed cannot add, delete or reorder', function () {
    $owner = landingTeamUser('owner');
    UserPermissionOverride::query()->create([
        'user_id' => $owner->id, 'permission_id' => Permission::query()->where('name', 'settings.manage')->value('id'),
        'effect' => PermissionOverrideEffect::Deny,
    ]);
    $this->actingAs($owner);

    $this->postJson(route('workspaces.website.team.store'), landingTeamCard())->assertForbidden();
    $this->deleteJson(route('workspaces.website.team.destroy', landingTeamMember('COO')))->assertForbidden();
    $this->putJson(route('workspaces.website.team.reorder'), ['ids' => array_reverse(LandingTeamMember::query()->pluck('id')->all())])->assertForbidden();

    expect(landingTeamOrder())->toBe(['CEO', 'COO', 'CTO', 'CFO', 'CHEF', 'CMO']);
});
