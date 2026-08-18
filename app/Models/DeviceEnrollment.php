<?php

namespace App\Models;

use App\Enums\DeviceEnrollmentStatus;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeviceEnrollment extends Model
{
    use HasPublicUuid;

    protected $fillable = [
        'device_id',
        'user_id',
        'provider',
        'mode',
        'status',
        'invited_at',
        'expires_at',
        'claimed_at',
        'completed_at',
        'revoked_at',
        'created_by',
        'metadata',
    ];

    protected $hidden = [
        'token_hash',
        'metadata',
    ];

    protected $casts = [
        'status' => DeviceEnrollmentStatus::class,
        'invited_at' => 'datetime',
        'expires_at' => 'datetime',
        'claimed_at' => 'datetime',
        'completed_at' => 'datetime',
        'revoked_at' => 'datetime',
        'metadata' => 'encrypted:array',
    ];

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function setPlainToken(string $token): self
    {
        $this->token_hash = hash('sha256', $token);

        return $this;
    }

    public function matchesPlainToken(string $token): bool
    {
        return is_string($this->token_hash)
            && hash_equals($this->token_hash, hash('sha256', $token));
    }

    public function scopeUsable(Builder $query): Builder
    {
        return $query
            ->whereIn('status', [
                DeviceEnrollmentStatus::Invited->value,
                DeviceEnrollmentStatus::Claimed->value,
            ])
            ->where('expires_at', '>', now())
            ->whereNull('revoked_at');
    }
}
