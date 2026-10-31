<?php

namespace App\Domain\Incidents\Events;

final class IncidentRegistered extends IncidentEvent
{
    public function action(): string
    {
        return 'created';
    }
}
