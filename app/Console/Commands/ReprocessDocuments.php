<?php

namespace App\Console\Commands;

use App\Models\Document;
use App\Models\DocumentChunk;
use App\Services\ChunkingService;
use App\Services\EmbeddingService;
use App\Services\TextExtractorService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ReprocessDocuments extends Command
{
    protected $signature = 'documents:reprocess
                            {--id= : Reprocess a specific document by ID}
                            {--all : Reprocess all documents}
                            {--embeddings-only : Only regenerate embeddings (skip re-extraction)}';

    protected $description = 'Re-extract text, re-chunk, and re-embed documents with the latest pipeline';

    public function handle(
        TextExtractorService $extractor,
        ChunkingService $chunker,
        EmbeddingService $embedder,
    ): int {
        $embeddingsOnly = $this->option('embeddings-only');

        if ($this->option('id')) {
            $documents = Document::where('id', $this->option('id'))->get();
        } elseif ($this->option('all')) {
            $documents = Document::all();
        } else {
            $this->error('Specify --id=N or --all');
            return 1;
        }

        if ($documents->isEmpty()) {
            $this->warn('No documents found.');
            return 0;
        }

        $this->info("Reprocessing {$documents->count()} document(s)...");
        $bar = $this->output->createProgressBar($documents->count());

        foreach ($documents as $document) {
            $bar->setMessage($document->original_name);
            $this->newLine();
            $this->info("  → {$document->original_name} (ID: {$document->id})");

            try {
                if ($embeddingsOnly) {
                    $this->reEmbed($document, $embedder);
                } else {
                    $this->fullReprocess($document, $extractor, $chunker, $embedder);
                }
                $document->update(['status' => 'completed', 'error_message' => null]);
                $this->info("    ✓ Done");
            } catch (\Throwable $e) {
                $document->update(['status' => 'failed', 'error_message' => mb_substr($e->getMessage(), 0, 500)]);
                $this->error("    ✗ Failed: {$e->getMessage()}");
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);
        $this->info('Reprocessing complete.');

        return 0;
    }

    protected function fullReprocess(
        Document $document,
        TextExtractorService $extractor,
        ChunkingService $chunker,
        EmbeddingService $embedder,
    ): void {
        $filePath = Storage::disk('local')->path($document->file_path);

        if (!file_exists($filePath)) {
            throw new \RuntimeException("File not found: {$filePath}");
        }

        // 1. Re-extract text
        $text = $extractor->extract($filePath, $document->mime_type);
        if (empty(trim($text))) {
            throw new \RuntimeException('No text could be extracted.');
        }

        $this->info("    Extracted " . mb_strlen($text) . " chars");

        // 2. Re-chunk
        $chunks = $chunker->chunk($text);
        $this->info("    Created " . count($chunks) . " chunks");

        // 3. Delete old chunks
        DocumentChunk::where('document_id', $document->id)->delete();

        // 4. Embed and store new chunks
        $this->storeChunks($document, $chunks, $embedder);
    }

    protected function reEmbed(Document $document, EmbeddingService $embedder): void
    {
        $chunks = DocumentChunk::where('document_id', $document->id)
            ->orderBy('chunk_index')
            ->get();

        if ($chunks->isEmpty()) {
            $this->warn("    No chunks found, skipping.");
            return;
        }

        $texts = $chunks->pluck('content')->toArray();
        $this->info("    Re-embedding " . count($texts) . " chunks");

        $batchSize = 10;
        $batches = array_chunk($texts, $batchSize);
        $chunkModels = $chunks->values();
        $idx = 0;

        foreach ($batches as $batch) {
            $embeddings = $embedder->embedBatch($batch);
            foreach ($embeddings as $embedding) {
                $vectorStr = '[' . implode(',', $embedding) . ']';
                DB::statement(
                    "UPDATE document_chunks SET embedding = ?::vector WHERE id = ?",
                    [$vectorStr, $chunkModels[$idx]->id]
                );
                $idx++;
            }
        }
    }

    protected function storeChunks(Document $document, array $chunks, EmbeddingService $embedder): void
    {
        $batchSize = 10;
        $chunkBatches = array_chunk($chunks, $batchSize);
        $chunkIndex = 0;

        DB::beginTransaction();
        try {
            foreach ($chunkBatches as $batch) {
                $embeddings = $embedder->embedBatch($batch);

                foreach ($batch as $i => $chunkText) {
                    $vectorStr = '[' . implode(',', $embeddings[$i]) . ']';

                    $chunk = DocumentChunk::create([
                        'document_id' => $document->id,
                        'topic_id' => $document->topic_id,
                        'content' => $chunkText,
                        'chunk_index' => $chunkIndex,
                        'metadata' => [
                            'source' => $document->original_name,
                            'chunk_of' => count($chunks),
                        ],
                    ]);

                    DB::statement(
                        "UPDATE document_chunks SET embedding = ?::vector WHERE id = ?",
                        [$vectorStr, $chunk->id]
                    );

                    $chunkIndex++;
                }
            }
            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }
}
