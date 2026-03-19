<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * KimiService — used exclusively for image/vision processing.
 * Text chat and classification are handled by DeepSeekService (cheaper).
 */
class KimiService
{
    protected string $apiKey;
    protected string $baseUrl;
    protected string $model;

    public function __construct()
    {
        $this->apiKey = config('ai.kimi_api_key');
        $this->baseUrl = config('ai.kimi_base_url');
        $this->model = config('ai.kimi_model');
    }

    /**
     * Extract text from an image using KIMI k2.5 multimodal vision.
     */
    public function extractTextFromImage(string $filePath): string
    {
        $imageData = base64_encode(file_get_contents($filePath));
        $mimeType = mime_content_type($filePath) ?: 'image/png';

        $response = null;
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $response = Http::withHeaders([
                'Authorization' => "Bearer {$this->apiKey}",
                'Content-Type' => 'application/json',
            ])->timeout(120)->post("{$this->baseUrl}/chat/completions", [
                'model' => $this->model,
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => 'You are a document text extraction assistant. Extract ALL text content from the image exactly as it appears. Preserve the structure, headings, bullet points, and formatting. Return only the extracted text, nothing else.',
                    ],
                    [
                        'role' => 'user',
                        'content' => [
                            [
                                'type' => 'image_url',
                                'image_url' => [
                                    'url' => "data:{$mimeType};base64,{$imageData}",
                                ],
                            ],
                            [
                                'type' => 'text',
                                'text' => 'Extract all the text from this image. Return only the text content.',
                            ],
                        ],
                    ],
                ],
                'temperature' => 1.0,
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
            Log::error('KIMI vision extraction failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            throw new \RuntimeException('Failed to extract text from image via KIMI vision: ' . $response->body());
        }

        return $response->json('choices.0.message.content', '');
    }
}
