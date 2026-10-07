<?php

namespace App\Models;

use App\Models\Concerns\HasUtcInstants;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

class AiIntakeDelivery extends Model
{
    use HasUtcInstants;

    protected function attemptedAt(): Attribute
    {
        return $this->utcInstant();
    }

    protected function sentAt(): Attribute
    {
        return $this->utcInstant();
    }

    protected $guarded = ['id'];

    protected $hidden = ['recipient_email', 'body', 'metadata', 'dedup_key'];

    protected $casts = ['recipient_email' => 'encrypted', 'body' => 'encrypted', 'metadata' => 'encrypted:array', 'references' => 'array', 'settings_revision' => 'integer', 'intake_revision' => 'integer', 'source_revision' => 'integer', 'question_round' => 'integer', 'attempts' => 'integer', 'attempted_at' => 'immutable_datetime', 'sent_at' => 'immutable_datetime'];

    public function intake()
    {
        return $this->belongsTo(AiIntake::class, 'intake_id');
    }

    public function message()
    {
        return $this->belongsTo(AiIntakeMessage::class, 'message_id');
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function contact()
    {
        return $this->belongsTo(CustomerContact::class, 'contact_id');
    }
}
