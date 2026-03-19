<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Conversation extends Model
{
    protected $fillable = ['topic_id', 'title', 'system_prompt'];

    public function topic(): BelongsTo
    {
        return $this->belongsTo(Topic::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }
}
