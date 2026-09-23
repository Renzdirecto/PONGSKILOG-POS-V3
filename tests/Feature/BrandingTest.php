<?php

test('every page head carries the Pongskilog icons and link preview instead of Laravel defaults', function () {
    $html = (string) $this->get(route('home'))->assertOk()->getContent();

    expect($html)
        ->toContain('<link rel="icon" href="/favicon.ico" sizes="16x16 32x32 48x48">')
        ->toContain('<link rel="icon" href="/images/branding/icons/favicon-192.png" type="image/png" sizes="192x192">')
        ->toContain('<meta property="og:title" content="'.e(config('app.name')).'">')
        ->toContain('<meta property="og:description" content="Pongskilog · Est. 2022">')
        ->toContain('<link rel="apple-touch-icon" href="/apple-touch-icon.png" sizes="180x180">')
        ->toContain('<meta name="theme-color" content="#111111">')
        ->toContain('<meta property="og:image" content="'.asset('images/branding/og-image.jpg').'">')
        ->toContain('<meta property="og:image:width" content="1200">')
        ->toContain('<meta name="twitter:card" content="summary_large_image">')
        ->toContain('<meta property="og:site_name" content="'.e(config('app.name')).'">')
        ->not->toContain('favicon.svg')
        ->not->toContain('<link rel="manifest"')
        ->not->toContain('serviceWorker');
    expect(file_get_contents(config_path('app.php')))->toContain("env('APP_NAME', 'Pongskilog')");
});

test('brand icons are derived at the sizes browsers and home screens expect', function (string $path, int $width, int $height, string $mime) {
    $size = getimagesize(public_path($path));

    expect($size)->not->toBeFalse()
        ->and([$size[0], $size[1], $size['mime']])->toBe([$width, $height, $mime]);
})->with([
    'apple touch icon' => ['apple-touch-icon.png', 180, 180, 'image/png'],
    'browser tab icon' => ['images/branding/icons/favicon-32.png', 32, 32, 'image/png'],
    'browser tab icon 192' => ['images/branding/icons/favicon-192.png', 192, 192, 'image/png'],
    'pwa-ready icon 192' => ['images/branding/icons/icon-192.png', 192, 192, 'image/png'],
    'pwa-ready icon 512' => ['images/branding/icons/icon-512.png', 512, 512, 'image/png'],
    'maskable icon 192' => ['images/branding/icons/icon-maskable-192.png', 192, 192, 'image/png'],
    'maskable icon 512' => ['images/branding/icons/icon-maskable-512.png', 512, 512, 'image/png'],
    'header and sidebar emblem' => ['images/branding/pongskilog-emblem.png', 256, 256, 'image/png'],
    'link preview' => ['images/branding/og-image.jpg', 1200, 630, 'image/jpeg'],
    'square logo source' => ['images/branding/source/pongskilog-square-logo.jpg', 1254, 1254, 'image/jpeg'],
    'round emblem source' => ['images/branding/source/pongskilog-round-emblem.jpg', 1254, 1254, 'image/jpeg'],
]);

test('the favicon is a multi-size icon file', function () {
    $ico = file_get_contents(public_path('favicon.ico'));
    $header = unpack('vreserved/vtype/vcount', substr($ico, 0, 6));
    $sizes = array_map(fn (int $entry): int => ord($ico[6 + 16 * $entry]), range(0, $header['count'] - 1));

    expect($header)->toBe(['reserved' => 0, 'type' => 1, 'count' => 3])
        ->and($sizes)->toBe([16, 32, 48]);
});

test('the browser tab icon is the rounded-square chef icon cut on its gold edge', function () {
    $tab = imagecreatefrompng(public_path('images/branding/icons/favicon-192.png'));
    $pixel = fn (int $x, int $y): array => imagecolorsforindex($tab, imagecolorat($tab, $x, $y));

    expect($pixel(0, 0)['alpha'])->toBe(127)
        ->and($pixel(191, 191)['alpha'])->toBe(127)
        ->and($pixel(96, 2)['alpha'])->toBe(0)
        ->and($pixel(96, 2)['red'])->toBeGreaterThan($pixel(96, 2)['blue'] + 60)
        ->and($pixel(96, 96)['alpha'])->toBe(0);
});

test('the approved link preview and tab icon sources are kept', function () {
    expect(file_exists(public_path('images/branding/source/pongskilog-link-preview-mockup.png')))->toBeTrue()
        ->and(file_exists(public_path('images/branding/source/pongskilog-tab-icon.png')))->toBeTrue();
});

test('the maskable icon keeps a full-bleed opaque background for launcher masks', function () {
    $icon = imagecreatefrompng(public_path('images/branding/icons/icon-maskable-512.png'));
    $corner = imagecolorsforindex($icon, imagecolorat($icon, 0, 0));
    $rounded = imagecreatefrompng(public_path('images/branding/icons/icon-512.png'));

    expect($corner['alpha'])->toBe(0)
        ->and(imagecolorsforindex($rounded, imagecolorat($rounded, 0, 0))['alpha'])->toBe(127);
});
