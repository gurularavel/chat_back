<?php

namespace App\Http\Controllers\Api;

use App\Enums\AiProvider;
use App\Http\Controllers\Controller;
use App\Models\AiCredential;
use App\Models\AuditLog;
use App\Services\Ai\AiGateway;
use App\Services\Ai\ResolvedAi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Throwable;

/** BYOK: the workspace's own OpenAI / Anthropic / Gemini keys. */
class AiCredentialController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => AiCredential::orderByDesc('is_default')->orderBy('id')->get(),
            'providers' => collect(AiProvider::cases())->map(fn (AiProvider $p) => [
                'id' => $p->value,
                'chat_models' => $p->chatModels(),
                'embedding_model' => $p->defaultEmbeddingModel(),
                'supports_embeddings' => $p->supportsEmbeddings(),
            ]),
        ]);
    }

    public function store(Request $request, AiGateway $ai): JsonResponse
    {
        $data = $this->validated($request, creating: true);
        $credential = new AiCredential($data);
        $credential->embedding_model ??= $credential->provider->defaultEmbeddingModel();
        $credential->is_default = $data['is_default'] ?? ! AiCredential::exists();
        $credential->save();

        $this->makeDefaultIfNeeded($credential);
        $this->verify($credential, $ai);
        AuditLog::record('ai_credential.created', $credential, ['provider' => $credential->provider->value]);

        return response()->json(['data' => $credential->fresh()], 201);
    }

    public function update(Request $request, AiCredential $aiCredential, AiGateway $ai): JsonResponse
    {
        $data = $this->validated($request, creating: false);
        if (empty($data['api_key'])) {
            unset($data['api_key']);
        }

        $aiCredential->update($data);
        $this->makeDefaultIfNeeded($aiCredential);

        if (isset($data['api_key']) || isset($data['chat_model']) || isset($data['embedding_model'])) {
            $this->verify($aiCredential, $ai);
        }

        return response()->json(['data' => $aiCredential->fresh()]);
    }

    public function destroy(AiCredential $aiCredential): JsonResponse
    {
        $aiCredential->delete();
        AuditLog::record('ai_credential.deleted', $aiCredential);

        if (! AiCredential::where('is_default', true)->exists()) {
            AiCredential::orderBy('id')->first()?->update(['is_default' => true]);
        }

        return response()->json(['ok' => true]);
    }

    public function test(AiCredential $aiCredential, AiGateway $ai): JsonResponse
    {
        $this->verify($aiCredential, $ai);

        return response()->json(['data' => $aiCredential->fresh()]);
    }

    private function verify(AiCredential $credential, AiGateway $ai): void
    {
        $workspace = $credential->workspace;
        $resolved = new ResolvedAi($credential->provider, $credential->chat_model, $credential->api_key);

        try {
            $ai->chat($workspace, 'Reply with the single word: pong', [['role' => 'user', 'content' => 'ping']], maxTokens: 10, ai: $resolved);

            if ($credential->provider->supportsEmbeddings()) {
                $ai->embed($workspace, ['ping'], new ResolvedAi($credential->provider, $credential->embedding_model, $credential->api_key));
            }

            $credential->update(['status' => 'valid', 'last_error' => null, 'last_verified_at' => now()]);
        } catch (Throwable $e) {
            $credential->update(['status' => 'invalid', 'last_error' => mb_substr($e->getMessage(), 0, 500), 'last_verified_at' => now()]);
        }
    }

    private function makeDefaultIfNeeded(AiCredential $credential): void
    {
        if ($credential->is_default) {
            AiCredential::whereKeyNot($credential->id)->update(['is_default' => false]);
        }
    }

    private function validated(Request $request, bool $creating): array
    {
        $provider = AiProvider::tryFrom((string) $request->input('provider', $request->route('aiCredential')?->provider?->value));

        return $request->validate([
            'provider' => [$creating ? 'required' : 'prohibited', Rule::enum(AiProvider::class)],
            'label' => ['nullable', 'string', 'max:80'],
            'api_key' => [$creating ? 'required' : 'nullable', 'string', 'min:10', 'max:500'],
            'chat_model' => [$creating ? 'required' : 'sometimes', 'string', 'max:100'],
            'embedding_model' => ['nullable', 'string', 'max:100', Rule::prohibitedIf($provider && ! $provider->supportsEmbeddings())],
            'is_default' => ['sometimes', 'boolean'],
        ]);
    }
}
