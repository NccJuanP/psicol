<?php

use App\Domain\Identity\Enums\Role;
use App\Domain\Incidents\Enums\IncidentStatus;
use App\Domain\Incidents\Enums\Priority;
use App\Domain\Incidents\Models\Incident;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->travelTo('2026-10-31 15:00:00'); // 10:00 in Bogota
    $this->requester = userWithRole(Role::Requester);
    $agent = userWithRole(Role::Agent);
    $make = fn (IncidentStatus $status, Priority $priority, string $createdAt, array $extra = []) => Incident::factory()
        ->status($status)
        ->create(['priority' => $priority, 'created_at' => $createdAt, 'due_at' => now()->addDay(), ...$extra]);

    // A: open, unassigned, overdue, today, owned by the requester.
    $make(IncidentStatus::Open, Priority::High, '2026-10-31 12:00:00', ['due_at' => now()->subHour(), 'created_by' => $this->requester->id]);
    // B: in progress; 03:00 UTC is still Oct 30 in Bogota.
    $make(IncidentStatus::InProgress, Priority::High, '2026-10-31 03:00:00', ['assigned_to' => $agent->id]);
    // C: on hold, overdue.
    $make(IncidentStatus::OnHold, Priority::Critical, '2026-10-28 15:00:00', ['assigned_to' => $agent->id, 'due_at' => now()->subDay()]);
    // D: resolved two days ago.
    $make(IncidentStatus::Resolved, Priority::Low, '2026-10-26 15:00:00', ['assigned_to' => $agent->id, 'resolved_at' => '2026-10-29 15:00:00', 'due_at' => now()->subDays(3)]);
    // E: closed long ago, outside the 30-day trend.
    $make(IncidentStatus::Closed, Priority::Medium, '2026-09-21 15:00:00', ['assigned_to' => $agent->id, 'resolved_at' => '2026-09-26 15:00:00', 'closed_at' => '2026-09-27 15:00:00']);
    // F: cancelled request, unassigned but not active.
    $make(IncidentStatus::Cancelled, Priority::Low, '2026-10-31 13:00:00', ['type' => 'request']);
});

it('computes every indicator over the whole population', function () {
    Sanctum::actingAs(userWithRole(Role::Admin));

    $stats = $this->getJson('/api/v1/dashboard/stats')->assertOk()->json('data');

    expect($stats)->toMatchArray(['total' => 6, 'active' => 3, 'overdue' => 2, 'unassigned' => 1])
        ->and($stats['by_status'])->toBe([
            ['status' => 'open', 'count' => 1], ['status' => 'in_progress', 'count' => 1], ['status' => 'on_hold', 'count' => 1],
            ['status' => 'resolved', 'count' => 1], ['status' => 'closed', 'count' => 1], ['status' => 'cancelled', 'count' => 1],
        ])
        ->and($stats['by_priority'])->toBe([
            ['priority' => 'low', 'count' => 2, 'overdue' => 0],
            ['priority' => 'medium', 'count' => 1, 'overdue' => 0],
            ['priority' => 'high', 'count' => 2, 'overdue' => 1],
            ['priority' => 'critical', 'count' => 1, 'overdue' => 1],
        ])
        ->and($stats['generated_at'])->toBe('2026-10-31T15:00:00Z');
});

it('builds a 30-day trend in Bogota days with zeros', function () {
    Sanctum::actingAs(userWithRole(Role::Admin));

    $trend = collect($this->getJson('/api/v1/dashboard/stats')->json('data.trend'))->keyBy('date');

    expect($trend)->toHaveCount(30)
        ->and($trend->keys()->first())->toBe('2026-10-02')
        ->and($trend->keys()->last())->toBe('2026-10-31')
        ->and($trend['2026-10-31'])->toBe(['date' => '2026-10-31', 'created' => 2, 'resolved' => 0])
        ->and($trend['2026-10-30']['created'])->toBe(1)
        ->and($trend['2026-10-29'])->toBe(['date' => '2026-10-29', 'created' => 0, 'resolved' => 1])
        ->and($trend['2026-10-10'])->toBe(['date' => '2026-10-10', 'created' => 0, 'resolved' => 0])
        ->and($trend->sum('created'))->toBe(5);
});

it('filters by type and creation range but keeps the trend window', function () {
    Sanctum::actingAs(userWithRole(Role::Admin));

    expect($this->getJson('/api/v1/dashboard/stats?type=request')->json('data.total'))->toBe(1);

    $ranged = $this->getJson('/api/v1/dashboard/stats?date_from=2026-10-27&date_to=2026-10-31')->json('data');
    expect($ranged['total'])->toBe(4)
        ->and(collect($ranged['trend'])->sum('resolved'))->toBe(1);
});

it('scopes the figures to a requester\'s own cases', function () {
    Sanctum::actingAs($this->requester);

    expect($this->getJson('/api/v1/dashboard/stats')->json('data'))
        ->toMatchArray(['total' => 1, 'active' => 1, 'overdue' => 1, 'unassigned' => 1]);
});

it('rejects unknown or invalid parameters', function () {
    Sanctum::actingAs(userWithRole(Role::Admin));

    $this->getJson('/api/v1/dashboard/stats?days=7')->assertUnprocessable()->assertJsonValidationErrors('days');
    $this->getJson('/api/v1/dashboard/stats?date_from=2026-10-31&date_to=2026-10-01')->assertUnprocessable()->assertJsonValidationErrors('date_to');
});

it('serves the catalogs from the enums with spanish labels', function () {
    Sanctum::actingAs($this->requester);

    $this->getJson('/api/v1/catalogs')
        ->assertOk()
        ->assertJsonPath('data.priorities.3', ['value' => 'critical', 'label' => 'Crítica'])
        ->assertJsonPath('data.statuses.4', ['value' => 'closed', 'label' => 'Cerrado', 'is_terminal' => true])
        ->assertJsonPath('data.types', [['value' => 'incident', 'label' => 'Incidente'], ['value' => 'request', 'label' => 'Solicitud']])
        ->assertJsonCount(6, 'data.statuses');
});
