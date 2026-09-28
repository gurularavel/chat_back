<?php

namespace App\Services\Ai;

use App\Enums\AiProvider;
use App\Models\AiUsageLog;
use App\Models\Workspace;
use Prism\Prism\Facades\Prism;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Throwable;

/**
 * Single entry point for all LLM calls. Uses the workspace's own key (BYOK)
 * and records every call in ai_usage_logs.
 */
class AiGateway
{
    public function __construct(private AiCredentialResolver $resolver) {}

    /**
     * @param  list<array{role: 'user'|'assistant', content: string}>  $messages
     */
    public function chat(
        Workspace $workspace,
        string $system,
        array $messages,
        float $temperature = 0.2,
        int $maxTokens = 800,
        ?int $conversationId = null,
        ?ResolvedAi $ai = null,
    ): AiChatResult {
        $ai ??= $this->resolver->chatFor($workspace);
        $started = microtime(true);

        try {
            $request = Prism::text()
                ->using($ai->provider->value, $ai->model, ['api_key' => $ai->apiKey])
                ->withSystemPrompt($system)
                ->withMessages(array_map(
                    fn (array $m) => $m['role'] === 'assistant' ? new AssistantMessage($m['content']) : new UserMessage($m['content']),
                    $messages,
                ))
                ->withMaxTokens($maxTokens)
                ->withClientOptions(['timeout' => 60]);

            // Newer Claude models reject temperature with a 400; they run with their own sampling
            // and think adaptively by default — a low effort keeps short chat answers fast.
            if ($ai->provider->supportsTemperature($ai->model)) {
                $request->usingTemperature($temperature);
            } elseif ($ai->provider === AiProvider::Anthropic && config('chat.ai_effort')) {
                $request->withProviderOptions(['effort' => config('chat.ai_effort')]);
            }

            $response = $request->asText();
        } catch (Throwable $e) {
            $this->log($workspace, $ai, 'chat', $started, $conversationId, error: $e->getMessage());
            throw $e;
        }

        $result = new AiChatResult(
            text: trim($response->text),
            inputTokens: $response->usage->promptTokens,
            outputTokens: $response->usage->completionTokens,
            model: $ai->model,
            provider: $ai->provider->value,
            latencyMs: (int) ((microtime(true) - $started) * 1000),
        );

        $this->log($workspace, $ai, 'chat', $started, $conversationId, $result->inputTokens, $result->outputTokens);

        return $result;
    }

    /**
     * @param  list<string>  $texts
     * @return list<list<float>>
     */
    public function embed(Workspace $workspace, array $texts, ?ResolvedAi $ai = null, ?int $conversationId = null): array
    {
        $ai ??= $this->resolver->embeddingFor($workspace);
        $started = microtime(true);

        $request = Prism::embeddings()
            ->using($ai->provider->value, $ai->model, ['api_key' => $ai->apiKey])
            ->fromArray($texts)
            ->withClientOptions(['timeout' => 60]);

        $dims = (int) config('chat.embedding_dimensions');
        if ($ai->provider === AiProvider::OpenAI) {
            $request->withProviderOptions(['dimensions' => $dims]);
        } elseif ($ai->provider === AiProvider::Gemini) {
            $request->withProviderOptions(['outputDimensionality' => $dims]);
        }

        try {
            $response = $request->asEmbeddings();
        } catch (Throwable $e) {
            $this->log($workspace, $ai, 'embedding', $started, $conversationId, error: $e->getMessage());
            throw $e;
        }

        $this->log($workspace, $ai, 'embedding', $started, $conversationId, (int) ($response->usage->tokens ?? 0));

        return array_map(fn ($e) => array_map('floatval', $e->embedding), $response->embeddings);
    }

    private function log(
        Workspace $workspace,
        ResolvedAi $ai,
        string $type,
        float $started,
        ?int $conversationId,
        int $inputTokens = 0,
        int $outputTokens = 0,
        ?string $error = null,
    ): void {
        AiUsageLog::create([
            'workspace_id' => $workspace->id,
            'conversation_id' => $conversationId,
            'provider' => $ai->provider->value,
            'model' => $ai->model,
            'type' => $type,
            'platform_key' => $ai->platformKey,
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'latency_ms' => (int) ((microtime(true) - $started) * 1000),
            'success' => $error === null,
            'error' => $error ? mb_substr($error, 0, 1000) : null,
        ]);
    }
}
