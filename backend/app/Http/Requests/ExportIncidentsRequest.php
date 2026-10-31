<?php

namespace App\Http\Requests;

use Illuminate\Support\Arr;

/** Same filters and visibility as the listing; no pagination or sort, rows come out newest id first (D-41). */
class ExportIncidentsRequest extends ListIncidentsRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'format' => ['required', 'in:csv,xlsx'],
            ...Arr::except(parent::rules(), ['page', 'per_page', 'sort']),
        ];
    }

    protected function allowedParameters(): array
    {
        return ['format', 'search', 'filter'];
    }

    public function exportFormat(): string
    {
        return $this->validated('format');
    }
}
