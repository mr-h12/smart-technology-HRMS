<?php

namespace Tests\Feature;

use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class HealthTest extends TestCase
{
    public function test_health_returns_ok_envelope_with_request_id(): void
    {
        $response = $this->getJson('/api/v1/health');

        $response->assertOk()->assertJsonPath('data.status', 'ok');
        $this->assertRequestIdMatchesHeader($response);
    }

    public function test_unknown_route_returns_not_found_envelope(): void
    {
        $response = $this->getJson('/api/v1/x')
            ->assertNotFound()
            ->assertJsonPath('error.code', 'not_found')
            ->assertJsonStructure(['error' => ['code', 'message'], 'meta' => ['request_id']]);
        $this->assertRequestIdMatchesHeader($response);
    }

    private function assertRequestIdMatchesHeader(TestResponse $response): void
    {
        $id = $response->json('meta.request_id');
        $this->assertIsString($id);
        $this->assertNotSame('', $id);
        $this->assertSame($id, $response->headers->get('X-Request-Id'));
    }
}
