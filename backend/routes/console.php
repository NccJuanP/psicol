<?php

use App\Domain\Identity\Models\User;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('app:seed-if-empty', function () {
    if (User::query()->exists()) {
        $this->info('La base de datos ya tiene datos; se omite el seed.');

        return;
    }

    // One transaction: a failed seed must not leave rows that make the next boot skip seeding.
    DB::transaction(fn () => $this->call('db:seed', ['--force' => true]));
})->purpose('Siembra la base de datos solo si está vacía');
