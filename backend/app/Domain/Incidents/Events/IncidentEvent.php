<?php

namespace App\Domain\Incidents\Events;

use App\Domain\Identity\Models\User;
use App\Domain\Incidents\Models\Incident;

/**
 * Domain event accumulated by the Incident aggregate and dispatched by the Action after saving.
 * Knows nothing about WebSockets: Infrastructure listeners translate it.
 */
abstract class IncidentEvent
{
    /** Every concrete event, for listeners that react to all of them (Laravel only resolves parent interfaces, not classes). */
    public const ALL = [
        IncidentRegistered::class,
        IncidentDetailsUpdated::class,
        IncidentReprioritized::class,
        IncidentAssigned::class,
        IncidentUnassigned::class,
        IncidentStatusChanged::class,
        IncidentDeleted::class,
    ];

    /**
     * @param  array<string, array{from: mixed, to: mixed}>  $changes
     */
    public function __construct(
        public readonly Incident $incident,
        public readonly ?User $actor,
        public readonly array $changes = [],
        public readonly ?string $note = null,
    ) {}

    /** Activity action stored in incident_activities. */
    abstract public function action(): string;
}
