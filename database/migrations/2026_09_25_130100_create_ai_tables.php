<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_credentials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('provider'); // openai, anthropic, gemini
            $table->string('label')->nullable();
            $table->text('api_key'); // encrypted cast
            $table->string('chat_model');
            $table->string('embedding_model')->nullable();
            $table->boolean('is_default')->default(false);
            $table->string('status')->default('unverified'); // unverified, valid, invalid
            $table->text('last_error')->nullable();
            $table->timestamp('last_verified_at')->nullable();
            $table->timestamps();
        });

        Schema::create('ai_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->unique()->constrained()->cascadeOnDelete();
            $table->text('system_prompt')->nullable();
            $table->string('tone')->default('friendly');
            $table->decimal('temperature', 3, 2)->default(0.2);
            $table->decimal('similarity_threshold', 4, 3)->default(0.35);
            $table->string('handoff_mode')->default('auto'); // auto, never, always
            $table->json('fallback_message')->nullable();
            $table->timestamps();
        });

        Schema::create('knowledge_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title');
            $table->string('disk_path');
            $table->string('mime')->default('application/pdf');
            $table->unsignedBigInteger('size')->default(0);
            $table->unsignedInteger('pages')->default(0);
            $table->unsignedInteger('chunks_count')->default(0);
            $table->string('status')->default('uploaded'); // uploaded, parsing, embedding, ready, failed
            $table->text('error')->nullable();
            $table->string('embedding_provider')->nullable();
            $table->string('embedding_model')->nullable();
            $table->timestamps();
            $table->index(['workspace_id', 'status']);
        });

        Schema::create('knowledge_chunks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained('knowledge_documents')->cascadeOnDelete();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('page')->default(1);
            $table->unsignedInteger('chunk_index');
            $table->text('content');
            $table->unsignedInteger('tokens')->default(0);
            $table->timestamps();
            $table->index(['workspace_id', 'document_id']);
        });

        // pgvector column + full-text column (Postgres only; sqlite test DB is not used)
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE EXTENSION IF NOT EXISTS vector');
            $dims = (int) config('chat.embedding_dimensions', 1536);
            DB::statement("ALTER TABLE knowledge_chunks ADD COLUMN embedding vector({$dims})");
            DB::statement("ALTER TABLE knowledge_chunks ADD COLUMN search tsvector GENERATED ALWAYS AS (to_tsvector('simple', content)) STORED");
            DB::statement('CREATE INDEX knowledge_chunks_embedding_hnsw ON knowledge_chunks USING hnsw (embedding vector_cosine_ops)');
            DB::statement('CREATE INDEX knowledge_chunks_search_gin ON knowledge_chunks USING gin (search)');
        } else {
            Schema::table('knowledge_chunks', function (Blueprint $table) {
                $table->json('embedding')->nullable();
            });
        }

        Schema::create('ai_usage_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('conversation_id')->nullable()->index();
            $table->string('provider');
            $table->string('model');
            $table->string('type'); // chat, embedding
            $table->boolean('platform_key')->default(false);
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->unsignedInteger('latency_ms')->default(0);
            $table->boolean('success')->default(true);
            $table->text('error')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['workspace_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_usage_logs');
        Schema::dropIfExists('knowledge_chunks');
        Schema::dropIfExists('knowledge_documents');
        Schema::dropIfExists('ai_settings');
        Schema::dropIfExists('ai_credentials');
    }
};
