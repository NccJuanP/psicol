<?php

use App\Domain\Identity\Enums\Role;
use App\Domain\Identity\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(fn () => $this->seed(RolesAndPermissionsSeeder::class))
    ->in('Feature');

// Unit tests boot the app (config) but never touch the database.
pest()->extend(TestCase::class)->in('Unit');

function userWithRole(Role $role, array $attributes = []): User
{
    return User::factory()->create(['password' => 'Password123!', ...$attributes])->assignRole($role->value);
}
