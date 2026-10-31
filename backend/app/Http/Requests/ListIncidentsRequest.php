<?php

namespace App\Http\Requests;

use App\Application\Incidents\Queries\IncidentFilters;
use App\Domain\Incidents\Enums\IncidentStatus;
use App\Domain\Incidents\Enums\IncidentType;
use App\Domain\Incidents\Enums\Priority;
use App\Http\Requests\Concerns\RejectsUnknownParameters;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Listing parameters of docs/api-contract.md. Any invalid or unknown parameter is a 422, never ignored. */
class ListIncidentsRequest extends FormRequest
{
    use RejectsUnknownParameters;

    public const SORTABLE = ['id', 'title', 'priority', 'status', 'due_at', 'created_at', 'updated_at'];

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'between:5,100'],
            'sort' => ['sometimes', Rule::in([...self::SORTABLE, ...array_map(fn ($c) => "-{$c}", self::SORTABLE)])],
            'search' => ['sometimes', 'string', 'min:2', 'max:100'],
            'filter' => ['sometimes', 'array:status,priority,assigned_to,type,overdue,date_field,date_from,date_to'],
            'filter.status' => ['sometimes', 'string', $this->csvIn(array_column(IncidentStatus::cases(), 'value'))],
            'filter.priority' => ['sometimes', 'string', $this->csvIn(Priority::codes())],
            'filter.assigned_to' => ['sometimes', 'regex:/^(me|unassigned|[1-9]\d{0,18})$/'],
            'filter.type' => ['sometimes', Rule::enum(IncidentType::class)],
            'filter.overdue' => ['sometimes', 'in:true,false,1,0'],
            'filter.date_field' => ['sometimes', 'in:created_at,due_at'],
            'filter.date_from' => ['sometimes', 'date_format:Y-m-d'],
            'filter.date_to' => ['sometimes', 'date_format:Y-m-d', ...($this->has('filter.date_from') ? ['after_or_equal:filter.date_from'] : [])],
        ];
    }

    protected function allowedParameters(): array
    {
        return ['page', 'per_page', 'sort', 'search', 'filter'];
    }

    public function perPage(): int
    {
        return $this->integer('per_page', 15);
    }

    public function filters(): IncidentFilters
    {
        $filter = $this->validated('filter', []);
        $sort = $this->validated('sort', '-created_at');
        $zone = config('incidents.timezone');
        $assignedTo = $filter['assigned_to'] ?? null;

        return new IncidentFilters(
            statuses: array_map(IncidentStatus::from(...), $this->csv($filter['status'] ?? null)),
            priorities: array_map(Priority::fromCode(...), $this->csv($filter['priority'] ?? null)),
            assignedTo: is_numeric($assignedTo) ? (int) $assignedTo : $assignedTo,
            type: isset($filter['type']) ? IncidentType::from($filter['type']) : null,
            overdue: in_array($filter['overdue'] ?? null, ['true', '1'], true),
            dateField: $filter['date_field'] ?? 'created_at',
            // S-08: days are Bogota days; date_to is inclusive, so the bound is the next local midnight.
            from: isset($filter['date_from']) ? CarbonImmutable::parse($filter['date_from'], $zone)->startOfDay()->utc() : null,
            until: isset($filter['date_to']) ? CarbonImmutable::parse($filter['date_to'], $zone)->addDay()->startOfDay()->utc() : null,
            search: $this->validated('search'),
            sort: ltrim($sort, '-'),
            direction: str_starts_with($sort, '-') ? 'desc' : 'asc',
        );
    }

    /** @param  list<string>  $allowed */
    private function csvIn(array $allowed): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($allowed) {
            if (! is_string($value) || array_diff($this->csv($value), $allowed) || ! $this->csv($value)) {
                $fail(__('validation.csv_in'));
            }
        };
    }

    /** @return list<string> */
    private function csv(?string $value): array
    {
        return $value === null ? [] : array_values(array_filter(array_map('trim', explode(',', $value)), 'strlen'));
    }
}
