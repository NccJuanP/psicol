<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Incidents\Models\Incident;
use App\Http\Controllers\Controller;
use App\Http\Resources\ActivityResource;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class IncidentActivityController extends Controller
{
    public function __invoke(Incident $incident): AnonymousResourceCollection
    {
        return ActivityResource::collection(
            $incident->activities()->with('user:id,name')->orderByDesc('id')->paginate(20),
        );
    }
}
