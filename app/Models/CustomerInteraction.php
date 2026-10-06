<?php

namespace App\Models;

use App\Models\Concerns\HasUtcInstants;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

class CustomerInteraction extends Model
{
    use HasUtcInstants;

    public const CHANNELS = ['email' => 'E-Mail', 'phone' => 'Telefon', 'meeting' => 'Besprechung', 'note' => 'Notiz'];

    public const DIRECTIONS = ['inbound' => 'Eingehend', 'outbound' => 'Ausgehend', 'internal' => 'Intern'];

    protected $guarded = ['id'];

    protected $hidden = ['body'];

    protected $casts = ['body' => 'encrypted', 'occurred_at' => 'immutable_datetime'];

    public static function ready(): bool
    {
        return Schema::hasColumns('customer_interactions', ['customer_id', 'contact_id', 'order_id', 'inquiry_id', 'channel', 'direction', 'occurred_at', 'timezone', 'subject', 'body', 'created_by']);
    }

    protected function occurredAt(): Attribute
    {
        return $this->utcInstant();
    }

    protected static function booted(): void
    {
        static::updating(fn () => abort(403, 'Kommunikationseinträge bleiben unverändert.'));
        static::deleting(fn () => abort(403, 'Kommunikationseinträge bleiben erhalten.'));
    }
}
