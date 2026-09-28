<?php

namespace App\Services\Knowledge;

use Illuminate\Support\Facades\DB;

/**
 * Hybrid search: pgvector cosine similarity + Postgres full-text, merged with
 * reciprocal rank fusion. `similarity` on each hit is always the cosine value,
 * which the responder uses for its handoff threshold.
 */
class PgVectorStore implements VectorStore
{
    private const RRF_K = 60;

    /** Question/filler words (az, ru, en), already lowercased with ı/İ folded to "i". */
    private const STOP_WORDS = [
        // az
        'hansi', 'necə', 'neçə', 'nədir', 'kimdir', 'kim', 'harada', 'hara', 'niyə', 'nəyə', 'haqqinda', 'haqda', 'məlumat',
        'ver', 'verin', 'verə', 'var', 'yox', 'varmi', 'olan', 'olur', 'olar', 'üçün', 'ilə', 'bir', 'mənə', 'sizin', 'bizə',
        'edir', 'etmək', 'istəyirəm', 'zəhmət', 'olmasa', 'salam', 'qədər', 'necədir', 'deyin', 'söylə', 'danış',
        'təqdim', 'göstər', 'göndər', 'lazım', 'lazımdır', 'lazimdi', 'bilir', 'bilər', 'nədi', 'hansıdır',
        // ru
        'какой', 'какая', 'какие', 'каким', 'что', 'кто', 'где', 'как', 'сколько', 'есть', 'это', 'для', 'про', 'расскажите',
        'расскажи', 'скажите', 'пожалуйста', 'можно', 'мне', 'нам', 'здравствуйте', 'привет',
        // en
        'what', 'which', 'who', 'where', 'when', 'how', 'why', 'tell', 'about', 'the', 'and', 'for', 'are', 'does', 'can',
        'please', 'her', 'his', 'their', 'you', 'your', 'have', 'has', 'there', 'info', 'information', 'hello',
    ];

    /** Letters folded to Latin in both the search column and the queries. */
    public const FOLD = ['ı' => 'i', 'ə' => 'e', 'ö' => 'o', 'ü' => 'u', 'ç' => 'c', 'ş' => 's', 'ğ' => 'g'];

    public function store(array $vectors): void
    {
        foreach ($vectors as $chunkId => $vector) {
            DB::update('UPDATE knowledge_chunks SET embedding = ?::vector WHERE id = ?', [self::literal($vector), $chunkId]);
        }
    }

    public function search(int $workspaceId, string $embeddingProvider, string $embeddingModel, array $vector, string $query, int $limit): array
    {
        $literal = self::literal($vector);
        $pool = $limit * 3;

        $base = <<<'SQL'
            SELECT c.id, c.document_id, c.page, c.content, d.title,
                   1 - (c.embedding <=> ?::vector) AS similarity
            FROM knowledge_chunks c
            JOIN knowledge_documents d ON d.id = c.document_id
            WHERE c.workspace_id = ?
              AND d.status = 'ready'
              AND d.embedding_provider = ?
              AND d.embedding_model = ?
              AND c.embedding IS NOT NULL
        SQL;
        $bindings = [$literal, $workspaceId, $embeddingProvider, $embeddingModel];

        $semantic = DB::select($base.' ORDER BY c.embedding <=> ?::vector LIMIT ?', [...$bindings, $literal, $pool]);

        $keyword = [];
        $tsQuery = $this->tsQuery($query);
        if ($tsQuery !== '') {
            $keyword = DB::select(
                $base." AND c.search @@ to_tsquery('simple', ?) ORDER BY ts_rank(c.search, to_tsquery('simple', ?)) DESC LIMIT ?",
                [...$bindings, $tsQuery, $tsQuery, $pool],
            );
        }

        $scores = [];
        $rows = [];
        foreach ([$semantic, $keyword] as $list) {
            foreach ($list as $rank => $row) {
                $scores[$row->id] = ($scores[$row->id] ?? 0) + 1 / (self::RRF_K + $rank + 1);
                $rows[$row->id] = $row;
            }
        }
        arsort($scores);

        return array_map(fn ($id) => new SearchHit(
            chunkId: $rows[$id]->id,
            documentId: $rows[$id]->document_id,
            documentTitle: $rows[$id]->title,
            page: $rows[$id]->page,
            content: $rows[$id]->content,
            similarity: (float) $rows[$id]->similarity,
        ), array_slice(array_keys($scores), 0, $limit));
    }

