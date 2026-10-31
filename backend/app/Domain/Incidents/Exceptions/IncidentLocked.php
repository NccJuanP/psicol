<?php

namespace App\Domain\Incidents\Exceptions;

final class IncidentLocked extends DomainRuleViolation
{
    public function __construct()
    {
        parent::__construct('El incidente está cerrado o cancelado y no admite cambios.', 'INCIDENT_LOCKED');
    }
}
