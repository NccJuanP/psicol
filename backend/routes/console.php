<?php

use App\Models\User;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('app:seed-if-empty', function () {
    if (User::query()->exists()) {
        $this->info('La base de datos ya tiene datos; se omite el seed.');

        return;
    }

    $this->call('db:seed', ['--force' => true]);
})->purpose('Siembra la base de datos solo si está vacía');
