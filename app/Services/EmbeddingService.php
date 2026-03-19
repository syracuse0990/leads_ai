<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Embedding service using sentence-transformers (all-MiniLM-L6-v2) via local microservice.
 * 384-dimensional vectors with true semantic understanding.
 */
class EmbeddingService
{
    protected string $serverUrl;

    public function __construct()
    {
        $this->serverUrl = rtrim(config('ai.embedding_server_url', 'http://127.0.0.1:9500'), '/');
    }

    /**
     * Generate embedding vector for a single text.
     *
     * @return float[]
     */
    public function embed(string $text): array
    {
        return $this->embedBatch([$text])[0];
    }

    /**
     * Generate embeddings for multiple texts in a batch.
     *
     * @param string[] $texts
     * @return array<int, float[]>
     */
    public function embedBatch(array $texts): array
    {
        // Process in batches of 50 to avoid overwhelming the server
        $allEmbeddings = [];
        $batches = array_chunk($texts, 50);

        foreach ($batches as $batch) {
            $response = Http::timeout(120)
                ->post("{$this->serverUrl}/embed", ['texts' => array_values($batch)]);

            if ($response->failed()) {
                Log::error('Embedding server error', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                throw new \RuntimeException('Embedding server error: ' . $response->body());
            }

            $embeddings = $response->json('embeddings', []);

            if (count($embeddings) !== count($batch)) {
                throw new \RuntimeException('Embedding count mismatch: expected ' . count($batch) . ', got ' . count($embeddings));
            }

            array_push($allEmbeddings, ...$embeddings);
        }

        return $allEmbeddings;
    }

    /**
     * Check if the embedding server is available.
     */
    public function isHealthy(): bool
    {
        try {
            $response = Http::timeout(5)->get("{$this->serverUrl}/health");
            return $response->successful();
        } catch (\Throwable) {
            return false;
        }
    }
}
