<?php

namespace App\Http\Requests;

use App\Models\ShippyFaq;
use App\Models\ShippySetting;
use App\Models\User;
use App\Support\ShippyAssistant;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class SaveShippyFaqRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user() instanceof User && $this->user()->can('update', ShippySetting::class);
    }

    /**
     * An answer is shown to guests as written, so it is no longer than any other reply of the chat.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'question' => ['required', 'string', 'max:'.ShippyAssistant::MAX_MESSAGE],
            'answer' => ['required', 'string', 'max:'.ShippyAssistant::MAX_REPLY],
            'category' => ['nullable', 'string', 'max:'.ShippyFaq::MAX_CATEGORY],
            'alternatives' => ['sometimes', 'array', 'max:'.ShippyFaq::MAX_ALTERNATIVES],
            'alternatives.*' => ['nullable', 'string', 'max:'.ShippyAssistant::MAX_MESSAGE],
            'is_enabled' => ['required', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['alternatives' => 'other wordings', 'alternatives.*' => 'other wording', 'is_enabled' => 'FAQ switch'];
    }

    /**
     * The FAQ as it is stored: plain text only, spaces collapsed and empty wordings dropped.
     *
     * @return array{question: string, answer: string, category: string|null, alternatives: list<string>, is_enabled: bool}
     */
    public function faq(): array
    {
        $alternatives = array_map(self::clean(...), (array) $this->validated('alternatives', []));

        return [
            'question' => self::clean($this->validated('question')),
            'answer' => self::clean($this->validated('answer')),
            'category' => self::clean($this->validated('category')) ?: null,
            'alternatives' => array_values(array_unique(array_filter($alternatives, fn (string $wording): bool => $wording !== ''))),
            'is_enabled' => $this->boolean('is_enabled'),
        ];
    }

    private static function clean(mixed $text): string
    {
        return trim((string) preg_replace('/[\p{C}\s]+/u', ' ', strip_tags(is_string($text) ? $text : '')));
    }
}
