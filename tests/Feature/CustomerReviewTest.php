<?php

use App\Enums\BranchStatus;
use App\Events\AuditLogRecorded;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\CustomerReview;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/** @return array<string, mixed> */
function customerReviewPayload(Branch $branch, array $overrides = []): array
{
    return array_replace([
        'branch_id' => $branch->id, 'submission_key' => Str::uuid()->toString(),
        'rating' => 5, 'preferred_name' => 'Suki', 'description' => 'Loved the garlic rice!',
        'visibility' => 'public', 'acknowledgment' => true, 'public_consent' => true,
        'notice_version' => CustomerReview::NOTICE_VERSION,
    ], $overrides);
}

function customerReviewUser(string $role): User
{
    $user = User::factory()->create();
    $user->roles()->attach(Role::query()->where('name', $role)->sole());

    return $user;
}

/** A decodable PNG with a valid ancillary text chunk, sized using actual bytes rather than a fake size override. */
function customerReviewPhotoWithBytes(int $bytes): UploadedFile
{
    $contents = UploadedFile::fake()->image('photo.png', 2400, 1200)->getContent();
    $text = 'qa'.chr(0).str_repeat('x', $bytes - strlen($contents) - 15);
    $chunk = 'tEXt'.$text;
    $contents = substr($contents, 0, -12).pack('N', strlen($text)).$chunk.pack('N', crc32($chunk)).substr($contents, -12);

    return UploadedFile::fake()->createWithContent('photo.png', $contents);
}

test('a guest submits a pending public review with versioned consent and cannot assign moderation fields', function () {
    $this->freezeTime();
    $branch = Branch::factory()->create();
    $this->postJson(route('reviews.store'), customerReviewPayload($branch, ['status' => 'approved', 'moderated_by' => 999, 'images' => [['path' => 'private/secret']]]))
        ->assertExactJson(['saved' => true]);

    $review = CustomerReview::query()->sole();
    expect($review->status)->toBe('pending')->and($review->public_consent)->toBeTrue()
        ->and($review->notice_version)->toBe(CustomerReview::NOTICE_VERSION)
        ->and($review->consented_at->timestamp === now()->timestamp)->toBeTrue()
        ->and($review->images)->toBe([])->and($review->moderated_by)->toBeNull();
    $this->get(route('home'))->assertInertia(fn (Assert $page) => $page->has('reviews', 0));
});

test('a guest submits private feedback without public display permission', function () {
    $branch = Branch::factory()->create();
    $this->postJson(route('reviews.store'), customerReviewPayload($branch, ['visibility' => 'private', 'public_consent' => false]))->assertExactJson(['saved' => true]);
    expect(CustomerReview::query()->sole()->public_consent)->toBeFalse();
    $this->get(route('home'))->assertInertia(fn (Assert $page) => $page->has('reviews', 0));
});

test('missing review fields return 422 and create no record', function () {
    $this->postJson(route('reviews.store'), [])->assertUnprocessable()->assertJsonValidationErrors(['branch_id', 'submission_key', 'rating', 'preferred_name', 'description', 'visibility', 'acknowledgment', 'notice_version']);
    $this->assertDatabaseCount('customer_reviews', 0);
});

test('invalid review values return 422 without saving', function (string $field, mixed $value) {
    $branch = Branch::factory()->create();
    $this->postJson(route('reviews.store'), customerReviewPayload($branch, [$field => $value]))->assertUnprocessable()->assertJsonValidationErrors($field);
    $this->assertDatabaseCount('customer_reviews', 0);
})->with([
    'too long' => ['description', str_repeat('x', 201)], 'empty text' => ['description', '  '],
    'rating zero' => ['rating', 0], 'rating six' => ['rating', 6], 'fractional rating' => ['rating', 3.5],
    'long name' => ['preferred_name', str_repeat('x', 61)], 'no acknowledgment' => ['acknowledgment', false],
    'no public permission' => ['public_consent', false], 'outdated notice' => ['notice_version', 'old'],
    'spam honeypot' => ['website', 'https://spam.example'], 'invalid visibility' => ['visibility', 'approved'],
    'malformed email' => ['customer_email', 'not-an-email'], 'long email' => ['customer_email', str_repeat('x', 250).'@a.ph'],
    'invalid visit' => ['visit_type', 'delivery'], 'unknown branch' => ['branch_id', '00000000-0000-4000-8000-000000000001'],
]);

