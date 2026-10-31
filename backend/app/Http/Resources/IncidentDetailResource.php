<?php

namespace App\Http\Resources;

use App\Domain\Incidents\Enums\IncidentStatus;
use App\Domain\Incidents\Models\Incident;
use Illuminate\Http\Request;

/**
 * IncidentDetail in docs/api-contract.md: the list item plus what the current user may do (D-24).
 *
 * @mixin Incident
 */
class IncidentDetailResource extends IncidentListResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $this->resource->loadMissing(['creator:id,name', 'assignee:id,name']);
        $user = $request->user();
        $locked = $this->resource->isLocked();

        $allowed = array_filter(
            $this->resource->availableTransitions(),
            fn (IncidentStatus $to) => $user->can('transition', [$this->resource, $to]),
        );

        return [
            ...parent::toArray($request),
            'description' => $this->description,
            'resolved_at' => $this->resolved_at?->toIso8601ZuluString(),
            'closed_at' => $this->closed_at?->toIso8601ZuluString(),
            'allowed_transitions' => array_values(array_map(fn (IncidentStatus $to) => $to->value, $allowed)),
            'can' => [
                'update' => ! $locked && $user->can('update', $this->resource),
                'reprioritize' => ! $locked && $user->can('reprioritize', $this->resource),
                'assign' => ! $locked && $user->can('assign', $this->resource),
                'delete' => $user->can('delete', $this->resource),
            ],
        ];
    }
}
