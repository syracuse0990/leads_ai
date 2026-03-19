<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Custom cosine distance function (drop-in replacement until pgvector is available)
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
            $$ LANGUAGE plpgsql IMMUTABLE STRICT;
        ');
    }

    public function down(): void
    {
        DB::statement('DROP FUNCTION IF EXISTS cosine_distance(float8[], float8[])');
    }
};
