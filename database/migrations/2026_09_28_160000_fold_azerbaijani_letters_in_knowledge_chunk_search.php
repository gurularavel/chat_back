<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Visitors often type Azerbaijani without its letters ("elaqe nomresi" for "Əlaqə nömrəsi").
 * Fold ə/ö/ü/ç/ş/ğ (and İ/I/ı, as before) to Latin letters before indexing;
 * PgVectorStore::lower() folds queries the same way.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->searchColumn("translate(content, 'İIıƏəÖöÜüÇçŞşĞğ', 'iiieeoouuccssgg')");
    }

    public function down(): void
    {
        $this->searchColumn("translate(content, 'İIı', 'iii')");
    }

    private function searchColumn(string $expression): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS knowledge_chunks_search_gin');
        DB::statement('ALTER TABLE knowledge_chunks DROP COLUMN IF EXISTS search');
        DB::statement("ALTER TABLE knowledge_chunks ADD COLUMN search tsvector GENERATED ALWAYS AS (to_tsvector('simple', {$expression})) STORED");
        DB::statement('CREATE INDEX knowledge_chunks_search_gin ON knowledge_chunks USING gin (search)');
    }
};
