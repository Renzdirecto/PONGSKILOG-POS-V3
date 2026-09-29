<?php

use App\Models\User;

test('staff pages render Light by default and Dark only when the device chose Dark', function (?string $cookie, bool $dark) {
    $request = $cookie === null ? $this : $this->withUnencryptedCookie('appearance', $cookie);
    $html = $request->actingAs(User::factory()->create())->get(route('profile.edit'))->assertOk()->getContent();

    expect(str_contains($html, '<html lang="en" class="dark"'))->toBe($dark)
        ->and($html)->not->toContain('prefers-color-scheme')
        ->and($html)->not->toContain('data-appearance-lock');
})->with([
    'no choice yet' => [null, false],
    'light' => ['light', false],
    'dark' => ['dark', true],
    'legacy system' => ['system', false],
    'unknown value' => ['"><script>', false],
]);

test('customer-facing pages stay Light even on a device that uses Dark', function () {
    $html = $this->withUnencryptedCookie('appearance', 'dark')->get(route('home'))->assertOk()->getContent();

    expect($html)->not->toContain('class="dark"')
        ->and($html)->toContain('data-appearance-lock="light"');
});
