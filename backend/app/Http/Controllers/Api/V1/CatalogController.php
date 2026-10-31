<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Incidents\Enums\IncidentStatus;
use App\Domain\Incidents\Enums\IncidentType;
use App\Domain\Incidents\Enums\Priority;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/** Catalogs of docs/api-contract.md, built from the enums so labels live in one place. */
class CatalogController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json(['data' => [
            'priorities' => array_map(fn (Priority $p) => ['value' => $p->code(), 'label' => $p->label()], Priority::cases()),
            'statuses' => array_map(fn (IncidentStatus $s) => ['value' => $s->value, 'label' => $s->label(), 'is_terminal' => $s->isTerminal()], IncidentStatus::cases()),
            'types' => array_map(fn (IncidentType $t) => ['value' => $t->value, 'label' => $t->label()], IncidentType::cases()),
        ]]);
    }
}
