<?php

namespace App\Http\Requests\Rules;

use App\Domain\Identity\Models\User;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** R-09: an active user with the agent or admin role. */
class AssignableUser implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! User::query()->assignable()->whereKey($value)->exists()) {
            $fail(__('incidents.invalid_assignee'));
        }
    }
}
