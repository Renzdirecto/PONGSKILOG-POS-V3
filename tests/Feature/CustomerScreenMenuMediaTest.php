<?php

use App\Events\CustomerScreenChanged;
use App\Models\Branch;
use App\Models\BranchInventory;
use App\Models\BranchProduct;
use App\Models\Category;
use App\Models\CustomerScreenMedia;
use App\Models\ModifierGroup;
use App\Models\ModifierOption;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use App\Support\ActiveBranchContext;
use App\Support\CustomerScreens;
use Database\Seeders\RbacSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->withCredentials();
    $this->main = Branch::factory()->create(['name' => 'Main Branch', 'code' => 'MAIN']);
    $this->qave = Branch::factory()->create(['name' => 'Qave Branch', 'code' => 'QAVE']);
    Storage::fake('s3')->buildTemporaryUrlsUsing(fn (string $path): string => 'https://media.example.test/'.$path.'?signature=test');
});

function mediaStaff(?Branch $branch, string $role): User
{
    $user = User::factory()->create();
    $user->roles()->attach(Role::query()->where('name', $role)->sole());
    if ($branch !== null) {
        $user->branches()->attach($branch, ['is_active' => true]);
    }

    return $user;
}

/** A Branch Custom Role holding Settings at its one assigned Branch. */
function mediaBranchSettingsManager(Branch $branch): User
{
    $role = Role::query()->forceCreate(['name' => 'custom_screen_'.Str::random(6), 'label' => 'Store Lead '.Str::random(4), 'is_system' => false, 'scope' => Role::SCOPE_BRANCH]);
    $role->permissions()->sync(Permission::query()->whereIn('name', ['settings.manage'])->pluck('id'));
    $user = User::factory()->create();
    $user->roles()->attach($role);
    $user->branches()->attach($branch, ['is_active' => true]);

    return $user;
}

/** A paired screen device of the Branch; returns its cookie value. */
function mediaPairedScreen(Branch $branch): string
{
    $cashier = mediaStaff($branch, 'cashier');
    $device = test()->withCookie(CustomerScreens::COOKIE, '')->postJson(route('customer-screen.pairing-code'))->assertOk();
    test()->actingAs($cashier)->withHeader(CustomerScreens::STATION_HEADER, (string) Str::uuid())
        ->postJson(route('pos.customer-screen.pair'), ['code' => $device->json('code')])->assertOk();

    return $device->getCookie(CustomerScreens::COOKIE)->getValue();
}

/** A minimal ISO-BMFF (MP4) file: ftyp, mdat and a moov with an mvhd of the given length and a codec sample entry. */
function mediaMp4(int $seconds, string $codec = 'avc1', string $brand = 'isom'): string
{
    $ftyp = pack('N', 20).'ftyp'.$brand.pack('N', 512).$brand;
    $payload = chr(0).str_repeat("\0", 3).pack('N', 0).pack('N', 0).pack('N', 1000).pack('N', $seconds * 1000).str_repeat("\0", 80);
    $mvhd = pack('N', 8 + strlen($payload)).'mvhd'.$payload;
    $stsd = pack('N', 16).'stsd'.$codec.'data';
    $moov = pack('N', 8 + strlen($mvhd) + strlen($stsd)).'moov'.$mvhd.$stsd;

    return $ftyp.pack('N', 16).'mdat'.str_repeat("\0", 8).$moov;
}

