<?php

namespace App\Support;

/**
 * The release a device is running (Phase 20), from deployment configuration only: APP_VERSION and the deployed commit
 * (APP_BUILD_SHA, or Railway's RAILWAY_GIT_COMMIT_SHA). Only these two safe values are ever exposed; a value that is
 * not a hex commit is dropped rather than echoed, so no other environment content can leak through it.
 */
class ReleaseInfo
{
    public const PRODUCT = 'PONGSKILOG POS';

    /** @return array{name: string, version: string, build: string|null} */
    public static function current(): array
    {
        $version = trim((string) config('app.version'));
        $sha = trim((string) config('app.build_sha'));

        return [
            'name' => self::PRODUCT,
            'version' => $version === '' ? 'development' : mb_substr($version, 0, 40),
            'build' => preg_match('/\A[0-9a-fA-F]{7,40}\z/', $sha) === 1 ? strtolower(substr($sha, 0, 7)) : null,
        ];
    }
}
