<?php

namespace App\Http\Controllers\Api\V1;

use App\Application\Reporting\Queries\DashboardStatsQuery;
use App\Http\Controllers\Controller;
use App\Http\Requests\DashboardStatsRequest;
use Illuminate\Http\JsonResponse;

class DashboardController extends Controller
{
    public function __invoke(DashboardStatsRequest $request, DashboardStatsQuery $stats): JsonResponse
    {
        return response()->json(['data' => $stats->handle($request->user(), $request->from(), $request->until(), $request->type())]);
    }
}
