<?php

use App\Domain\Identity\Enums\Role;
use App\Domain\Incidents\Models\Incident;
use Laravel\Sanctum\Sanctum;

// InnoDB only indexes FULLTEXT on commit, so this suite truncates instead of wrapping each test in a transaction.

it('searches title and description by word prefix, ignoring accents', function () {
    Sanctum::actingAs(userWithRole(Role::Admin));
    $printer = Incident::factory()->create(['title' => 'La impresión sale en blanco', 'description' => 'Ocurre en contabilidad desde ayer.']);
    $network = Incident::factory()->create(['title' => 'Sin red en bodega', 'description' => 'La impresora tampoco conecta.']);
    Incident::factory()->create(['title' => 'Monitor apagado', 'description' => 'No enciende desde el lunes.']);

    $ids = fn (string $search) => array_column($this->getJson('/api/v1/incidents?search='.urlencode($search))->assertOk()->json('data'), 'id');

    expect($ids('impresion'))->toBe([$printer->id])
        ->and($ids('impres'))->toEqualCanonicalizing([$printer->id, $network->id])
        ->and($ids('impres bodega'))->toBe([$network->id]);
});

it('neutralises boolean operators typed by the user', function () {
    Sanctum::actingAs(userWithRole(Role::Admin));
    $incident = Incident::factory()->create(['title' => 'Correo corporativo caído', 'description' => 'No llegan mensajes externos.']);

    $this->getJson('/api/v1/incidents?search='.urlencode('-correo +"caído" (*'))
        ->assertOk()
        ->assertJsonPath('data.0.id', $incident->id);
});
