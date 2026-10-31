<?php

namespace App\Application\Incidents\Queries;

use App\Domain\Incidents\Enums\IncidentStatus;
use App\Domain\Incidents\Enums\IncidentType;
use App\Domain\Incidents\Enums\Priority;
use Carbon\CarbonImmutable;

/** Validated listing/export criteria (ListIncidentsRequest::filters()). Dates are already UTC. */
final readonly class IncidentFilters
{
    /**
     * @param  list<IncidentStatus>  $statuses
     * @param  list<Priority>  $priorities
     * @param  int|'me'|'unassigned'|null  $assignedTo
     */
    public function __construct(
        public array $statuses = [],
        public array $priorities = [],
        public int|string|null $assignedTo = null,
        public ?IncidentType $type = null,
        public bool $overdue = false,
        public string $dateField = 'created_at',
        public ?CarbonImmutable $from = null,
        public ?CarbonImmutable $until = null, // exclusive
        public ?string $search = null,
        public string $sort = 'created_at',
        public string $direction = 'desc',
    ) {}
}
