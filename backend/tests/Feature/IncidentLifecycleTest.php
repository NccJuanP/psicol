<?php

use App\Domain\Identity\Enums\Role;
use App\Domain\Incidents\Enums\IncidentStatus;
use App\Domain\Incidents\Models\Incident;
use Laravel\Sanctum\Sanctum;

$payload = fn (array $overrides = []) => [
    'title' => 'Impresora del piso 3 sin conexión',
    'description' => 'No imprime desde esta mañana; muestra error de red.',
    'priority' => 'medium',
    ...$overrides,
];

it('registers an incident as open, owned by the caller, with the SLA due date', function () use ($payload) {
    $this->freezeTime();
    $requester = userWithRole(Role::Requester);
    Sanctum::actingAs($requester);

    $response = $this->postJson('/api/v1/incidents', $payload())
        ->assertCreated()
        ->assertJsonPath('data.status', 'open')
        ->assertJsonPath('data.type', 'incident')
        ->assertJsonPath('data.creator.id', $requester->id)
        ->assertJsonPath('data.assignee', null)
        ->assertJsonPath('data.due_at', now()->addDays(5)->toIso8601ZuluString());

    $id = $response->json('data.id');
    $response->assertHeader('Location', url("/api/v1/incidents/{$id}"))
        ->assertJsonPath('data.code', sprintf('INC-%06d', $id));
    expect(Incident::findOrFail($id)->activities()->pluck('action')->all())->toBe(['created']);
});

it('rejects status on create and validates fields in spanish', function () use ($payload) {
    Sanctum::actingAs(userWithRole(Role::Requester));

    $this->postJson('/api/v1/incidents', $payload(['status' => 'closed', 'title' => 'abc', 'due_at' => now()->subDay()->toIso8601String()]))
        ->assertUnprocessable()
        ->assertJsonPath('errors.status.0', 'El campo estado no está permitido.')
        ->assertJsonPath('errors.title.0', 'El campo título debe tener al menos 5 caracteres.')
        ->assertJsonValidationErrors('due_at');
});

it('lets an agent self-assign on create but not assign someone else', function () use ($payload) {
    $agent = userWithRole(Role::Agent);
    $other = userWithRole(Role::Agent);
    Sanctum::actingAs($agent);

    $this->postJson('/api/v1/incidents', $payload(['assigned_to' => $other->id]))->assertForbidden();
    $this->postJson('/api/v1/incidents', $payload(['assigned_to' => $agent->id]))
        ->assertCreated()
        ->assertJsonPath('data.assignee.id', $agent->id);
});

it('forbids a requester from reprioritizing their own case', function () {
    $requester = userWithRole(Role::Requester);
    $incident = Incident::factory()->createdBy($requester)->create();
    Sanctum::actingAs($requester);

    $this->patchJson("/api/v1/incidents/{$incident->id}", ['priority' => 'critical'])
        ->assertForbidden()
        ->assertJsonPath('code', 'FORBIDDEN');
    $this->patchJson("/api/v1/incidents/{$incident->id}", ['title' => 'Nuevo título del caso'])
        ->assertOk()
        ->assertJsonPath('data.title', 'Nuevo título del caso');
});

it('forbids a requester from editing once the case is being handled', function () {
    $requester = userWithRole(Role::Requester);
    $incident = Incident::factory()->createdBy($requester)->assignedTo(userWithRole(Role::Agent))
        ->status(IncidentStatus::InProgress)->create();
    Sanctum::actingAs($requester);

    $this->patchJson("/api/v1/incidents/{$incident->id}", ['title' => 'Nuevo título del caso'])->assertForbidden();
});

it('answers 409 for a transition outside the state machine', function () {
    $incident = Incident::factory()->create();
    Sanctum::actingAs(userWithRole(Role::Admin));

    $this->postJson("/api/v1/incidents/{$incident->id}/transition", ['status' => 'closed'])
        ->assertConflict()
        ->assertJsonPath('code', 'INVALID_STATUS_TRANSITION')
        ->assertJsonPath('message', 'No se puede pasar de «Abierto» a «Cerrado».');
});

it('requires an assignee to start working a case', function () {
    $incident = Incident::factory()->create();
    Sanctum::actingAs(userWithRole(Role::Admin));

    $this->postJson("/api/v1/incidents/{$incident->id}/transition", ['status' => 'in_progress'])
        ->assertConflict()
        ->assertJsonPath('code', 'ASSIGNEE_REQUIRED');
});

it('does not allow unassigning a case in progress', function () {
    $incident = Incident::factory()->assignedTo(userWithRole(Role::Agent))->status(IncidentStatus::InProgress)->create();
    Sanctum::actingAs(userWithRole(Role::Admin));

    $this->postJson("/api/v1/incidents/{$incident->id}/assign", ['assigned_to' => null])
        ->assertConflict()
        ->assertJsonPath('code', 'ASSIGNEE_REQUIRED');
});

it('locks closed and cancelled cases', function (IncidentStatus $status) {
    $incident = Incident::factory()->status($status)->create();
    Sanctum::actingAs(userWithRole(Role::Admin));

    $this->patchJson("/api/v1/incidents/{$incident->id}", ['title' => 'Intento de edición'])
        ->assertConflict()
        ->assertJsonPath('code', 'INCIDENT_LOCKED');
    $this->getJson("/api/v1/incidents/{$incident->id}")
        ->assertJsonPath('data.allowed_transitions', [])
        ->assertJsonPath('data.can.update', false);
})->with([IncidentStatus::Closed, IncidentStatus::Cancelled]);

