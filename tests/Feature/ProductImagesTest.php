<?php

use App\Actions\Catalog\RemoveProductImage;
use App\Actions\Catalog\ReplaceProductImage;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use App\Support\ProductImages;
use Database\Seeders\RbacSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

function productImageManager(string $role = 'owner'): User
{
    test()->seed(RbacSeeder::class);
    $user = User::factory()->create();
    $user->roles()->attach(Role::query()->where('name', $role)->sole());

    return $user;
}

function productWithImage(): Product
{
    $product = Product::factory()->create();
    $directory = 'catalog/products/'.$product->id.'/'.Str::uuid();
    foreach (['source.jpg', 'card.webp', 'detail.webp'] as $filename) {
        Storage::disk('s3')->put($directory.'/'.$filename, 'existing image');
    }
    $product->update(['image_path' => $directory.'/detail.webp']);

    return $product;
}

function productImageFaultDisk(FilesystemAdapter $disk): FilesystemAdapter
{
    $mock = Mockery::mock(FilesystemAdapter::class, [$disk->getDriver(), $disk->getAdapter(), $disk->getConfig()])->makePartial();
    Storage::set('s3', $mock);

    return $mock;
}

test('catalog managers can replace product images', function (string $role) {
    $disk = Storage::fake('s3');
    $user = productImageManager($role);
    $product = Product::factory()->create();

    $updated = app(ReplaceProductImage::class)->execute($user, $product, UploadedFile::fake()->image('meal.jpg'));

    $this->assertDatabaseHas('products', ['id' => $product->id, 'image_path' => $updated->image_path]);
    $disk->assertExists($updated->image_path);
})->with(['owner', 'super_admin']);

test('image mutations reject staff roles and preserve the existing asset', function (string $role, string $action) {
    $disk = Storage::fake('s3');
    $user = productImageManager($role);
    $product = productWithImage();
    $oldPath = $product->image_path;

    expect(fn () => app($action)->execute($user, $product, UploadedFile::fake()->image('meal.jpg')))
        ->toThrow(AuthorizationException::class);

    expect($product->refresh()->image_path)->toBe($oldPath);
    $disk->assertCount('catalog', 3, true);
})->with(['cashier', 'kitchen_staff', 'cashier_kitchen'])->with([ReplaceProductImage::class, RemoveProductImage::class]);

test('image mutations recheck persisted user activity and permissions', function (string $change, string $action) {
    $disk = Storage::fake('s3');
    $user = productImageManager();
    $product = productWithImage();
    $oldPath = $product->image_path;
    $user->load('roles.permissions');
    if ($change === 'inactive') {
        User::query()->whereKey($user->id)->update(['is_active' => false]);
    } else {
        $user->roles()->firstOrFail()->permissions()->detach();
    }

    expect(fn () => app($action)->execute($user, $product, UploadedFile::fake()->image('meal.jpg')))
        ->toThrow(AuthorizationException::class);

    expect($product->refresh()->image_path)->toBe($oldPath);
    $disk->assertCount('catalog', 3, true);
})->with(['inactive', 'revoked'])->with([ReplaceProductImage::class, RemoveProductImage::class]);

test('image mutations require a persisted product', function (string $state, string $action) {
    $disk = Storage::fake('s3');
    $user = productImageManager();
    $product = Product::factory()->make();
    if ($state === 'deleted') {
        $product = Product::factory()->create();
        $product->delete();
    }

    expect(fn () => app($action)->execute($user, $product, UploadedFile::fake()->image('meal.jpg')))
        ->toThrow(ModelNotFoundException::class);

    $disk->assertDirectoryEmpty('/');
})->with(['unsaved', 'deleted'])->with([ReplaceProductImage::class, RemoveProductImage::class]);