test('an optional customer email is stored encrypted and never leaves the server', function () {
    $this->seed(RbacSeeder::class);
    $branch = Branch::factory()->create();
    $this->postJson(route('reviews.store'), customerReviewPayload($branch, ['customer_email' => 'suki@example.com']))->assertExactJson(['saved' => true]);
    $this->postJson(route('reviews.store'), customerReviewPayload($branch, ['description' => 'No email this time.', 'customer_email' => '']))->assertOk();

    $review = CustomerReview::query()->whereNotNull('customer_email')->sole();
    expect($review->customer_email)->toBe('suki@example.com')
        ->and($review->getRawOriginal('customer_email'))->not->toContain('suki@example.com')
        ->and($review->toArray())->not->toHaveKey('customer_email');
    $this->assertDatabaseCount('customer_reviews', 2);
    $review->update(['status' => 'approved']);
    $this->get(route('home'))->assertInertia(fn (Assert $page) => $page->where('reviews.0.id', $review->id)->missing('reviews.0.customer_email'));
    $this->actingAs(customerReviewUser('owner'))->getJson(route('branches.reviews.index', [$branch, 'section' => 'approved']))
        ->assertJsonPath('reviews.0.id', $review->id)->assertJsonMissingPath('reviews.0.customer_email')->assertDontSee('suki@example.com');
});

test('the 200 unicode character boundary is accepted', function () {
    $branch = Branch::factory()->create();
    $this->postJson(route('reviews.store'), customerReviewPayload($branch, ['description' => str_repeat("\u{00e9}", 200)]))->assertOk();
    expect(mb_strlen(CustomerReview::query()->sole()->description))->toBe(200);
});

test('inactive branches do not receive guest reviews', function () {
    $branch = Branch::factory()->create(['status' => BranchStatus::Inactive]);
    $this->postJson(route('reviews.store'), customerReviewPayload($branch))->assertUnprocessable()->assertJsonValidationErrors('branch_id');
    $this->assertDatabaseCount('customer_reviews', 0);
});

test('retries and new keys for identical content save only one review', function () {
    $branch = Branch::factory()->create();
    $this->withCredentials()->withCookie(config('session.cookie'), Str::random(40));
    $payload = customerReviewPayload($branch);
    $this->postJson(route('reviews.store'), $payload)->assertOk();
    $this->postJson(route('reviews.store'), $payload)->assertOk();
    $this->postJson(route('reviews.store'), [...$payload, 'submission_key' => Str::uuid()->toString()])->assertOk();
    $this->assertDatabaseCount('customer_reviews', 1);
    $this->postJson(route('reviews.store'), [...$payload, 'description' => 'Different review'])->assertUnprocessable()->assertJsonValidationErrors('submission_key');
});

test('guest submission is rate limited independently from landing reads', function () {
    $branch = Branch::factory()->create();
    $this->withCredentials()->withCookie(config('session.cookie'), Str::random(40));
    for ($attempt = 0; $attempt < 5; $attempt++) {
        $this->postJson(route('reviews.store'), customerReviewPayload($branch, ['description' => 'Visit '.$attempt]))->assertOk();
    }
    $this->postJson(route('reviews.store'), customerReviewPayload($branch))->assertTooManyRequests();
    $this->get(route('home'))->assertOk();
    $this->assertDatabaseCount('customer_reviews', 5);
});

test('photos are resized reencoded as private WebP and only image metadata is stored', function () {
    $disk = Storage::fake('s3');
    $branch = Branch::factory()->create();
    $photo = UploadedFile::fake()->image('food.jpg', 2400, 1200);
    $profile = UploadedFile::fake()->image('avatar.png', 800, 800);
    $this->postJson(route('reviews.store'), customerReviewPayload($branch, ['profile_photo' => $profile, 'review_images' => [$photo]]))->assertOk();
    $review = CustomerReview::query()->sole();
    expect($review->images)->toHaveCount(2);
    foreach ($review->images as $image) {
        $disk->assertExists($image['path']);
        if (DIRECTORY_SEPARATOR !== '\\') {
            expect($disk->getVisibility($image['path']))->toBe('private');
        }
        $dimensions = getimagesizefromstring($disk->get($image['path']));
        expect($dimensions[2])->toBe(IMAGETYPE_WEBP)->and($image['width'])->toBe($image['kind'] === 'profile' ? 256 : 1200);
    }
    expect($review->images[1]['bytes'])->toBeLessThan($photo->getSize());
    $this->get(route('reviews.images.show', [$review, 0]))->assertNotFound();
});

