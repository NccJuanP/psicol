<?php

namespace App\Infrastructure\Broadcasting;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/**
 * Minimal payload (docs/api-contract.md, section 8): clients never render it, they only decide what to
 * refetch, so nothing outside the receiver's visibility leaks. Sent synchronously (D-13, D-14).
 */
class IncidentChanged implements ShouldBroadcastNow
{
    /**
     * @param  list<string>  $changes
     * @param  array{id: int, name: string}|null  $actor
     */
    public function __construct(
        public readonly int $id,
        public readonly string $code,
        public readonly string $event,
        public readonly array $changes,
        public readonly ?array $actor,
        public readonly string $occurredAt,
        public readonly int $creatorId,
    ) {}

    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('incidents.staff'), new PrivateChannel("users.{$this->creatorId}")];
    }

    public function broadcastAs(): string
    {
        return $this->event;
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'event' => $this->event,
            'changes' => $this->changes,
            'actor' => $this->actor,
            'occurred_at' => $this->occurredAt,
        ];
    }
}
