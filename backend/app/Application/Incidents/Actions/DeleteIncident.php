<?php

namespace App\Application\Incidents\Actions;

use App\Domain\Identity\Models\User;
use App\Domain\Incidents\Models\Incident;
use Illuminate\Support\Facades\DB;

class DeleteIncident
{
    /** R-14: soft delete, so the audit trail and historical figures survive. */
    public function handle(User $actor, Incident $incident): void
    {
        DB::transaction(function () use ($actor, $incident) {
            $incident->markDeleted($actor);
            $incident->delete();
            $incident->dispatchEvents();
        });
    }
}
