<?php

namespace Tests\Feature;

use App\Enums\AiProvider;
use App\Services\Ai\AiGateway;
use App\Services\Ai\Anthropic\AnthropicProvider;
use App\Services\Ai\Anthropic\EffortText;
use App\Services\Ai\ResolvedAi;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Prism\Prism\Facades\Prism;
use Prism\Prism\PrismManager;
use Prism\Prism\Testing\TextResponseFake;
use Tests\TestCase;

class AiGatewayTemperatureTest extends TestCase
{
    use RefreshDatabase;

    public static function models(): array
    {
        return [
            'Claude Sonnet 5 rejects temperature' => [AiProvider::Anthropic, 'claude-sonnet-5', null],
            'Claude Opus 5.5 rejects temperature' => [AiProvider::Anthropic, 'claude-opus-5-5', null],
            'Claude Opus 4.7 rejects temperature' => [AiProvider::Anthropic, 'claude-opus-4-7', null],
            'Claude Haiku 4.5 takes temperature' => [AiProvider::Anthropic, 'claude-haiku-4-5', 0.3],
            'Claude Sonnet 4.6 takes temperature' => [AiProvider::Anthropic, 'claude-sonnet-4-6', 0.3],
            'OpenAI takes temperature' => [AiProvider::OpenAI, 'gpt-4.1-mini', 0.3],
        ];
    }

    #[DataProvider('models')]
    public function test_temperature_is_only_sent_to_models_that_accept_it(AiProvider $provider, string $model, ?float $expected): void
    {
        $workspace = $this->createWorkspace();
        $fake = Prism::fake([TextResponseFake::make()->withText('ok')]);

        app(AiGateway::class)->chat($workspace, 'system', [['role' => 'user', 'content' => 'hi']], temperature: 0.3, ai: new ResolvedAi($provider, $model, 'key'));

        $fake->assertRequest(fn (array $requests) => $this->assertSame($expected, $requests[0]->temperature()));
    }

    public function test_newer_claude_models_get_a_low_effort_in_the_api_payload(): void
    {
        $workspace = $this->createWorkspace();
        $fake = Prism::fake([TextResponseFake::make()->withText('ok'), TextResponseFake::make()->withText('ok')]);
        $gateway = app(AiGateway::class);

        $gateway->chat($workspace, 'system', [['role' => 'user', 'content' => 'salam']], ai: new ResolvedAi(AiProvider::Anthropic, 'claude-sonnet-5', 'key'));
        $gateway->chat($workspace, 'system', [['role' => 'user', 'content' => 'salam']], ai: new ResolvedAi(AiProvider::Anthropic, 'claude-haiku-4-5', 'key'));

        $fake->assertRequest(function (array $requests) {
            $sonnet = EffortText::buildHttpRequestPayload($requests[0]);
            $haiku = EffortText::buildHttpRequestPayload($requests[1]);

            $this->assertSame(['effort' => 'low'], $sonnet['output_config']);
            $this->assertArrayNotHasKey('temperature', $sonnet);
            $this->assertArrayNotHasKey('output_config', $haiku); // Haiku 4.5 rejects effort
            $this->assertSame(0.2, $haiku['temperature']);
        });
    }

    public function test_the_anthropic_provider_is_the_effort_aware_one(): void
    {
        $this->assertInstanceOf(AnthropicProvider::class, app(PrismManager::class)->resolve('anthropic', ['api_key' => 'k']));
    }
}