    /**
     * Full-text only (no embeddings), used when the workspace has just a Claude key.
     * `similarity` is the share of query stems found in the chunk (0..1), so the
     * workspace's relevance threshold keeps working in this mode too.
     */
    public function keywordSearch(int $workspaceId, string $query, int $limit): array
    {
        $stems = self::stems($query);
        if ($stems === []) {
            return [];
        }
        $tsQuery = implode(' | ', array_map(fn (string $stem) => $stem.':*', $stems));

        $rows = DB::select(<<<'SQL'
            SELECT c.id, c.document_id, c.page, c.content, d.title,
                   ts_rank(c.search, to_tsquery('simple', ?)) AS rank
            FROM knowledge_chunks c
            JOIN knowledge_documents d ON d.id = c.document_id
            WHERE c.workspace_id = ?
              AND d.status = 'ready'
              AND c.search @@ to_tsquery('simple', ?)
            ORDER BY rank DESC
            LIMIT ?
        SQL, [$tsQuery, $workspaceId, $tsQuery, $limit * 4]);

        $hits = array_map(function (object $row) use ($stems) {
            $content = self::lower($row->content);
            $matched = count(array_filter($stems, fn (string $stem) => str_contains($content, $stem)));

            return new SearchHit(
                chunkId: $row->id,
                documentId: $row->document_id,
                documentTitle: $row->title,
                page: $row->page,
                content: $row->content,
                similarity: round($matched / count($stems), 3),
                keyword: true,
            );
        }, $rows);

        usort($hits, fn (SearchHit $a, SearchHit $b) => $b->similarity <=> $a->similarity);

        return array_slice($hits, 0, $limit);
    }

    /** OR-query of the meaningful word stems, e.g. "qiymə:* | çatdırılm:*". */
    private function tsQuery(string $query): string
    {
        return implode(' | ', array_map(fn (string $stem) => $stem.':*', self::stems($query)));
    }

    /**
     * Crude stemming for agglutinative languages (az, ru, tr): long words lose their
     * last characters so "çatdırılması" still matches "çatdırılma" via prefix search.
     *
     * @return list<string>
     */
    public static function stems(string $query): array
    {
        preg_match_all('/[\p{L}\p{N}]{3,}/u', self::lower($query), $m);

        // Question words never appear in documents but would lower the match share.
        $words = array_values(array_diff($m[0], array_map(self::lower(...), self::STOP_WORDS)));
        if ($words === []) {
            $words = $m[0];
        }

        $stems = array_map(function (string $word) {
            $length = mb_strlen($word);

            return $length > 6 ? mb_substr($word, 0, max(5, $length - 3)) : $word;
        }, $words);

        return array_slice(array_values(array_unique($stems)), 0, 12);
    }

    /**
     * Lowercase and fold Azerbaijani letters to Latin ones (ə→e, ö→o, ü→u, ç→c, ş→s, ğ→g, ı/İ→i),
     * matching the search column (see the fold_azerbaijani_letters migration). People often type
     * without these letters: "elaqe nomresi" must find "Əlaqə nömrəsi". mb_strtolower("İ") yields "i" + U+0307.
     */
    private static function lower(string $text): string
    {
        return strtr(str_replace("\u{0307}", '', mb_strtolower($text)), self::FOLD);
    }

    /** @param list<float> $vector */
    public static function literal(array $vector): string
    {
        return '['.implode(',', array_map(fn ($v) => sprintf('%.7F', $v), $vector)).']';
    }
}