test('three review photos and an optional profile at the real five MiB boundary are compressed and saved privately', function () {
    $disk = Storage::fake('s3');
    $branch = Branch::factory()->create();
    $photo = customerReviewPhotoWithBytes(5 * 1024 * 1024);
    expect($photo->getSize())->toBe(5242880);

    $this->post(route('reviews.store'), customerReviewPayload($branch, [
        'profile_photo' => $photo,
        'review_images' => [$photo, $photo, $photo],
    ]), ['Accept' => 'application/json'])->assertExactJson(['saved' => true]);

    $review = CustomerReview::query()->sole();
    expect($review->images)->toHaveCount(4);
    expect($disk->allFiles())->toHaveCount(4);
    foreach ($review->images as $index => $image) {
        $contents = $disk->get($image['path']);
        $dimensions = getimagesizefromstring($contents);
        expect($dimensions[2])->toBe(IMAGETYPE_WEBP);
        expect($dimensions[0])->toBe($index === 0 ? 256 : 1200);
        expect($image['bytes'])->toBe(strlen($contents))->toBeLessThan(5242880);
        $this->get(route('reviews.images.show', [$review, $index]))->assertNotFound();
    }
});

test('an original one byte above five MiB returns 422 without saving any attachments', function (string $field) {
    $disk = Storage::fake('s3');
    $branch = Branch::factory()->create();
    $photo = customerReviewPhotoWithBytes(5 * 1024 * 1024 + 1);
    expect($photo->getSize())->toBe(5242881);
    $attachments = $field === 'profile_photo' ? ['profile_photo' => $photo] : ['review_images' => [$photo]];

    $this->post(route('reviews.store'), customerReviewPayload($branch, $attachments), ['Accept' => 'application/json'])
        ->assertUnprocessable()->assertJsonValidationErrors($field);

    $this->assertDatabaseCount('customer_reviews', 0);
    expect($disk->allFiles())->toBe([]);
})->with(['profile_photo', 'review_images.0']);

test('oversized excessive spoofed or invalid-dimension images return 422', function (string $scenario, string $error) {
    Storage::fake('s3');
    $branch = Branch::factory()->create();
    $images = match ($scenario) {
        'size' => [UploadedFile::fake()->image('food.jpg')->size(5121)],
        'count' => array_map(fn () => UploadedFile::fake()->image('food.jpg'), range(1, 4)),
        'spoofed' => [UploadedFile::fake()->createWithContent('food.jpg', '<?php echo "bad";')],
        'dimensions' => [UploadedFile::fake()->image('tiny.jpg', 8, 8)],
    };
    $this->postJson(route('reviews.store'), customerReviewPayload($branch, ['review_images' => $images]))->assertUnprocessable()->assertJsonValidationErrors($error);
    $this->assertDatabaseCount('customer_reviews', 0);
    expect(Storage::disk('s3')->allFiles())->toBe([]);
})->with([['size', 'review_images.0'], ['count', 'review_images'], ['spoofed', 'review_images.0'], ['dimensions', 'review_images.0']]);

test('partial storage failure rolls back the record cleans the attempted directory and permits retry', function () {
    $branch = Branch::factory()->create();
    $disk = Mockery::mock(FilesystemAdapter::class);
    $directory = null;
    $disk->shouldReceive('put')->twice()->andReturnUsing(function (string $path, string $contents, array $options) use (&$directory): bool {
        expect($options['visibility'])->toBe('private');
        if ($directory === null) {
            $directory = dirname($path);

            return true;
        }

        return false;
    });
    $disk->shouldReceive('deleteDirectory')->once()->with(Mockery::on(function (string $path) use (&$directory): bool {
        return $path === $directory;
    }))->andReturn(true);
    Storage::shouldReceive('disk')->with('s3')->andReturn($disk);
    $payload = customerReviewPayload($branch, ['review_images' => [UploadedFile::fake()->image('a.jpg', 100, 100), UploadedFile::fake()->image('b.jpg', 100, 100)]]);
    $this->postJson(route('reviews.store'), $payload)->assertUnprocessable()->assertJsonValidationErrors('submission');
    $this->assertDatabaseCount('customer_reviews', 0);
    Storage::swap(new FilesystemManager($this->app));
    Storage::fake('s3');
    $this->postJson(route('reviews.store'), $payload)->assertExactJson(['saved' => true]);
    $this->assertDatabaseCount('customer_reviews', 1);
});

