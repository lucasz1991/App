<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Weist ein Paket einem Gerät, einem Arbeitsplatzprofil oder allen Firmengeräten zu. */
class DeviceSoftwareAssignment extends Model
{
    use HasPublicUuid;

    public const TARGET_CORPORATE = 'corporate';

    public const TARGET_PROFILE = 'profile';

    public const TARGET_DEVICE = 'device';

    protected $guarded = ['id'];

    public function package(): BelongsTo
    {
        return $this->belongsTo(DeviceSoftwarePackage::class, 'software_package_id');
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }
}
