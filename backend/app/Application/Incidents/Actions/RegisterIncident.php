<?php

namespace App\Application\Incidents\Actions;

use App\Domain\Identity\Models\User;
use App\Domain\Incidents\Enums\IncidentType;
use App\Domain\Incidents\Enums\Priority;
use App\Domain\Incidents\Models\Incident;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class RegisterIncident
{
    /** @param  array<string, mixed>  $data  validated StoreIncidentRequest payload */
    public function handle(User $creator, array $data): Incident
    {
        return DB::transaction(function () use ($creator, $data) {
            $incident = Incident::register(
                creator: $creator, // R-02
                type: IncidentType::from($data['type'] ?? IncidentType::Incident->value),
                title: $data['title'],
                description: $data['description'],
                priority: Priority::fromCode($data['priority']),
                dueAt: isset($data['due_at']) ? Carbon::parse($data['due_at']) : null,
                assignee: isset($data['assigned_to']) ? User::findOrFail($data['assigned_to']) : null,
            );

            $incident->persist();

            return $incident;
        });
    }
}
