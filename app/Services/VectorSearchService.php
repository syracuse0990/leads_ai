<?php

namespace App\Services;

use App\Models\DocumentChunk;
use Illuminate\Support\Facades\DB;

class VectorSearchService
{
    protected EmbeddingService $embeddingService;
    protected int $topK;
    protected float $threshold;

    public function __construct(EmbeddingService $embeddingService)
    {
        $this->embeddingService = $embeddingService;
        $this->topK = config('ai.search_top_k', 5);
        $this->threshold = config('ai.similarity_threshold', 0.8);
    }

    /**
     * Hybrid search: pgvector cosine distance + keyword boost.
     * Uses parameterized queries and HNSW index for fast retrieval.
     *
     * @return array<int, array{content: string, distance: float, document_id: int, chunk_index: int, source: string}>
     */
    public function search(string $query, ?int $topicId = null): array
    {
        try {
            $queryEmbedding = $this->embeddingService->embed($query);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::warning('Embedding failed for search query, returning empty results', [
                'error' => $e->getMessage(),
            ]);
            return [];
        }

        $vectorParam = '[' . implode(',', $queryEmbedding) . ']';

        // Extract meaningful keywords (skip stop words)
        $stopWords = ['the', 'is', 'are', 'was', 'were', 'who', 'what', 'when', 'where', 'why', 'how',
            'can', 'could', 'would', 'should', 'does', 'did', 'has', 'have', 'had', 'will',
            'and', 'but', 'for', 'not', 'this', 'that', 'with', 'from', 'about', 'into',
            'than', 'then', 'also', 'just', 'more', 'some', 'any', 'all', 'its', 'his',
            'her', 'our', 'your', 'their', 'tell', 'give', 'know', 'show', 'find'];
        $words = preg_split('/\s+/', mb_strtolower(trim($query)));
        $keywords = array_values(array_filter($words, fn($w) => mb_strlen($w) >= 2 && !in_array($w, $stopWords)));

        // Build keyword boost using parameterized LIKE clauses
        $keywordBoost = '0';
        $bindings = [];

        // First binding: the vector parameter
        $bindings[] = $vectorParam;

        if (!empty($keywords)) {
            $boostParts = [];
            foreach ($keywords as $kw) {
                $boostParts[] = "CASE WHEN LOWER(dc.content) LIKE ? THEN 0.3 ELSE 0 END";
                $bindings[] = '%' . str_replace(['%', '_'], ['\%', '\_'], $kw) . '%';
            }
            foreach ($keywords as $kw) {
                $boostParts[] = "CASE WHEN LOWER(d.original_name) LIKE ? THEN 0.35 ELSE 0 END";
                $bindings[] = '%' . str_replace(['%', '_'], ['\%', '\_'], $kw) . '%';
            }
            $keywordBoost = implode(' + ', $boostParts);
        }

        // pgvector <=> operator for cosine distance (uses HNSW index)
        // Filter on combined_score (vector_dist - keyword_boost) so keyword matches
        // can rescue high-distance but textually relevant chunks
        $sql = "
            SELECT *, (vector_dist - keyword_boost) AS combined_score FROM (
                SELECT dc.id, dc.document_id, dc.content, dc.chunk_index, dc.metadata,
                       d.original_name AS source_name,
                       (dc.embedding <=> ?::vector) AS vector_dist,
                       ({$keywordBoost}) AS keyword_boost
                FROM document_chunks dc
                LEFT JOIN documents d ON d.id = dc.document_id
                WHERE dc.embedding IS NOT NULL
        ";

        if ($topicId) {
            $sql .= " AND dc.topic_id = ?";
            $bindings[] = $topicId;
        }

        $sql .= "
            ) sub
            WHERE (vector_dist - keyword_boost) < ?
            ORDER BY combined_score ASC
            LIMIT ?
        ";
        $bindings[] = $this->threshold;
        $bindings[] = $this->topK;

        $results = DB::select($sql, $bindings);

        return array_map(fn($chunk) => [
            'content' => $chunk->content,
            'distance' => $chunk->combined_score,
            'document_id' => $chunk->document_id,
            'chunk_index' => $chunk->chunk_index,
            'source' => $chunk->source_name ?? 'Unknown',
        ], $results);
    }
}
