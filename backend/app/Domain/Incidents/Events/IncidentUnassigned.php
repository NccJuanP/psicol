<?php

namespace App\Domain\Incidents\Events;

final class IncidentUnassigned extends IncidentEvent
{
    public function action(): string
    {
        return 'unassigned';
    }
}
