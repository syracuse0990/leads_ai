<?php

namespace App\Http\Controllers;

use App\Models\Conversation;
use App\Models\DocumentChunk;
use App\Models\Document;
use App\Models\Message;
use App\Models\Topic;
use Inertia\Inertia;

class DashboardController extends Controller
{
    public function index()
    {
        return Inertia::render('Dashboard', [
            'stats' => self::getStats(),
            'topicHits' => self::getTopicHits(),
        ]);
    }

    public static function getStats(): array
    {
        return [
            'topics' => Topic::count(),
            'documents' => Document::count(),
            'chunks' => DocumentChunk::count(),
            'conversations' => Conversation::count(),
            'active_chats' => Conversation::whereHas('messages', function ($q) {
                $q->where('created_at', '>=', now()->subMinutes(5));
            })->count(),
        ];
    }

    public static function getTopicHits(): array
    {
        return Topic::where('hits', '>', 0)
            ->orderByDesc('hits')
            ->limit(15)
            ->get(['id', 'name', 'hits'])
            ->toArray();
    }
}
