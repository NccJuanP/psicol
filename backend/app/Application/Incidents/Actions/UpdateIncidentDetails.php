<?php

namespace App\Application\Incidents\Actions;

use App\Domain\Identity\Models\User;
use App\Domain\Incidents\Enums\IncidentType;
use App\Domain\Incidents\Enums\Priority;
use App\Domain\Incidents\Models\Incident;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Covers UpdateIncidentDetails and ReprioritizeIncident: one PATCH may carry both. */
class UpdateIncidentDetails
{
    /** @param  array<string, mixed>  $data  validated UpdateIncidentRequest payload */
    public function handle(User $actor, Incident $incident, array $data): Incident
    {
        return DB::transaction(function () use ($actor, $incident, $data) {
            $details = Arr::only($data, ['type', 'title', 'description', 'due_at']);

            if (isset($details['type'])) {
                $details['type'] = IncidentType::from($details['type']);
            }

            if (isset($details['due_at'])) {
                $details['due_at'] = Carbon::parse($details['due_at']);
            }

            if ($details) {
                $incident->updateDetails($actor, $details);
            }

            if (isset($data['priority'])) {
                $incident->reprioritize($actor, Priority::fromCode($data['priority']));
            }

            $incident->persist();

            return $incident;
        });
    }
}
