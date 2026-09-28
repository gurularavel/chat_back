<?php

namespace App\Enums;

enum AiProvider: string
{
    case OpenAI = 'openai';
    case Anthropic = 'anthropic';
    case Gemini = 'gemini';

    public function supportsEmbeddings(): bool
    {
        return (bool) config("chat.providers.{$this->value}.supports_embeddings");
    }

    public function defaultEmbeddingModel(): ?string
    {
        return config("chat.providers.{$this->value}.embedding_model");
    }

    /**
     * Whether the model accepts sampling parameters (temperature / top_p).
     * Claude removed them from Opus 4.7 onward (Opus 4.7/4.8/5/5.5, Sonnet 5, Fable…): sending one
     * returns a 400. Only the older Claude models that still take them are allowed, so any future
     * model is safe by default.
     */
    public function supportsTemperature(string $model): bool
    {
        if ($this !== self::Anthropic) {
            return true;
        }

        // claude-3-*, claude-3-5-sonnet-…, claude-{haiku|sonnet|opus}-4-{0..6}[-date], claude-sonnet-4-20250514
        return (bool) preg_match('/^claude-(3[-.]|(haiku|sonnet|opus)-4(-[0-6])?(-\d{8})?$|(haiku|sonnet|opus)-4-[0-6]-\d{8}$)/', $model);
    }

    /** @return list<string> */
    public function chatModels(): array
    {
        return config("chat.providers.{$this->value}.chat_models", []);
    }
}
