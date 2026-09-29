<?php

namespace App\Http\Controllers;

use App\Actions\Audit\AuditRecorder;
use App\Models\Branch;
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
 * The optional store photo of a Branch (Phase 20). Uploads go through the same checked image pipeline as Product
 * images (format, signature, dimensions, memory) and are re-encoded to one WebP of at most 1200 px on object storage;
 * the original upload is never kept and nothing is stored in the database but the path.
 */
class BranchImageController extends Controller
{
    public const BOUND = 1200;

    public const MAX_KILOBYTES = 5120;

    /** The versioned URL of a Branch's image (a new image gets a new path, so the URL may be cached), or null. */
    public static function url(Branch $branch): ?string
    {
        return $branch->image_path === null ? null : route('branches.image.show', $branch, false).'?v='.md5($branch->image_path);
    }

    /** Staff of the Branch only; a request carrying the current version is cached privately for a year. */
    public function show(Request $request, Branch $branch): StreamedResponse
    {
        Gate::authorize('view', $branch);
        abort_unless($branch->image_path !== null && Storage::disk('s3')->exists($branch->image_path), 404);
        $versioned = hash_equals(md5($branch->image_path), (string) $request->query('v'));

        return Storage::disk('s3')->response($branch->image_path, null, [
            'Cache-Control' => $versioned ? 'private, max-age=31536000, immutable' : 'private, no-cache',
            'Content-Type' => 'image/webp',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function store(Request $request, Branch $branch, ProductImageProcessor $processor, AuditRecorder $audit): JsonResponse
    {
        Gate::authorize('update', $branch);
        $request->validate(['image' => ['required', 'file']]);
        $upload = $request->file('image');
        abort_unless($upload instanceof UploadedFile, 422);
        $contents = $processor->rendition($upload, self::BOUND, self::MAX_KILOBYTES);
        $path = 'branch-images/'.$branch->id.'/'.Str::uuid().'.webp';
        if (! Storage::disk('s3')->put($path, $contents, ['CacheControl' => 'public, max-age=31536000, immutable', 'ContentType' => 'image/webp'])) {
            throw new RuntimeException('Could not store the Branch image.');
        }

        try {
            $previous = $this->replace($request, $branch, $path, $audit);
        } catch (Throwable $exception) {
            Storage::disk('s3')->delete($path);
            throw $exception;
        }
        if ($previous !== null) {
            rescue(fn () => Storage::disk('s3')->delete($previous));
        }

        return response()->json(['saved' => true]);
    }

    public function destroy(Request $request, Branch $branch, AuditRecorder $audit): JsonResponse
    {
        Gate::authorize('update', $branch);
        $previous = $this->replace($request, $branch, null, $audit);
        if ($previous !== null) {
            rescue(fn () => Storage::disk('s3')->delete($previous));
        }

        return response()->json(['saved' => true]);
    }

    private function replace(Request $request, Branch $branch, ?string $path, AuditRecorder $audit): ?string
    {
        return DB::transaction(function () use ($request, $branch, $path, $audit): ?string {
            $actor = $request->user();
            abort_unless($actor instanceof User, 401);
            $locked = Branch::query()->whereKey($branch->id)->lockForUpdate()->firstOrFail();
            $previous = $locked->image_path;
            if ($previous === null && $path === null) {
                return null;
            }
            $locked->forceFill(['image_path' => $path])->save();
            $audit->record(
                branch: $locked,
                actor: $actor,
                module: 'branches',
                action: $path === null ? 'branch.image_removed' : 'branch.image_updated',
                auditableType: Branch::class,
                auditableId: $locked->id,
                before: ['has_image' => $previous !== null],
                after: ['has_image' => $path !== null],
            );

            return $previous;
        });
    }
}
