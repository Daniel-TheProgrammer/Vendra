<?php

declare(strict_types=1);

use App\Http\Middleware\ResolveTenant;
use App\Shared\Domain\Exception\DomainException;
use App\Shared\Infrastructure\Http\Middleware\AssignRequestId;
use App\Shared\Infrastructure\Http\Middleware\ForceJsonResponse;
use App\Shared\Infrastructure\Http\Problem\ProblemRenderer;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        apiPrefix: 'api/v1',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Global, not in the "api" group: a request that matches no route must
        // still get a correlation id and a JSON problem response.
        $middleware->prepend([
            AssignRequestId::class,
            ForceJsonResponse::class,
        ]);

        $middleware->alias([
            'tenant' => ResolveTenant::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $isApi = static fn (Request $request): bool => $request->is('api/*') || $request->expectsJson();

        // Business-rule failures are expected client errors, not incidents.
        $exceptions->dontReport([DomainException::class]);

        $exceptions->shouldRenderJsonWhen(fn (Request $request, Throwable $e): bool => $isApi($request));

        $exceptions->render(function (Throwable $e, Request $request) use ($isApi) {
            if (! $isApi($request)) {
                return null;
            }

            return app(ProblemRenderer::class)->render($e, $request);
        });
    })->create();
