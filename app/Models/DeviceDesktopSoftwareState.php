<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Vom Desktopclient gemeldeter Stand eines Verzeichnispakets auf einem Gerät. */
class DeviceDesktopSoftwareState extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['installed' => 'boolean', 'reported_at' => 'datetime',
        'next_attempt_at' => 'datetime', 'attempts' => 'integer'];

    public function client(): BelongsTo
    {
        return $this->belongsTo(DeviceDesktopClient::class, 'client_id');
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(DeviceSoftwarePackage::class, 'software_package_id');
    }
}
