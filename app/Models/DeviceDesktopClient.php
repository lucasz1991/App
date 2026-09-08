<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DeviceDesktopClient extends Model
{
    use HasPublicUuid;

    protected $guarded = ['id'];

    protected $hidden = ['token_hash', 'policy', 'last_report'];

    protected $casts = ['policy' => 'encrypted:array', 'last_report' => 'encrypted:array',
        'last_seen_at' => 'datetime', 'revoked_at' => 'datetime', 'policy_revision' => 'integer'];

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(DeviceAssignment::class, 'device_assignment_id');
    }

    public function jobs(): HasMany
    {
        return $this->hasMany(DeviceDesktopJob::class, 'client_id');
    }
}
