<?php

namespace App\Services\Ai;

use App\Enums\AiProvider;

final readonly class ResolvedAi
{
    public function __construct(
        public AiProvider $provider,
        public string $model,
        public string $apiKey,
        public bool $platformKey = false,
    ) {}

    /** Identifier stored on documents so query and chunk vectors always come from the same model. */
    public function signature(): string
    {
        return $this->provider->value.':'.$this->model;
    }
}
