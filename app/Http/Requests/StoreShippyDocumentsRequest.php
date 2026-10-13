<?php

namespace App\Http\Requests;

use App\Models\ShippyDocument;
use App\Models\ShippySetting;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreShippyDocumentsRequest extends FormRequest
{
    /** What one uploaded document must be before its text is read: a small `.md` file. */
    public const FILE = ['required', 'file', 'extensions:md,markdown', 'max:'.ShippyDocument::MAX_KILOBYTES];

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user() instanceof User && $this->user()->can('update', ShippySetting::class);
    }

    /**
     * One or more Markdown files. Their encoding and content are checked when they are read (ShippyMarkdown).
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'files' => ['required', 'array', 'min:1', 'max:'.ShippyDocument::MAX_DOCUMENTS],
            'files.*' => self::FILE,
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['files' => 'documents', 'files.*' => 'document'];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'files.*.extensions' => 'Only Markdown (.md) files can be uploaded.',
            'files.*.max' => 'A document may be at most '.ShippyDocument::MAX_KILOBYTES.' KB.',
        ];
    }
}