test('landing exposes only approved consenting public reviews of active branches', function () {
    $branch = Branch::factory()->create();
    $published = CustomerReview::factory()->for($branch)->create(['status' => 'approved']);
    CustomerReview::factory()->for($branch)->create();
    CustomerReview::factory()->for($branch)->create(['status' => 'rejected']);
    CustomerReview::factory()->for($branch)->create(['visibility' => 'private', 'status' => 'approved']);
    CustomerReview::factory()->for($branch)->create(['status' => 'approved', 'public_consent' => false]);
    CustomerReview::factory()->for(Branch::factory()->create(['status' => BranchStatus::Inactive]))->create(['status' => 'approved']);
    $this->get(route('home'))->assertInertia(fn (Assert $page) => $page->has('reviews', 1)->where('reviews.0.id', $published->id)->missing('reviews.0.submission_digest')->missing('reviews.0.consented_at')->missing('reviews.0.branch_id'));
});

test('the owner filters by branch approves rejects and audits without customer text', function () {
    $this->seed(RbacSeeder::class);
    $owner = customerReviewUser('owner');
    $review = CustomerReview::factory()->create();
    CustomerReview::factory()->create();
    Event::fake([AuditLogRecorded::class]);
    $this->actingAs($owner)->getJson(route('branches.reviews.index', $review->branch))->assertJsonCount(1, 'reviews')->assertJsonPath('reviews.0.id', $review->id);
    $this->putJson(route('branches.reviews.update', [$review->branch, $review]), ['status' => 'approved'])->assertExactJson(['saved' => true]);
    expect($review->fresh()->status)->toBe('approved');
    $audit = AuditLog::query()->where('action', 'customer_review.approved')->sole();
    expect($audit->user_id)->toBe($owner->id)->and($audit->branch_id)->toBe($review->branch_id)
        ->and($audit->after)->toBe(['status' => 'approved', 'visibility' => 'public']);
    Event::assertDispatched(AuditLogRecorded::class);
    $this->putJson(route('branches.reviews.update', [$review->branch, $review]), ['status' => 'rejected'])->assertOk();
    expect($review->fresh()->status)->toBe('rejected');
    $this->get(route('home'))->assertInertia(fn (Assert $page) => $page->has('reviews', 0));
});

test('accounts other than the Owner and Super Admin cannot manage reviews', function (string $role) {
    $this->seed(RbacSeeder::class);
    $user = customerReviewUser($role);
    $review = CustomerReview::factory()->create();
    $this->actingAs($user)->getJson(route('branches.reviews.index', $review->branch))->assertForbidden();
    $this->putJson(route('branches.reviews.update', [$review->branch, $review]), ['status' => 'approved'])->assertForbidden();
    $this->deleteJson(route('branches.reviews.destroy', [$review->branch, $review]))->assertForbidden();
    expect($review->fresh()->status)->toBe('pending');
})->with(['cashier', 'kitchen_staff', 'cashier_kitchen']);

test('a Super Admin opens Reviews in Settings and approves and rejects but cannot publish private feedback', function () {
    $this->seed(RbacSeeder::class);
    $admin = customerReviewUser('super_admin');
    $review = CustomerReview::factory()->create();
    $private = CustomerReview::factory()->for($review->branch)->create(['visibility' => 'private', 'public_consent' => false]);
    $this->actingAs($admin)->get(route('branches.index'))->assertInertia(fn (Assert $page) => $page->where('scope.can_manage_reviews', true));
    $this->getJson(route('branches.reviews.index', $review->branch))->assertJsonCount(1, 'reviews')->assertJsonPath('reviews.0.id', $review->id);
    $this->putJson(route('branches.reviews.update', [$review->branch, $review]), ['status' => 'approved'])->assertExactJson(['saved' => true]);
    expect($review->fresh()->status)->toBe('approved')->and($review->fresh()->moderated_by)->toBe($admin->id);
    $this->putJson(route('branches.reviews.update', [$review->branch, $review]), ['status' => 'rejected'])->assertOk();
    expect($review->fresh()->status)->toBe('rejected');
    $this->getJson(route('branches.reviews.index', [$review->branch, 'section' => 'private']))->assertJsonPath('reviews.0.id', $private->id);
    $this->putJson(route('branches.reviews.update', [$review->branch, $private]), ['status' => 'approved'])->assertForbidden();
    expect($private->fresh()->status)->toBe('pending');
});

