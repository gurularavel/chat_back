<?php

return [

    'frontend_url' => env('FRONTEND_URL', 'http://localhost:3000'),

    'locales' => ['az', 'en', 'ru'],

    'trial_days' => (int) env('TRIAL_DAYS', 14),

    // Thinking effort for Claude models that think adaptively (Opus 4.7+, Sonnet 5…):
    // low | medium | high | xhigh | max. Short support answers are fastest at "low".
    'ai_effort' => env('AI_EFFORT', 'low'),

    // All embeddings are stored with this many dimensions (pgvector column size).
    'embedding_dimensions' => 1536,

    'providers' => [
        'openai' => [
            'chat_models' => ['gpt-4.1-mini', 'gpt-4.1', 'gpt-4o-mini', 'gpt-4o'],
            'embedding_model' => 'text-embedding-3-small',
            'supports_embeddings' => true,
        ],
        'anthropic' => [
            'chat_models' => ['claude-haiku-4-5', 'claude-sonnet-5', 'claude-opus-5-5'],
            'embedding_model' => null,
            'supports_embeddings' => false,
        ],
        'gemini' => [
            'chat_models' => ['gemini-2.5-flash', 'gemini-2.5-pro'],
            'embedding_model' => 'gemini-embedding-001',
            'supports_embeddings' => true,
        ],
    ],

    // Used when a workspace only has a provider without embeddings (Claude).
    'platform_embedding' => [
        'provider' => env('PLATFORM_EMBEDDING_PROVIDER', 'openai'),
        'model' => env('PLATFORM_EMBEDDING_MODEL', 'text-embedding-3-small'),
        'api_key' => env('PLATFORM_EMBEDDING_KEY'),
    ],

    'rag' => [
        'chunk_tokens' => 800,
        'chunk_overlap' => 150,
        'top_k' => 6,
        'embed_batch' => 64,
        'history_messages' => 6,
        'max_visitor_message_chars' => 2000,
    ],

    'handoff_token' => '[[HANDOFF]]',
];
