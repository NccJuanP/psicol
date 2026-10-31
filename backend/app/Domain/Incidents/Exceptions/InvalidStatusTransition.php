<?php

namespace App\Domain\Incidents\Exceptions;

use App\Domain\Incidents\Enums\IncidentStatus;

final class InvalidStatusTransition extends DomainRuleViolation
{
    public function __construct(IncidentStatus $from, IncidentStatus $to)
    {
        parent::__construct("No se puede pasar de «{$from->label()}» a «{$to->label()}».", 'INVALID_STATUS_TRANSITION');
    }
}