it('hides other people\'s cases from a requester with 404', function () {
    $incident = Incident::factory()->create();

    Sanctum::actingAs(userWithRole(Role::Requester));
    $this->getJson("/api/v1/incidents/{$incident->id}")->assertNotFound()->assertJsonPath('code', 'NOT_FOUND');
    $this->postJson("/api/v1/incidents/{$incident->id}/transition", ['status' => 'cancelled'])->assertNotFound();

    Sanctum::actingAs(userWithRole(Role::Agent));
    $this->getJson("/api/v1/incidents/{$incident->id}")->assertOk();
});

it('lets only an admin delete, softly', function () {
    $incident = Incident::factory()->create();

    Sanctum::actingAs(userWithRole(Role::Agent));
    $this->deleteJson("/api/v1/incidents/{$incident->id}")->assertForbidden();

    Sanctum::actingAs(userWithRole(Role::Admin));
    $this->deleteJson("/api/v1/incidents/{$incident->id}")->assertNoContent();
    $this->getJson("/api/v1/incidents/{$incident->id}")->assertNotFound();

    expect(Incident::withTrashed()->findOrFail($incident->id)->trashed())->toBeTrue()
        ->and($incident->activities()->pluck('action')->all())->toBe(['deleted']);
});

it('lets an agent take an unassigned case only for themselves', function () {
    $agent = userWithRole(Role::Agent);
    $other = userWithRole(Role::Agent);
    $incident = Incident::factory()->create();
    Sanctum::actingAs($agent);

    $this->postJson("/api/v1/incidents/{$incident->id}/assign", ['assigned_to' => $other->id])->assertForbidden();
    $this->postJson("/api/v1/incidents/{$incident->id}/assign", ['assigned_to' => $agent->id])
        ->assertOk()
        ->assertJsonPath('data.assignee.id', $agent->id);
    $this->postJson("/api/v1/incidents/{$incident->id}/assign", ['assigned_to' => $agent->id])->assertForbidden();
});

it('rejects a requester as assignee', function () {
    $incident = Incident::factory()->create();
    Sanctum::actingAs(userWithRole(Role::Admin));

    $this->postJson("/api/v1/incidents/{$incident->id}/assign", ['assigned_to' => userWithRole(Role::Requester)->id])
        ->assertUnprocessable()
        ->assertJsonPath('errors.assigned_to.0', 'El responsable debe ser un agente o administrador activo.');
});

it('requires a note to reopen', function () {
    $requester = userWithRole(Role::Requester);
    $incident = Incident::factory()->createdBy($requester)->assignedTo(userWithRole(Role::Agent))
        ->status(IncidentStatus::Resolved)->create(['resolved_at' => now()]);
    Sanctum::actingAs($requester);

    $this->postJson("/api/v1/incidents/{$incident->id}/transition", ['status' => 'in_progress'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('note');
    $this->postJson("/api/v1/incidents/{$incident->id}/transition", ['status' => 'in_progress', 'note' => 'La impresora volvió a fallar.'])
        ->assertOk()
        ->assertJsonPath('data.status', 'in_progress')
        ->assertJsonPath('data.resolved_at', null);
});

it('exposes allowed transitions and abilities for the current user', function () {
    $requester = userWithRole(Role::Requester);
    $agent = userWithRole(Role::Agent);
    $incident = Incident::factory()->createdBy($requester)->assignedTo($agent)->create();

    Sanctum::actingAs($agent);
    $this->getJson("/api/v1/incidents/{$incident->id}")
        ->assertJsonPath('data.allowed_transitions', ['in_progress'])
        ->assertJsonPath('data.can', ['update' => true, 'reprioritize' => true, 'assign' => false, 'delete' => false]);

    Sanctum::actingAs($requester);
    $this->getJson("/api/v1/incidents/{$incident->id}")
        ->assertJsonPath('data.allowed_transitions', ['cancelled'])
        ->assertJsonPath('data.can', ['update' => true, 'reprioritize' => false, 'assign' => false, 'delete' => false]);
});

it('records one activity per command across the lifecycle', function () use ($payload) {
    $this->freezeTime();
    $requester = userWithRole(Role::Requester);
    $agent = userWithRole(Role::Agent);

    Sanctum::actingAs($requester);
    $id = $this->postJson('/api/v1/incidents', $payload())->json('data.id');

    Sanctum::actingAs($agent);
    $this->postJson("/api/v1/incidents/{$id}/assign", ['assigned_to' => $agent->id])->assertOk();
    $this->patchJson("/api/v1/incidents/{$id}", ['priority' => 'high'])->assertOk();
    $this->patchJson("/api/v1/incidents/{$id}", ['description' => 'Se revisó el cable de red y el switch.'])->assertOk();
    $this->postJson("/api/v1/incidents/{$id}/transition", ['status' => 'in_progress'])->assertOk();
    $this->postJson("/api/v1/incidents/{$id}/transition", ['status' => 'resolved'])
        ->assertJsonPath('data.resolved_at', now()->toIso8601ZuluString());

    Sanctum::actingAs($requester);
    $this->postJson("/api/v1/incidents/{$id}/transition", ['status' => 'closed'])->assertOk();

    $activities = $this->getJson("/api/v1/incidents/{$id}/activities")->assertOk();

    // toEqual, not toBe: MySQL's JSON type does not keep key order.
    expect(array_column($activities->json('data'), 'action'))->toBe([
        'status_changed', 'status_changed', 'status_changed', 'updated', 'reprioritized', 'assigned', 'created',
    ])
        ->and($activities->json('data.0.changes'))->toEqual(['status' => ['from' => 'resolved', 'to' => 'closed']])
        ->and($activities->json('data.0.actor.id'))->toBe($requester->id)
        ->and($activities->json('data.4.changes'))->toEqual(['priority' => ['from' => 'medium', 'to' => 'high']])
        ->and($activities->json('data.6.changes'))->toBe([])
        ->and($activities->json('meta.total'))->toBe(7);
});
