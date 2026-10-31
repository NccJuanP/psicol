<?php

namespace App\Infrastructure\Broadcasting;

use Illuminate\Broadcasting\PrivateChannel;

/** P2: tells the new assignee, and only them, that a case is now theirs. */
class IncidentAssignedToYou extends IncidentChanged
{
    /**
     * @param  list<string>  $changes
     * @param  array{id: int, name: string}|null  $actor
     */
    public function __construct(
        int $id,
        string $code,
        array $changes,
        ?array $actor,
        string $occurredAt,
        int $creatorId,
        public readonly int $assigneeId,
    ) {
        parent::__construct($id, $code, 'incident.assigned_to_you', $changes, $actor, $occurredAt, $creatorId);
    }

    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel("users.{$this->assigneeId}")];
    }
}
