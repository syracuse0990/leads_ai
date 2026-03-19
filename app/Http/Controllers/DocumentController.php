<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessDocument;
use App\Models\Document;
use App\Models\Topic;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

class DocumentController extends Controller
{
    public function index(Request $request)
    {
        $documents = Document::with('topic')
            ->when($request->topic_id, fn($q, $topicId) => $q->where('topic_id', $topicId))
            ->when($request->status, fn($q, $status) => $q->where('status', $status))
            ->when($request->search, fn($q, $search) => $q->where('original_name', 'ilike', '%' . $search . '%'))
            ->latest()
            ->paginate(20)
            ->withQueryString();

        $topics = Topic::orderBy('name')->get(['id', 'name']);

        return Inertia::render('Documents/Index', [
            'documents' => $documents,
            'topics' => $topics,
            'filters' => $request->only('topic_id', 'status', 'search'),
        ]);
    }

    public function create()
    {
        return Inertia::render('Documents/Upload');
    }

    public function store(Request $request)
    {
        $request->validate([
            'files' => 'required|array|min:1',
            'files.*' => 'required|file|max:51200|mimes:pdf,png,jpg,jpeg,gif,webp,txt,md,csv,doc,docx,xls,xlsx',
        ]);

        $uploaded = [];

        foreach ($request->file('files') as $file) {
            $filename = time() . '_' . $file->hashName();
            $path = $file->storeAs('documents', $filename, 'local');

            $document = Document::create([
                'topic_id' => null,
                'filename' => $filename,
                'original_name' => $file->getClientOriginalName(),
                'mime_type' => $file->getMimeType(),
                'file_path' => $path,
                'file_size' => $file->getSize(),
                'status' => 'pending',
            ]);

            ProcessDocument::dispatch($document);
            $uploaded[] = $document;
        }

        return redirect()->back()->with('uploadedDocuments', collect($uploaded)->map(fn($d) => [
            'id' => $d->id,
            'name' => $d->original_name,
        ])->toArray());
    }

    public function destroy(Document $document)
    {
        Storage::disk('local')->delete($document->file_path);
        $document->delete();

        return redirect()->route('documents.index');
    }
}
