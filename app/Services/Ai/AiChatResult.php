<?php

namespace App\Services\Ai;

final readonly class AiChatResult
{
    public function __construct(
        public string $text,
        public int $inputTokens,
        public int $outputTokens,
        public string $model,
        public string $provider,
        public int $latencyMs,
    ) {}
}
