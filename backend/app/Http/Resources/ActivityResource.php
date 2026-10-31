<?php

namespace App\Http\Resources;

use App\Domain\Audit\Models\IncidentActivity;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Activity in docs/api-contract.md. Expects user:id,name eager loaded.
 *
 * @mixin IncidentActivity
 */
class ActivityResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'action' => $this->action,
            'changes' => (object) $this->changes, // {} rather than [] when empty
            'note' => $this->note,
            'actor' => $this->user?->only(['id', 'name']),
            'created_at' => $this->created_at->toIso8601ZuluString(),
        ];
    }
}
