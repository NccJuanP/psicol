<?php

namespace App\Application\Incidents\Actions;

use App\Domain\Identity\Models\User;
use App\Domain\Incidents\Models\Incident;
use Illuminate\Support\Facades\DB;

class AssignIncident
{
    public function handle(User $actor, Incident $incident, ?int $assigneeId): Incident
    {
        return DB::transaction(function () use ($actor, $incident, $assigneeId) {
            $incident->assignTo($actor, $assigneeId ? User::findOrFail($assigneeId) : null);
            $incident->persist();

            return $incident;
        });
    }
}
