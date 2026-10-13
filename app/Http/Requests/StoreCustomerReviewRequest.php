<?php

namespace App\Http\Requests;

use App\Models\CustomerReview;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCustomerReviewRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'branch_id' => ['required', 'uuid', Rule::exists('branches', 'id')->where('status', 'active')],
            'submission_key' => ['required', 'uuid'],
            'rating' => ['required', 'integer', 'between:1,5'],
            'preferred_name' => ['required', 'string', 'max:60'],
            'description' => ['required', 'string', 'max:200'],
            'purchased_items' => ['nullable', 'string', 'max:200'],
            'visit_type' => ['nullable', Rule::in(['dine_in', 'takeout'])],
            'visibility' => ['required', Rule::in(['public', 'private'])],
            'acknowledgment' => ['required', 'accepted'],
            'public_consent' => ['required_if:visibility,public', 'accepted_if:visibility,public'],
            'notice_version' => ['required', Rule::in([CustomerReview::NOTICE_VERSION])],
            'website' => ['nullable', 'string', 'max:0'],
            'profile_photo' => $this->imageRules(),
            'review_images' => ['nullable', 'array', 'max:3'],
            'review_images.*' => ['required', ...$this->imageRules()],
        ];
    }

    /** @return list<string> */
    private function imageRules(): array
    {
        return ['bail', 'nullable', 'file', 'max:5120', 'mimes:jpg,jpeg,png,webp', 'mimetypes:image/jpeg,image/png,image/webp', 'dimensions:min_width=16,min_height=16,max_width=6000,max_height=6000'];
    }
}
