<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Throwable;

class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        try {
            DB::select('select 1');
            $db = 'ok';
        } catch (Throwable) {
            $db = 'error';
        }

        return response()->json(
            ['status' => $db === 'ok' ? 'ok' : 'degraded', 'db' => $db],
            $db === 'ok' ? 200 : 503,
        );
    }
}
