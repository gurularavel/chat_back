<?php

namespace App\Services\Ai;

use App\Enums\AiProvider;
use App\Models\AiCredential;
use App\Models\PlatformSetting;
use App\Models\Workspace;
use Illuminate\Support\Collection;

/**
 * Picks which key/model a workspace uses for chat and for embeddings (BYOK).
 */
class AiCredentialResolver
{
    public function chatFor(Workspace $workspace): ResolvedAi
    {
        $credential = $this->credentials($workspace)->first();

        if (! $credential) {
            throw AiNotConfiguredException::chat();
        }

        return new ResolvedAi($credential->provider, $credential->chat_model, $credential->api_key);
    }

    public function embeddingFor(Workspace $workspace): ResolvedAi
    {
        $credential = $this->credentials($workspace)
            ->first(fn (AiCredential $c) => $c->provider->supportsEmbeddings());

        if ($credential) {
            return new ResolvedAi(
                $credential->provider,
                $credential->embedding_model ?: $credential->provider->defaultEmbeddingModel(),
                $credential->api_key,
            );
        }

        // Workspace only has a provider without an embeddings API (Claude) → platform key.
        $platformKey = PlatformSetting::get('platform_embedding_key', config('chat.platform_embedding.api_key'));
        if ($platformKey) {
            return new ResolvedAi(
                AiProvider::from(PlatformSetting::get('platform_embedding_provider', config('chat.platform_embedding.provider'))),
                PlatformSetting::get('platform_embedding_model', config('chat.platform_embedding.model')),
                $platformKey,
                platformKey: true,
            );
        }

        throw AiNotConfiguredException::embeddings();
    }

    /**
     * How the knowledge base is searched:
     * "semantic" (embeddings), "keyword" (full-text only, e.g. just a Claude key) or null (no AI key at all).
     */
    public function retrievalMode(Workspace $workspace): ?string
    {
        if ($this->hasEmbeddings($workspace)) {
            return self::SEMANTIC;
        }

        return $this->credentials($workspace)->isNotEmpty() ? self::KEYWORD : null;
    }

    public const SEMANTIC = 'semantic';

    public const KEYWORD = 'keyword';

    public function hasEmbeddings(Workspace $workspace): bool
    {
        try {
            $this->embeddingFor($workspace);

            return true;
        } catch (AiNotConfiguredException) {
            return false;
        }
    }

    /** Usable credentials, default first. @return Collection<int, AiCredential> */
    private function credentials(Workspace $workspace): Collection
    {
        return AiCredential::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->where('status', '!=', 'invalid')
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->get();
    }
}
