<?php

use App\Domain\Identity\Enums\Role;
use App\Domain\Incidents\Enums\IncidentStatus;
use App\Domain\Incidents\Models\Incident;
use App\Infrastructure\Broadcasting\IncidentAssignedToYou;
use App\Infrastructure\Broadcasting\IncidentChanged;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\Sanctum;

/** Tests run with the null broadcaster; these switch to a real Reverb driver pointed wherever they need. */
function useReverbDriver(int $port): void
{
    config([
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb' => [
            'driver' => 'reverb', 'key' => 'test-key', 'secret' => 'test-secret', 'app_id' => 'test',
            'options' => ['host' => '127.0.0.1', 'port' => $port, 'scheme' => 'http', 'useTLS' => false],
        ],
    ]);
    app(BroadcastManager::class)->forgetDrivers();
    require base_path('routes/channels.php');
}

function channelNames(IncidentChanged $event): array
{
    return array_map(fn ($channel) => $channel->name, $event->broadcastOn());
}

it('broadcasts every command to staff and to the creator', function (string $expected, Closure $command) {
    Event::fake([IncidentChanged::class, IncidentAssignedToYou::class]);
    $admin = userWithRole(Role::Admin);
    $creator = userWithRole(Role::Requester);
    $incident = Incident::factory()->createdBy($creator)->assignedTo(userWithRole(Role::Agent))->create();
    Sanctum::actingAs($admin);

    $command($this, $incident, $creator)->assertSuccessful();

    Event::assertDispatched(IncidentChanged::class, function (IncidentChanged $e) use ($expected, $incident, $creator, $admin) {
        return $e->event === $expected
            && channelNames($e) === ['private-incidents.staff', "private-users.{$creator->id}"]
            && $e->broadcastWith()['id'] === $incident->id
            && $e->broadcastWith()['code'] === $incident->code
            && $e->broadcastWith()['actor'] === ['id' => $admin->id, 'name' => $admin->name];
    });
})->with([
    'updated' => ['incident.updated', fn ($t, $i) => $t->patchJson("/api/v1/incidents/{$i->id}", ['title' => 'Título nuevo del caso'])],
    'reprioritized' => ['incident.updated', fn ($t, $i) => $t->patchJson("/api/v1/incidents/{$i->id}", ['priority' => 'critical'])],
    'status changed' => ['incident.status_changed', fn ($t, $i) => $t->postJson("/api/v1/incidents/{$i->id}/transition", ['status' => 'in_progress'])],
    'unassigned' => ['incident.assigned', fn ($t, $i) => $t->postJson("/api/v1/incidents/{$i->id}/assign", ['assigned_to' => null])],
    'deleted' => ['incident.deleted', fn ($t, $i) => $t->deleteJson("/api/v1/incidents/{$i->id}")],
]);

it('broadcasts creation with the changed field names only', function () {
    Event::fake([IncidentChanged::class, IncidentAssignedToYou::class]);
    $requester = userWithRole(Role::Requester);
    Sanctum::actingAs($requester);

    $this->postJson('/api/v1/incidents', ['title' => 'Monitor sin imagen', 'description' => 'La pantalla queda en negro.', 'priority' => 'low'])->assertCreated();

    Event::assertDispatched(IncidentChanged::class, fn (IncidentChanged $e) => $e->event === 'incident.created'
        && channelNames($e) === ['private-incidents.staff', "private-users.{$requester->id}"]
        && array_keys($e->broadcastWith()) === ['id', 'code', 'event', 'changes', 'actor', 'occurred_at']);
    Event::assertNotDispatched(IncidentAssignedToYou::class);
});

it('notifies the new assignee privately, but not on self-assignment', function () {
    Event::fake([IncidentChanged::class, IncidentAssignedToYou::class]);
    $agent = userWithRole(Role::Agent);
    $incident = Incident::factory()->create();

    Sanctum::actingAs(userWithRole(Role::Admin));
    $this->postJson("/api/v1/incidents/{$incident->id}/assign", ['assigned_to' => $agent->id])->assertOk();

    Event::assertDispatched(IncidentAssignedToYou::class, fn (IncidentAssignedToYou $e) => $e->event === 'incident.assigned_to_you'
        && channelNames($e) === ["private-users.{$agent->id}"]
        && $e->changes === ['assigned_to']);
    Event::assertDispatchedTimes(IncidentAssignedToYou::class, 1);

    $other = Incident::factory()->create();
    Sanctum::actingAs($agent);
    $this->postJson("/api/v1/incidents/{$other->id}/assign", ['assigned_to' => $agent->id])->assertOk();
    Event::assertDispatchedTimes(IncidentAssignedToYou::class, 1);
});

it('does not broadcast when the command fails', function () {
    Event::fake([IncidentChanged::class]);
    $incident = Incident::factory()->status(IncidentStatus::Closed)->create();
    Sanctum::actingAs(userWithRole(Role::Admin));

    $this->patchJson("/api/v1/incidents/{$incident->id}", ['title' => 'Intento de cambio'])->assertConflict();

    Event::assertNotDispatched(IncidentChanged::class);
});

it('authorizes the staff channel only for agents and admins', function (Role $role, int $status) {
    useReverbDriver(8080);
    Sanctum::actingAs(userWithRole($role));

    $this->postJson('/api/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => 'private-incidents.staff'])
        ->assertStatus($status);
})->with([
    'admin' => [Role::Admin, 200],
    'agent' => [Role::Agent, 200],
    'requester' => [Role::Requester, 403],
]);

it('authorizes a user channel only for its owner', function () {
    useReverbDriver(8080);
    $user = userWithRole(Role::Requester);
    Sanctum::actingAs($user);

    $this->postJson('/api/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => "private-users.{$user->id}"])
        ->assertOk()
        ->assertJsonStructure(['auth']);
    $this->postJson('/api/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => 'private-users.'.($user->id + 1)])
        ->assertForbidden();
});

it('requires a token to authorize channels', function () {
    $this->postJson('/api/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => 'private-incidents.staff'])
        ->assertUnauthorized()
        ->assertJsonPath('code', 'UNAUTHENTICATED');
});

it('still completes the command and logs the error when Reverb is down', function () {
    useReverbDriver(9); // nothing listens on the discard port
    Log::spy();
    $incident = Incident::factory()->assignedTo(userWithRole(Role::Agent))->create();
    Sanctum::actingAs(userWithRole(Role::Admin));

    $this->postJson("/api/v1/incidents/{$incident->id}/transition", ['status' => 'in_progress'])
        ->assertOk()
        ->assertJsonPath('data.status', 'in_progress');

    expect($incident->fresh()->status)->toBe(IncidentStatus::InProgress);
    Log::shouldHaveReceived('error')
        ->withArgs(fn (string $message, array $context) => str_contains($message, 'tiempo real') && $context['event'] === 'incident.status_changed')
        ->once();
});
