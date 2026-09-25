<?php

use App\Models\Branch;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->publicPath = storage_path('framework/testing/public-'.Str::random(8));
    File::ensureDirectoryExists($this->publicPath.'/build');
    app()->usePublicPath($this->publicPath);
});

afterEach(function () {
    File::deleteDirectory($this->publicPath);
    /** Trusted proxies are process-wide Symfony state; never leak them into other tests. */
    Request::setTrustedProxies([], Request::getTrustedHeaderSet());
});

test('the built service worker is served at the site root with no-cache headers and no session', function () {
    File::put($this->publicPath.'/build/sw.js', 'self.addEventListener("push", () => {});');

    $response = $this->get('/sw.js');

    $response->assertOk()
        ->assertHeader('Content-Type', 'text/javascript; charset=utf-8')
        ->assertHeader('Cache-Control', 'must-revalidate, no-cache, no-store, private')
        ->assertHeader('Service-Worker-Allowed', '/');
    expect($response->getContent())->toContain('self.addEventListener')
        ->and($response->headers->getCookies())->toBe([]);
});

test('there is no service worker without a production build or while the Vite dev server runs', function () {
    $this->get('/sw.js')->assertNotFound();

    File::put($this->publicPath.'/build/sw.js', '// built');
    File::put($this->publicPath.'/hot', 'http://127.0.0.1:5173');

    $this->get('/sw.js')->assertNotFound();
});

test('behind a trusted HTTPS proxy the app builds https URLs; an untrusted forwarded scheme is ignored', function () {
    $forwarded = fn () => $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
        ->withHeaders(['X-Forwarded-Proto' => 'https', 'X-Forwarded-Host' => 'pos.pongskilog.test'])
        ->get(route('login'));

    $forwarded()->assertSee('content="http://', false)->assertDontSee('pos.pongskilog.test', false);

    config(['trustedproxy.proxies' => '127.0.0.1']);

    $forwarded()->assertSee('content="https://pos.pongskilog.test/images/branding/og-image.jpg"', false);
});

test('staff pages are installable; public Customer QR pages are not part of the app', function () {
    $this->get(route('login'))
        ->assertOk()
        ->assertSee('<link rel="manifest" href="/manifest.webmanifest">', false)
        ->assertSee('viewport-fit=cover', false)
        ->assertSee('id="pwa-boot"', false);

    $branch = Branch::factory()->create();
    $this->get(route('kiosk.show', ['branch' => $branch->kiosk_code]))
        ->assertOk()
        ->assertDontSee('rel="manifest"', false)
        ->assertDontSee('id="pwa-boot"', false);
});
