<?php

namespace App\Http\Controllers;

use App\Actions\Audit\AuditRecorder;
use App\Events\CustomerScreenChanged;
use App\Models\Branch;
use App\Models\CustomerScreen;
use App\Models\CustomerScreenMedia;
use App\Models\User;
use App\Support\CustomerScreenMediaLibrary;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Settings › Customer Screen: a Branch's advertisement media (Phase 19.6A). Authorization is the Branch-local settings
 * rule (`BranchPolicy::update`: `settings.manage` at a Branch the account can access — Owner, Super Admin, business-wide
 * Custom Roles for every Branch, a Branch Custom Role only for its own). The Branch always comes from the URL and the
 * media must belong to it, so a forged Branch or media id answers 403/404. Each change is audited and invalidates only
 * that Branch's paired screens.
 */
class CustomerScreenMediaController extends Controller
{
    public function __construct(private CustomerScreenMediaLibrary $library, private AuditRecorder $audit) {}

    public function index(Branch $branch): JsonResponse
    {
        Gate::authorize('update', $branch);
        $expiresAt = now()->addMinutes(30);

        return response()->json([
            'media' => CustomerScreenMedia::query()->where('branch_id', $branch->getKey())
                ->orderBy('sort_order')->orderBy('created_at')->orderBy('id')->get()
                ->map(fn (CustomerScreenMedia $media): array => [
                    'id' => $media->id,
                    'type' => $media->media_type,
                    'label' => $media->label,
                    'duration_seconds' => $media->duration_seconds,
                    'is_active' => $media->is_active,
                    'size_bytes' => $media->size_bytes,
                    'preview_url' => $this->library->url($media, $expiresAt),
                ])->all(),
            'limits' => [
                'max_items' => CustomerScreenMediaLibrary::MAX_ITEMS,
                'image_max_mb' => CustomerScreenMediaLibrary::IMAGE_MAX_KILOBYTES / 1024,
                'video_max_mb' => CustomerScreenMediaLibrary::VIDEO_MAX_KILOBYTES / 1024,
                'video_max_seconds' => CustomerScreenMediaLibrary::VIDEO_MAX_SECONDS,
            ],
        ])->header('Cache-Control', 'private, no-store');
    }

    public function store(Request $request, Branch $branch): JsonResponse
    {
        Gate::authorize('update', $branch);
        $data = $request->validate([
            'file' => ['required', 'file'],
            'label' => ['nullable', 'string', 'max:80'],
            'duration_seconds' => ['nullable', 'integer', 'min:3', 'max:60'],
        ]);
        if (CustomerScreenMedia::query()->where('branch_id', $branch->getKey())->count() >= CustomerScreenMediaLibrary::MAX_ITEMS) {
            throw ValidationException::withMessages(['file' => 'A Branch can have at most '.CustomerScreenMediaLibrary::MAX_ITEMS.' advertisements. Remove one first.']);
        }
        $stored = $this->library->store($branch, $request->file('file'));

        try {
            $media = DB::transaction(function () use ($request, $branch, $data, $stored): CustomerScreenMedia {
                $actor = $this->actor($request);
                $locked = Branch::query()->whereKey($branch->getKey())->lockForUpdate()->firstOrFail();
                $media = CustomerScreenMedia::query()->create([
                    'branch_id' => $locked->getKey(),
                    'media_type' => $stored['media_type'],
                    'label' => $this->label($data['label'] ?? null, $request->file('file')?->getClientOriginalName(), $stored['media_type']),
                    'path' => $stored['path'],
                    'mime_type' => $stored['mime_type'],
                    'size_bytes' => $stored['size_bytes'],
                    'duration_seconds' => $stored['duration_seconds'] ?? (int) ($data['duration_seconds'] ?? CustomerScreenMediaLibrary::IMAGE_DEFAULT_SECONDS),
                    'sort_order' => (int) CustomerScreenMedia::query()->where('branch_id', $locked->getKey())->max('sort_order') + 1,
                    'is_active' => true,
                    'created_by_user_id' => $actor->getKey(),
                ]);
                $this->audit->record(branch: $locked, actor: $actor, module: 'settings', action: 'customer_screen_media.created',
                    auditableType: CustomerScreenMedia::class, auditableId: $media->id, after: $this->snapshot($media));
                $this->signalScreens($locked);

                return $media;
            });
        } catch (Throwable $exception) {
            $this->library->delete($stored['path']);

            throw $exception;
        }

        return response()->json(['id' => $media->id], 201);
    }

