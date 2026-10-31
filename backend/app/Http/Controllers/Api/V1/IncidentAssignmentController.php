<?php

namespace App\Http\Controllers\Api\V1;

use App\Application\Incidents\Actions\AssignIncident;
use App\Domain\Incidents\Models\Incident;
use App\Http\Controllers\Controller;
use App\Http\Requests\AssignIncidentRequest;
use App\Http\Resources\IncidentDetailResource;

class IncidentAssignmentController extends Controller
{
    public function __invoke(AssignIncidentRequest $request, Incident $incident, AssignIncident $assign): IncidentDetailResource
    {
        $assigneeId = $request->validated('assigned_to');

        return IncidentDetailResource::make($assign->handle($request->user(), $incident, $assigneeId === null ? null : (int) $assigneeId));
    }
}
