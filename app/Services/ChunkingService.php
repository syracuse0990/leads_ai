<?php

namespace App\Services;

class ChunkingService
{
    protected int $chunkSize;
    protected int $chunkOverlap;

    public function __construct()
    {
        $this->chunkSize = config('ai.chunk_size', 500);
        $this->chunkOverlap = config('ai.chunk_overlap', 50);
    }

    /**
     * Split text into overlapping chunks, respecting paragraph and sentence boundaries.
     *
     * @return string[]
     */
    public function chunk(string $text): array
    {
        // Split into paragraphs first
        $paragraphs = preg_split('/\n\s*\n/', $text, -1, PREG_SPLIT_NO_EMPTY);
        $paragraphs = array_map('trim', $paragraphs);
        $paragraphs = array_filter($paragraphs, fn($p) => mb_strlen($p) > 10);

        if (empty($paragraphs)) {
            $text = preg_replace('/\s+/', ' ', trim($text));
            return mb_strlen($text) > 10 ? [$text] : [];
        }

        $chunks = [];
        $currentChunk = '';
        $currentWordCount = 0;

        foreach ($paragraphs as $para) {
            $paraWords = str_word_count($para);

            // If a single paragraph exceeds chunk size, split it by sentences
            if ($paraWords > $this->chunkSize) {
                // Flush current chunk first
                if ($currentWordCount > 0) {
                    $chunks[] = trim($currentChunk);
                    $currentChunk = '';
                    $currentWordCount = 0;
                }

                $sentenceChunks = $this->splitBySentences($para);
                array_push($chunks, ...$sentenceChunks);
                continue;
            }

            // If adding this paragraph would exceed the limit, flush
            if ($currentWordCount + $paraWords > $this->chunkSize && $currentWordCount > 0) {
                $chunks[] = trim($currentChunk);

                // Start new chunk with overlap from the end of the previous chunk
                $overlapText = $this->getOverlapText($currentChunk);
                $currentChunk = $overlapText ? $overlapText . "\n\n" . $para : $para;
                $currentWordCount = str_word_count($currentChunk);
            } else {
                $currentChunk .= ($currentWordCount > 0 ? "\n\n" : '') . $para;
                $currentWordCount += $paraWords;
            }
        }

        // Flush remaining
        if ($currentWordCount > 0) {
            $chunks[] = trim($currentChunk);
        }

        return $chunks;
    }

    /**
     * Split a large paragraph into chunks at sentence boundaries.
     */
    protected function splitBySentences(string $text): array
    {
        // Split on sentence-ending punctuation followed by space
        $sentences = preg_split('/(?<=[.!?])\s+/', $text, -1, PREG_SPLIT_NO_EMPTY);

        $chunks = [];
        $currentChunk = '';
        $currentWordCount = 0;

        foreach ($sentences as $sentence) {
            $sentenceWords = str_word_count($sentence);

            if ($currentWordCount + $sentenceWords > $this->chunkSize && $currentWordCount > 0) {
                $chunks[] = trim($currentChunk);
                $overlapText = $this->getOverlapText($currentChunk);
                $currentChunk = $overlapText ? $overlapText . ' ' . $sentence : $sentence;
                $currentWordCount = str_word_count($currentChunk);
            } else {
                $currentChunk .= ($currentWordCount > 0 ? ' ' : '') . $sentence;
                $currentWordCount += $sentenceWords;
            }
        }

        if ($currentWordCount > 0) {
            $chunks[] = trim($currentChunk);
        }

        return $chunks;
    }

    /**
     * Get the last N words from text for overlap.
     */
    protected function getOverlapText(string $text): string
    {
        $words = explode(' ', $text);
        if (count($words) <= $this->chunkOverlap) {
            return '';
        }
        return implode(' ', array_slice($words, -$this->chunkOverlap));
    }
}
