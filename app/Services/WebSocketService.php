<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WebSocketService
{
    protected string $url;
    protected string $appKey;
    protected string $appSecret;

    public function __construct()
    {
        $this->url = config('websocket.url');
        $this->appKey = config('websocket.app_key');
        $this->appSecret = config('websocket.app_secret');
    }

    /**
     * Trigger an event on one or more channels.
     */
    public function trigger(string|array $channels, string $event, array $data = []): bool
    {
        $channels = is_array($channels) ? $channels : [$channels];

        try {
            $response = Http::withHeaders([
                'X-App-Key' => $this->appKey,
                'X-App-Signature' => $this->appSecret,
            ])->post("{$this->url}/api/trigger", [
                'channels' => $channels,
                'event' => $event,
                'data' => $data,
            ]);

            if ($response->failed()) {
                Log::warning('WebSocket trigger failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                    'event' => $event,
                    'channels' => $channels,
                ]);
                return false;
            }

            return true;
        } catch (\Throwable $e) {
            Log::warning('WebSocket trigger error', [
                'error' => $e->getMessage(),
                'event' => $event,
                'channels' => $channels,
            ]);
            return false;
        }
    }

    // ── Document Processing ──────────────────────────

    public function documentProgress(int $documentId, string $stage, int $percent, ?string $message = null): bool
    {
        return $this->trigger('documents', 'processing.progress', [
            'document_id' => $documentId,
            'stage' => $stage,
            'percent' => $percent,
            'message' => $message ?? $this->stageLabel($stage),
        ]);
    }

    // ── Global Toast Notifications ───────────────────

    public function toast(string $type, string $title, ?string $body = null): bool
    {
        return $this->trigger('notifications', 'toast', [
            'type' => $type,   // success, error, info, warning
            'title' => $title,
            'body' => $body,
        ]);
    }

    // ── Dashboard Stats Update ───────────────────────

    public function dashboardStats(array $stats): bool
    {
        return $this->trigger('dashboard', 'stats.updated', $stats);
    }

    // ── Chat Thinking Indicator ──────────────────────

    public function chatThinking(int $conversationId, bool $thinking): bool
    {
        return $this->trigger("chat.{$conversationId}", $thinking ? 'thinking.start' : 'thinking.stop', [
            'conversation_id' => $conversationId,
        ]);
    }

    // ── Reclassify Progress ──────────────────────────

    public function reclassifyProgress(int $current, int $total, string $documentName, ?string $newTopic = null): bool
    {
        return $this->trigger('reclassify', 'reclassify.progress', [
            'current' => $current,
            'total' => $total,
            'document' => $documentName,
            'topic' => $newTopic,
            'percent' => $total > 0 ? (int) round($current / $total * 100) : 0,
        ]);
    }

    // ── Queue Health ─────────────────────────────────

    public function queueStats(array $stats): bool
    {
        return $this->trigger('queue', 'queue.stats', $stats);
    }

    // ── Topic Created ────────────────────────────────

    public function topicCreated(array $topic): bool
    {
        return $this->trigger('topics', 'topic.created', $topic);
    }

    // ── Helpers ──────────────────────────────────────

    protected function stageLabel(string $stage): string
    {
        return match ($stage) {
            'extracting' => 'Extracting text...',
            'classifying' => 'Classifying topic...',
            'chunking' => 'Chunking text...',
            'embedding' => 'Generating embeddings...',
            'completed' => 'Processing complete',
            'failed' => 'Processing failed',
            default => ucfirst($stage),
        };
    }
}
