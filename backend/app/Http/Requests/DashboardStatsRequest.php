<?php

namespace App\Http\Requests;

use App\Domain\Incidents\Enums\IncidentType;
use App\Http\Requests\Concerns\RejectsUnknownParameters;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DashboardStatsRequest extends FormRequest
{
    use RejectsUnknownParameters;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'date_from' => ['sometimes', 'date_format:Y-m-d'],
            'date_to' => ['sometimes', 'date_format:Y-m-d', ...($this->has('date_from') ? ['after_or_equal:date_from'] : [])],
            'type' => ['sometimes', Rule::enum(IncidentType::class)],
        ];
    }

    protected function allowedParameters(): array
    {
        return ['date_from', 'date_to', 'type'];
    }

    /** Start of date_from in Bogota, as UTC (S-08). */
    public function from(): ?CarbonImmutable
    {
        return $this->has('date_from')
            ? CarbonImmutable::parse($this->validated('date_from'), config('incidents.timezone'))->startOfDay()->utc()
            : null;
    }

    /** Exclusive upper bound: the local midnight after date_to, as UTC. */
    public function until(): ?CarbonImmutable
    {
        return $this->has('date_to')
            ? CarbonImmutable::parse($this->validated('date_to'), config('incidents.timezone'))->addDay()->startOfDay()->utc()
            : null;
    }

    public function type(): ?IncidentType
    {
        return $this->has('type') ? IncidentType::from($this->validated('type')) : null;
    }
}
