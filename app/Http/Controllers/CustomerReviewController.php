<?php

namespace App\Http\Controllers;

use App\Actions\Audit\AuditRecorder;
use App\Actions\SubmitCustomerReview;
use App\Http\Requests\StoreCustomerReviewRequest;
use App\Models\Branch;
use App\Models\CustomerReview;
use App\Models\User;
use App\Support\CustomerReviewImages;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

class CustomerReviewController extends Controller
{
    public function store(StoreCustomerReviewRequest $request, SubmitCustomerReview $submit): JsonResponse
    {
        $submit->execute($request);

        return response()->json(['saved' => true])->header('Cache-Control', 'private, no-store');
    }

    public function index(Request $request, Branch $branch): JsonResponse
    {
        Gate::authorize('viewAny', CustomerReview::class);
        Gate::authorize('update', $branch);
        $data = $request->validate(['section' => ['nullable', Rule::in(['pending', 'approved', 'rejected', 'private'])], 'page' => ['nullable', 'integer', 'min:1']]);
        $section = $data['section'] ?? 'pending';
        $reviews = CustomerReview::query()->where('branch_id', $branch->id)
            ->where('visibility', $section === 'private' ? 'private' : 'public')
            ->when($section !== 'private', fn ($query) => $query->where('status', $section))
            ->orderByDesc('created_at')->orderByDesc('id')->paginate(20);

        return response()->json([
            'reviews' => $reviews->getCollection()->map(fn (CustomerReview $review): array => [
                ...$review->projection(), ...$review->only(['status', 'visibility', 'notice_version', 'consented_at']),
            ]),
            'page' => $reviews->currentPage(), 'last_page' => $reviews->lastPage(), 'total' => $reviews->total(),
        ])->header('Cache-Control', 'private, no-store');
    }

    public function update(Request $request, Branch $branch, CustomerReview $review, AuditRecorder $audit): JsonResponse
    {
        abort_unless($review->branch_id === $branch->id, 404);
        Gate::authorize('update', $review);
        $data = $request->validate(['status' => ['required', Rule::in(['approved', 'rejected'])]]);
        DB::transaction(function () use ($request, $review, $data, $audit, $branch): void {
            $locked = CustomerReview::query()->whereKey($review->id)->lockForUpdate()->firstOrFail();
            Gate::authorize('update', $locked);
            $before = $locked->only(['status', 'visibility']);
            $locked->update(['status' => $data['status'], 'moderated_by' => $request->user()?->id, 'moderated_at' => now()]);
            $audit->record($branch, $request->user(), 'settings', 'customer_review.'.$data['status'], CustomerReview::class, $locked->id, $before, $locked->only(['status', 'visibility']));
        });

        return response()->json(['saved' => true])->header('Cache-Control', 'private, no-store');
    }

    public function destroy(Request $request, Branch $branch, CustomerReview $review, CustomerReviewImages $images, AuditRecorder $audit): JsonResponse
    {
        abort_unless($review->branch_id === $branch->id, 404);
        Gate::authorize('delete', $review);
        DB::transaction(function () use ($request, $review, $branch, $audit): void {
            $locked = CustomerReview::query()->whereKey($review->id)->lockForUpdate()->firstOrFail();
            $audit->record($branch, $request->user(), 'settings', 'customer_review.removed', CustomerReview::class, $locked->id, $locked->only(['status', 'visibility']));
            $locked->delete();
        });
        $images->deleteDirectory($review->id);

        return response()->json(['saved' => true])->header('Cache-Control', 'private, no-store');
    }

    public function image(Request $request, CustomerReview $review, int $image): Response
    {
        $published = $review->isPublished();
        if (! $published) {
            abort_unless($request->user() instanceof User && $request->user()->can('view', $review), 404);
        }
        $attachment = $review->images[$image] ?? null;
        abort_unless($attachment !== null && str_starts_with($attachment['path'], 'reviews/'.$review->id.'/'), 404);
        $etag = '"'.hash('sha256', $attachment['path']).'"';
        $headers = ['Content-Type' => 'image/webp', 'Cache-Control' => $published ? 'private, no-cache' : 'private, no-store', 'X-Content-Type-Options' => 'nosniff', 'ETag' => $etag];
        if ($published && $request->header('If-None-Match') === $etag) {
            return response('', 304, $headers);
        }
        abort_unless(Storage::disk('s3')->exists($attachment['path']), 404);

        return Storage::disk('s3')->response($attachment['path'], null, $headers, 'inline');
    }
}
