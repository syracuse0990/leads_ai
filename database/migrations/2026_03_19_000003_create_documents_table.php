<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('topic_id')->constrained()->cascadeOnDelete();
            $table->string('filename');
            $table->string('original_name');
            $table->string('mime_type');
            $table->string('file_path');
            $table->unsignedBigInteger('file_size')->default(0);
            $table->string('status')->default('pending'); // pending, processing, completed, failed
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
