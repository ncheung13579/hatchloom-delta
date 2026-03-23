<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    public function test_health_endpoint_returns_ok(): void
    {
        Http::fake(['*' => Http::response(['status' => 'ok'])]);

        $response = $this->getJson('/api/school/dashboard/health');

        $response->assertStatus(200)
            ->assertJsonFragment(['service' => 'dashboard', 'database' => 'connected']);
    }
}
