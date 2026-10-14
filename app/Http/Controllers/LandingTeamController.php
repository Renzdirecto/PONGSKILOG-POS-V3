<?php

namespace App\Http\Controllers;

use App\Actions\Audit\AuditRecorder;
use App\Http\Requests\ReorderLandingTeamRequest;
use App\Http\Requests\StoreLandingTeamMemberRequest;
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
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Website → Team (v1.3.0): the Owner and Super Admin add, edit, hide, reorder and delete the landing page's team. A
 * portrait goes through the same checked image pipeline as Product and Branch images and is re-encoded to one WebP on
 * object storage; the original upload is never kept. The landing page reads the team on every visit, so a change needs
 * no deployment. Every change is audited under `settings`.
 */
class LandingTeamController extends Controller
{
    /** A card is at most 300 CSS px wide and 3:4, so 1080 px on the long side stays sharp on a phone screen. */
    public const BOUND = 1080;

    public const MAX_KILOBYTES = 5120;

    private const FIELDS = ['role_label', 'role_title', 'name', 'bio', 'is_visible'];

    /** Adds a member after everyone already on the team, with the optional portrait in the same request. */
    public function store(StoreLandingTeamMemberRequest $request, ProductImageProcessor $processor, AuditRecorder $audit): JsonResponse
    {
        $upload = $request->file('image');
        /** A portrait that is not a usable image stops here, before anything is created. */
        $contents = $upload instanceof UploadedFile ? $processor->rendition($upload, self::BOUND, self::MAX_KILOBYTES) : null;
        $stored = null;

        try {
            DB::transaction(function () use ($request, $contents, $audit, &$stored): void {
                $positions = LandingTeamMember::query()->lockForUpdate()->pluck('position');
                if ($positions->count() >= LandingTeamMember::MAX_MEMBERS) {
                    throw ValidationException::withMessages(['name' => 'The team already has '.LandingTeamMember::MAX_MEMBERS.' members. Delete one before adding another.']);
                }
                $member = new LandingTeamMember($request->safe()->only(self::FIELDS));
                $member->position = (int) $positions->max() + 1;
                $member->save();
                if ($contents !== null) {
                    $stored = $this->put($member, $contents);
                    $member->forceFill(['image_path' => $stored])->save();
                }
                $audit->record(null, $request->user(), 'settings', 'landing_team.created', LandingTeamMember::class, (string) $member->id, null, $member->only(self::FIELDS) + ['has_photo' => $stored !== null]);
            });
        } catch (Throwable $exception) {
            $this->discard($stored);
            throw $exception;
        }

        return response()->json(['saved' => true]);
    }

    public function update(UpdateLandingTeamMemberRequest $request, LandingTeamMember $member, AuditRecorder $audit): JsonResponse
    {
        DB::transaction(function () use ($request, $member, $audit): void {
            $locked = LandingTeamMember::query()->whereKey($member->id)->lockForUpdate()->firstOrFail();
            $before = $locked->only(self::FIELDS);
            $locked->update($request->validated());
            $audit->record(null, $request->user(), 'settings', 'landing_team.updated', LandingTeamMember::class, (string) $locked->id, $before, $locked->only(self::FIELDS));
        });

        return response()->json(['saved' => true]);
    }

    /** Deletes a member and their uploaded portrait; the members after them move up one place. */
    public function destroy(Request $request, LandingTeamMember $member, AuditRecorder $audit): JsonResponse
    {
        Gate::authorize('delete', LandingTeamMember::class);
        $path = DB::transaction(function () use ($request, $member, $audit): ?string {
            $team = LandingTeamMember::query()->lockForUpdate()->orderBy('position')->orderBy('id')->get()->keyBy('id');
            $gone = $team->get($member->id);
            abort_if($gone === null, 404);
            $gone->delete();
            $this->arrange($team->except([$gone->id])->modelKeys(), $team->all());
            $audit->record(null, $request->user(), 'settings', 'landing_team.deleted', LandingTeamMember::class, (string) $gone->id, $gone->only(self::FIELDS) + ['has_photo' => $gone->image_path !== null], null);

            return $gone->image_path;
        });
        $this->discard($path);

        return response()->json(['saved' => true]);
    }

    /** Puts the whole team in the order given; the list must name every member exactly once. */
    public function reorder(ReorderLandingTeamRequest $request, AuditRecorder $audit): JsonResponse
    {
        $order = array_map(intval(...), (array) $request->validated('ids'));
        DB::transaction(function () use ($request, $order, $audit): void {
            $team = LandingTeamMember::query()->lockForUpdate()->orderBy('position')->orderBy('id')->get()->keyBy('id');
            $before = array_map(intval(...), $team->keys()->all());
            if (count($order) !== count($before) || array_diff($before, $order) !== []) {
                throw ValidationException::withMessages(['ids' => 'The team changed since this page was loaded. Refresh and try again.']);
            }
            if ($order !== $before) {
                $this->arrange($order, $team->all());
                $audit->record(null, $request->user(), 'settings', 'landing_team.reordered', LandingTeamMember::class, 'order', ['order' => $before], ['order' => $order]);
            }
        });

        return response()->json(['saved' => true]);
    }

    public function storePhoto(Request $request, LandingTeamMember $member, ProductImageProcessor $processor, AuditRecorder $audit): JsonResponse
    {
        Gate::authorize('update', LandingTeamMember::class);
        $request->validate(['image' => ['required', 'file']]);
        $upload = $request->file('image');
        abort_unless($upload instanceof UploadedFile, 422);
        $path = $this->put($member, $processor->rendition($upload, self::BOUND, self::MAX_KILOBYTES));

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

    /** Stores a re-encoded portrait on object storage and returns its path. */
    private function put(LandingTeamMember $member, string $contents): string
    {
        $path = LandingTeamMember::UPLOADS.$member->id.'/'.Str::uuid().'.webp';
        if (! Storage::disk('s3')->put($path, $contents, ['CacheControl' => 'public, max-age=31536000, immutable', 'ContentType' => 'image/webp'])) {
            throw new RuntimeException('Could not store the team photo.');
        }

        return $path;
    }

    /**
     * Numbers the given members 1, 2, 3 and so on, in the order given.
     *
     * @param  array<int, int|string>  $order
     * @param  array<int|string, LandingTeamMember>  $team
     */
    private function arrange(array $order, array $team): void
    {
        foreach (array_values($order) as $index => $id) {
            if ($team[$id]->position !== $index + 1) {
                $team[$id]->forceFill(['position' => $index + 1])->save();
            }
        }
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
