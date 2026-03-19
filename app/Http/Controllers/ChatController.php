<?php

namespace App\Http\Controllers;

use App\Http\Controllers\DashboardController;
use App\Models\Conversation;
use App\Models\Document;
use App\Models\Message;
use App\Models\Topic;
use App\Services\DeepSeekService;
use App\Services\VectorSearchService;
use App\Services\WebSocketService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ChatController extends Controller
{
    public function index(Request $request)
    {
        $conversations = Conversation::latest()
            ->limit(30)
            ->get(['id', 'title', 'created_at']);

        return Inertia::render('Chat/Index', [
            'conversations' => $conversations,
            'activeConversation' => null,
            'messages' => [],
        ]);
    }

    public function show(Conversation $conversation)
    {
        $conversations = Conversation::latest()
            ->limit(30)
            ->get(['id', 'title', 'created_at']);

        $messages = $conversation->messages()->orderBy('created_at')->get();

        return Inertia::render('Chat/Index', [
            'conversations' => $conversations,
            'activeConversation' => $conversation,
            'messages' => $messages,
        ]);
    }

    public function startConversation(Request $request)
    {
        $conversation = Conversation::create([
            'topic_id' => null,
            'title' => 'New Conversation',
        ]);

        return redirect()->route('chat.show', $conversation);
    }

    public function sendMessage(
        Request $request,
        Conversation $conversation,
        VectorSearchService $vectorSearch,
        DeepSeekService $deepSeek,
        WebSocketService $ws
    ) {
        $validated = $request->validate([
            'message' => 'required|string|max:5000',
        ]);

        // Save user message
        $conversation->messages()->create([
            'role' => 'user',
            'content' => $validated['message'],
        ]);

        $ws->chatThinking($conversation->id, true);

        // Search across ALL topics — system auto-funnels based on question
        $results = $vectorSearch->search($validated['message']);

        // Build context with source attribution
        $context = array_map(fn($r) => "[Source: {$r['source']}]\n{$r['content']}", $results);

        // Track topic hits
        $this->trackTopicHits($results);

        // Get recent conversation history (last 10 messages for multi-turn context)
        $history = $conversation->messages()
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get(['role', 'content'])
            ->reverse()
            ->values()
            ->map(fn($m) => ['role' => $m->role, 'content' => $m->content])
            ->all();

        // Get AI response via DeepSeek (cheaper)
        $response = $deepSeek->chat($validated['message'], $context, 1.0, $conversation->system_prompt, $history);

        $ws->chatThinking($conversation->id, false);

        // Save assistant message
        $conversation->messages()->create([
            'role' => 'assistant',
            'content' => $response,
        ]);

        // Update conversation title on first message
        if ($conversation->messages()->count() <= 2 && $conversation->title === 'New Conversation') {
            $conversation->update([
                'title' => mb_substr($validated['message'], 0, 80),
            ]);
        }

        $ws->dashboardStats(DashboardController::getStats());

        return redirect()->route('chat.show', $conversation);
    }

    public function stream(
        Request $request,
        Conversation $conversation,
        VectorSearchService $vectorSearch,
        DeepSeekService $deepSeek,
        WebSocketService $ws
    ): StreamedResponse {
        $validated = $request->validate([
            'message' => 'required|string|max:5000',
        ]);

        // Save user message
        $conversation->messages()->create([
            'role' => 'user',
            'content' => $validated['message'],
        ]);

        $ws->chatThinking($conversation->id, true);

        // Search across ALL topics — system auto-funnels based on question
        $results = $vectorSearch->search($validated['message']);

        // Build context with source attribution
        $context = array_map(fn($r) => "[Source: {$r['source']}]\n{$r['content']}", $results);

        // Track topic hits
        $this->trackTopicHits($results);

        // Get recent conversation history (last 10 messages for multi-turn context)
        $history = $conversation->messages()
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get(['role', 'content'])
            ->reverse()
            ->values()
            ->map(fn($m) => ['role' => $m->role, 'content' => $m->content])
            ->all();

        // Update conversation title on first real message
        if ($conversation->messages()->count() <= 1 && $conversation->title === 'New Conversation') {
            $conversation->update([
                'title' => mb_substr($validated['message'], 0, 80),
            ]);
        }

        return response()->stream(function () use ($deepSeek, $validated, $context, $conversation, $ws, $history) {
            $ws->chatThinking($conversation->id, false);
            $fullResponse = '';

            foreach ($deepSeek->streamChat($validated['message'], $context, $conversation->system_prompt, $history) as $chunk) {
                $fullResponse .= $chunk;
                echo "data: " . json_encode(['chunk' => $chunk]) . "\n\n";
                ob_flush();
                flush();
            }

            // Save complete assistant response
            $conversation->messages()->create([
                'role' => 'assistant',
                'content' => $fullResponse,
            ]);

            $ws->dashboardStats(DashboardController::getStats());

            echo "data: [DONE]\n\n";
            ob_flush();
            flush();
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    public function destroyConversation(Conversation $conversation)
    {
        $conversation->delete();

        return redirect()->route('chat.index');
    }

    public function feedback(Request $request, Message $message)
    {
        $validated = $request->validate([
            'feedback' => 'required|in:up,down,null',
        ]);

        $message->update([
            'feedback' => $validated['feedback'] === 'null' ? null : $validated['feedback'],
        ]);

        return response()->json(['ok' => true]);
    }

    public function updateSystemPrompt(Request $request, Conversation $conversation)
    {
        $validated = $request->validate([
            'system_prompt' => 'nullable|string|max:2000',
        ]);

        $conversation->update([
            'system_prompt' => $validated['system_prompt'] ?: null,
        ]);

        return response()->json(['ok' => true]);
    }

    protected function trackTopicHits(array $results): void
    {
        $documentIds = array_unique(array_column($results, 'document_id'));
        if (empty($documentIds)) {
            return;
        }

        $topicIds = Document::whereIn('id', $documentIds)
            ->whereNotNull('topic_id')
            ->pluck('topic_id')
            ->unique()
            ->values()
            ->all();

        if (!empty($topicIds)) {
            Topic::whereIn('id', $topicIds)->increment('hits');
        }
    }
}
