<?php

namespace App\Domain\Incidents\Models;

use App\Domain\Audit\Models\IncidentActivity;
use App\Domain\Identity\Enums\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Incidents\Enums\IncidentStatus;
use App\Domain\Incidents\Enums\IncidentType;
use App\Domain\Incidents\Enums\Priority;
use App\Domain\Incidents\Events\IncidentAssigned;
use App\Domain\Incidents\Events\IncidentDeleted;
use App\Domain\Incidents\Events\IncidentDetailsUpdated;
use App\Domain\Incidents\Events\IncidentEvent;
use App\Domain\Incidents\Events\IncidentRegistered;
use App\Domain\Incidents\Events\IncidentReprioritized;
use App\Domain\Incidents\Events\IncidentStatusChanged;
use App\Domain\Incidents\Events\IncidentUnassigned;
use App\Domain\Incidents\Exceptions\AssigneeRequired;
use App\Domain\Incidents\Exceptions\IncidentLocked;
use App\Domain\Incidents\Exceptions\InvalidStatusTransition;
use App\Domain\Incidents\Services\DueDatePolicy;
use BackedEnum;
use Carbon\CarbonImmutable;
use Database\Factories\IncidentFactory;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

/**
 * Aggregate root. Every state change goes through a domain method that checks its invariants
 * and records an event; the Action saves inside a transaction and dispatches them (persist()).
 *
 * @property IncidentType $type
 * @property Priority $priority
 * @property IncidentStatus $status
 */
#[UseFactory(IncidentFactory::class)]
class Incident extends Model
{
    /** @use HasFactory<IncidentFactory> */
    use HasFactory, SoftDeletes;

    private const DETAIL_FIELDS = ['type', 'title', 'description', 'due_at'];

    /** @var list<IncidentEvent> */
    private array $pendingEvents = [];

