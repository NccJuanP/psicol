<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

// No WithoutModelEvents: spatie/laravel-permission flushes its cache through model events.
class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            RolesAndPermissionsSeeder::class,
            UserSeeder::class,
            IncidentSeeder::class,
        ]);
    }
}
