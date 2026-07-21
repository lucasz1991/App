<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChatMessage extends Model
{
    protected $fillable = ['chat_id', 'user_id', 'body'];

    /**
     * Chat-Inhalte werden mit dem App-Key verschlüsselt gespeichert.
     */
    protected function casts(): array
    {
        return [
            'body' => 'encrypted',
        ];
    }

    public function chat(): BelongsTo
    {
        return $this->belongsTo(Chat::class);
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
