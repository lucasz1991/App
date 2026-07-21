<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChatMessageView extends Model
{
    protected $fillable = ['chat_message_id', 'user_id', 'viewed_at'];

    protected $casts = [
        'viewed_at' => 'datetime',
    ];

    public function message(): BelongsTo
    {
        return $this->belongsTo(ChatMessage::class, 'chat_message_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
