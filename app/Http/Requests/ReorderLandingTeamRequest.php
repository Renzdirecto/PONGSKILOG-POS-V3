<?php

namespace App\Http\Requests;

use App\Models\LandingTeamMember;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ReorderLandingTeamRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user() instanceof User && $this->user()->can('update', LandingTeamMember::class);
    }

    /**
     * Every member's id once, in the order the landing page should show them.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'ids' => ['required', 'array', 'max:'.LandingTeamMember::MAX_MEMBERS],
            'ids.*' => ['integer', 'distinct'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['ids' => 'team order'];
    }
}
