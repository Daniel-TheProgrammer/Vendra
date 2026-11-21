<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Http\Problem;

use App\Shared\Domain\Exception\DomainException;
use App\Shared\Infrastructure\Http\Middleware\AssignRequestId;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Translates any throwable into a Problem Details response.
 */
final readonly class ProblemRenderer
{
    public function __construct(
        private Config $config,
    ) {}

    public function render(Throwable $exception, Request $request): JsonResponse
    {
        return $this->problemFor($exception, $request)->toResponse($request);
    }

    public function problemFor(Throwable $exception, Request $request): ProblemDetails
    {
        $instance = '/'.ltrim($request->path(), '/');
        $requestId = $request->attributes->get(AssignRequestId::ATTRIBUTE);
        $extensions = is_string($requestId) ? ['request_id' => $requestId] : [];

        if ($exception instanceof ValidationException) {
            return new ProblemDetails(
                status: 422,
                type: 'validation-failed',
                title: 'Validation failed',
                detail: 'One or more fields are invalid.',
                instance: $instance,
                extensions: [...$extensions, 'errors' => $exception->errors()],
            );
        }

        if ($exception instanceof AuthenticationException) {
            return new ProblemDetails(401, 'unauthenticated', 'Unauthenticated', 'Authentication is required.', $instance, $extensions);
        }

        if ($exception instanceof AuthorizationException) {
            return new ProblemDetails(403, 'forbidden', 'Forbidden', 'You are not allowed to perform this action.', $instance, $extensions);
        }

        if ($exception instanceof ModelNotFoundException) {
            return new ProblemDetails(404, 'not-found', 'Not found', 'The requested resource does not exist.', $instance, $extensions);
        }

        if ($exception instanceof DomainException) {
            return new ProblemDetails(
                status: $exception->status(),
                type: $exception->type(),
                title: $exception->title(),
                detail: $exception->getMessage(),
                instance: $instance,
                extensions: [...$extensions, ...$exception->extensions()],
            );
        }

        if ($exception instanceof HttpExceptionInterface) {
            $status = $exception->getStatusCode();
            $message = $exception->getMessage();

            return new ProblemDetails(
                status: $status,
                type: $this->typeForStatus($status),
                title: Response::$statusTexts[$status] ?? 'Error',
                detail: $message !== '' ? $message : null,
                instance: $instance,
                extensions: $extensions,
                headers: $this->stringHeaders($exception->getHeaders()),
            );
        }

        $debug = (bool) $this->config->get('app.debug', false);

        return new ProblemDetails(
            status: 500,
            type: 'internal-error',
            title: 'Internal server error',
            detail: $debug ? $exception->getMessage() : 'Something went wrong on our side. Quote the request_id when reporting it.',
            instance: $instance,
            extensions: $debug
                ? [...$extensions, 'exception' => $exception::class, 'file' => $exception->getFile(), 'line' => $exception->getLine()]
                : $extensions,
        );
    }

    /**
     * The framework converts some exceptions to HttpExceptions before rendering
     * (AuthorizationException becomes a 403, ModelNotFound a 404), so the status
     * code is the reliable signal here.
     */
    private function typeForStatus(int $status): string
    {
        return match ($status) {
            401 => 'unauthenticated',
            403 => 'forbidden',
            404 => 'not-found',
            405 => 'method-not-allowed',
            409 => 'conflict',
            415 => 'unsupported-media-type',
            429 => 'rate-limited',
            default => 'http-error',
        };
    }

    /**
     * @param  array<string, mixed>  $headers
     * @return array<string, string>
     */
    private function stringHeaders(array $headers): array
    {
        $result = [];

        foreach ($headers as $name => $value) {
            if (is_scalar($value)) {
                $result[$name] = (string) $value;
            }
        }

        return $result;
    }
}