test('supported uploads produce two valid WebP variants and retain a private source', function (string $extension) {
    $disk = Storage::fake('s3');
    $user = productImageManager();
    $product = Product::factory()->create();
    $upload = UploadedFile::fake()->image('client-secret-name.'.$extension, 1600, 800);

    $updated = app(ReplaceProductImage::class)->execute($user, $product, $upload);

    $images = app(ProductImages::class);
    $directory = $images->assetDirectory($updated);
    expect(basename($directory))->toBeUuid();
    expect($directory)->toStartWith('catalog/products/'.$product->id.'/')->not->toContain('client-secret-name');
    expect($updated->image_path)->toBe($directory.'/detail.webp')->not->toContain('https:', '?');
    expect($images->cardPath($updated))->toBe($directory.'/card.webp');
    $disk->assertCount($directory, 3);
    expect($disk->get($directory.'/source.'.($extension === 'jpeg' ? 'jpg' : $extension)))->toBe($upload->getContent());
    foreach (['card' => [480, 240], 'detail' => [1200, 600]] as $variant => $expected) {
        $contents = $disk->get($directory.'/'.$variant.'.webp');
        $size = getimagesizefromstring($contents);
        expect([$size[0], $size[1]])->toBe($expected);
        expect($size['mime'])->toBe('image/webp');
        expect(imagecreatefromstring($contents))->toBeInstanceOf(GdImage::class);
    }
})->with(['jpg', 'jpeg', 'png', 'webp']);

test('variants preserve portrait and small source sizes without cropping or upscaling', function (int $width, int $height, array $card, array $detail) {
    $disk = Storage::fake('s3');
    $user = productImageManager();
    $product = Product::factory()->create();

    $updated = app(ReplaceProductImage::class)->execute($user, $product, UploadedFile::fake()->image('meal.png', $width, $height));

    $directory = dirname($updated->image_path);
    expect(array_slice(getimagesizefromstring($disk->get($directory.'/card.webp')), 0, 2))->toBe($card);
    expect(array_slice(getimagesizefromstring($disk->get($directory.'/detail.webp')), 0, 2))->toBe($detail);
})->with([
    'portrait' => [800, 1600, [240, 480], [600, 1200]],
    'small' => [120, 80, [120, 80], [120, 80]],
    'between bounds' => [600, 300, [480, 240], [600, 300]],
]);

test('image content determines the source extension instead of the client filename', function () {
    $disk = Storage::fake('s3');
    $user = productImageManager();
    $product = Product::factory()->create();
    $png = UploadedFile::fake()->image('actual.png');
    $upload = UploadedFile::fake()->createWithContent('misleading.jpg', $png->getContent());

    $updated = app(ReplaceProductImage::class)->execute($user, $product, $upload);

    $disk->assertExists(dirname($updated->image_path).'/source.png');
});

