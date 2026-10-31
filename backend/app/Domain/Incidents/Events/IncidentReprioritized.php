<?php

namespace App\Domain\Incidents\Events;

final class IncidentReprioritized extends IncidentEvent
{
    public function action(): string
    {
        return 'reprioritized';
    }
}
