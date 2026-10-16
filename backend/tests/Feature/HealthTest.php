<?php

namespace Tests\Feature;

use Tests\TestCase;

class HealthTest extends TestCase
{
    public function test_health_returns_ok_envelope_with_request_id(): void
    {
        $response = $this->getJson('/api/v1/health');

        $response->assertOk()->assertJsonPath('data.status', 'ok');
        $this->assertNotEmpty($response->headers->get('X-Request-Id'));
        $response->assertJsonPath('meta.request_id', $response->headers->get('X-Request-Id'));
    }

    public function test_unknown_route_returns_not_found_envelope(): void
    {
        $this->getJson('/api/v1/x')
            ->assertNotFound()
            ->assertJsonPath('error.code', 'not_found')
            ->assertJsonStructure(['error' => ['code', 'message'], 'meta' => ['request_id']]);
    }
}
