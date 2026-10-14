<?php

namespace App\Http\Requests;

use App\Support\ShippyAssistant;
use App\Support\ShippyLanguage;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreShippyMessageRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * A guest's question, the few earlier turns of the same chat and the language the guest asked for in it. All are
     * untrusted, with hard limits.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'message' => ['required', 'string', 'max:'.ShippyAssistant::MAX_MESSAGE],
            'history' => ['sometimes', 'array', 'max:'.ShippyAssistant::MAX_HISTORY],
            'history.*.role' => ['required', Rule::in(['user', 'bot'])],
            'history.*.text' => ['required', 'string', 'max:'.ShippyAssistant::MAX_REPLY],
            'preference' => ['nullable', Rule::in([ShippyLanguage::ENGLISH, ShippyLanguage::TAGLISH])],
        ];
    }

    /**
     * The question with control characters removed and spaces collapsed.
     */
    public function question(): string
    {
        return self::clean($this->validated('message'));
    }

    /** The language the guest asked for earlier in this chat, if any. */
    public function preference(): ?string
    {
        $preference = $this->validated('preference');

        return is_string($preference) ? $preference : null;
    }

    /**
     * @return list<array{role: string, text: string}>
     */
    public function turns(): array
    {
        $turns = [];
        foreach ((array) $this->validated('history', []) as $turn) {
            $turns[] = ['role' => (string) $turn['role'], 'text' => self::clean($turn['text'])];
        }

        return $turns;
    }

    private static function clean(mixed $text): string
    {
        return trim((string) preg_replace('/[\p{C}\s]+/u', ' ', is_string($text) ? $text : ''));
    }
}
