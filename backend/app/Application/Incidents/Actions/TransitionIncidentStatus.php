<?php

namespace App\Application\Incidents\Actions;

use App\Domain\Identity\Models\User;
use App\Domain\Incidents\Enums\IncidentStatus;
use App\Domain\Incidents\Models\Incident;
use Illuminate\Support\Facades\DB;

class TransitionIncidentStatus
{
    public function handle(User $actor, Incident $incident, IncidentStatus $to, ?string $note = null): Incident
    {
        return DB::transaction(function () use ($actor, $incident, $to, $note) {
            $incident->transitionTo($actor, $to, $note);
            $incident->persist();

            return $incident;
        });
    }
}
