<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    public function test_health_endpoint_returns_ok(): void
    {
        $response = $this->getJson('/api/school/enrolments/health');

        $response->assertStatus(200)
            ->assertJsonFragment(['status' => 'ok', 'service' => 'enrolment']);
    }
}
