<?php

namespace App\Domain\Audit\Listeners;

use App\Domain\Audit\Models\IncidentActivity;
use App\Domain\Incidents\Events\IncidentEvent;

/** P1: runs synchronously inside the command's transaction, so a failed audit fails the command. */
class RecordIncidentActivity
{
    public function handle(IncidentEvent $event): void
    {
        IncidentActivity::create([
            'incident_id' => $event->incident->id,
            'user_id' => $event->actor?->id,
            'action' => $event->action(),
            'changes' => $event->changes,
            'note' => $event->note,
        ]);
    }
}
