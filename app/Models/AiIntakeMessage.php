<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiIntakeMessage extends Model
{
    protected $guarded = ['id'];
    protected $hidden = ['body', 'raw_path', 'metadata', 'sender_email', 'reply_to_email'];
    protected $casts = ['body' => 'encrypted', 'sender_email' => 'encrypted', 'reply_to_email' => 'encrypted', 'metadata' => 'encrypted:array'];

    public function intake() { return $this->belongsTo(AiIntake::class, 'intake_id'); }
    public function attachments() { return $this->hasMany(AiIntakeAttachment::class, 'message_id'); }

    protected static function booted(): void
    {
        static::updating(fn () => abort(403, 'Originalnachrichten bleiben unverändert.'));
        static::deleting(fn () => abort(403, 'Originalnachrichten bleiben erhalten.'));
    }
}
