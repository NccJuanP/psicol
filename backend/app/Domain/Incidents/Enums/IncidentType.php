<?php

namespace App\Domain\Incidents\Enums;

enum IncidentType: string
{
    case Incident = 'incident';
    case Request = 'request';

    public function label(): string
    {
        return match ($this) {
            self::Incident => 'Incidente',
            self::Request => 'Solicitud',
        };
    }
}
