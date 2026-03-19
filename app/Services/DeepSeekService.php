<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class DeepSeekService
{
    protected string $apiKey;
    protected string $baseUrl;
    protected string $model;

    public function __construct()
    {
        $this->apiKey = config('ai.deepseek_api_key');
        $this->baseUrl = config('ai.deepseek_base_url');
        $this->model = config('ai.deepseek_model');
    }

    /**
     * Generate a chat completion using DeepSeek V3.2.
     */
    public function chat(string $prompt, array $context = [], float $temperature = 1.0, ?string $systemPrompt = null, array $history = []): string
    {
        $messages = $this->buildMessages($prompt, $context, $systemPrompt, $history);

        $response = Http::withHeaders([
            'Authorization' => "Bearer {$this->apiKey}",
            'Content-Type' => 'application/json',
        ])->timeout(60)->post("{$this->baseUrl}/chat/completions", [
            'model' => $this->model,
            'messages' => $messages,
            'temperature' => $temperature,
        ]);

        if ($response->failed()) {
            Log::error('DeepSeek API error', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            throw new \RuntimeException('Failed to get response from DeepSeek API: ' . $response->body());
        }

        return $response->json('choices.0.message.content', '');
    }

    /**
     * Stream a chat completion response via SSE.
     */
    public function streamChat(string $prompt, array $context = [], ?string $systemPrompt = null, array $history = []): \Generator
    {
        $messages = $this->buildMessages($prompt, $context, $systemPrompt, $history);

        $response = Http::withHeaders([
            'Authorization' => "Bearer {$this->apiKey}",
            'Content-Type' => 'application/json',
        ])->timeout(120)->withOptions([
            'stream' => true,
        ])->post("{$this->baseUrl}/chat/completions", [
            'model' => $this->model,
            'messages' => $messages,
            'stream' => true,
        ]);

        $body = $response->toPsrResponse()->getBody();

        $buffer = '';
        while (!$body->eof()) {
            $buffer .= $body->read(1024);

            while (($pos = strpos($buffer, "\n")) !== false) {
                $line = trim(substr($buffer, 0, $pos));
                $buffer = substr($buffer, $pos + 1);

                if (str_starts_with($line, 'data: ')) {
                    $data = substr($line, 6);
                    if ($data === '[DONE]') {
                        return;
                    }
                    $json = json_decode($data, true);
                    $content = $json['choices'][0]['delta']['content'] ?? '';
                    if ($content !== '') {
                        yield $content;
                    }
                }
            }
        }
    }

    /**
     * Classify document text into a topic name.
     */
    public function classifyTopic(string $text, array $existingTopics = []): string
    {
        $cleanText = preg_replace('/[^\P{C}\n\t]/u', '', $text);
        $cleanText = mb_substr($cleanText, 0, 1500);

        if (mb_strlen(trim($cleanText)) < 20) {
            return 'General';
        }

        $topicList = !empty($existingTopics)
            ? "Existing topics:\n" . implode("\n", array_map(fn($t) => "- {$t}", $existingTopics))
            : 'No existing topics yet.';

        $prompt = <<<PROMPT
Classify this document into a specific topic.

{$topicList}

Rules:
1. Read the document text carefully and determine its MAIN subject.
2. Only reuse an existing topic if the document is CLEARLY about the same specific subject. Do NOT force a document into an existing topic just because of loose similarity.
3. If the document doesn't clearly fit any existing topic, create a NEW short topic name (2-5 words, Title Case) that accurately describes the document's content.
4. Be specific — prefer "Rice Pest Management" over a generic "Agriculture" topic.
5. Return ONLY the topic name, nothing else.

Text:
{$cleanText}
PROMPT;

        $response = null;
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $response = Http::withHeaders([
                'Authorization' => "Bearer {$this->apiKey}",
                'Content-Type' => 'application/json',
            ])->timeout(30)->post("{$this->baseUrl}/chat/completions", [
                'model' => $this->model,
                'messages' => [
                    ['role' => 'system', 'content' => 'You are a document classification assistant. You only return a short, specific topic name that accurately describes the document content. Do NOT force documents into existing topics unless they are truly about the same subject. Return only the topic name, nothing else.'],
                    ['role' => 'user', 'content' => $prompt],
                ],
                'temperature' => 0.3,
            ]);

            if ($response->successful()) {
                break;
            }

            if ($response->status() === 429 && $attempt < 3) {
                sleep(3 * $attempt);
                continue;
            }

            break;
        }

        if ($response->failed()) {
            Log::warning('DeepSeek topic classification failed, using fallback', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            return 'General';
        }

        $topicName = trim($response->json('choices.0.message.content', 'General'));
        $topicName = trim($topicName, '"\'\'`');
        $topicName = mb_substr($topicName, 0, 100);

        return $topicName ?: 'General';
    }

    protected function buildMessages(string $prompt, array $context, ?string $systemPrompt = null, array $history = []): array
    {
        $messages = [];

        $baseInstruction = $systemPrompt
            ? $systemPrompt . "\n\n"
            : '';

        if (!empty($context)) {
            $contextText = implode("\n\n---\n\n", $context);
            $messages[] = [
                'role' => 'system',
                'content' => $baseInstruction . "You are \"AgriTulong AI\", a friendly and knowledgeable farming assistant built for Filipino farmers. Your expertise covers Philippine agriculture: crop pests and diseases, weed management, plant health assessment, soil care, harvest timing, organic and chemical treatments, and practical farming recommendations.\n\nLANGUAGE RULES (VERY IMPORTANT):\n- ALWAYS reply in the SAME language the farmer uses.\n- If the farmer writes in Tagalog, answer in Tagalog.\n- If the farmer writes in Bisaya/Cebuano, answer in Bisaya/Cebuano.\n- If the farmer writes in Ilocano, Hiligaynon, Waray, Pangasinan, or any other Filipino language, reply in that same language.\n- If the farmer writes in English, answer in English.\n- You may mix languages (Taglish, etc.) if the farmer does so.\n\nCOMMUNICATION STYLE:\n- Use simple, everyday words that farmers can easily understand. Avoid technical jargon — if you must use a scientific term, explain it in simple words (e.g., \"Bacterial Leaf Blight (BLB) — ito yung sakit ng dahon na nagiging dilaw at natutuyo\").\n- Be warm, encouraging, and respectful. Treat every question as important.\n- Give practical, actionable advice that farmers can apply right away.\n- When recommending products or treatments, mention locally available options in the Philippines.\n- Use bullet points or numbered steps for instructions so they are easy to follow.\n\nANSWERING RULES:\n1. Base your answer PRIMARILY on the provided document context below.\n2. When the context contains relevant information, synthesize it into a clear, practical answer. Mention the source document when citing (e.g., 'Ayon sa [Source: filename]...').\n3. If the documents don't fully cover the question but it's a common Philippine farming topic, you may supplement with general agricultural knowledge — but clearly distinguish what comes from documents vs. general advice.\n4. If you truly don't have enough information, say so kindly and suggest the farmer ask their local agricultural technician or municipal agriculture office.\n5. Do NOT fabricate specific data (yields, prices, exact dosages) that aren't in the documents.\n6. For pest/disease identification from images, describe what you see and give treatment options.\n\nDocument Context:\n{$contextText}",
            ];
        } else {
            $messages[] = [
                'role' => 'system',
                'content' => $baseInstruction . "You are \"AgriTulong AI\", a friendly farming assistant for Filipino farmers. You speak the same language as the farmer (Tagalog, Bisaya, Ilocano, English, etc.).\n\nNo relevant documents were found for this question. Kindly let the farmer know in their language that you don't have uploaded documents matching their question yet. Suggest they:\n- Upload relevant farming guides, manuals, or reference documents\n- Try rephrasing their question\n- Consult their local agricultural technician (AT) or municipal agriculture office (MAO) for specific local advice\n\nBe warm and helpful. If it's a very common, basic farming question about Philippine agriculture, you may provide brief general guidance while noting that more specific documents would help you give better answers.",
            ];
        }

        // Append recent conversation history for multi-turn context
        foreach ($history as $msg) {
            $messages[] = [
                'role' => $msg['role'],
                'content' => $msg['content'],
            ];
        }

        $messages[] = [
            'role' => 'user',
            'content' => $prompt,
        ];

        return $messages;
    }
}
