<?php

namespace App\Models;

use App\Models\Concerns\HasUtcInstants;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

class AiIntakeDelivery extends Model
{
    use HasUtcInstants;

    public const TYPE_LABELS = ['receipt' => 'Eingangsbestätigung', 'clarification' => 'Rückfrage', 'order_confirmation' => 'Auftragsbestätigung'];

    public const STATUS_LABELS = ['draft' => 'Persönliche Nachrichtenfreigabe ausstehend', 'pending' => 'Versand ausstehend', 'sending' => 'Versand gestartet', 'sent' => 'Gesendet', 'canceled' => 'Versand aufgehoben', 'unknown' => 'Versandausgang prüfen'];

    protected function attemptedAt(): Attribute
    {
        return $this->utcInstant();
    }

    protected function sentAt(): Attribute
    {
        return $this->utcInstant();
    }

    protected function approvedAt(): Attribute
    {
        return $this->utcInstant();
    }

    protected $guarded = ['id'];

    protected $hidden = ['recipient_email', 'body', 'metadata', 'dedup_key', 'order_fingerprint'];

    protected $casts = ['recipient_email' => 'encrypted', 'body' => 'encrypted', 'metadata' => 'encrypted:array', 'references' => 'array', 'settings_revision' => 'integer', 'intake_revision' => 'integer', 'source_revision' => 'integer', 'question_round' => 'integer', 'attempts' => 'integer', 'attempted_at' => 'immutable_datetime', 'sent_at' => 'immutable_datetime', 'approved_by' => 'integer', 'approved_at' => 'immutable_datetime', 'order_id' => 'integer', 'approval_audit_id' => 'integer'];

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

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
