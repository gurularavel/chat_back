<?php

namespace App\Services\Ai;

use RuntimeException;

class AiNotConfiguredException extends RuntimeException
{
    public static function chat(): self
    {
        return new self('No AI API key is configured for this workspace.');
    }

    public static function embeddings(): self
    {
        return new self('No embedding-capable AI key (OpenAI or Gemini) is configured for this workspace.');
    }
}
