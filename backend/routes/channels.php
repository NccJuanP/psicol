<?php

use App\Domain\Identity\Enums\Role;
use App\Domain\Identity\Models\User;
use Illuminate\Support\Facades\Broadcast;

// docs/api-contract.md, section 8. Authorized at POST /api/broadcasting/auth with the Bearer token.

Broadcast::channel('incidents.staff', fn (User $user) => $user->hasAnyRole([Role::Admin->value, Role::Agent->value]));

Broadcast::channel('users.{id}', fn (User $user, int $id) => $user->id === $id);
