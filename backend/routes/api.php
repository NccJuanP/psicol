<?php

use App\Http\Controllers\Api\V1\AssignableUserController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\IncidentActivityController;
use App\Http\Controllers\Api\V1\IncidentAssignmentController;
use App\Http\Controllers\Api\V1\IncidentController;
use App\Http\Controllers\Api\V1\IncidentTransitionController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::get('health', HealthController::class);

    Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:login');

    Route::middleware(['auth:sanctum', 'throttle:api'])->group(function () {
        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::get('auth/me', [AuthController::class, 'me']);

        Route::get('users/assignable', AssignableUserController::class)->middleware('can:incidents.assign');

        Route::get('incidents', [IncidentController::class, 'index']);
        Route::post('incidents', [IncidentController::class, 'store']);
        Route::get('incidents/{incident}', [IncidentController::class, 'show']);
        Route::patch('incidents/{incident}', [IncidentController::class, 'update']);
        Route::delete('incidents/{incident}', [IncidentController::class, 'destroy']);
        Route::post('incidents/{incident}/transition', IncidentTransitionController::class);
        Route::post('incidents/{incident}/assign', IncidentAssignmentController::class);
        Route::get('incidents/{incident}/activities', IncidentActivityController::class);
    });
});
