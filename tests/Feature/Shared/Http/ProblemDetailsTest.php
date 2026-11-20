<?php

declare(strict_types=1);

namespace Tests\Feature\Shared\Http;

use App\Shared\Domain\Exception\ConflictException;
use App\Shared\Infrastructure\Http\Middleware\AssignRequestId;
use App\Shared\Infrastructure\Http\Problem\ProblemDetails;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

final class ProblemDetailsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware('api')->prefix('api/v1/_test')->group(function (): void {
            Route::post('validate', function (Request $request): JsonResponse {
                $request->validate(['name' => ['required', 'string']]);

                return new JsonResponse(['ok' => true]);
            });
            Route::get('unauthenticated', fn (): never => throw new AuthenticationException);
            Route::get('forbidden', fn (): never => throw new AuthorizationException);
            Route::get('conflict', fn (): never => throw new ConflictException('The order is already paid.'));
            Route::get('boom', fn (): never => throw new RuntimeException('database password is hunter2'));
        });
    }

    #[Test]
    public function unknown_routes_render_a_not_found_problem(): void
    {
        $this->getJson('/api/v1/does-not-exist')
            ->assertNotFound()
            ->assertHeader('Content-Type', ProblemDetails::CONTENT_TYPE)
            ->assertJson([
                'type' => 'urn:problem-type:not-found',
                'title' => 'Not Found',
                'status' => 404,
                'instance' => '/api/v1/does-not-exist',
            ])
            ->assertJsonStructure(['request_id']);
    }

    #[Test]
    public function validation_failures_list_every_field_error(): void
    {
        $this->postJson('/api/v1/_test/validate', [])
            ->assertUnprocessable()
            ->assertHeader('Content-Type', ProblemDetails::CONTENT_TYPE)
            ->assertJson([
                'type' => 'urn:problem-type:validation-failed',
                'status' => 422,
            ])
            ->assertJsonPath('errors.name.0', 'The name field is required.');
    }

    #[Test]
    public function authentication_and_authorization_failures_are_distinct(): void
    {
        $this->getJson('/api/v1/_test/unauthenticated')
            ->assertUnauthorized()
            ->assertJsonPath('type', 'urn:problem-type:unauthenticated');

        $this->getJson('/api/v1/_test/forbidden')
            ->assertForbidden()
            ->assertJsonPath('type', 'urn:problem-type:forbidden');
    }

    #[Test]
    public function domain_exceptions_describe_themselves(): void
    {
        $this->getJson('/api/v1/_test/conflict')
            ->assertConflict()
            ->assertJson([
                'type' => 'urn:problem-type:conflict',
                'title' => 'Conflict',
                'status' => 409,
                'detail' => 'The order is already paid.',
            ]);
    }

    #[Test]
    public function unexpected_errors_hide_internals_unless_debugging(): void
    {
        config(['app.debug' => false]);

        $response = $this->getJson('/api/v1/_test/boom')->assertStatus(500);

        $response->assertJsonPath('type', 'urn:problem-type:internal-error');
        $response->assertJsonMissingPath('exception');
        self::assertStringNotContainsString('hunter2', $response->getContent() ?: '');

        config(['app.debug' => true]);

        $this->getJson('/api/v1/_test/boom')
            ->assertStatus(500)
            ->assertJsonPath('detail', 'database password is hunter2')
            ->assertJsonPath('exception', RuntimeException::class);
    }

    #[Test]
    public function a_well_formed_caller_request_id_is_honoured_and_echoed(): void
    {
        $response = $this->getJson('/api/v1/does-not-exist', [AssignRequestId::HEADER => 'client-req-0001']);

        $response->assertHeader(AssignRequestId::HEADER, 'client-req-0001');
        $response->assertJsonPath('request_id', 'client-req-0001');
    }

    #[Test]
    public function a_malformed_caller_request_id_is_replaced(): void
    {
        $response = $this->getJson('/api/v1/does-not-exist', [AssignRequestId::HEADER => 'bad id with spaces']);

        $echoed = $response->headers->get(AssignRequestId::HEADER);

        self::assertIsString($echoed);
        self::assertNotSame('bad id with spaces', $echoed);
        $response->assertJsonPath('request_id', $echoed);
    }
}
