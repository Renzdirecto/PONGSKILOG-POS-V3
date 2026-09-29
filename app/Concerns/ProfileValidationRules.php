<?php

namespace App\Concerns;

use App\Models\User;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Eloquent\Builder;

trait ProfileValidationRules
{
    /**
     * Get the validation rules used to validate user profiles.
     *
     * @return array<string, array<int, ValidationRule|Closure|array<mixed>|string>>
     */
    protected function profileRules(?int $userId = null): array
    {
        return [
            'name' => $this->nameRules(),
            'email' => $this->emailRules($userId),
        ];
    }

    /**
     * Get the validation rules used to validate user names.
     *
     * @return array<int, ValidationRule|array<mixed>|string>
     */
    protected function nameRules(): array
    {
        return ['required', 'string', 'max:255'];
    }

    /**
     * Get the validation rules used to validate user emails. Uniqueness ignores case, like the Staff forms, so two
     * accounts can never share one sign-in address in different letter case.
     *
     * @return array<int, ValidationRule|Closure|array<mixed>|string>
     */
    protected function emailRules(?int $userId = null): array
    {
        return [
            'required',
            'string',
            'email',
            'max:255',
            function (string $attribute, mixed $value, Closure $fail) use ($userId): void {
                $taken = User::query()->whereRaw('LOWER(email) = ?', [mb_strtolower(trim((string) $value))])
                    ->when($userId !== null, fn (Builder $query) => $query->whereKeyNot($userId))
                    ->exists();
                if ($taken) {
                    $fail('This email is already used by another account.');
                }
            },
        ];
    }
}
