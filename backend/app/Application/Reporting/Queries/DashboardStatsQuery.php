<?php

namespace App\Application\Reporting\Queries;

use App\Domain\Identity\Models\User;
use App\Domain\Incidents\Enums\IncidentStatus;
use App\Domain\Incidents\Enums\IncidentType;
use App\Domain\Incidents\Enums\Priority;
use App\Domain\Incidents\Models\Incident;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;

/**
 * DashboardStats of docs/api-contract.md in five GROUP BY/COUNT queries over visibleTo($user) (S-07);
 * total, active and overdue are derived from them. No cache: see docs/performance.md (D-12).
 */
final class DashboardStatsQuery
{
    private const TREND_DAYS = 30;

    /** @return array<string, mixed> */
    public function handle(User $user, ?CarbonImmutable $from = null, ?CarbonImmutable $until = null, ?IncidentType $type = null): array
    {
        $now = CarbonImmutable::now();
        $active = array_map(fn (IncidentStatus $s) => $s->value, IncidentStatus::active());

        $visible = fn (): Builder => Incident::query()
            ->visibleTo($user)
            ->when($type, fn ($query) => $query->where('type', $type))
            ->toBase();

        $population = fn (): Builder => $visible()
            ->when($from, fn ($query) => $query->where('created_at', '>=', $from))
            ->when($until, fn ($query) => $query->where('created_at', '<', $until));

        $byStatus = $population()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $placeholders = implode(',', array_fill(0, count($active), '?'));
        $byPriority = $population()
            ->selectRaw("priority, count(*) as total, sum(case when status in ({$placeholders}) and due_at < ? then 1 else 0 end) as overdue", [...$active, $now])
            ->groupBy('priority')
            ->get()
            ->keyBy('priority');

        $unassigned = $population()->whereNull('assigned_to')->whereIn('status', $active)->count();

        // Trend ignores the date range: always the last 30 Bogota days, today included.
        $zone = config('incidents.timezone');
        $today = $now->setTimezone($zone)->startOfDay();
        $firstDay = $today->subDays(self::TREND_DAYS - 1);
        $offset = $today->format('P'); // Colombia has no DST, so one fixed offset is exact
        $perDay = fn (string $column) => $visible()
            ->selectRaw("DATE(CONVERT_TZ({$column}, '+00:00', ?)) as day, count(*) as total", [$offset])
            ->where($column, '>=', $firstDay->utc())
            ->groupBy('day')
            ->pluck('total', 'day');
        $created = $perDay('created_at');
        $resolved = $perDay('resolved_at');

        $statusRows = array_map(fn (IncidentStatus $s) => ['status' => $s->value, 'count' => (int) ($byStatus[$s->value] ?? 0)], IncidentStatus::cases());
        $priorityRows = array_map(fn (Priority $p) => [
            'priority' => $p->code(),
            'count' => (int) ($byPriority[$p->value]->total ?? 0),
            'overdue' => (int) ($byPriority[$p->value]->overdue ?? 0),
        ], Priority::cases());

        $trend = [];
        for ($day = $firstDay; $day <= $today; $day = $day->addDay()) {
            $key = $day->toDateString();
            $trend[] = ['date' => $key, 'created' => (int) ($created[$key] ?? 0), 'resolved' => (int) ($resolved[$key] ?? 0)];
        }

        return [
            'total' => array_sum(array_column($statusRows, 'count')),
            'active' => array_sum(array_map(fn ($row) => in_array($row['status'], $active, true) ? $row['count'] : 0, $statusRows)),
            'overdue' => array_sum(array_column($priorityRows, 'overdue')),
            'unassigned' => $unassigned,
            'by_status' => $statusRows,
            'by_priority' => $priorityRows,
            'trend' => $trend,
            'generated_at' => $now->toIso8601ZuluString(),
        ];
    }
}