test('the Menu is a browse-only projection of the Branch catalog with no ids or stock counts', function () {
    $drinks = Category::factory()->create(['name' => 'Drinks', 'icon_key' => 'drink']);
    $hidden = Category::factory()->create(['name' => 'Retired', 'is_active' => false]);
    $lemonade = Product::factory()->for($drinks)->soldAt($this->main)->create(['name' => 'Lemonade', 'default_price' => '80.00']);
    $size = ModifierGroup::factory()->create(['name' => 'Size', 'semantic_role' => 'size', 'min_select' => 1]);
    ModifierOption::factory()->for($size)->create(['name' => 'Regular', 'price_delta' => '0.00', 'sort_order' => 1]);
    ModifierOption::factory()->for($size)->create(['name' => 'Large', 'price_delta' => '25.00', 'sort_order' => 2]);
    $lemonade->modifierGroups()->attach($size);
    $paused = Product::factory()->for($drinks)->soldAt($this->main)->create(['name' => 'Iced Tea']);
    BranchProduct::query()->where('product_id', $paused->id)->update(['is_available' => false]);
    $soldOut = Product::factory()->for($drinks)->soldAt($this->main)->create(['name' => 'Mango Shake']);
    BranchProduct::query()->where('product_id', $soldOut->id)->update(['tracks_inventory' => true]);
    BranchInventory::factory()->for($this->main)->for($soldOut)->create(['on_hand' => 0]);
    Product::factory()->for($drinks)->soldAt($this->main)->create(['name' => 'Disabled Product', 'is_active' => false]);
    Product::factory()->for($hidden)->soldAt($this->main)->create(['name' => 'Retired Product']);
    Product::factory()->for($drinks)->soldAt($this->qave)->create(['name' => 'Qave Special']);
    $token = mediaPairedScreen($this->main);

    $menu = $this->withCookie(CustomerScreens::COOKIE, $token)->getJson(route('customer-screen.menu'))->assertOk()->json('menu');

    expect(array_column($menu['categories'], 'name'))->toBe(['Drinks'])
        ->and(array_column($menu['products'], 'name'))->toBe(['Iced Tea', 'Lemonade', 'Mango Shake']);
    $byName = collect($menu['products'])->keyBy('name');
    expect($byName['Lemonade']['price'])->toBe('80.00')
        ->and($byName['Lemonade']['available'])->toBeTrue()
        ->and($byName['Lemonade']['sizes'])->toBe([
            ['name' => 'Regular', 'price' => '80.00', 'available' => true],
            ['name' => 'Large', 'price' => '105.00', 'available' => true],
        ])
        ->and($byName['Iced Tea']['status'])->toBe('unavailable')
        ->and($byName['Mango Shake']['status'])->toBe('sold_out')
        ->and(array_keys($byName['Lemonade']))->toBe(['key', 'category', 'name', 'description', 'price', 'available', 'status', 'image_url', 'sizes', 'has_options']);
    expect(json_encode($menu))->not->toContain($lemonade->id)
        ->not->toContain($drinks->id)
        ->not->toContain('on_hand')
        ->not->toContain('capacity');

    $this->withCookie(CustomerScreens::COOKIE, '')->getJson(route('customer-screen.menu'))->assertNotFound();
    foreach (['postJson', 'putJson', 'deleteJson'] as $method) {
        $this->withCookie(CustomerScreens::COOKIE, $token)->{$method}(route('customer-screen.menu'))->assertMethodNotAllowed();
    }
});

test('Settings managers upload advertisements only for Branches they manage', function () {
    Event::fake([CustomerScreenChanged::class]);
    $owner = mediaStaff(null, 'owner');
    $branchManager = mediaBranchSettingsManager($this->qave);
    $upload = fn (User $user, Branch $branch) => $this->actingAs($user)->post(route('branches.customer-screen-media.store', $branch), [
        'file' => UploadedFile::fake()->image('Promo Banner.jpg', 1200, 800),
        'duration_seconds' => 10,
    ], ['Accept' => 'application/json']);

    $upload($owner, $this->main)->assertCreated();
    $upload($branchManager, $this->qave)->assertCreated();
    $upload($branchManager, $this->main)->assertForbidden();
    $upload(mediaStaff($this->main, 'cashier'), $this->main)->assertForbidden();
    $upload(mediaStaff(null, 'super_admin'), $this->main)->assertCreated();

    $stored = CustomerScreenMedia::query()->where('branch_id', $this->qave->id)->sole();
    expect($stored->media_type)->toBe('image')
        ->and($stored->label)->toBe('Promo Banner')
        ->and($stored->duration_seconds)->toBe(10)
        ->and($stored->mime_type)->toBe('image/webp')
        ->and($stored->path)->toMatch('#\Acustomer-screen/'.$this->qave->id.'/[0-9a-f-]{36}/display\.webp\z#');
    Storage::disk('s3')->assertExists($stored->path);
    expect(getimagesizefromstring(Storage::disk('s3')->get($stored->path))[0])->toBeLessThanOrEqual(1920);
    $this->assertDatabaseHas('audit_logs', ['action' => 'customer_screen_media.created', 'branch_id' => $this->qave->id]);

    /** A Branch manager cannot reach another Branch's media through a forged pair of ids. */
    $mainMedia = CustomerScreenMedia::query()->where('branch_id', $this->main->id)->first();
    $this->actingAs($branchManager)->putJson(route('branches.customer-screen-media.update', ['branch' => $this->qave, 'media' => $mainMedia]), ['is_active' => false])->assertNotFound();
    $this->actingAs($branchManager)->deleteJson(route('branches.customer-screen-media.destroy', ['branch' => $this->main, 'media' => $mainMedia]))->assertForbidden();
    $this->actingAs($branchManager)->getJson(route('branches.customer-screen-media.index', $this->main))->assertForbidden();
    expect($mainMedia->fresh()->is_active)->toBeTrue();
});