test('invalid images are rejected without changing the existing asset', function (Closure $upload) {
    $disk = Storage::fake('s3');
    $user = productImageManager();
    $product = productWithImage();
    $oldPath = $product->image_path;

    try {
        app(ReplaceProductImage::class)->execute($user, $product, $upload());
        $this->fail('Invalid product image was accepted.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('image');
        expect($exception->errors()['image'])->not->toBeEmpty();
    }

    expect($product->refresh()->image_path)->toBe($oldPath);
    $disk->assertCount('catalog', 3, true);
})->with([
    'text disguised as jpeg' => fn () => UploadedFile::fake()->createWithContent('photo.jpg', 'not an image'),
    'executable' => fn () => UploadedFile::fake()->createWithContent('photo.jpg', '<?php echo 1;'),
    'pdf' => fn () => UploadedFile::fake()->createWithContent('photo.jpg', '%PDF-1.4 document'),
    'svg' => fn () => UploadedFile::fake()->createWithContent('photo.svg', '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"></svg>'),
    'gif' => fn () => UploadedFile::fake()->image('photo.gif'),
    'corrupted png' => fn () => UploadedFile::fake()->createWithContent('photo.png', substr(UploadedFile::fake()->image('photo.png', 20, 20)->getContent(), 0, 50)),
    'over 8 MB' => fn () => UploadedFile::fake()->image('photo.jpg')->size(8193),
    'too wide' => fn () => UploadedFile::fake()->image('photo.png', 6001, 1),
    'too tall' => fn () => UploadedFile::fake()->image('photo.png', 1, 6001),
]);

test('replacement verifies all new objects before saving and removes only the persisted old asset', function () {
    $disk = Storage::fake('s3');
    $user = productImageManager();
    $product = productWithImage();
    $oldPath = $product->image_path;
    $other = productWithImage();
    $product->image_path = $other->image_path;
    Event::listen('eloquent.updating: '.Product::class, function (Product $saving) use ($disk, $oldPath) {
        $directory = dirname($saving->image_path);
        $disk->assertExists([$directory.'/source.jpg', $directory.'/card.webp', $directory.'/detail.webp', $oldPath]);
        expect(Product::findOrFail($saving->id)->image_path)->toBe($oldPath);
    });

    $updated = app(ReplaceProductImage::class)->execute($user, $product, UploadedFile::fake()->image('meal.jpg'));

    expect(dirname($updated->image_path))->not->toBe(dirname($oldPath));
    $disk->assertDirectoryEmpty(dirname($oldPath));
    $disk->assertExists($other->image_path);
    expect($product->refresh()->image_path)->toBe($updated->image_path);
});

test('failed writes or existence checks preserve the old image and clean partial new uploads', function (string $failure) {
    $disk = Storage::fake('s3');
    $user = productImageManager();
    $product = productWithImage();
    $oldPath = $product->image_path;
    $faulty = productImageFaultDisk($disk);
    if ($failure === 'missing object') {
        $faulty->shouldReceive('exists')->withArgs(fn (string $path) => str_ends_with($path, '/card.webp'))->andReturnFalse();
    } elseif ($failure === 'exception') {
        $faulty->shouldReceive('put')->withArgs(fn (string $path, string $contents) => str_ends_with($path, '/card.webp'))
            ->andThrow(new RuntimeException('Primary upload failed.'));
    } else {
        $faulty->shouldReceive('put')->withArgs(fn (string $path, string $contents) => str_ends_with($path, '/'.$failure))->andReturnFalse();
    }

    expect(fn () => app(ReplaceProductImage::class)->execute($user, $product, UploadedFile::fake()->image('meal.jpg')))
        ->toThrow(RuntimeException::class);

    expect($product->refresh()->image_path)->toBe($oldPath);
    $disk->assertCount('catalog', 3, true);
    $disk->assertExists($oldPath);
})->with(['source.jpg', 'card.webp', 'detail.webp', 'missing object', 'exception']);

test('failed database saves roll back the reference and remove new uploads', function (string $failure) {
    $disk = Storage::fake('s3');
    $user = productImageManager();
    $product = productWithImage();
    $oldPath = $product->image_path;
    if ($failure === 'cancelled') {
        Event::listen('eloquent.updating: '.Product::class, fn () => false);
    } else {
        Event::listen('eloquent.updated: '.Product::class, fn () => throw new RuntimeException('Database operation failed.'));
    }

    expect(fn () => app(ReplaceProductImage::class)->execute($user, $product, UploadedFile::fake()->image('meal.jpg')))
        ->toThrow(RuntimeException::class);

    expect($product->refresh()->image_path)->toBe($oldPath);
    $disk->assertCount('catalog', 3, true);
    $disk->assertExists($oldPath);
})->with(['cancelled', 'exception after write']);

test('an outer transaction rollback preserves the old asset and cleans the uncommitted replacement', function () {
    $disk = Storage::fake('s3');
    $user = productImageManager();
    $product = productWithImage();
    $oldPath = $product->image_path;

    expect(function () use ($disk, $user, $product, $oldPath) {
        DB::transaction(function () use ($disk, $user, $product, $oldPath) {
            app(ReplaceProductImage::class)->execute($user, $product, UploadedFile::fake()->image('meal.jpg'));
            $disk->assertExists($oldPath);
            $disk->assertCount('catalog', 6, true);
            throw new RuntimeException('Caller rolled back.');
        });
    })->toThrow(RuntimeException::class, 'Caller rolled back.');

    expect($product->refresh()->image_path)->toBe($oldPath);
    $disk->assertCount('catalog', 3, true);
});

test('old asset cleanup failures are logged safely without undoing a successful replacement', function (bool $throws) {
    $disk = Storage::fake('s3');
    $user = productImageManager();
    $product = productWithImage();
    $oldPath = $product->image_path;
    $faulty = productImageFaultDisk($disk);
    $expectation = $faulty->shouldReceive('deleteDirectory')->with(dirname($oldPath));
    if ($throws) {
        $expectation->andThrow(new RuntimeException('secret-in-storage-error'));
    } else {
        $expectation->andReturnFalse();
    }
    Log::shouldReceive('warning')->once()->with('Product image asset cleanup failed.', [
        'product_id' => $product->id, 'exception_type' => RuntimeException::class,
    ]);

    $updated = app(ReplaceProductImage::class)->execute($user, $product, UploadedFile::fake()->image('meal.jpg'));

    expect($product->refresh()->image_path)->toBe($updated->image_path)->not->toBe($oldPath);
    $disk->assertExists($updated->image_path);
})->with([false, true]);

test('partial asset cleanup failure does not replace the primary upload exception', function () {
    $disk = Storage::fake('s3');
    $user = productImageManager();
    $product = productWithImage();
    $oldPath = $product->image_path;
    $faulty = productImageFaultDisk($disk);
    $faulty->shouldReceive('put')->withArgs(fn (string $path, string $contents) => str_ends_with($path, '/card.webp'))
        ->andThrow(new RuntimeException('Primary failure.'));
    $faulty->shouldReceive('deleteDirectory')->andThrow(new RuntimeException('Cleanup secret.'));
    Log::shouldReceive('warning')->once()->with('Product image asset cleanup failed.', [
        'product_id' => $product->id, 'exception_type' => RuntimeException::class,
    ]);

    expect(fn () => app(ReplaceProductImage::class)->execute($user, $product, UploadedFile::fake()->image('meal.jpg')))
        ->toThrow(RuntimeException::class, 'Primary failure.');

    expect($product->refresh()->image_path)->toBe($oldPath);
    $disk->assertExists($oldPath);
});

test('managers remove the image reference before deleting the old asset', function (string $role) {
    $disk = Storage::fake('s3');
    $user = productImageManager($role);
    $product = productWithImage();
    $directory = dirname($product->image_path);
    $faulty = productImageFaultDisk($disk);
    $faulty->shouldReceive('deleteDirectory')->once()->with($directory)->andReturnUsing(function (string $path) use ($disk, $product) {
        expect($product->refresh()->image_path)->toBeNull();

        return $disk->deleteDirectory($path);
    });

    $updated = app(RemoveProductImage::class)->execute($user, $product);

    expect($updated->image_path)->toBeNull();
    $disk->assertDirectoryEmpty($directory);
})->with(['owner', 'super_admin']);

test('removing an absent image is harmless', function () {
    $disk = Storage::fake('s3');
    $user = productImageManager();
    $product = Product::factory()->create();

    app(RemoveProductImage::class)->execute($user, $product);

    expect($product->refresh()->image_path)->toBeNull();
    $disk->assertDirectoryEmpty('/');
});

test('removal cleanup failure keeps the safely cleared reference', function () {
    $disk = Storage::fake('s3');
    $user = productImageManager();
    $product = productWithImage();
    $oldPath = $product->image_path;
    productImageFaultDisk($disk)->shouldReceive('deleteDirectory')->andReturnFalse();
    Log::shouldReceive('warning')->once()->with('Product image asset cleanup failed.', [
        'product_id' => $product->id, 'exception_type' => RuntimeException::class,
    ]);

    app(RemoveProductImage::class)->execute($user, $product);

    expect($product->refresh()->image_path)->toBeNull();
    $disk->assertExists($oldPath);
});

test('products without an image have null paths and URLs', function () {
    $images = app(ProductImages::class);
    $product = Product::factory()->make();
    Storage::shouldReceive('disk')->never();

    expect($images->assetDirectory($product))->toBeNull();
    expect($images->cardPath($product))->toBeNull();
    expect($images->detailPath($product))->toBeNull();
    expect($images->cardUrl($product))->toBeNull();
    expect($images->detailUrl($product))->toBeNull();
});

test('only application variants receive expiring storage URLs', function () {
    $this->freezeTime();
    $disk = Storage::fake('s3');
    $product = productWithImage();
    $images = app(ProductImages::class);
    $directory = dirname($product->image_path);
    $disk->buildTemporaryUrlsUsing(function (string $path, DateTimeInterface $expiration) use ($directory) {
        expect($path)->toBeIn([$directory.'/card.webp', $directory.'/detail.webp']);

        return 'https://storage.example.test/'.$path.'?expires='.$expiration->getTimestamp();
    });

    expect($images->cardUrl($product))->toBe('https://storage.example.test/'.$directory.'/card.webp?expires='.now()->addMinutes(5)->getTimestamp());
    expect($images->detailUrl($product, now()->addMinute()))->toBe('https://storage.example.test/'.$directory.'/detail.webp?expires='.now()->addMinute()->getTimestamp());
    expect($product->refresh()->image_path)->toBe($directory.'/detail.webp');
});

test('unsupported temporary URLs fail explicitly', function () {
    $disk = Storage::fake('s3');
    $product = productWithImage();
    productImageFaultDisk($disk)->shouldReceive('providesTemporaryUrls')->andReturnFalse();

    expect(fn () => app(ProductImages::class)->detailUrl($product))
        ->toThrow(RuntimeException::class, 'The product image disk does not support temporary URLs.');
});

test('invalid or foreign image paths cannot be signed or recursively deleted', function (string $path) {
    $disk = Storage::fake('s3');
    $user = productImageManager();
    $other = productWithImage();
    $product = Product::factory()->create(['image_path' => $path === 'foreign' ? $other->image_path : $path]);
    Log::shouldReceive('warning')->once()->with('Product image asset cleanup failed.', [
        'product_id' => $product->id, 'exception_type' => RuntimeException::class,
    ]);

    expect(fn () => app(ProductImages::class)->detailUrl($product))->toThrow(RuntimeException::class);
    app(RemoveProductImage::class)->execute($user, $product);

    expect($product->refresh()->image_path)->toBeNull();
    $disk->assertExists($other->image_path);
})->with(['detail.webp', 'catalog/products/../detail.webp', 'https://external.example/image.webp', 'foreign']);

test('the configured S3 adapter signs variant URLs without contacting storage', function () {
    $this->freezeTime();
    config(['filesystems.disks.s3' => [
        'driver' => 's3', 'key' => 'test-key', 'secret' => 'test-secret',
        'region' => 'us-east-1', 'bucket' => 'test-private-bucket',
        'endpoint' => 'https://storage.example.test/storage/v1/s3',
        'use_path_style_endpoint' => true,
    ]]);
    Storage::forgetDisk('s3');
    $product = Product::factory()->create();
    $path = 'catalog/products/'.$product->id.'/'.Str::uuid().'/detail.webp';
    $product->update(['image_path' => $path]);

    $url = app(ProductImages::class)->detailUrl($product);

    expect(parse_url($url, PHP_URL_PATH))->toBe('/storage/v1/s3/test-private-bucket/'.$path);
    parse_str(parse_url($url, PHP_URL_QUERY), $parameters);
    $signedAt = new DateTimeImmutable($parameters['X-Amz-Date']);
    expect($signedAt->getTimestamp() + (int) $parameters['X-Amz-Expires'])->toBe(now()->addMinutes(5)->getTimestamp());
    expect($parameters['X-Amz-Algorithm'])->toBe('AWS4-HMAC-SHA256');
    expect($parameters['X-Amz-Signature'])->not->toBeEmpty();
    expect($product->refresh()->image_path)->toBe($path);
});

test('reencoding strips source metadata from application variants', function () {
    $disk = Storage::fake('s3');
    $user = productImageManager();
    $product = Product::factory()->create();
    $jpeg = UploadedFile::fake()->image('meal.jpg')->getContent();
    $metadata = 'private-source-comment';
    $jpeg = substr($jpeg, 0, 2)."\xff\xfe".pack('n', strlen($metadata) + 2).$metadata.substr($jpeg, 2);

    $updated = app(ReplaceProductImage::class)->execute($user, $product, UploadedFile::fake()->createWithContent('meal.jpg', $jpeg));

    $directory = dirname($updated->image_path);
    expect($disk->get($directory.'/source.jpg'))->toContain($metadata);
    expect($disk->get($directory.'/card.webp'))->not->toContain($metadata);
    expect($disk->get($directory.'/detail.webp'))->not->toContain($metadata);
});

test('transparent PNG pixels retain transparency in both variants', function () {
    $disk = Storage::fake('s3');
    $user = productImageManager();
    $product = Product::factory()->create();
    $source = imagecreatetruecolor(20, 10);
    imagealphablending($source, false);
    imagesavealpha($source, true);
    imagefill($source, 0, 0, imagecolorallocatealpha($source, 255, 0, 0, 127));
    ob_start();
    imagepng($source);
    $png = ob_get_clean();

    $updated = app(ReplaceProductImage::class)->execute($user, $product, UploadedFile::fake()->createWithContent('meal.png', $png));

    foreach (['card.webp', 'detail.webp'] as $variant) {
        $decoded = imagecreatefromstring($disk->get(dirname($updated->image_path).'/'.$variant));
        expect(imagecolorsforindex($decoded, imagecolorat($decoded, 0, 0))['alpha'])->toBe(127);
    }
});

test('memory intensive images are rejected before decoding without changing storage', function () {
    $disk = Storage::fake('s3');
    $user = productImageManager();
    $product = productWithImage();
    $oldPath = $product->image_path;
    $png = UploadedFile::fake()->image('meal.png')->getContent();
    $header = 'IHDR'.pack('N2', 6000, 6000).substr($png, 24, 5);
    $png = substr($png, 0, 12).$header.pack('N', crc32($header)).substr($png, 33);
    $upload = UploadedFile::fake()->createWithContent('meal.png', $png);
    $previousLimit = ini_get('memory_limit');
    ini_set('memory_limit', (string) (memory_get_usage(true) + 40 * 1024 * 1024));

    try {
        app(ReplaceProductImage::class)->execute($user, $product, $upload);
        $this->fail('An image exceeding the safe memory budget was accepted.');
    } catch (ValidationException $exception) {
        expect($exception->errors()['image'])->toBe(['The image is too large to process safely. Please reduce its dimensions.']);
    } finally {
        ini_set('memory_limit', $previousLimit);
    }

    expect($product->refresh()->image_path)->toBe($oldPath);
    $disk->assertCount('catalog', 3, true);
});

test('the upload size and dimension limits are inclusive', function () {
    $disk = Storage::fake('s3');
    $user = productImageManager();
    $product = Product::factory()->create();
    $upload = UploadedFile::fake()->image('meal.png', 6000, 1)->size(8192);

    $updated = app(ReplaceProductImage::class)->execute($user, $product, $upload);

    $disk->assertExists($updated->image_path);
});

test('a failed removal save preserves the reference and old objects', function () {
    $disk = Storage::fake('s3');
    $user = productImageManager();
    $product = productWithImage();
    $oldPath = $product->image_path;
    Event::listen('eloquent.updating: '.Product::class, fn () => false);

    expect(fn () => app(RemoveProductImage::class)->execute($user, $product))->toThrow(RuntimeException::class);

    expect($product->refresh()->image_path)->toBe($oldPath);
    $disk->assertExists($oldPath);
});

test('removal inside a rolled back outer transaction retains the old asset', function () {
    $disk = Storage::fake('s3');
    $user = productImageManager();
    $product = productWithImage();
    $oldPath = $product->image_path;

    expect(fn () => DB::transaction(function () use ($user, $product) {
        app(RemoveProductImage::class)->execute($user, $product);
        throw new RuntimeException('Caller rolled back.');
    }))->toThrow(RuntimeException::class, 'Caller rolled back.');

    expect($product->refresh()->image_path)->toBe($oldPath);
    $disk->assertExists($oldPath);
});
