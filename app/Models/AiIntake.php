<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class AiIntake extends Model
{
    public const STATUSES = ['received', 'analyzing', 'review', 'waiting_customer', 'ready', 'paused', 'failed', 'completed'];

    protected $guarded = ['id'];

    protected $hidden = ['analysis'];

    protected $casts = [
        'revision' => 'integer', 'source_revision' => 'integer', 'processed_source_revision' => 'integer',
        'question_round' => 'integer', 'inquiry_ids' => 'array', 'missing_fields' => 'array',
        'analysis' => 'encrypted:array', 'confidence' => 'float', 'paused_at' => 'immutable_datetime',
        'last_analyzed_at' => 'immutable_datetime',
        'customer_id' => 'integer', 'customer_contact_id' => 'integer', 'supervising_user_id' => 'integer', 'latest_inbound_message_id' => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(fn (self $record) => $record->public_id ??= (string) Str::uuid());
    }

    public function messages() { return $this->hasMany(AiIntakeMessage::class, 'intake_id')->orderBy('id'); }
    public function latestInboundMessage() { return $this->hasOne(AiIntakeMessage::class, 'intake_id')->ofMany(['id' => 'max'], fn ($query) => $query->where('direction', 'inbound')); }
    public function attachments() { return $this->hasMany(AiIntakeAttachment::class, 'intake_id'); }
    public function runs() { return $this->hasMany(AiIntakeRun::class, 'intake_id'); }
    public function proposals() { return $this->hasMany(AiIntakeProposal::class, 'intake_id')->orderBy('position_index'); }
    public function deliveries() { return $this->hasMany(AiIntakeDelivery::class, 'intake_id'); }
    public function customer() { return $this->belongsTo(Customer::class); }
    public function contact() { return $this->belongsTo(CustomerContact::class, 'customer_contact_id'); }
    public function supervisor() { return $this->belongsTo(User::class, 'supervising_user_id'); }
}
