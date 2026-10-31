<?php

use App\Domain\Audit\Models\IncidentActivity;
use App\Domain\Identity\Enums\Role;
use App\Domain\Incidents\Enums\IncidentStatus;
use App\Domain\Incidents\Enums\Priority;
use App\Domain\Incidents\Models\Incident;
use Database\Seeders\IncidentSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

function listedIds(string $query = ''): array
{
    return array_column(test()->getJson('/api/v1/incidents'.$query)->assertOk()->json('data'), 'id');
}

it('returns the contract shape, newest first', function () {
    Sanctum::actingAs(userWithRole(Role::Admin));
    $old = Incident::factory()->create(['created_at' => now()->subDays(2)]);
    $new = Incident::factory()->create();

    $response = $this->getJson('/api/v1/incidents')->assertOk();

    expect(array_column($response->json('data'), 'id'))->toBe([$new->id, $old->id])
        ->and(array_keys($response->json('meta')))->toEqualCanonicalizing(['current_page', 'per_page', 'total', 'last_page', 'from', 'to'])
        ->and(array_keys($response->json('links')))->toEqualCanonicalizing(['first', 'last', 'prev', 'next'])
        ->and($response->json('data.0'))->not->toHaveKey('description')
        ->and($response->json('meta.per_page'))->toBe(15);
});

it('combines status, priority, type and assignee filters', function () {
    $me = userWithRole(Role::Agent);
    Sanctum::actingAs($me);
    $match = Incident::factory()->assignedTo($me)->status(IncidentStatus::InProgress)->create(['priority' => Priority::High]);
    Incident::factory()->assignedTo($me)->status(IncidentStatus::InProgress)->create(['priority' => Priority::Low]);
    Incident::factory()->assignedTo(userWithRole(Role::Agent))->status(IncidentStatus::InProgress)->create(['priority' => Priority::High]);
    Incident::factory()->assignedTo($me)->status(IncidentStatus::Closed)->create(['priority' => Priority::High]);
    $unassigned = Incident::factory()->create(['type' => 'request']);

    expect(listedIds('?filter[status]=open,in_progress&filter[priority]=high,critical&filter[assigned_to]=me'))->toBe([$match->id])
        ->and(listedIds('?filter[assigned_to]=unassigned'))->toBe([$unassigned->id])
        ->and(listedIds('?filter[type]=request'))->toBe([$unassigned->id])
        ->and(listedIds("?filter[assigned_to]={$me->id}&filter[status]=closed"))->toHaveCount(1);
});

it('filters overdue cases as active and past due', function () {
    Sanctum::actingAs(userWithRole(Role::Admin));
    $overdue = Incident::factory()->create(['due_at' => now()->subHour()]);
    Incident::factory()->create(['due_at' => now()->addHour()]);
    Incident::factory()->status(IncidentStatus::Closed)->create(['due_at' => now()->subHour()]);

    expect(listedIds('?filter[overdue]=true'))->toBe([$overdue->id])
        ->and($this->getJson("/api/v1/incidents/{$overdue->id}")->json('data.is_overdue'))->toBeTrue();
});

it('reads date ranges as Bogota days, inclusive', function () {
    Sanctum::actingAs(userWithRole(Role::Admin));
    $before = Incident::factory()->create(['created_at' => '2026-10-01 04:59:00']); // Sep 30, 23:59 in Bogota
    $first = Incident::factory()->create(['created_at' => '2026-10-01 05:00:00']);  // Oct 1, 00:00
    $last = Incident::factory()->create(['created_at' => '2026-10-02 04:59:00']);   // Oct 1, 23:59
    $after = Incident::factory()->create(['created_at' => '2026-10-02 05:00:00']);
    $due = Incident::factory()->create(['created_at' => '2026-09-01 12:00:00', 'due_at' => '2026-10-01 12:00:00']);

    expect(listedIds('?filter[date_from]=2026-10-01&filter[date_to]=2026-10-01'))->toEqualCanonicalizing([$first->id, $last->id])
        ->and(listedIds('?filter[date_field]=due_at&filter[date_from]=2026-10-01&filter[date_to]=2026-10-01'))->toBe([$due->id])
        ->and(listedIds('?filter[date_from]=2026-10-02'))->toBe([$after->id])
        ->and($before->id)->toBeInt();
});

