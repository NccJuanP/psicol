<?php

namespace App\Domain\Incidents\Services;

use App\Domain\Incidents\Enums\Priority;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/** Default due date when none is sent (R-05): the SLA of the priority, counted from registration. */
final class DueDatePolicy
{
    public static function dueAt(Priority $priority, DateTimeInterface $from): CarbonImmutable
    {
        return CarbonImmutable::instance($from)->addDays($priority->slaDays());
    }
}
