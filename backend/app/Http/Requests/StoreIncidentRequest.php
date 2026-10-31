<?php

namespace App\Http\Requests;

use App\Domain\Incidents\Enums\IncidentType;
use App\Domain\Incidents\Enums\Priority;
use App\Domain\Incidents\Models\Incident;
use App\Http\Requests\Rules\AssignableUser;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreIncidentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', [Incident::class, $this->filled('assigned_to') ? $this->integer('assigned_to') : null]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['prohibited'], // R-01
            'type' => ['sometimes', Rule::enum(IncidentType::class)],
            'title' => ['required', 'string', 'min:5', 'max:150'],
            'description' => ['required', 'string', 'min:10', 'max:5000'],
            'priority' => ['required', Rule::in(Priority::codes())],
            'due_at' => ['sometimes', 'nullable', 'date', 'after:now'], // R-06
            'assigned_to' => ['sometimes', 'nullable', 'integer', new AssignableUser],
        ];
    }
}
