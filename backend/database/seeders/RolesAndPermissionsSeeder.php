<?php

namespace Database\Seeders;

use App\Domain\Identity\Enums\Role;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role as RoleModel;
use Spatie\Permission\PermissionRegistrar;

class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (Role::allPermissions() as $permission) {
            Permission::findOrCreate($permission);
        }

        foreach (Role::cases() as $role) {
            RoleModel::findOrCreate($role->value)->syncPermissions($role->permissions());
        }
    }
}
