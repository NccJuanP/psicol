<?php

namespace App\Http\Requests;

use App\Domain\Incidents\Enums\IncidentType;
use App\Domain\Incidents\Enums\Priority;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateIncidentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $incident = $this->route('incident');

        return $this->user()->can('update', $incident)
            && (! $this->hasAny(['priority', 'due_at']) || $this->user()->can('reprioritize', $incident));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['prohibited'],
            'assigned_to' => ['prohibited'],
            'type' => ['sometimes', Rule::enum(IncidentType::class)],
            'title' => ['sometimes', 'string', 'min:5', 'max:150'],
            'description' => ['sometimes', 'string', 'min:10', 'max:5000'],
            'priority' => ['sometimes', Rule::in(Priority::codes())],
            'due_at' => ['sometimes', 'date', 'after:now'],
        ];
    }
}
