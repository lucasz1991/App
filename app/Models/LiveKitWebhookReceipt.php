<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LiveKitWebhookReceipt extends Model
{
    protected $fillable = [
        'event_id',
        'event_type',
        'received_at',
    ];

    protected $casts = [
        'received_at' => 'datetime',
    ];
}
