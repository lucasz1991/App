<?php

namespace App\Console\Commands;

use App\Services\Ai\SpeechServiceClient;
use App\Services\Ai\SpeechServiceException;
use Illuminate\Console\Command;

class CheckAssistantSpeechService extends Command
{
    protected $signature = 'railtime:speech-service-status {--smoke : Erzeugt zusätzlich eine kurze TTS-Ausgabe im Speicher}';

    protected $description = 'Prüft den gemeinsamen lokalen TTS/STT-Dienst ohne Zugangsdaten oder Pfade auszugeben';

    public function handle(SpeechServiceClient $speech): int
    {
        try {
            $status = $speech->status();
            $this->components->info('Speech-Dienst erreichbar.');
            $this->line('Status: '.(string) ($status['status'] ?? 'unknown'));

            foreach ((array) ($status['engines'] ?? []) as $engine => $ready) {
                if (! in_array($engine, ['ffmpeg', 'whisper', 'piper'], true)) {
                    continue;
                }

                $isReady = $ready === true || $ready === 'ready';
                $this->line(sprintf('%s: %s', $engine, $isReady ? 'bereit' : 'nicht bereit'));
            }

            if ($this->option('smoke')) {
                $audio = $speech->synthesize('RailTime Sprachdienst Test.', 1.0)['audio'];
                if (! str_starts_with($audio, 'RIFF')) {
                    $this->components->error('TTS-Smoke-Test lieferte keine gültige WAV-Datei.');

                    return self::FAILURE;
                }

                $this->components->info('TTS-Smoke-Test erfolgreich.');
            }

            return self::SUCCESS;
        } catch (SpeechServiceException $exception) {
            $this->components->error('Speech-Dienst nicht bereit ('.$exception->reasonCode.').');

            return self::FAILURE;
        }
    }
}