test('a mismatched branch review route returns 404 without changing the review', function () {
    $this->seed(RbacSeeder::class);
    $owner = customerReviewUser('owner');
    $review = CustomerReview::factory()->create();
    $other = Branch::factory()->create();
    $this->actingAs($owner)->putJson(route('branches.reviews.update', [$other, $review]), ['status' => 'approved'])->assertNotFound();
    $this->deleteJson(route('branches.reviews.destroy', [$other, $review]))->assertNotFound();
    expect($review->fresh()->status)->toBe('pending');
});

test('private feedback and its photos are Owner-only and cannot be published even by the Owner', function () {
    $this->seed(RbacSeeder::class);
    $owner = customerReviewUser('owner');
    $disk = Storage::fake('s3');
    $review = CustomerReview::factory()->create(['visibility' => 'private', 'public_consent' => false]);
    $path = 'reviews/'.$review->id.'/'.Str::uuid().'.webp';
    $disk->put($path, 'encoded image', 'private');
    $review->update(['images' => [['path' => $path, 'kind' => 'review', 'width' => 100, 'height' => 100, 'bytes' => 13]]]);
    $this->get(route('reviews.images.show', [$review, 0]))->assertNotFound();
    $this->actingAs($owner)->getJson(route('branches.reviews.index', [$review->branch, 'section' => 'private']))->assertJsonPath('reviews.0.id', $review->id);
    $this->get(route('reviews.images.show', [$review, 0]))->assertOk()->assertHeader('Cache-Control', 'no-store, private');
    $this->putJson(route('branches.reviews.update', [$review->branch, $review]), ['status' => 'approved', 'visibility' => 'public'])->assertForbidden();
    expect($review->fresh()->visibility)->toBe('private');
});

test('approved public photos revalidate and become inaccessible after rejection', function () {
    $disk = Storage::fake('s3');
    $review = CustomerReview::factory()->create(['status' => 'approved']);
    $path = 'reviews/'.$review->id.'/'.Str::uuid().'.webp';
    $disk->put($path, 'image', 'private');
    $review->update(['images' => [['path' => $path, 'kind' => 'review', 'width' => 100, 'height' => 100, 'bytes' => 5]]]);
    $response = $this->get(route('reviews.images.show', [$review, 0]))->assertOk()->assertHeader('Content-Type', 'image/webp');
    $this->withHeader('If-None-Match', $response->headers->get('ETag'))->get(route('reviews.images.show', [$review, 0]))->assertStatus(304);
    $review->update(['status' => 'rejected']);
    $this->get(route('reviews.images.show', [$review, 0]))->assertNotFound();
});

test('Owner removal deletes the record and images and records a privacy-minimal audit', function () {
    $this->seed(RbacSeeder::class);
    $owner = customerReviewUser('owner');
    $disk = Storage::fake('s3');
    $review = CustomerReview::factory()->create();
    $path = 'reviews/'.$review->id.'/photo.webp';
    $disk->put($path, 'image');
    Event::fake([AuditLogRecorded::class]);
    $this->actingAs($owner)->deleteJson(route('branches.reviews.destroy', [$review->branch, $review]))->assertExactJson(['saved' => true]);
    $this->assertModelMissing($review);
    $disk->assertMissing($path);
    $this->assertDatabaseHas('audit_logs', ['action' => 'customer_review.removed', 'auditable_id' => $review->id]);
});

test('retention removes expired feedback and old orphan files while preserving current reviews and fresh uploads', function () {
    $this->freezeTime();
    $disk = Storage::fake('s3');
    $expired = CustomerReview::factory()->create(['visibility' => 'private', 'created_at' => now()->subDays(91)]);
    $current = CustomerReview::factory()->create(['status' => 'approved', 'created_at' => now()->subDays(100)]);
    $expiredPublic = CustomerReview::factory()->create(['status' => 'approved', 'created_at' => now()->subDays(366)]);
    $disk->put('reviews/'.$expired->id.'/photo.webp', 'expired');
    $disk->put('reviews/'.$current->id.'/photo.webp', 'current');
    $orphan = 'reviews/'.Str::uuid().'/photo.webp';
    $fresh = 'reviews/'.Str::uuid().'/photo.webp';
    $disk->put($orphan, 'old');
    touch($disk->path($orphan), now()->subDays(2)->timestamp);
    $disk->put($fresh, 'fresh');
    $this->artisan('reviews:prune')->assertSuccessful();
    $this->assertModelMissing($expired);
    $this->assertModelMissing($expiredPublic);
    $this->assertModelExists($current);
    $disk->assertMissing($orphan);
    $disk->assertExists($fresh);
    $disk->assertExists('reviews/'.$current->id.'/photo.webp');
});
