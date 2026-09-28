<?php

namespace Tests\Feature;

use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Logger;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\TestCase;

class SlowRequestLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_response_carries_server_timing(): void
    {
        $this->seed(PlanSeeder::class);

        $timing = $this->getJson('/api/plans')->assertOk()->headers->get('Server-Timing');

        $this->assertMatchesRegularExpression('/boot;dur=[\d.]+, app;dur=[\d.]+, db;dur=[\d.]+;desc="\d+ queries", total;dur=[\d.]+/', $timing);
    }

    public function test_requests_over_the_threshold_are_logged_with_their_breakdown(): void
    {
        config(['chat.slow_request_ms' => 0]); // everything counts as slow
        $channel = Mockery::mock(Logger::class);
        $channel->shouldReceive('info')->once()->withArgs(function (string $message, array $context) {
            return $message === 'slow request'
                && $context['path'] === '/api/plans'
                && $context['status'] === 200
                && isset($context['total_ms'], $context['boot_ms'], $context['sql_ms'], $context['queries']);
        });
        Log::shouldReceive('channel')->with('performance')->andReturn($channel);

        $this->getJson('/api/plans')->assertOk();
    }

    public function test_fast_requests_are_not_logged(): void
    {
        config(['chat.slow_request_ms' => 60_000]);
        Log::shouldReceive('channel')->with('performance')->never();

        $this->getJson('/api/plans')->assertOk();
    }
}
