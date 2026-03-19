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
Classify this document into a topic.

{$topicList}

Rules:
1. If it fits an existing topic, return that EXACT topic name.
2. Otherwise create a short topic name (2-5 words, Title Case).
3. Return ONLY the topic name.

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
                    ['role' => 'system', 'content' => 'You are a document classification assistant. You only return a short topic name, nothing else.'],
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
                'content' => $baseInstruction . "You are a knowledgeable AI assistant for an organization's internal knowledge base. Your PRIMARY task is to answer questions using ONLY the provided document context below. Follow these rules strictly:\n\n1. Base your answer EXCLUSIVELY on the provided context. Do NOT use your general knowledge unless the context is completely insufficient.\n2. When the context contains relevant information, synthesize it into a clear, specific answer that references details from the documents.\n3. Always mention the source document name when citing information (e.g., 'According to [Source: filename]...').\n4. If the context does not contain enough information to fully answer the question, explicitly state: 'Based on the available documents, I don't have enough information to fully answer this.' Then provide what limited information is available from the context.\n5. Do NOT fabricate information or fill gaps with general knowledge.\n6. Keep answers focused and relevant to what the documents say.\n\nDocument Context:\n{$contextText}",
            ];
        } else {
            $messages[] = [
                'role' => 'system',
                'content' => $baseInstruction . "You are a helpful AI assistant for an organization's knowledge base. No documents have been found matching this query. Let the user know that no relevant documents were found and suggest they upload relevant documents or refine their question.",
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
