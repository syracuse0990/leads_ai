<?php

namespace App\Console\Commands;

use App\Models\Document;
use App\Models\DocumentChunk;
use App\Models\Topic;
use App\Services\DeepSeekService;
use App\Services\TextExtractorService;
use App\Services\WebSocketService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class ReclassifyDocuments extends Command
{
    protected $signature = 'documents:reclassify {--force : Reclassify all documents, not just those under General}';
    protected $description = 'Reclassify documents that were assigned to the General topic';

    public function handle(DeepSeekService $deepSeek, TextExtractorService $extractor, WebSocketService $ws): int
    {
        $query = Document::where('status', 'completed');

        if (!$this->option('force')) {
            $generalTopic = Topic::where('name', 'General')->first();
            if (!$generalTopic) {
                $this->info('No "General" topic found — nothing to reclassify.');
                return 0;
            }
            $query->where('topic_id', $generalTopic->id);
        }

        $documents = $query->get();

        if ($documents->isEmpty()) {
            $this->info('No documents to reclassify.');
            return 0;
        }

        $total = $documents->count();
        $this->info("Found {$total} document(s) to reclassify.");

        foreach ($documents as $index => $document) {
            $this->line("Processing: {$document->original_name}");
            $ws->reclassifyProgress($index + 1, $total, $document->original_name);

            try {
                $filePath = Storage::disk('local')->path($document->file_path);
                $text = $extractor->extract($filePath, $document->mime_type);
                $textSample = mb_substr($text, 0, 2000);

                $existingTopics = Topic::where('name', '!=', 'General')->pluck('name')->toArray();
                $topicName = $deepSeek->classifyTopic($textSample, $existingTopics);

                if ($topicName === 'General') {
                    $this->warn("  Still classified as General — skipping.");
                    continue;
                }

                $topic = Topic::firstOrCreate(
                    ['name' => $topicName],
                    ['description' => 'Auto-classified from uploaded documents.']
                );

                if ($topic->wasRecentlyCreated) {
                    $ws->topicCreated([
                        'id' => $topic->id,
                        'name' => $topic->name,
                        'description' => $topic->description,
                        'documents_count' => 0,
                        'chunks_count' => 0,
                    ]);
                }

                $document->update(['topic_id' => $topic->id]);
                DocumentChunk::where('document_id', $document->id)->update(['topic_id' => $topic->id]);

                $ws->reclassifyProgress($index + 1, $total, $document->original_name, $topicName);
                $this->info("  → Reclassified to: {$topicName}");

            } catch (\Throwable $e) {
                $this->error("  Error: {$e->getMessage()}");
            }
        }

        // Clean up General topic if empty
        $generalTopic = Topic::where('name', 'General')->first();
        if ($generalTopic && $generalTopic->documents()->count() === 0) {
            $generalTopic->delete();
            $this->info('Removed empty "General" topic.');
        }

        $ws->toast('success', 'Reclassification Complete', "{$total} document(s) reclassified.");
        $ws->dashboardStats([
            'topics' => Topic::count(),
            'documents' => Document::count(),
            'chunks' => DocumentChunk::count(),
        ]);

        $this->info('Done.');
        return 0;
    }
}