test('only real images and short H.264 MP4 videos are accepted', function () {
    $owner = mediaStaff(null, 'owner');
    $upload = fn (UploadedFile $file) => $this->actingAs($owner)->post(route('branches.customer-screen-media.store', $this->main), ['file' => $file], ['Accept' => 'application/json']);

    $upload(UploadedFile::fake()->createWithContent('clip.mp4', mediaMp4(30)))->assertCreated();
    $video = CustomerScreenMedia::query()->where('media_type', 'video')->sole();
    expect($video->duration_seconds)->toBe(30)->and($video->mime_type)->toBe('video/mp4')
        ->and($video->path)->toEndWith('/video.mp4');

    $upload(UploadedFile::fake()->createWithContent('long.mp4', mediaMp4(90)))->assertUnprocessable()->assertJsonValidationErrors('file');
    $upload(UploadedFile::fake()->createWithContent('hevc.mp4', mediaMp4(10, 'hvc1')))->assertUnprocessable()->assertJsonValidationErrors('file');
    $upload(UploadedFile::fake()->createWithContent('menu.pdf', "%PDF-1.4\n%fake"))->assertUnprocessable()->assertJsonValidationErrors('file');
    $upload(UploadedFile::fake()->createWithContent('disguised.jpg', '<?php echo "no"; ?>'))->assertUnprocessable()->assertJsonValidationErrors('file');
    $upload(UploadedFile::fake()->createWithContent('broken.mp4', 'not really a video file at all'))->assertUnprocessable()->assertJsonValidationErrors('file');
    $upload(UploadedFile::fake()->create('huge.mp4', 60_000, 'video/mp4'))->assertUnprocessable();

    expect(CustomerScreenMedia::query()->count())->toBe(1);
});

test('advertisements play in their sequence, inactive ones are hidden and screens get safe links only', function () {
    $owner = mediaStaff(null, 'owner');
    $first = CustomerScreenMedia::factory()->for($this->main)->create(['label' => 'First', 'sort_order' => 1]);
    $second = CustomerScreenMedia::factory()->video()->for($this->main)->create(['label' => 'Second', 'sort_order' => 2]);
    $third = CustomerScreenMedia::factory()->for($this->main)->create(['label' => 'Third', 'sort_order' => 3]);
    CustomerScreenMedia::factory()->for($this->qave)->create(['label' => 'Qave ad']);
    $token = mediaPairedScreen($this->main);

    $this->actingAs($owner)->putJson(route('branches.customer-screen-media.reorder', $this->main), ['ids' => [$third->id, $first->id, $second->id]])->assertOk();
    $this->actingAs($owner)->putJson(route('branches.customer-screen-media.update', ['branch' => $this->main, 'media' => $first]), ['is_active' => false])->assertOk();
    $this->actingAs($owner)->putJson(route('branches.customer-screen-media.reorder', $this->main), ['ids' => [$third->id, $first->id]])->assertUnprocessable();

    $playlist = $this->withCookie(CustomerScreens::COOKIE, $token)->getJson(route('customer-screen.media'))->assertOk()->json('media');
    expect(array_column($playlist['items'], 'type'))->toBe(['image', 'video'])
        ->and(array_column($playlist['items'], 'duration_ms'))->toBe([8000, 15000])
        ->and($playlist['items'][0]['url'])->toStartWith('https://media.example.test/customer-screen/'.$this->main->id.'/')
        ->and(array_keys($playlist['items'][0]))->toBe(['key', 'type', 'url', 'duration_ms']);
    expect(json_encode($playlist))->not->toContain($third->id)->not->toContain('Third')->not->toContain('Qave ad');

    $this->actingAs($owner)->getJson(route('branches.customer-screen-media.index', $this->main))->assertOk()
        ->assertJsonPath('media.0.label', 'Third')->assertJsonPath('media.1.is_active', false)->assertJsonPath('media.2.type', 'video');
    $this->actingAs($owner)->deleteJson(route('branches.customer-screen-media.destroy', ['branch' => $this->main, 'media' => $second]))->assertOk();
    expect(CustomerScreenMedia::query()->whereKey($second->id)->exists())->toBeFalse();
    $this->assertDatabaseHas('audit_logs', ['action' => 'customer_screen_media.deleted', 'branch_id' => $this->main->id]);

    $this->withCookie(CustomerScreens::COOKIE, '')->getJson(route('customer-screen.media'))->assertNotFound();
});

test('a Branch-scoped Settings manager without a selected Branch still cannot manage another Branch', function () {
    $manager = mediaBranchSettingsManager($this->main);

    $this->actingAs($manager)->withSession([ActiveBranchContext::SESSION_KEY => $this->qave->id])
        ->getJson(route('branches.customer-screen-media.index', $this->qave))->assertForbidden();
    $this->actingAs($manager)->getJson(route('branches.customer-screen-media.index', $this->main))->assertOk();
});
