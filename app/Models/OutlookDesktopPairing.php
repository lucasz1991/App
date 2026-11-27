<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Kopplung Outlook-Add-in ↔ RailTime-App auf einem PC. Nonce und Schlüssel liegen nur als Hash vor. */
class OutlookDesktopPairing extends Model
{
    use HasPublicUuid;

    protected $guarded = ['id'];

    protected $hidden = ['nonce_hash', 'key_hash'];

    protected $casts = ['expires_at' => 'datetime', 'confirmed_at' => 'datetime', 'paired_at' => 'datetime',
        'last_used_at' => 'datetime', 'revoked_at' => 'datetime'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(DeviceDesktopClient::class, 'client_id');
    }
}
