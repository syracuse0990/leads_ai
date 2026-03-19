<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Enable pgvector extension
        DB::statement('CREATE EXTENSION IF NOT EXISTS vector');

        // 2. Add a proper vector(384) column
        DB::statement('ALTER TABLE document_chunks ADD COLUMN embedding_vec vector(384)');

        // 3. Migrate existing float8[] data to the new vector column
        DB::statement('
            UPDATE document_chunks
            SET embedding_vec = embedding::vector(384)
            WHERE embedding IS NOT NULL
        ');

        // 4. Drop old float8[] column and its GIN index
        DB::statement('DROP INDEX IF EXISTS document_chunks_embedding_idx');
        DB::statement('ALTER TABLE document_chunks DROP COLUMN IF EXISTS embedding');

        // 5. Rename new column to "embedding"
        DB::statement('ALTER TABLE document_chunks RENAME COLUMN embedding_vec TO embedding');

        // 6. Create HNSW index for fast cosine similarity search
        DB::statement('
            CREATE INDEX document_chunks_embedding_hnsw_idx
            ON document_chunks
            USING hnsw (embedding vector_cosine_ops)
            WITH (m = 16, ef_construction = 64)
        ');

        // 7. Drop the old PL/pgSQL cosine_distance function (pgvector handles this natively)
        DB::statement('DROP FUNCTION IF EXISTS cosine_distance(float8[], float8[])');
    }

    public function down(): void
    {
        // Recreate old structure
        DB::statement('DROP INDEX IF EXISTS document_chunks_embedding_hnsw_idx');
        DB::statement('ALTER TABLE document_chunks ADD COLUMN embedding_old float8[]');
        DB::statement('
            UPDATE document_chunks
            SET embedding_old = embedding::float8[]
            WHERE embedding IS NOT NULL
        ');
        DB::statement('ALTER TABLE document_chunks DROP COLUMN IF EXISTS embedding');
        DB::statement('ALTER TABLE document_chunks RENAME COLUMN embedding_old TO embedding');
        DB::statement('CREATE INDEX document_chunks_embedding_idx ON document_chunks USING gin (embedding)');

        // Recreate the old PL/pgSQL function
        DB::statement('
            CREATE OR REPLACE FUNCTION cosine_distance(a float8[], b float8[])
            RETURNS float8 AS $$
            DECLARE
                dot_product float8 := 0;
                norm_a float8 := 0;
                norm_b float8 := 0;
                i int;
            BEGIN
                FOR i IN 1..array_length(a, 1) LOOP
                    dot_product := dot_product + a[i] * b[i];
                    norm_a := norm_a + a[i] * a[i];
                    norm_b := norm_b + b[i] * b[i];
                END LOOP;
                IF norm_a = 0 OR norm_b = 0 THEN
                    RETURN 1.0;
                END IF;
                RETURN 1.0 - (dot_product / (sqrt(norm_a) * sqrt(norm_b)));
            END;
            $$ LANGUAGE plpgsql IMMUTABLE STRICT
        ');

        DB::statement('DROP EXTENSION IF EXISTS vector');
    }
};
