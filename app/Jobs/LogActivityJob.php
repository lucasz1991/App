<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;
use App\Models\User;

class LogActivityJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $user;
    public $requestData;

    /**
     * Erhält den User (oder null für Gäste) und ein Array mit Request-Daten.
     */
    public function __construct($user, array $requestData)
    {
        $this->user = $user;
        $this->requestData = $requestData;
    }

    /**
     * Führt den Job aus.
     */
    public function handle()
    {
        $user = $this->user;
        $data = $this->requestData;

        // SubjectType nur setzen, wenn $user nicht null ist
        $subjectType = $user ? get_class($user) : null;

        // Beschreibung anpassen: Admin, User oder Gast
        $description = $user
            ? ($user->isAdmin() ? 'Admin' : 'User')
            : 'Gast';

        // Event-Slug (z. B. GET-dashboard)
        $eventSlug = $data['method'].'-'.Str::slug($data['path']);

        // Beschreibungstext erstellen
        $descriptionText = sprintf(
            '%s - used URL - %s - %s ',
            $description,
            $data['full_url'],
            $data['method']
        );

        // Activity-Log schreiben
        activity()
            ->causedBy($user) // kann null sein für Gäste
            ->withProperties($data) // Hier das Array mit IP, Headers etc.
            ->tap(function ($activity) use ($subjectType, $eventSlug) {
                if ($subjectType) {
                    $activity->subject_type = $subjectType;
                }
                $activity->event = $eventSlug;
            })
            ->log($descriptionText);
    }
}
