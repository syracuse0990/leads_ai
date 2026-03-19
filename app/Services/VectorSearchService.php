<?php

namespace App\Services;

use App\Models\DocumentChunk;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class VectorSearchService
{
    protected EmbeddingService $embeddingService;
    protected int $topK;
    protected float $threshold;

    public function __construct(EmbeddingService $embeddingService)
    {
        $this->embeddingService = $embeddingService;
        $this->topK = config('ai.search_top_k', 8);
        $this->threshold = config('ai.similarity_threshold', 0.65);
    }

    /**
     * Hybrid search: pgvector cosine distance + keyword boost.
     * Translates non-English queries to English for better embedding match.
     * Falls back to keyword-only search if vector search returns nothing.
     */
    public function search(string $query, ?int $topicId = null): array
    {
        // Translate query to English for embedding (keeps original for keyword matching)
        $englishQuery = $this->translateForSearch($query);
        $searchQuery = $englishQuery ?: $query;

        // Collect keywords from BOTH original and translated queries
        $keywords = $this->extractKeywords($query);
        if ($englishQuery && $englishQuery !== $query) {
            $keywords = array_unique(array_merge($keywords, $this->extractKeywords($englishQuery)));
        }

        try {
            $queryEmbedding = $this->embeddingService->embed($searchQuery);
        } catch (\Exception $e) {
            Log::warning('Embedding failed for search query, trying keyword-only fallback', [
                'error' => $e->getMessage(),
            ]);
            return $this->keywordFallbackSearch($keywords, $topicId);
        }

        $results = $this->hybridSearch($queryEmbedding, $keywords, $topicId);

        // If vector search returned nothing, try keyword-only fallback
        if (empty($results) && !empty($keywords)) {
            Log::info('Vector search empty, trying keyword fallback', ['keywords' => $keywords]);
            $results = $this->keywordFallbackSearch($keywords, $topicId);
        }

        return $results;
    }

    /**
     * Translate a non-English query to English for better embedding matching.
     */
    protected function translateForSearch(string $query): ?string
    {
        // Quick check: if query looks like it's already English, skip translation
        if (preg_match('/^[a-zA-Z0-9\s\?\.\,\!\-\'\"]+$/', $query)) {
            return null;
        }

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . config('ai.deepseek_api_key'),
                'Content-Type' => 'application/json',
            ])->timeout(10)->post(config('ai.deepseek_base_url') . '/chat/completions', [
                'model' => config('ai.deepseek_model'),
                'messages' => [
                    ['role' => 'system', 'content' => 'Translate the user\'s message to English. Return ONLY the English translation, nothing else. If it\'s already English, return it as-is. Keep it concise — this is a search query.'],
                    ['role' => 'user', 'content' => $query],
                ],
                'temperature' => 0.1,
                'max_tokens' => 100,
            ]);

            if ($response->successful()) {
                $translated = trim($response->json('choices.0.message.content', ''));
                if ($translated && mb_strlen($translated) > 2) {
                    Log::debug('Query translated for search', ['original' => $query, 'translated' => $translated]);
                    return $translated;
                }
            }
        } catch (\Exception $e) {
            Log::debug('Query translation failed, using original', ['error' => $e->getMessage()]);
        }

        return null;
    }

    /**
     * Extract meaningful search keywords from a query string.
     */
    protected function extractKeywords(string $query): array
    {
        $stopWords = ['the', 'is', 'are', 'was', 'were', 'who', 'what', 'when', 'where', 'why', 'how',
            'can', 'could', 'would', 'should', 'does', 'did', 'has', 'have', 'had', 'will',
            'and', 'but', 'for', 'not', 'this', 'that', 'with', 'from', 'about', 'into',
            'than', 'then', 'also', 'just', 'more', 'some', 'any', 'all', 'its', 'his',
            'her', 'our', 'your', 'their', 'tell', 'give', 'know', 'show', 'find',
            // Common Tagalog/Bisaya stop words
            'ang', 'mga', 'lang', 'din', 'rin', 'naman', 'kasi', 'pero', 'para', 'kung',
            'ano', 'anu', 'saan', 'kailan', 'bakit', 'paano', 'nang', 'yung', 'dito',
            'doon', 'akin', 'atin', 'natin', 'nila', 'niya', 'siya', 'ito', 'iyon',
            'pong', 'nasa', 'naa', 'unsa', 'asa', 'ngano', 'mao', 'kini', 'kadto'];

        $words = preg_split('/\s+/', mb_strtolower(trim($query)));
        $words = array_map(fn($w) => preg_replace('/[^\w]/', '', $w), $words);

        return array_values(array_filter($words, fn($w) => mb_strlen($w) >= 2 && !in_array($w, $stopWords)));
    }

    /**
     * Main hybrid search: pgvector cosine distance + keyword boost.
     */
    protected function hybridSearch(array $queryEmbedding, array $keywords, ?int $topicId = null): array
    {
        $vectorParam = '[' . implode(',', $queryEmbedding) . ']';

        $keywordBoost = '0';
        $bindings = [];
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

    /**
     * Keyword-only fallback when vector search returns nothing.
     * Searches chunks by text matching without vector distance.
     */
    protected function keywordFallbackSearch(array $keywords, ?int $topicId = null): array
    {
        if (empty($keywords)) {
            return [];
        }

        $bindings = [];
        $whereParts = [];

        foreach ($keywords as $kw) {
            $whereParts[] = "LOWER(dc.content) LIKE ?";
            $bindings[] = '%' . str_replace(['%', '_'], ['\%', '\_'], $kw) . '%';
        }

        $sql = "
            SELECT dc.id, dc.document_id, dc.content, dc.chunk_index, dc.metadata,
                   d.original_name AS source_name,
                   0.5 AS combined_score
            FROM document_chunks dc
            LEFT JOIN documents d ON d.id = dc.document_id
            WHERE (" . implode(' OR ', $whereParts) . ")
        ";

        if ($topicId) {
            $sql .= " AND dc.topic_id = ?";
            $bindings[] = $topicId;
        }

        $sql .= " ORDER BY dc.id ASC LIMIT ?";
        $bindings[] = $this->topK;

        $results = DB::select($sql, $bindings);

        Log::info('Keyword fallback results', ['count' => count($results), 'keywords' => $keywords]);

        return array_map(fn($chunk) => [
            'content' => $chunk->content,
            'distance' => $chunk->combined_score,
            'document_id' => $chunk->document_id,
            'chunk_index' => $chunk->chunk_index,
            'source' => $chunk->source_name ?? 'Unknown',
        ], $results);
    }
}
