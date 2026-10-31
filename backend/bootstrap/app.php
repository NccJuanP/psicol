<?php

use App\Domain\Incidents\Exceptions\DomainRuleViolation;
use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\ForceJsonResponse;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Global, not only the api group, so unmatched routes (404) also carry a request id.
        $middleware->prepend(AssignRequestId::class);
        $middleware->prependToGroup('api', ForceJsonResponse::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Single error envelope: docs/api-contract.md, "Formato único de error".
        $exceptions->render(function (Throwable $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            [$status, $code, $message] = match (true) {
                $e instanceof ValidationException => [422, 'VALIDATION_ERROR', 'Los datos enviados no son válidos.'],
                $e instanceof AuthenticationException => [401, 'UNAUTHENTICATED', 'Tu sesión no es válida o expiró. Inicia sesión de nuevo.'],
                $e instanceof DomainRuleViolation => [409, $e->errorCode, $e->getMessage()],
                $e instanceof HttpExceptionInterface => match ($e->getStatusCode()) {
                    403 => [403, 'FORBIDDEN', 'No tienes permiso para esta acción.'],
                    404 => [404, 'NOT_FOUND', 'El recurso solicitado no existe.'],
                    429 => [429, 'TOO_MANY_REQUESTS', 'Demasiadas solicitudes. Intenta de nuevo en unos segundos.'],
                    default => [$e->getStatusCode(), 'HTTP_ERROR', 'La solicitud no se pudo procesar.'],
                },
                default => [500, 'SERVER_ERROR', 'Ocurrió un error inesperado.'],
            };

            $body = ['message' => $message, 'code' => $code];

            if ($e instanceof ValidationException) {
                $body['errors'] = $e->errors();
            }

            $body['request_id'] = Context::get('request_id');

            if ($status === 500 && config('app.debug')) {
                $body['debug'] = ['exception' => $e::class, 'message' => $e->getMessage(), 'at' => $e->getFile().':'.$e->getLine()];
            }

            return response()->json($body, $status, $e instanceof HttpExceptionInterface ? $e->getHeaders() : []);
        });
    })->create();
