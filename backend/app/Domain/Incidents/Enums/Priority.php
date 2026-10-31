<?php

namespace App\Domain\Incidents\Enums;

use ValueError;

/** Stored as TINYINT so ordering by priority is ordering by criticality (D-09); travels as a text code. */
enum Priority: int
{
    case Low = 1;
    case Medium = 2;
    case High = 3;
    case Critical = 4;

    public function code(): string
    {
        return strtolower($this->name);
    }

    public static function fromCode(string $code): self
    {
        foreach (self::cases() as $case) {
            if ($case->code() === $code) {
                return $case;
            }
        }

        throw new ValueError("Unknown priority code [{$code}].");
    }

    /** @return list<string> */
    public static function codes(): array
    {
        return array_map(fn (self $case) => $case->code(), self::cases());
    }

    public function label(): string
    {
        return match ($this) {
            self::Low => 'Baja',
            self::Medium => 'Media',
            self::High => 'Alta',
            self::Critical => 'Crítica',
        };
    }

    public function slaDays(): int
    {
        return (int) config("incidents.sla_days.{$this->code()}");
    }
}
