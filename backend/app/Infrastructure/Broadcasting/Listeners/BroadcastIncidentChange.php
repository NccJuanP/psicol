<?php

namespace App\Infrastructure\Broadcasting\Listeners;

use App\Domain\Incidents\Events\IncidentAssigned;
use App\Domain\Incidents\Events\IncidentDeleted;
use App\Domain\Incidents\Events\IncidentEvent;
use App\Domain\Incidents\Events\IncidentRegistered;
use App\Domain\Incidents\Events\IncidentStatusChanged;
use App\Domain\Incidents\Events\IncidentUnassigned;
use App\Infrastructure\Broadcasting\IncidentAssignedToYou;
use App\Infrastructure\Broadcasting\IncidentChanged;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * P2 and P3: translates domain events into WebSocket events once the transaction has committed.
 * Realtime never breaks a business operation: if Reverb fails, the error is logged and the command still succeeds.
 */
class BroadcastIncidentChange implements ShouldHandleEventsAfterCommit
{
    public function handle(IncidentEvent $event): void
    {
        $incident = $event->incident;
        $payload = [
            'id' => $incident->id,
            'code' => $incident->code,
            'changes' => array_keys($event->changes),
            'actor' => $event->actor?->only(['id', 'name']),
            'occurredAt' => now()->toIso8601ZuluString(),
            'creatorId' => $incident->created_by,
        ];

        $this->send(new IncidentChanged(...[...$payload, 'event' => self::eventName($event)]));

        $assigneeId = $incident->assigned_to;
        if ($event instanceof IncidentAssigned && $assigneeId !== null && $assigneeId !== $event->actor?->id) {
            $this->send(new IncidentAssignedToYou(...[...$payload, 'assigneeId' => $assigneeId]));
        }
    }

    private static function eventName(IncidentEvent $event): string
    {
        return match (true) {
            $event instanceof IncidentRegistered => 'incident.created',
            $event instanceof IncidentStatusChanged => 'incident.status_changed',
            $event instanceof IncidentAssigned, $event instanceof IncidentUnassigned => 'incident.assigned',
            $event instanceof IncidentDeleted => 'incident.deleted',
            default => 'incident.updated', // details updated, reprioritized
        };
    }

    private function send(IncidentChanged $broadcast): void
    {
        try {
            event($broadcast);
        } catch (Throwable $e) {
            Log::error('No se pudo emitir el evento de tiempo real.', [
                'event' => $broadcast->event,
                'incident_id' => $broadcast->id,
                'exception' => $e->getMessage(),
            ]);
        }
    }
}
