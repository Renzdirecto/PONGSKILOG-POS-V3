<?php

namespace App\Http\Requests;

use App\Models\LandingTeamMember;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;

class StoreLandingTeamMemberRequest extends UpdateLandingTeamMemberRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user() instanceof User && $this->user()->can('create', LandingTeamMember::class);
    }

    /**
     * A new member takes the same details as an edited one, plus an optional portrait. The portrait's type, size and
     * content are checked by the image pipeline that re-encodes it.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [...parent::rules(), 'image' => ['nullable', 'file']];
    }
}
