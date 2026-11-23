<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/** Öffentlicher Teil eines Herausgeber-Zertifikats, dem die RailTime-App auf Firmengeräten vertrauen lässt. */
class DeviceTrustCertificate extends Model
{
    use HasPublicUuid;

    protected $guarded = ['id'];

    protected $casts = ['active' => 'boolean', 'self_signed' => 'boolean', 'valid_from' => 'datetime', 'valid_until' => 'datetime'];

    /** Versionen verbindlicher Dateien, die mit diesem Zertifikat signiert sind. */
    public function documentVersions(): BelongsToMany
    {
        return $this->belongsToMany(ManagedDocumentVersion::class, 'device_trust_certificate_version')->withTimestamps();
    }

    /** Fingerabdruck in der Schreibweise, die Windows anzeigt. */
    public function getThumbprintLabelAttribute(): string
    {
        return strtoupper(trim(chunk_split((string) $this->thumbprint_sha1, 4, ' ')));
    }
}
