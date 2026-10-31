<?php

namespace App\Policies;

use App\Domain\Identity\Enums\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Incidents\Enums\IncidentStatus;
use App\Domain\Incidents\Models\Incident;

/**
 * Permission + ownership + case status (docs/PLAN.md section 4). Visibility (R-16) is not here:
 * the {incident} route binding already answers 404 for cases outside the user's scope.
 * Status invariants (R-07, R-08, R-10) are not here either: the aggregate answers 409.
 */
class IncidentPolicy
{
    public function create(User $user, ?int $assigneeId = null): bool
    {
        if (! $user->hasPermissionTo('incidents.create')) {
            return false;
        }

        return $assigneeId === null
            || $this->isAdmin($user)
            || ($user->hasPermissionTo('incidents.assign') && $assigneeId === $user->id);
    }

    /** R-11: the creator edits only while the case is open; the assignee and admins always. */
    public function update(User $user, Incident $incident): bool
    {
        return $user->hasPermissionTo('incidents.update') && (
            $this->isAdmin($user)
            || $this->isAssignee($user, $incident)
            || ($incident->created_by === $user->id && $incident->status === IncidentStatus::Open)
        );
    }

    /** R-04 and H-4: priority and due date belong to whoever handles the case. */
    public function reprioritize(User $user, Incident $incident): bool
    {
        return $user->hasPermissionTo('incidents.reprioritize')
            && ($this->isAdmin($user) || $this->isAssignee($user, $incident));
    }

    /** Admin assigns anyone; an agent may only take a case that has no assignee. */
    public function assign(User $user, Incident $incident): bool
    {
        return $user->hasPermissionTo('incidents.assign')
            && ($this->isAdmin($user) || $incident->assigned_to === null);
    }

    public function assignTo(User $user, Incident $incident, ?int $assigneeId): bool
    {
        return $this->assign($user, $incident)
            && ($this->isAdmin($user) || $assigneeId === $user->id);
    }

    public function transition(User $user, Incident $incident, IncidentStatus $to): bool
    {
        if (! $user->hasPermissionTo('incidents.transition')) {
            return false;
        }

        // Transitions that do not exist are left to the aggregate, which answers 409.
        if (! $incident->status->canTransitionTo($to) || $this->isAdmin($user)) {
            return true;
        }

        return match (true) {
            $incident->status === IncidentStatus::Resolved => $incident->created_by === $user->id, // close, reopen
            $to === IncidentStatus::Cancelled => $incident->status === IncidentStatus::Open && $incident->created_by === $user->id,
            default => $this->isAssignee($user, $incident), // start, pause, resume, resolve
        };
    }

    public function delete(User $user, Incident $incident): bool
    {
        return $user->hasPermissionTo('incidents.delete');
    }

    private function isAdmin(User $user): bool
    {
        return $user->hasRole(Role::Admin->value);
    }

    private function isAssignee(User $user, Incident $incident): bool
    {
        return $incident->assigned_to === $user->id;
    }
}