it('finds an incident by exact id or code', function () {
    Sanctum::actingAs(userWithRole(Role::Admin));
    $target = Incident::factory()->create();
    Incident::factory()->create();

    expect(listedIds("?search={$target->id}"))->toBe([$target->id])
        ->and(listedIds('?search='.sprintf('INC-%06d', $target->id)))->toBe([$target->id]);
});

it('sorts by a whitelisted column with id as tie breaker', function () {
    Sanctum::actingAs(userWithRole(Role::Admin));
    $low = Incident::factory()->create(['priority' => Priority::Low]);
    $critical = Incident::factory()->create(['priority' => Priority::Critical]);
    $critical2 = Incident::factory()->create(['priority' => Priority::Critical]);

    expect(listedIds('?sort=-priority'))->toBe([$critical2->id, $critical->id, $low->id])
        ->and(listedIds('?sort=id'))->toBe([$low->id, $critical->id, $critical2->id]);
});

it('rejects invalid or unknown parameters with 422', function (string $query, string $field) {
    Sanctum::actingAs(userWithRole(Role::Admin));

    $this->getJson('/api/v1/incidents'.$query)->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    'sort outside whitelist' => ['?sort=description', 'sort'],
    'per_page over 100' => ['?per_page=101', 'per_page'],
    'per_page under 5' => ['?per_page=2', 'per_page'],
    'unknown status' => ['?filter[status]=open,nope', 'filter.status'],
    'unknown filter' => ['?filter[color]=red', 'filter'],
    'unknown parameter' => ['?foo=bar', 'foo'],
    'bad assignee' => ['?filter[assigned_to]=someone', 'filter.assigned_to'],
    'reversed range' => ['?filter[date_from]=2026-10-05&filter[date_to]=2026-10-01', 'filter.date_to'],
    'short search' => ['?search=a', 'search'],
]);

it('shows a requester only their own cases', function () {
    $requester = userWithRole(Role::Requester);
    $mine = Incident::factory()->createdBy($requester)->create();
    Incident::factory()->count(3)->create();
    Sanctum::actingAs($requester);

    expect(listedIds())->toBe([$mine->id]);
});

it('runs the same number of queries for 15 and 50 rows (no N+1)', function () {
    Sanctum::actingAs(userWithRole(Role::Admin));
    $agents = [userWithRole(Role::Agent), userWithRole(Role::Agent)];
    Incident::factory()->count(60)->sequence(fn ($s) => ['assigned_to' => $s->index % 3 ? $agents[$s->index % 2]->id : null])->create();

    $queriesFor = function (int $perPage) {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $rows = count($this->getJson("/api/v1/incidents?per_page={$perPage}")->assertOk()->json('data'));
        DB::disableQueryLog();

        return [$rows, count(DB::getQueryLog())];
    };

    $queriesFor(5); // warm the permission cache so both measurements see the same caches
    [$rows15, $queries15] = $queriesFor(15);
    [$rows50, $queries50] = $queriesFor(50);

    expect([$rows15, $rows50])->toBe([15, 50])->and($queries50)->toBe($queries15);
});

it('seeds coherent bulk data', function () {
    $this->seed(UserSeeder::class);
    (new IncidentSeeder)->run(300);

    $handled = [IncidentStatus::InProgress, IncidentStatus::OnHold, IncidentStatus::Resolved, IncidentStatus::Closed];

    expect(Incident::count())->toBe(300)
        ->and(IncidentActivity::where('action', 'created')->count())->toBe(300)
        ->and(Incident::whereIn('status', $handled)->whereNull('assigned_to')->count())->toBe(0)
        ->and(Incident::whereIn('status', [IncidentStatus::Resolved, IncidentStatus::Closed])->whereNull('resolved_at')->count())->toBe(0)
        ->and(Incident::where('status', IncidentStatus::Closed)->whereNull('closed_at')->count())->toBe(0)
        ->and(Incident::whereNotIn('status', [IncidentStatus::Resolved, IncidentStatus::Closed])->whereNotNull('resolved_at')->count())->toBe(0)
        ->and(Incident::where('due_at', '<', DB::raw('created_at'))->count())->toBe(0)
        ->and(Incident::overdue()->count())->toBeGreaterThan(0);
});
