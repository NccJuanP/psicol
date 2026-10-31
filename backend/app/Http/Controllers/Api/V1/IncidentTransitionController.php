<?php

namespace App\Http\Controllers\Api\V1;

use App\Application\Incidents\Actions\TransitionIncidentStatus;
use App\Domain\Incidents\Enums\IncidentStatus;
use App\Domain\Incidents\Models\Incident;
use App\Http\Controllers\Controller;
use App\Http\Requests\TransitionIncidentRequest;
use App\Http\Resources\IncidentDetailResource;

class IncidentTransitionController extends Controller
{
    public function __invoke(TransitionIncidentRequest $request, Incident $incident, TransitionIncidentStatus $transition): IncidentDetailResource
    {
        return IncidentDetailResource::make($transition->handle(
            $request->user(),
            $incident,
            IncidentStatus::from($request->validated('status')),
            $request->validated('note'),
        ));
    }
}
