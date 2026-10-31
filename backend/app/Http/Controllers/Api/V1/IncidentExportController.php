<?php

namespace App\Http\Controllers\Api\V1;

use App\Application\Reporting\Exports\IncidentsExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\ExportIncidentsRequest;
use Symfony\Component\HttpFoundation\StreamedResponse;

class IncidentExportController extends Controller
{
    public function __invoke(ExportIncidentsRequest $request, IncidentsExport $export): StreamedResponse
    {
        return $export->download($request->user(), $request->filters(), $request->exportFormat());
    }
}
