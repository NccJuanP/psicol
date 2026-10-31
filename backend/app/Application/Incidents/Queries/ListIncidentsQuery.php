<?php

namespace App\Application\Incidents\Queries;

use App\Domain\Identity\Models\User;
use App\Domain\Incidents\Models\Incident;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/** Read side of the listing; the export walks the same builder (D-17). */
final class ListIncidentsQuery
{
    /** Everything IncidentListResource needs, without description (TEXT). */
    public const COLUMNS = [
        'id', 'type', 'title', 'priority', 'status', 'created_by', 'assigned_to', 'due_at', 'created_at', 'updated_at',
    ];

    public function paginate(User $user, IncidentFilters $filters, int $perPage): LengthAwarePaginator
    {
        return $this->builder($user, $filters)->paginate($perPage)->withQueryString();
    }

    /** @return Builder<Incident> */
    public function builder(User $user, IncidentFilters $filters): Builder
    {
        $query = Incident::query()
            ->visibleTo($user)
            ->select(self::COLUMNS)
            ->with(['creator:id,name', 'assignee:id,name']);

        if ($filters->statuses) {
            $query->whereIn('status', $filters->statuses);
        }

        if ($filters->priorities) {
            $query->whereIn('priority', $filters->priorities);
        }

        if ($filters->assignedTo === 'me') {
            $query->where('assigned_to', $user->id);
        } elseif ($filters->assignedTo === 'unassigned') {
            $query->whereNull('assigned_to');
        } elseif ($filters->assignedTo !== null) {
            $query->where('assigned_to', $filters->assignedTo);
        }

        if ($filters->type) {
            $query->where('type', $filters->type);
        }

        if ($filters->overdue) {
            $query->overdue();
        }

        if ($filters->from) {
            $query->where($filters->dateField, '>=', $filters->from);
        }

        if ($filters->until) {
            $query->where($filters->dateField, '<', $filters->until);
        }

        if ($filters->search !== null) {
            $this->applySearch($query, $filters->search);
        }

        $query->orderBy($filters->sort, $filters->direction);

        if ($filters->sort !== 'id') {
            $query->orderByDesc('id'); // stable pages
        }

        return $query;
    }

    /** D-10: "123" or "INC-000123" is an exact id; anything else is a FULLTEXT prefix search. */
    private function applySearch(Builder $query, string $search): void
    {
        if (preg_match('/^(?:INC-)?0*(\d{1,18})$/i', trim($search), $match)) {
            $query->whereKey((int) $match[1]);

            return;
        }

        // Splitting on anything but letters and digits drops the user's boolean operators (+-<>()~*"@).
        // Words under 3 characters are below InnoDB's innodb_ft_min_token_size and would never match.
        $terms = array_filter(
            preg_split('/[^\p{L}\p{N}]+/u', $search, -1, PREG_SPLIT_NO_EMPTY),
            fn (string $term) => mb_strlen($term) >= 3,
        );

        if (! $terms) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->whereFullText(
            ['title', 'description'],
            implode(' ', array_map(fn (string $term) => "+{$term}*", $terms)),
            ['mode' => 'boolean'],
        );
    }
}
