<?php

namespace App\Jobs;

use App\Models\Document;
use App\Models\DocumentChunk;
use App\Models\Topic;
use App\Services\ChunkingService;
use App\Services\EmbeddingService;
use App\Services\DeepSeekService;
use App\Services\TextExtractorService;
use App\Services\WebSocketService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ProcessDocument implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 30;
    public int $timeout = 300;

    public function __construct(
        protected Document $document
    ) {
        $this->onQueue('training');
    }

    public function handle(
        TextExtractorService $extractor,
        ChunkingService $chunker,
        EmbeddingService $embedder,
        DeepSeekService $deepSeek,
        WebSocketService $ws
    ): void {
        ini_set('memory_limit', '512M');

        $this->document->update(['status' => 'processing']);
        $ws->documentProgress($this->document->id, 'extracting', 5);

        try {
            // 1. Extract text
            $filePath = Storage::disk('local')->path($this->document->file_path);
            $text = $extractor->extract($filePath, $this->document->mime_type);

            if (empty(trim($text))) {
                throw new \RuntimeException('No text could be extracted from the document.');
            }

            $ws->documentProgress($this->document->id, 'extracting', 25, 'Text extracted');

            // 2. Auto-classify into a topic if not already assigned
            if (!$this->document->topic_id) {
                $ws->documentProgress($this->document->id, 'classifying', 30);
                $topicId = $this->classifyAndAssignTopic($text, $deepSeek);
                $this->document->update(['topic_id' => $topicId]);
            }

            $ws->documentProgress($this->document->id, 'classifying', 40, 'Topic assigned');

            // 3. Chunk the text
            $ws->documentProgress($this->document->id, 'chunking', 45);
            $chunks = $chunker->chunk($text);
            $ws->documentProgress($this->document->id, 'chunking', 50, count($chunks) . ' chunks created');

            // 4. Generate embeddings in batches of 10
            $batchSize = 10;
            $chunkBatches = array_chunk($chunks, $batchSize);
            $totalBatches = count($chunkBatches);

            DB::beginTransaction();

            try {
                $chunkIndex = 0;

                foreach ($chunkBatches as $batchNum => $batch) {
                    // Progress: 50% to 95% during embedding
                    $embedPercent = 50 + (int) (($batchNum / max($totalBatches, 1)) * 45);
                    $ws->documentProgress(
                        $this->document->id,
                        'embedding',
                        $embedPercent,
                        'Embedding batch ' . ($batchNum + 1) . '/' . $totalBatches
                    );

                    $embeddings = $embedder->embedBatch($batch);

                    foreach ($batch as $i => $chunkText) {
                        $embedding = $embeddings[$i];
                        $vectorStr = '[' . implode(',', $embedding) . ']';

                        $chunk = DocumentChunk::create([
                            'document_id' => $this->document->id,
                            'topic_id' => $this->document->topic_id,
                            'content' => $chunkText,
                            'chunk_index' => $chunkIndex,
                            'metadata' => [
                                'source' => $this->document->original_name,
                                'chunk_of' => count($chunks),
                            ],
                        ]);

                        // Update embedding via raw SQL (pgvector type)
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

            $this->document->update(['status' => 'completed']);
            $ws->documentProgress($this->document->id, 'completed', 100);

            // Toast + dashboard stats
            $ws->toast('success', 'Document Ready', "\"{$this->document->original_name}\" processed with " . count($chunks) . ' chunks.');
            $ws->dashboardStats([
                'topics' => Topic::count(),
                'documents' => Document::count(),
                'chunks' => DocumentChunk::count(),
            ]);
            $ws->queueStats($this->getQueueStats());

            Log::info("Document processed successfully", [
                'document_id' => $this->document->id,
                'chunks_created' => count($chunks),
            ]);

        } catch (\Throwable $e) {
            $this->document->update([
                'status' => 'failed',
                'error_message' => mb_substr($e->getMessage(), 0, 500),
            ]);

            $ws->documentProgress($this->document->id, 'failed', 0, mb_substr($e->getMessage(), 0, 200));
            $ws->toast('error', 'Processing Failed', "\"{$this->document->original_name}\" failed: " . mb_substr($e->getMessage(), 0, 100));
            $ws->queueStats($this->getQueueStats());

            Log::error("Document processing failed", [
                'document_id' => $this->document->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Use AI to classify document text into an existing or new topic.
     */
    protected function classifyAndAssignTopic(string $text, DeepSeekService $deepSeek): int
    {
        $existingTopics = Topic::pluck('name')->toArray();

        $textSample = mb_substr($text, 0, 2000);
        $topicName = $deepSeek->classifyTopic($textSample, $existingTopics);

        // Find or create the topic
        $topic = Topic::firstOrCreate(
            ['name' => $topicName],
            ['description' => 'Auto-classified from uploaded documents.']
        );

        // Notify frontend if new topic was created
        if ($topic->wasRecentlyCreated) {
            app(WebSocketService::class)->topicCreated([
                'id' => $topic->id,
                'name' => $topic->name,
                'description' => $topic->description,
                'documents_count' => 0,
                'chunks_count' => 0,
            ]);
        }

        Log::info('Document auto-classified', [
            'document_id' => $this->document->id,
            'topic' => $topicName,
            'topic_id' => $topic->id,
            'is_new' => $topic->wasRecentlyCreated,
        ]);

        return $topic->id;
    }

    protected function getQueueStats(): array
    {
        return [
            'pending' => Document::where('status', 'pending')->count(),
            'processing' => Document::where('status', 'processing')->count(),
            'completed' => Document::where('status', 'completed')->count(),
            'failed' => Document::where('status', 'failed')->count(),
        ];
    }
}
