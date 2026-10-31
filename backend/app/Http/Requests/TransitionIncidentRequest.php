<?php

namespace App\Http\Requests;

use App\Domain\Incidents\Enums\IncidentStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TransitionIncidentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $to = IncidentStatus::tryFrom((string) $this->input('status'));

        // An unknown status is a validation error (422), not a permission one.
        return $to === null || $this->user()->can('transition', [$this->route('incident'), $to]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $reopening = $this->route('incident')->status === IncidentStatus::Resolved
            && $this->input('status') === IncidentStatus::InProgress->value;

        return [
            'status' => ['required', Rule::enum(IncidentStatus::class)],
            'note' => [Rule::requiredIf($reopening), 'nullable', 'string', 'min:10', 'max:500'], // R-12
        ];
    }
}
