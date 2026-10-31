<?php

namespace App\Http\Resources;

use App\Domain\Incidents\Models\Incident;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * IncidentListItem in docs/api-contract.md. Expects creator:id,name and assignee:id,name eager loaded.
 *
 * @mixin Incident
 */
class IncidentListResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'type' => $this->type->value,
            'title' => $this->title,
            'priority' => $this->priority->code(),
            'status' => $this->status->value,
            'is_overdue' => $this->is_overdue,
            'due_at' => $this->due_at->toIso8601ZuluString(),
            'creator' => $this->creator->only(['id', 'name']),
            'assignee' => $this->assignee?->only(['id', 'name']),
            'created_at' => $this->created_at->toIso8601ZuluString(),
            'updated_at' => $this->updated_at->toIso8601ZuluString(),
        ];
    }
}
