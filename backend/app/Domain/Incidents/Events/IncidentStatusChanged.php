<?php

namespace App\Domain\Incidents\Events;

final class IncidentStatusChanged extends IncidentEvent
{
    public function action(): string
    {
        return 'status_changed';
    }
}
