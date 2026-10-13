<?php

namespace App\Actions;

use App\Http\Requests\StoreCustomerReviewRequest;
use App\Models\CustomerReview;
use App\Support\CustomerReviewImages;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class SubmitCustomerReview
{
    public function __construct(private CustomerReviewImages $images) {}

    public function execute(StoreCustomerReviewRequest $request): void
    {
        $data = $request->safe()->only(['branch_id', 'rating', 'preferred_name', 'description', 'purchased_items', 'visit_type', 'visibility']);
        $digest = hash_hmac('sha256', $request->session()->getId().'|'.json_encode($data, JSON_THROW_ON_ERROR), (string) config('app.key'));
        $key = $request->validated('submission_key');
        $existing = CustomerReview::query()->where('submission_key', $key)->orWhere('submission_digest', $digest)->first();
        if ($existing !== null) {
            $this->assertSameSubmission($existing, $digest);

            return;
        }
        $id = Str::uuid()->toString();
        try {
            DB::transaction(function () use ($request, $data, $digest, $key, $id): void {
                $review = new CustomerReview;
                $review->id = $id;
                $review->fill([
                    ...$data, 'submission_key' => $key, 'submission_digest' => $digest,
                    'status' => 'pending', 'public_consent' => $data['visibility'] === 'public',
                    'consented_at' => now(), 'notice_version' => CustomerReview::NOTICE_VERSION,
                ])->save();
                $attachments = [];
                $profile = $request->file('profile_photo');
                if ($profile instanceof UploadedFile) {
                    $attachments[] = $this->images->store($profile, $id, 'profile');
                }
                foreach ($request->file('review_images', []) as $upload) {
                    $attachments[] = $this->images->store($upload, $id, 'review');
                }
                $review->update(['images' => $attachments]);
            });
        } catch (UniqueConstraintViolationException) {
            $this->images->deleteDirectory($id);
            $existing = CustomerReview::query()->where('submission_key', $key)->orWhere('submission_digest', $digest)->firstOrFail();
            $this->assertSameSubmission($existing, $digest);
        } catch (ValidationException) {
            $this->images->deleteDirectory($id);
            throw ValidationException::withMessages(['attachments' => 'An image could not be decoded safely. Choose a smaller JPEG, PNG or WebP image.']);
        } catch (Throwable) {
            $this->images->deleteDirectory($id);
            throw ValidationException::withMessages(['submission' => 'Your review could not be saved. Please try again with the same form.']);
        }
    }

    private function assertSameSubmission(CustomerReview $review, string $digest): void
    {
        if (! hash_equals($review->submission_digest, $digest)) {
            throw ValidationException::withMessages(['submission_key' => 'This form was already submitted. Reopen Write a Review to start a new review.']);
        }
    }
}