    public function update(Request $request, Branch $branch, CustomerScreenMedia $media): JsonResponse
    {
        Gate::authorize('update', $branch);
        abort_unless($media->branch_id === $branch->getKey(), 404);
        $data = $request->validate([
            'label' => ['sometimes', 'string', 'min:1', 'max:80'],
            'duration_seconds' => ['sometimes', 'integer', 'min:3', 'max:60'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        if ($media->media_type === 'video') {
            unset($data['duration_seconds']);
        }

        DB::transaction(function () use ($request, $branch, $media, $data): void {
            $locked = CustomerScreenMedia::query()->where('branch_id', $branch->getKey())->whereKey($media->getKey())->lockForUpdate()->firstOrFail();
            $before = $this->snapshot($locked);
            $locked->update(array_intersect_key($data, array_flip(['label', 'duration_seconds', 'is_active'])));
            $this->audit->record(branch: $branch, actor: $this->actor($request), module: 'settings', action: 'customer_screen_media.updated',
                auditableType: CustomerScreenMedia::class, auditableId: $locked->id, before: $before, after: $this->snapshot($locked));
            $this->signalScreens($branch);
        });

        return response()->json(['saved' => true]);
    }

    public function destroy(Request $request, Branch $branch, CustomerScreenMedia $media): JsonResponse
    {
        Gate::authorize('update', $branch);
        abort_unless($media->branch_id === $branch->getKey(), 404);

        $path = DB::transaction(function () use ($request, $branch, $media): string {
            $locked = CustomerScreenMedia::query()->where('branch_id', $branch->getKey())->whereKey($media->getKey())->lockForUpdate()->firstOrFail();
            $before = $this->snapshot($locked);
            $locked->delete();
            $this->audit->record(branch: $branch, actor: $this->actor($request), module: 'settings', action: 'customer_screen_media.deleted',
                auditableType: CustomerScreenMedia::class, auditableId: $locked->id, before: $before);
            $this->signalScreens($branch);

            return $locked->path;
        });
        $this->library->delete($path);

        return response()->json(['deleted' => true]);
    }

    /** The complete new sequence: exactly this Branch's media ids, each once. */
    public function reorder(Request $request, Branch $branch): JsonResponse
    {
        Gate::authorize('update', $branch);
        $data = $request->validate(['ids' => ['required', 'array', 'max:'.CustomerScreenMediaLibrary::MAX_ITEMS], 'ids.*' => ['required', 'uuid', 'distinct']]);

        DB::transaction(function () use ($request, $branch, $data): void {
            $locked = Branch::query()->whereKey($branch->getKey())->lockForUpdate()->firstOrFail();
            $existing = CustomerScreenMedia::query()->where('branch_id', $locked->getKey())->pluck('id')->map(fn (mixed $id): string => strtolower(strval($id)))->sort()->values()->all();
            $requested = array_map(fn (mixed $id): string => strtolower(is_string($id) ? $id : ''), array_values((array) $data['ids']));
            $sorted = $requested;
            sort($sorted);
            if ($sorted !== $existing) {
                throw ValidationException::withMessages(['ids' => 'The advertisement list changed. Refresh and try again.']);
            }
            foreach ($requested as $position => $id) {
                CustomerScreenMedia::query()->where('branch_id', $locked->getKey())->whereKey($id)->update(['sort_order' => $position + 1]);
            }
            $this->audit->record(branch: $locked, actor: $this->actor($request), module: 'settings', action: 'customer_screen_media.reordered',
                auditableType: Branch::class, auditableId: $locked->id, metadata: ['count' => count($requested)]);
            $this->signalScreens($locked);
        });

        return response()->json(['saved' => true]);
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        return $actor;
    }

    /** An admin-given label, else a readable name from the file name (letters, digits and simple punctuation only). */
    private function label(?string $label, ?string $clientName, string $type): string
    {
        $candidate = trim((string) ($label ?? pathinfo((string) $clientName, PATHINFO_FILENAME)));
        $clean = trim((string) preg_replace('/\s+/', ' ', (string) preg_replace('/[^\p{L}\p{N} ._()&+-]/u', ' ', $candidate)));

        return mb_substr($clean !== '' ? $clean : ($type === 'video' ? 'Video' : 'Image'), 0, 80);
    }

    /** @return array<string, mixed> */
    private function snapshot(CustomerScreenMedia $media): array
    {
        return $media->only(['media_type', 'label', 'duration_seconds', 'sort_order', 'is_active']);
    }

    private function signalScreens(Branch $branch): void
    {
        $keys = CustomerScreen::query()->where('branch_id', $branch->getKey())->pluck('channel_key')->all();
        CustomerScreenChanged::dispatch(array_values($keys), 'ads');
    }
}
