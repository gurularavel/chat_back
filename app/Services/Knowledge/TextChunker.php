<?php

namespace App\Services\Knowledge;

/**
 * Splits page texts into overlapping chunks on paragraph / sentence boundaries.
 * Token count is approximated as chars / 4 (good enough for sizing).
 */
class TextChunker
{
    public function __construct(
        private int $chunkTokens = 800,
        private int $overlapTokens = 150,
    ) {}

    public static function fromConfig(): self
    {
        return new self(config('chat.rag.chunk_tokens'), config('chat.rag.chunk_overlap'));
    }

    public static function estimateTokens(string $text): int
    {
        return (int) ceil(mb_strlen($text) / 4);
    }

    /**
     * @param  list<array{page: int, text: string}>  $pages
     * @return list<array{page: int, content: string, tokens: int}>
     */
    public function chunk(array $pages): array
    {
        $maxChars = $this->chunkTokens * 4;
        $overlapChars = $this->overlapTokens * 4;
        $chunks = [];

        foreach ($pages as $page) {
            $buffer = '';
            foreach ($this->segments($page['text'], $maxChars) as $segment) {
                if ($buffer !== '' && mb_strlen($buffer) + mb_strlen($segment) + 1 > $maxChars) {
                    $chunks[] = $this->make($page['page'], $buffer);
                    $buffer = $this->tail($buffer, $overlapChars);
                }
                $buffer = $buffer === '' ? $segment : $buffer.' '.$segment;
            }
            if (trim($buffer) !== '') {
                $chunks[] = $this->make($page['page'], $buffer);
            }
        }

        return $chunks;
    }

    /** Sentences, with over-long sentences hard-split. @return list<string> */
    private function segments(string $text, int $maxChars): array
    {
        $parts = preg_split('/(?<=[.!?…])\s+|\n{2,}/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $segments = [];

        foreach ($parts as $part) {
            $part = trim(preg_replace('/\s+/u', ' ', $part));
            while (mb_strlen($part) > $maxChars) {
                $segments[] = mb_substr($part, 0, $maxChars);
                $part = mb_substr($part, $maxChars);
            }
            if ($part !== '') {
                $segments[] = $part;
            }
        }

        return $segments;
    }

    private function tail(string $text, int $chars): string
    {
        if (mb_strlen($text) <= $chars) {
            return $text;
        }
        $tail = mb_substr($text, -$chars);
        $space = mb_strpos($tail, ' ');

        return $space === false ? $tail : mb_substr($tail, $space + 1);
    }

    private function make(int $page, string $content): array
    {
        $content = trim($content);

        return ['page' => $page, 'content' => $content, 'tokens' => self::estimateTokens($content)];
    }
}
