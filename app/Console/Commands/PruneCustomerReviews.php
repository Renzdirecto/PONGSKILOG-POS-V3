<?php

namespace App\Console\Commands;

use App\Models\CustomerReview;
use App\Support\CustomerReviewImages;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class PruneCustomerReviews extends Command
{
    protected $signature = 'reviews:prune';

    protected $description = 'Remove expired reviews and review objects orphaned for at least one day';

    public function handle(CustomerReviewImages $images): int
    {
        CustomerReview::query()->where(function ($query): void {
            $query->where('created_at', '<', now()->subYear())
                ->orWhere(function ($unpublished): void {
                    $unpublished->where('created_at', '<', now()->subDays(90))
                        ->where(fn ($state) => $state->where('visibility', 'private')->orWhere('status', '!=', 'approved'));
                });
        })->chunkById(100, function ($reviews) use ($images): void {
            foreach ($reviews as $review) {
                DB::transaction(function () use ($review, $images): void {
                    $locked = CustomerReview::query()->whereKey($review->id)->lockForUpdate()->first();
                    if ($locked === null || $locked->created_at === null) {
                        return;
                    }
                    $expired = $locked->created_at->lt(now()->subYear())
                        || ($locked->created_at->lt(now()->subDays(90)) && ($locked->visibility === 'private' || $locked->status !== 'approved'));
                    if ($expired && $images->deleteDirectory($locked->id)) {
                        $locked->delete();
                    }
                });
            }
        });
        $disk = Storage::disk('s3');
        foreach ($disk->directories('reviews') as $directory) {
            $id = basename($directory);
            if (! Str::isUuid($id) || CustomerReview::query()->whereKey($id)->exists()) {
                continue;
            }
            $files = $disk->files($directory);
            if ($files !== [] && collect($files)->every(fn (string $path) => $disk->lastModified($path) < now()->subDay()->timestamp)) {
                $images->deleteDirectory($id);
            }
        }
        $this->info('Expired reviews and eligible orphan objects processed.');

        return self::SUCCESS;
    }
}
