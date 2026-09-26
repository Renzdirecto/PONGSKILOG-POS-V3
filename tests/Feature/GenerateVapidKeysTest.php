<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->environmentPath = storage_path('framework/testing/env-'.Str::random(8));
    File::ensureDirectoryExists($this->environmentPath);
    app()->useEnvironmentPath($this->environmentPath);
});

afterEach(function () {
    File::deleteDirectory($this->environmentPath);
});

test('an existing VAPID pair is never overwritten', function () {
    File::put($this->environmentPath.'/.env', "APP_NAME=Test\nVAPID_PUBLIC_KEY=existing-public\nVAPID_PRIVATE_KEY=existing-private\n");

    $this->artisan('pwa:vapid-keys')
        ->expectsOutputToContain('left unchanged')
        ->assertSuccessful();

    expect(File::get($this->environmentPath.'/.env'))
        ->toContain('VAPID_PUBLIC_KEY=existing-public')
        ->toContain('VAPID_PRIVATE_KEY=existing-private');
});

test('a new pair fills the empty placeholders and only the public key is printed', function () {
    File::put($this->environmentPath.'/.env', "APP_NAME=Test\nVAPID_SUBJECT=\nVAPID_PUBLIC_KEY=\nVAPID_PRIVATE_KEY=\n");

    $this->artisan('pwa:vapid-keys', ['--subject' => 'mailto:owner@example.com'])
        ->expectsOutputToContain('Created a VAPID key pair')
        ->doesntExpectOutputToContain('VAPID_PRIVATE_KEY=')
        ->assertSuccessful();

    $environment = File::get($this->environmentPath.'/.env');
    expect($environment)->toMatch('/^VAPID_PUBLIC_KEY=[A-Za-z0-9_-]{87}$/m')
        ->toMatch('/^VAPID_PRIVATE_KEY=[A-Za-z0-9_-]{43}$/m')
        ->toContain('VAPID_SUBJECT=mailto:owner@example.com')
        ->and(substr_count($environment, 'VAPID_PUBLIC_KEY='))->toBe(1);
})->skip(fn (): bool => @openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]) === false, 'PHP cannot create P-256 keys here (on Windows set OPENSSL_CONF to PHP\'s extras\ssl\openssl.cnf).');
