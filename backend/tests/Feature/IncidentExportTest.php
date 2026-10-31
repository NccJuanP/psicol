<?php

use App\Domain\Identity\Enums\Role;
use App\Domain\Incidents\Enums\IncidentStatus;
use App\Domain\Incidents\Models\Incident;
use Laravel\Sanctum\Sanctum;

it('streams a csv with the listing filters and spanish headers', function () {
    $this->travelTo('2026-10-31 15:00:00');
    Sanctum::actingAs(userWithRole(Role::Agent));
    $open = Incident::factory()->count(3)->create(['title' => 'Falla de red, piso 3']);
    Incident::factory()->status(IncidentStatus::Closed)->count(2)->create();

    $response = $this->get('/api/v1/incidents/export?format=csv&filter[status]=open')
        ->assertOk()
        ->assertHeader('Content-Type', 'text/csv; charset=UTF-8')
        ->assertHeader('Content-Disposition', 'attachment; filename=incidentes_20261031_1000.csv');

    $lines = array_map('str_getcsv', array_filter(explode("\n", $response->streamedContent())));

    expect($lines)->toHaveCount(4)
        ->and(ltrim($lines[0][0], "\u{FEFF}"))->toBe('Código')
        ->and($lines[0])->toContain('Responsable', 'Vence (hora Colombia)')
        ->and($lines[1][0])->toBe($open->last()->code) // newest id first
        ->and($lines[1][2])->toBe('Falla de red, piso 3')
        ->and($lines[1][4])->toBe('Abierto');
});

it('streams an xlsx file', function () {
    Sanctum::actingAs(userWithRole(Role::Admin));
    Incident::factory()->count(2)->create();

    $response = $this->get('/api/v1/incidents/export?format=xlsx')
        ->assertOk()
        ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

    expect(substr($response->streamedContent(), 0, 2))->toBe('PK');
});

it('requires the export permission', function () {
    Sanctum::actingAs(userWithRole(Role::Requester));

    $this->getJson('/api/v1/incidents/export?format=csv')->assertForbidden();
});

it('validates the export parameters', function (string $query, string $field) {
    Sanctum::actingAs(userWithRole(Role::Admin));

    $this->getJson('/api/v1/incidents/export'.$query)->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    'missing format' => ['', 'format'],
    'bad format' => ['?format=pdf', 'format'],
    'pagination is not allowed' => ['?format=csv&page=2', 'page'],
    'invalid filter' => ['?format=csv&filter[priority]=urgent', 'filter.priority'],
]);
