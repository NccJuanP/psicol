<?php

namespace App\Http\Requests;

use App\Http\Requests\Rules\AssignableUser;
use Illuminate\Foundation\Http\FormRequest;

class AssignIncidentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('assignTo', [$this->route('incident'), $this->filled('assigned_to') ? $this->integer('assigned_to') : null]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'assigned_to' => ['present', 'nullable', 'integer', new AssignableUser],
        ];
    }
}
