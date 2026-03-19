<?php

namespace App\Providers;

use App\Services\ChunkingService;
use App\Services\DeepSeekService;
use App\Services\EmbeddingService;
use App\Services\KimiService;
use App\Services\TextExtractorService;
use App\Services\VectorSearchService;
use App\Services\WebSocketService;
use Illuminate\Support\ServiceProvider;

class AiServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(KimiService::class);
        $this->app->singleton(DeepSeekService::class);
        $this->app->singleton(EmbeddingService::class);
        $this->app->singleton(TextExtractorService::class);
        $this->app->singleton(ChunkingService::class);
        $this->app->singleton(VectorSearchService::class);
        $this->app->singleton(WebSocketService::class);
    }

    public function boot(): void
    {
        //
    }
}
