<?php

return [

    /*
    |--------------------------------------------------------------------------
    | KIMI 2.5 (Moonshot AI) — Vision/Image Processing
    |--------------------------------------------------------------------------
    */

    'kimi_api_key' => env('KIMI_API_KEY'),
    'kimi_base_url' => env('KIMI_BASE_URL', 'https://api.moonshot.ai/v1'),
    'kimi_model' => env('KIMI_CHAT_MODEL', 'kimi-k2.5'),

    /*
    |--------------------------------------------------------------------------
    | DeepSeek V3.2 — Text Chat & Classification (cost-effective)
    |--------------------------------------------------------------------------
    */

    'deepseek_api_key' => env('DEEPSEEK_API_KEY'),
    'deepseek_base_url' => env('DEEPSEEK_BASE_URL', 'https://api.deepseek.com'),
    'deepseek_model' => env('DEEPSEEK_CHAT_MODEL', 'deepseek-chat'),

    'embedding_dimensions' => env('EMBEDDING_DIMENSIONS', 384),

    /*
    |--------------------------------------------------------------------------
    | Embedding Server (sentence-transformers microservice)
    |--------------------------------------------------------------------------
    */

    'embedding_server_url' => env('EMBEDDING_SERVER_URL', 'http://127.0.0.1:9500'),

    /*
    |--------------------------------------------------------------------------
    | Chunking Configuration
    |--------------------------------------------------------------------------
    */

    'chunk_size' => env('CHUNK_SIZE', 500),        // tokens per chunk
    'chunk_overlap' => env('CHUNK_OVERLAP', 50),    // overlap between chunks

    /*
    |--------------------------------------------------------------------------
    | Vector Search Configuration
    |--------------------------------------------------------------------------
    */

    'search_top_k' => env('SEARCH_TOP_K', 5),      // number of nearest chunks to retrieve
    'similarity_threshold' => env('SIMILARITY_THRESHOLD', 0.3),

];
