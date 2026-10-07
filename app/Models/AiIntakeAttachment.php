<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiIntakeAttachment extends Model
{
    protected $guarded = ['id'];
    protected $hidden = ['file_path', 'extracted_text', 'metadata'];
    protected $casts = ['file_size' => 'integer', 'extracted_text' => 'encrypted', 'metadata' => 'encrypted:array'];

    public function intake() { return $this->belongsTo(AiIntake::class, 'intake_id'); }
    public function message() { return $this->belongsTo(AiIntakeMessage::class, 'message_id'); }
}
