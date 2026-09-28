<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AiSetting;
use App\Services\Ai\AiCredentialResolver;
use App\Services\Ai\AiGateway;
use App\Services\Knowledge\KnowledgeSearch;
use App\Services\Knowledge\SearchHit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Throwable;

class AiSettingController extends Controller
{
    public function show(Request $request, AiCredentialResolver $resolver): JsonResponse
    {
        $workspace = $request->attributes->get('workspace');

        return response()->json([
            'data' => $this->settings($request),
            'has_chat_key' => $workspace->aiCredentials()->exists(),
            'has_embeddings' => $resolver->hasEmbeddings($workspace),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'system_prompt' => ['nullable', 'string', 'max:4000'],
            'tone' => ['sometimes', Rule::in(['friendly', 'formal', 'concise'])],
            'temperature' => ['sometimes', 'numeric', 'between:0,1'],
            'similarity_threshold' => ['sometimes', 'numeric', 'between:0,1'],
            'handoff_mode' => ['sometimes', Rule::in(['auto', 'never', 'always'])],
            'fallback_message' => ['nullable', 'array'],
            'fallback_message.*' => ['nullable', 'string', 'max:500'],
        ]);

        $settings = $this->settings($request);
        $settings->fill($data)->save();

        return response()->json(['data' => $settings]);
    }

    /**
     * Playground: ask a question against the knowledge base without creating a conversation.
     */
    public function playground(Request $request, KnowledgeSearch $search, AiGateway $ai): JsonResponse
    {
        $data = $request->validate(['question' => ['required', 'string', 'max:1000']]);
        $workspace = $request->attributes->get('workspace');
        $settings = $this->settings($request);

        try {
            $hits = $search->search($workspace, $data['question']);
            $relevant = array_values(array_filter($hits, fn (SearchHit $h) => $h->similarity >= $settings->similarity_threshold));
            $context = implode("\n\n", array_map(fn (SearchHit $h) => "[{$h->documentTitle} p.{$h->page}]\n{$h->content}", $relevant));

            $answer = $ai->chat(
                $workspace,
                'Answer only from this knowledge. If it does not contain the answer, reply exactly '.config('chat.handoff_token').". Reply in the user's language.\n".
                trim((string) $settings->system_prompt)."\n<knowledge>\n".($context ?: '(empty)')."\n</knowledge>",
                [['role' => 'user', 'content' => $data['question']]],
                temperature: $settings->temperature,
            );
        } catch (Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'answer' => $answer->text,
            'handoff' => str_contains($answer->text, config('chat.handoff_token')),
            'threshold' => $settings->similarity_threshold,
            'hits' => array_map(fn (SearchHit $h) => $h->toSource() + ['excerpt' => mb_substr($h->content, 0, 300)], $hits),
            'usage' => ['input_tokens' => $answer->inputTokens, 'output_tokens' => $answer->outputTokens, 'latency_ms' => $answer->latencyMs],
        ]);
    }

    private function settings(Request $request): AiSetting
    {
        return AiSetting::firstOrCreate(['workspace_id' => $request->attributes->get('workspace')->id]);
    }
}
