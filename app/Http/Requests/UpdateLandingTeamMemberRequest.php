<?php

namespace App\Http\Requests;

use App\Models\LandingTeamMember;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateLandingTeamMemberRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user() instanceof User && $this->user()->can('update', LandingTeamMember::class);
    }

    /**
     * A name and bio may be left empty: the landing page then marks them as still to follow.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'role_label' => ['required', 'string', 'max:12'],
            'role_title' => ['required', 'string', 'max:60'],
            'name' => ['nullable', 'string', 'max:60'],
            'bio' => ['nullable', 'string', 'max:120'],
            'is_visible' => ['required', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['role_label' => 'role label', 'role_title' => 'role', 'bio' => 'short bio', 'is_visible' => 'visibility'];
    }
}
