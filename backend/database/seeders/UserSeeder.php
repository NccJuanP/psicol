<?php

namespace Database\Seeders;

use App\Domain\Identity\Enums\Role;
use App\Domain\Identity\Models\User;
use Illuminate\Database\Seeder;

class UserSeeder extends Seeder
{
    public const DEMO_PASSWORD = 'Password123!';

    /** 1 admin, 5 agents and 10 requesters; the first of each role is a demo account. */
    public function run(): void
    {
        $demo = [
            [Role::Admin, 'Administrador Psicol', 'admin@psicol.test', 0],
            [Role::Agent, 'Ana Agente', 'agente@psicol.test', 4],
            [Role::Requester, 'Sara Solicitante', 'solicitante@psicol.test', 9],
        ];

        foreach ($demo as [$role, $name, $email, $extra]) {
            User::factory()
                ->create(['name' => $name, 'email' => $email, 'password' => self::DEMO_PASSWORD])
                ->assignRole($role->value);

            User::factory($extra)->create()->each->assignRole($role->value);
        }
    }
}