    protected function casts(): array
    {
        return [
            'type' => IncidentType::class,
            'priority' => Priority::class,
            'status' => IncidentStatus::class,
            'created_by' => 'integer',
            'assigned_to' => 'integer',
            'due_at' => 'datetime',
            'resolved_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function activities(): HasMany
    {
        return $this->hasMany(IncidentActivity::class);
    }

    /** R-16: a requester only sees what they created; agent and admin see everything. */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        if (! $user->hasPermissionTo('incidents.view_all')) {
            $query->where('created_by', $user->id);
        }
    }

    public function scopeActive(Builder $query): void
    {
        $query->whereIn('status', IncidentStatus::active());
    }

    /** R-13: overdue is derived, never stored. */
    public function scopeOverdue(Builder $query): void
    {
        $query->active()->where('due_at', '<', now());
    }

    protected function isOverdue(): Attribute
    {
        return Attribute::get(fn () => $this->status->isActive() && $this->due_at->isPast());
    }

    protected function code(): Attribute
    {
        return Attribute::get(fn () => sprintf('INC-%06d', $this->id));
    }

    public static function register(
        User $creator,
        IncidentType $type,
        string $title,
        string $description,
        Priority $priority,
        ?DateTimeInterface $dueAt = null,
        ?User $assignee = null,
    ): self {
        $incident = new self;
        $incident->type = $type;
        $incident->title = $title;
        $incident->description = $description;
        $incident->priority = $priority;
        $incident->status = IncidentStatus::Open;
        $incident->created_by = $creator->id;
        $incident->due_at = $dueAt ?? DueDatePolicy::dueAt($priority, now());

        $incident->record(new IncidentRegistered($incident, $creator));

        if ($assignee) {
            $incident->assignTo($creator, $assignee);
        }

        return $incident;
    }

    /** @param  array<string, mixed>  $details  type, title, description and/or due_at */
    public function updateDetails(User $actor, array $details): void
    {
        $this->assertMutable();

        foreach (Arr::only($details, self::DETAIL_FIELDS) as $field => $value) {
            $this->setAttribute($field, $value);
        }

        if ($changes = $this->changesFor(self::DETAIL_FIELDS)) {
            $this->record(new IncidentDetailsUpdated($this, $actor, $changes));
        }
    }

    public function reprioritize(User $actor, Priority $priority): void
    {
        $this->assertMutable();
        $this->priority = $priority;

        if ($changes = $this->changesFor(['priority'])) {
            $this->record(new IncidentReprioritized($this, $actor, $changes));
        }
    }

    public function assignTo(User $actor, ?User $assignee): void
    {
        $this->assertMutable();

        // R-09, also checked by the FormRequest: only active agents or admins handle cases.
        if ($assignee && ! ($assignee->is_active && $assignee->hasAnyRole([Role::Agent->value, Role::Admin->value]))) {
            throw ValidationException::withMessages(['assigned_to' => __('incidents.invalid_assignee')]);
        }

        // R-08 holds for the whole time a case is being worked, not only when it starts.
        if (! $assignee && in_array($this->status, [IncidentStatus::InProgress, IncidentStatus::OnHold], true)) {
            throw new AssigneeRequired;
        }

        $this->assigned_to = $assignee?->id;
        $this->unsetRelation('assignee');

        if ($changes = $this->changesFor(['assigned_to'])) {
            $this->record($assignee
                ? new IncidentAssigned($this, $actor, $changes)
                : new IncidentUnassigned($this, $actor, $changes));
        }
    }

    public function transitionTo(User $actor, IncidentStatus $to, ?string $note = null): void
    {
        $this->assertMutable();
        $from = $this->status;

        if (! $from->canTransitionTo($to)) {
            throw new InvalidStatusTransition($from, $to);
        }

        if ($to === IncidentStatus::InProgress && $this->assigned_to === null) {
            throw new AssigneeRequired;
        }

        $this->status = $to;

        if ($to === IncidentStatus::Resolved) {
            $this->resolved_at = now();
        } elseif ($to === IncidentStatus::Closed) {
            $this->closed_at = now();
        } elseif ($from === IncidentStatus::Resolved) {
            $this->resolved_at = null; // reopened
        }

        $this->record(new IncidentStatusChanged($this, $actor, $this->changesFor(['status']), $note));
    }

    public function markDeleted(User $actor): void
    {
        $this->record(new IncidentDeleted($this, $actor));
    }

    /**
     * Transitions the state machine allows right now, before asking who may run them.
     *
     * @return list<IncidentStatus>
     */
    public function availableTransitions(): array
    {
        return array_values(array_filter(
            $this->status->transitions(),
            fn (IncidentStatus $to) => $to !== IncidentStatus::InProgress || $this->assigned_to !== null,
        ));
    }

    /** R-10 */
    public function isLocked(): bool
    {
        return $this->status->isTerminal();
    }

    public function persist(): void
    {
        $this->save();
        $this->dispatchEvents();
    }

    public function dispatchEvents(): void
    {
        [$events, $this->pendingEvents] = [$this->pendingEvents, []];

        foreach ($events as $event) {
            event($event);
        }
    }

    private function assertMutable(): void
    {
        if ($this->isLocked()) {
            throw new IncidentLocked;
        }
    }

    private function record(IncidentEvent $event): void
    {
        $this->pendingEvents[] = $event;
    }

    /**
     * @param  list<string>  $fields
     * @return array<string, array{from: mixed, to: mixed}>
     */
    private function changesFor(array $fields): array
    {
        $changes = [];

        foreach ($fields as $field) {
            if ($this->isDirty($field)) {
                $changes[$field] = [
                    'from' => self::auditValue($this->getOriginal($field)),
                    'to' => self::auditValue($this->getAttribute($field)),
                ];
            }
        }

        return $changes;
    }

    private static function auditValue(mixed $value): mixed
    {
        return match (true) {
            $value instanceof Priority => $value->code(),
            $value instanceof BackedEnum => $value->value,
            $value instanceof DateTimeInterface => CarbonImmutable::instance($value)->toIso8601ZuluString(),
            default => $value,
        };
    }
}
