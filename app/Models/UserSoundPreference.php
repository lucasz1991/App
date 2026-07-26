<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Persoenliche Abweichung eines Nutzers von der systemweiten Ton-Zuordnung.
 * Nur bewusst geaenderte Ereignisse werden gespeichert (siehe SoundLibrary).
 */
class UserSoundPreference extends Model
{
    protected $fillable = ['user_id', 'event', 'signature'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
