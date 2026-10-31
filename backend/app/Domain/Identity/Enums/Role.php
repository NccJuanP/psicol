<?php

namespace App\Domain\Identity\Enums;

enum Role: string
{
    case Admin = 'admin';
    case Agent = 'agent';
    case Requester = 'requester';

    /**
     * Permission matrix from docs/PLAN.md section 4. Ownership and status rules live in IncidentPolicy.
     *
     * @return list<string>
     */
    public function permissions(): array
    {
        return match ($this) {
            self::Admin => self::allPermissions(),
            self::Agent => array_values(array_diff(self::allPermissions(), ['incidents.delete'])),
            self::Requester => ['incidents.create', 'incidents.update', 'incidents.transition', 'dashboard.view'],
        };
    }

    /**
     * @return list<string>
     */
    public static function allPermissions(): array
    {
        return [
            'incidents.view_all',
            'incidents.create',
            'incidents.update',
            'incidents.reprioritize',
            'incidents.assign',
            'incidents.transition',
            'incidents.delete',
            'incidents.export',
            'dashboard.view',
        ];
    }
}
