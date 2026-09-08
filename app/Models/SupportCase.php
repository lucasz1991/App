<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SupportCase extends Model
{
    use HasPublicUuid;

    protected $guarded = ['id'];

    protected $hidden = ['diagnostics', 'request_hash'];

    protected $casts = ['subject' => 'encrypted', 'diagnostics' => 'encrypted:array', 'diagnostics_expires_at' => 'datetime', 'closed_at' => 'datetime'];

    public function messages(): HasMany { return $this->hasMany(SupportCaseMessage::class); }
    public function attachments(): HasMany { return $this->hasMany(SupportCaseAttachment::class); }
    public function sessions(): HasMany { return $this->hasMany(SupportRemoteSession::class); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function device(): BelongsTo { return $this->belongsTo(Device::class); }
}
