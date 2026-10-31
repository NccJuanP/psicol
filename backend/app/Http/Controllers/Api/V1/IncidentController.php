<?php

namespace App\Http\Controllers\Api\V1;

use App\Application\Incidents\Actions\DeleteIncident;
use App\Application\Incidents\Actions\RegisterIncident;
use App\Application\Incidents\Actions\UpdateIncidentDetails;
use App\Application\Incidents\Queries\ListIncidentsQuery;
use App\Domain\Incidents\Models\Incident;
use App\Http\Controllers\Controller;
use App\Http\Requests\ListIncidentsRequest;
use App\Http\Requests\StoreIncidentRequest;
use App\Http\Requests\UpdateIncidentRequest;
use App\Http\Resources\IncidentDetailResource;
use App\Http\Resources\IncidentListResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class IncidentController extends Controller
{
    public function index(ListIncidentsRequest $request, ListIncidentsQuery $query): AnonymousResourceCollection
    {
        return IncidentListResource::collection($query->paginate($request->user(), $request->filters(), $request->perPage()));
    }

    public function store(StoreIncidentRequest $request, RegisterIncident $register): JsonResponse
    {
        $incident = $register->handle($request->user(), $request->validated());

        return IncidentDetailResource::make($incident)
            ->response()
            ->setStatusCode(201)
            ->header('Location', url("/api/v1/incidents/{$incident->id}"));
    }

    public function show(Incident $incident): IncidentDetailResource
    {
        return IncidentDetailResource::make($incident);
    }

    public function update(UpdateIncidentRequest $request, Incident $incident, UpdateIncidentDetails $update): IncidentDetailResource
    {
        return IncidentDetailResource::make($update->handle($request->user(), $incident, $request->validated()));
    }

    public function destroy(Request $request, Incident $incident, DeleteIncident $delete): Response
    {
        Gate::authorize('delete', $incident);
        $delete->handle($request->user(), $incident);

        return response()->noContent();
    }
}
