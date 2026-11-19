<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Ein freigegebenes WinGet-Paket mit festgelegter Version im Programmverzeichnis. */
class DeviceSoftwarePackage extends Model
{
    use HasPublicUuid;

    protected $guarded = ['id'];

    protected $casts = ['active' => 'boolean', 'agreements_accepted_at' => 'datetime'];

    public function assignments(): HasMany
    {
        return $this->hasMany(DeviceSoftwareAssignment::class, 'software_package_id');
    }

    public function states(): HasMany
    {
        return $this->hasMany(DeviceDesktopSoftwareState::class, 'software_package_id');
    }
}
