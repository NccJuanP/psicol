<?php

namespace App\Domain\Incidents\Enums;

enum IncidentStatus: string
{
    case Open = 'open';
    case InProgress = 'in_progress';
    case OnHold = 'on_hold';
    case Resolved = 'resolved';
    case Closed = 'closed';
    case Cancelled = 'cancelled';

    /**
     * The only allowed transitions (R-07). Who may run each one lives in IncidentPolicy.
     *
     * @return list<self>
     */
    public function transitions(): array
    {
        return match ($this) {
            self::Open => [self::InProgress, self::Cancelled],
            self::InProgress => [self::OnHold, self::Resolved, self::Cancelled],
            self::OnHold => [self::InProgress, self::Cancelled],
            self::Resolved => [self::Closed, self::InProgress],
            self::Closed, self::Cancelled => [],
        };
    }

    public function canTransitionTo(self $to): bool
    {
        return in_array($to, $this->transitions(), true);
    }

    /** @return list<self> */
    public static function active(): array
    {
        return [self::Open, self::InProgress, self::OnHold];
    }

    public function isActive(): bool
    {
        return in_array($this, self::active(), true);
    }

    public function isTerminal(): bool
    {
        return $this === self::Closed || $this === self::Cancelled;
    }

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Abierto',
            self::InProgress => 'En progreso',
            self::OnHold => 'En espera',
            self::Resolved => 'Resuelto',
            self::Closed => 'Cerrado',
            self::Cancelled => 'Cancelado',
        };
    }
}
