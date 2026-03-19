<?php

namespace App\Console\Commands;

use App\Models\DocumentChunk;
use App\Services\EmbeddingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ReembedDocuments extends Command
{
    protected $signature = 'documents:reembed {--batch=20 : Batch size for embedding} {--chunk-id= : Re-embed a specific chunk}';
    protected $description = 'Re-embed all document chunks using the new semantic embedding model';

    public function handle(EmbeddingService $embedder): int
    {
        if (!$embedder->isHealthy()) {
            $this->error('Embedding server is not available. Make sure it is running on ' . config('ai.embedding_server_url'));
            return 1;
        }

        $this->info('Embedding server is healthy.');

        $chunkId = $this->option('chunk-id');
        $batchSize = (int) $this->option('batch');

        $query = DocumentChunk::query()->orderBy('id');
        if ($chunkId) {
            $query->where('id', $chunkId);
        }

        $total = $query->count();
        if ($total === 0) {
            $this->info('No chunks to re-embed.');
            return 0;
        }

        $this->info("Re-embedding {$total} chunks in batches of {$batchSize}...");
        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $processed = 0;
        $errors = 0;

        $query->chunk($batchSize, function ($chunks) use ($embedder, &$processed, &$errors, $bar) {
            $texts = $chunks->pluck('content')->all();

            try {
                $embeddings = $embedder->embedBatch($texts);

                foreach ($chunks as $i => $chunk) {
                    $vectorStr = '[' . implode(',', $embeddings[$i]) . ']';
                    DB::statement(
                        "UPDATE document_chunks SET embedding = ?::vector WHERE id = ?",
                        [$vectorStr, $chunk->id]
                    );
                    $processed++;
                    $bar->advance();
                }
            } catch (\Throwable $e) {
                $errors += count($chunks);
                $bar->advance(count($chunks));
                $this->newLine();
                $this->error("Batch error: {$e->getMessage()}");
            }
        });

        $bar->finish();
        $this->newLine(2);
        $this->info("Done! Processed: {$processed}, Errors: {$errors}");

        return $errors > 0 ? 1 : 0;
    }
}
