<?php

namespace Tests\Feature;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\TestCase;

class ErrorEnvelopeTest extends TestCase
{
    public function test_authentication_exception_is_401_unauthenticated(): void
    {
        Route::get('/api/v1/_t', fn () => throw new AuthenticationException);

        $this->getJson('/api/v1/_t')
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'unauthenticated')
            ->assertJsonStructure(['meta' => ['request_id']]);
    }

    public function test_unexpected_exception_is_500_without_trace_when_not_debug(): void
    {
        config(['app.debug' => false]);
        Route::get('/api/v1/_t', fn () => throw new RuntimeException('secret detail'));

        $this->getJson('/api/v1/_t')
            ->assertStatus(500)
            ->assertJsonPath('error.code', 'server_error')
            ->assertJsonMissingPath('error.trace')
            ->assertDontSee('secret detail');
    }

    public function test_validation_exception_is_422_with_field_details(): void
    {
        Route::get('/api/v1/_t', fn () => throw ValidationException::withMessages(['name' => ['Required.']]));

        $this->getJson('/api/v1/_t')
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.details.0.field', 'name')
            ->assertJsonPath('error.details.0.message', 'Required.');
    }

    public function test_http_response_exception_keeps_its_own_response(): void
    {
        Route::get('/api/v1/_t', fn () => throw new HttpResponseException(response()->json(['x' => 1], 418)));

        $this->getJson('/api/v1/_t')->assertStatus(418)->assertJsonPath('x', 1);
    }

    public function test_http_exceptions_keep_their_status(): void
    {
        Route::get('/api/v1/_t', fn () => abort(403));

        $this->getJson('/api/v1/_t')->assertForbidden();
    }
}
