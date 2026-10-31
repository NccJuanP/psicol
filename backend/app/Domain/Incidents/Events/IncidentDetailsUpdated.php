<?php

namespace App\Domain\Incidents\Events;

final class IncidentDetailsUpdated extends IncidentEvent
{
    public function action(): string
    {
        return 'updated';
    }
}
