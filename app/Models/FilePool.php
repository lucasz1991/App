<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class FilePool extends Model
{
    protected $fillable = [
        'title',
        'type',
        'description',
        'filepoolable_type',
        'filepoolable_id',
    ];

    /**
     * Polymorphe Beziehung z. B. zu User, Course, etc.
     */
    public function filepoolable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Alle Dateien in diesem Pool
     */
    public function files(): MorphMany
    {
        return $this->morphMany(\App\Models\File::class, 'fileable');
    }

    /**
     * Firmenweiter Pool fuer die zentrale Dateiverwaltung.
     * Dateien daraus werden ueber shared_roles rollenbasiert
     * an Mitarbeiter/Benutzer freigegeben.
     */
    public static function company(): self
    {
        return static::firstOrCreate(
            [
                'filepoolable_type' => 'company',
                'filepoolable_id' => 0,
            ],
            [
                'title' => 'Firmendateien',
                'type' => 'company',
                'description' => '',
            ]
        );
    }
}
