<?php

namespace App\Http\Controllers;

use App\Actions\Audit\AuditRecorder;
use App\Http\Requests\UpdateLandingTeamMemberRequest;
use App\Models\LandingTeamMember;
use App\Models\User;
use App\Support\ProductImageProcessor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Website → Team (v1.3.0): the Owner and Super Admin edit the six landing page team cards. A portrait goes through the
 * same checked image pipeline as Product and Branch images and is re-encoded to one WebP on object storage; the
 * original upload is never kept. The landing page reads the cards on every visit, so a change needs no deployment.
 */
class LandingTeamController extends Controller
{
    /** A card is at most 300 CSS px wide and 3:4, so 1080 px on the long side stays sharp on a phone screen. */
    public const BOUND = 1080;

    public const MAX_KILOBYTES = 5120;

    public function update(UpdateLandingTeamMemberRequest $request, LandingTeamMember $member, AuditRecorder $audit): JsonResponse
    {
        DB::transaction(function () use ($request, $member, $audit): void {
            $locked = LandingTeamMember::query()->whereKey($member->id)->lockForUpdate()->firstOrFail();
            $fields = ['role_label', 'role_title', 'name', 'bio', 'is_visible'];
            $before = $locked->only($fields);
            $locked->update($request->validated());
            $audit->record(null, $request->user(), 'settings', 'landing_team.updated', LandingTeamMember::class, (string) $locked->id, $before, $locked->only($fields));
        });

        return response()->json(['saved' => true]);
    }

    public function storePhoto(Request $request, LandingTeamMember $member, ProductImageProcessor $processor, AuditRecorder $audit): JsonResponse
    {
        Gate::authorize('update', LandingTeamMember::class);
        $request->validate(['image' => ['required', 'file']]);
        $upload = $request->file('image');
        abort_unless($upload instanceof UploadedFile, 422);
        $contents = $processor->rendition($upload, self::BOUND, self::MAX_KILOBYTES);
        $path = LandingTeamMember::UPLOADS.$member->id.'/'.Str::uuid().'.webp';
        if (! Storage::disk('s3')->put($path, $contents, ['CacheControl' => 'public, max-age=31536000, immutable', 'ContentType' => 'image/webp'])) {
            throw new RuntimeException('Could not store the team photo.');
        }

        try {
            $previous = $this->replace($request, $member, $path, $audit);
        } catch (Throwable $exception) {
            Storage::disk('s3')->delete($path);
            throw $exception;
        }
        $this->discard($previous);

        return response()->json(['saved' => true]);
    }

    public function destroyPhoto(Request $request, LandingTeamMember $member, AuditRecorder $audit): JsonResponse
    {
        Gate::authorize('update', LandingTeamMember::class);
        $this->discard($this->replace($request, $member, null, $audit));

        return response()->json(['saved' => true]);
    }

    /**
     * The public portrait of a card shown on the landing page; a hidden card's portrait is served only to the accounts
     * that manage the team. A request carrying the current version may be cached for a year.
     */
    public function photo(Request $request, LandingTeamMember $member): StreamedResponse
    {
        abort_unless($member->is_visible || ($request->user() instanceof User && $request->user()->can('viewAny', LandingTeamMember::class)), 404);
        abort_unless($member->hasUploadedPhoto() && Storage::disk('s3')->exists((string) $member->image_path), 404);
        $versioned = hash_equals(md5((string) $member->image_path), (string) $request->query('v'));

        return Storage::disk('s3')->response((string) $member->image_path, null, [
            'Cache-Control' => $versioned && $member->is_visible ? 'public, max-age=31536000, immutable' : 'private, no-cache',
            'Content-Type' => 'image/webp',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** Points the card at a new portrait (or none) and returns the path it replaced. */
    private function replace(Request $request, LandingTeamMember $member, ?string $path, AuditRecorder $audit): ?string
    {
        return DB::transaction(function () use ($request, $member, $path, $audit): ?string {
            $locked = LandingTeamMember::query()->whereKey($member->id)->lockForUpdate()->firstOrFail();
            $previous = $locked->image_path;
            if ($previous === null && $path === null) {
                return null;
            }
            $locked->forceFill(['image_path' => $path])->save();
            $audit->record(null, $request->user(), 'settings', $path === null ? 'landing_team.photo_removed' : 'landing_team.photo_updated', LandingTeamMember::class, (string) $locked->id, ['has_photo' => $previous !== null], ['has_photo' => $path !== null]);

            return $previous;
        });
    }

    /** Deletes a replaced upload. A portrait bundled with the release is a public file and is left alone. */
    private function discard(?string $path): void
    {
        if ($path !== null && str_starts_with($path, LandingTeamMember::UPLOADS)) {
            rescue(fn () => Storage::disk('s3')->delete($path));
        }
    }
}
