<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Postgres does not lowercase "İ", and "I"/"ı" differ between Azerbaijani and English.
 * Fold İ, I and ı to "i" before indexing; PgVectorStore folds queries the same way.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS knowledge_chunks_search_gin');
        DB::statement('ALTER TABLE knowledge_chunks DROP COLUMN IF EXISTS search');
        DB::statement("ALTER TABLE knowledge_chunks ADD COLUMN search tsvector GENERATED ALWAYS AS (to_tsvector('simple', translate(content, 'İIı', 'iii'))) STORED");
        DB::statement('CREATE INDEX knowledge_chunks_search_gin ON knowledge_chunks USING gin (search)');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS knowledge_chunks_search_gin');
        DB::statement('ALTER TABLE knowledge_chunks DROP COLUMN IF EXISTS search');
        DB::statement("ALTER TABLE knowledge_chunks ADD COLUMN search tsvector GENERATED ALWAYS AS (to_tsvector('simple', content)) STORED");
        DB::statement('CREATE INDEX knowledge_chunks_search_gin ON knowledge_chunks USING gin (search)');
    }
};
