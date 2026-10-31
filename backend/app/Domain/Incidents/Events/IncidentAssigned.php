<?php

namespace App\Domain\Incidents\Events;

final class IncidentAssigned extends IncidentEvent
{
    public function action(): string
    {
        return 'assigned';
    }
}
