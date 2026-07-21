<?php

namespace App\Models;

use App\Jobs\ProcessMailJob;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Mail extends Model
{
    protected $fillable = [
        'type', 'status', 'content', 'recipients',
    ];

    protected $casts = [
        'status' => 'boolean',
        'content' => 'json',
        'recipients' => 'json',
    ];

    /**
     * Event-Listener fuer das "created"-Ereignis:
     * Jede neu angelegte Mail wird automatisch ueber den Job-Flow verarbeitet.
     */
    protected static function boot()
    {
        parent::boot();

        static::created(function ($mail) {
            ProcessMailJob::dispatch($mail);
        });
    }

    /**
     * Alle angehaengten Dateien dieser Mail.
     */
    public function files(): MorphMany
    {
        return $this->morphMany(\App\Models\File::class, 'fileable');
    }
}
