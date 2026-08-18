<?php

namespace App\Models;

use App\Enums\DevicePlatform;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeviceArtifact extends Model
{
    use HasPublicUuid;

    protected $fillable = [
        'device_id',
        'device_provisioning_profile_id',
        'kind',
        'platform',
        'name',
        'original_name',
        'disk',
        'storage_path',
        'mime_type',
        'size_bytes',
        'sha256',
        'metadata',
        'uploaded_by',
        'approved_by',
        'approved_at',
    ];

    protected $hidden = [
        'storage_path',
        'metadata',
    ];

    protected $casts = [
        'platform' => DevicePlatform::class,
        'size_bytes' => 'integer',
        'metadata' => 'encrypted:array',
        'approved_at' => 'datetime',
    ];

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function provisioningProfile(): BelongsTo
    {
        return $this->belongsTo(DeviceProvisioningProfile::class, 'device_provisioning_profile_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
