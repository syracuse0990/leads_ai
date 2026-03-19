<?php

use App\Http\Controllers\ChatController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\TopicController;
use Illuminate\Support\Facades\Route;

Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

// Topics
Route::get('/topics', [TopicController::class, 'index'])->name('topics.index');
Route::post('/topics', [TopicController::class, 'store'])->name('topics.store');
Route::put('/topics/{topic}', [TopicController::class, 'update'])->name('topics.update');
Route::delete('/topics/{topic}', [TopicController::class, 'destroy'])->name('topics.destroy');

// Documents
Route::get('/documents', [DocumentController::class, 'index'])->name('documents.index');
Route::get('/documents/upload', [DocumentController::class, 'create'])->name('documents.create');
Route::post('/documents', [DocumentController::class, 'store'])->name('documents.store');
Route::post('/documents/url', [DocumentController::class, 'storeUrl'])->name('documents.storeUrl');
Route::delete('/documents/{document}', [DocumentController::class, 'destroy'])->name('documents.destroy');

// Chat
Route::get('/chat', [ChatController::class, 'index'])->name('chat.index');
Route::post('/chat', [ChatController::class, 'startConversation'])->name('chat.start');
Route::get('/chat/{conversation}', [ChatController::class, 'show'])->name('chat.show');
Route::post('/chat/{conversation}/message', [ChatController::class, 'sendMessage'])->name('chat.message');
Route::post('/chat/{conversation}/stream', [ChatController::class, 'stream'])->name('chat.stream');
Route::delete('/chat/{conversation}', [ChatController::class, 'destroyConversation'])->name('chat.destroy');
Route::post('/chat/{conversation}/system-prompt', [ChatController::class, 'updateSystemPrompt'])->name('chat.systemPrompt');
Route::post('/messages/{message}/feedback', [ChatController::class, 'feedback'])->name('messages.feedback');

// API: Document status check (for polling fallback)
Route::get('/api/documents/status', function (\Illuminate\Http\Request $request) {
    $ids = explode(',', $request->query('ids', ''));
    $ids = array_filter($ids, fn($id) => is_numeric($id));
    if (empty($ids)) {
        return response()->json([]);
    }
    return response()->json(
        \App\Models\Document::whereIn('id', $ids)
            ->get(['id', 'status'])
            ->keyBy('id')
    );
})->name('documents.status');

// API: Queue stats
Route::get('/api/queue-stats', function () {
    return response()->json([
        'pending' => \App\Models\Document::where('status', 'pending')->count(),
        'processing' => \App\Models\Document::where('status', 'processing')->count(),
        'completed' => \App\Models\Document::where('status', 'completed')->count(),
        'failed' => \App\Models\Document::where('status', 'failed')->count(),
    ]);
})->name('queue.stats');
