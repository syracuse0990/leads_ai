<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_chunks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            $table->foreignId('topic_id')->constrained()->cascadeOnDelete();
            $table->text('content');
            $table->integer('chunk_index')->default(0);
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->index(['topic_id', 'chunk_index']);
        });

        // Store embeddings as native PostgreSQL float8 array
        DB::statement('ALTER TABLE document_chunks ADD COLUMN embedding float8[]');

        // GIN index on the array for basic lookups
        DB::statement('CREATE INDEX document_chunks_embedding_idx ON document_chunks USING gin (embedding)');
    }

    public function down(): void
    {
        Schema::dropIfExists('document_chunks');
    }
};
