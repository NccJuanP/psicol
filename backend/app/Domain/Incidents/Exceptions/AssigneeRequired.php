<?php

namespace App\Domain\Incidents\Exceptions;

final class AssigneeRequired extends DomainRuleViolation
{
    public function __construct()
    {
        parent::__construct('El incidente necesita un responsable mientras se atiende.', 'ASSIGNEE_REQUIRED');
    }
}
