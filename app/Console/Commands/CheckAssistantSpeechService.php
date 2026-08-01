<?php

namespace App\Console\Commands;

use App\Services\Ai\SpeechServiceClient;
use App\Services\Ai\SpeechServiceException;
use Illuminate\Console\Command;
use Illuminate\Http\UploadedFile;
use RuntimeException;
use Throwable;

class CheckAssistantSpeechService extends Command
{
    protected $signature = 'railtime:speech-service-status
        {--smoke : Fuehrt zusaetzlich einen echten TTS-zu-STT-Rundtest ueber den Dienst aus}';

    protected $description = 'Prüft den gemeinsamen lokalen TTS/STT-Dienst ohne Zugangsdaten oder Pfade auszugeben';

    /**
     * Die Engines, die der Dienst fuer einen vollstaendigen Sprachbetrieb
     * bereitstellen muss. ffmpeg und Whisper tragen STT, Piper traegt TTS.
     */
    private const REQUIRED_ENGINES = ['ffmpeg', 'whisper', 'piper'];

    public function handle(SpeechServiceClient $speech): int
    {
        if (! $speech->isConfigured()) {
            $this->components->error('Speech-Dienst ist in RailTime nicht konfiguriert.');

            return self::FAILURE;
        }

        try {
            $status = $speech->status();
        } catch (SpeechServiceException $exception) {
            $this->components->error('Speech-Dienst nicht erreichbar ('.$exception->reasonCode.').');

            return self::FAILURE;
        }

        $engines = is_array($status['engines'] ?? null) ? $status['engines'] : [];
        $rows = [];
        $missing = [];

        foreach (self::REQUIRED_ENGINES as $engine) {
            $state = $engines[$engine] ?? null;
            $ready = $state === true || $state === 'ready';

            if (! $ready) {
                $missing[] = $engine;
            }

            $rows[] = [$engine, $ready ? 'bereit' : 'nicht bereit'];
        }

        $this->line('Status: '.(string) ($status['status'] ?? 'unknown'));
        $this->table(['Engine', 'Zustand'], $rows);

        // Ein degradierter Dienst darf nicht als Erfolg durchgehen: dieser
        // Befehl ist die Abnahmepruefung nach dem Rollout und wird in
        // Skripten ueber den Exit-Code ausgewertet.
        if ($missing !== []) {
            $this->components->error('Nicht bereit: '.implode(', ', $missing).'.');

            return self::FAILURE;
        }

        $this->components->info('Speech-Dienst bereit.');

        if ((bool) $this->option('smoke') && ! $this->runSmokeTest($speech)) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * Erzeugt per TTS eine WAV-Datei und schickt sie unmittelbar zurueck durch
     * STT. Erst dieser Rundlauf beweist, dass ffmpeg, Whisper und Piper mit
     * den real hinterlegten Modellen zusammenarbeiten; der Status allein sagt
     * nur, dass die Binaries auffindbar sind.
     */
    private function runSmokeTest(SpeechServiceClient $speech): bool
    {
        $path = tempnam(sys_get_temp_dir(), 'railtime-speech-smoke-');

        if ($path === false) {
            $this->components->error('Temporaere WAV-Datei fuer den Rundtest konnte nicht angelegt werden.');

            return false;
        }

        try {
            $audio = $speech->synthesize('Hallo. Dies ist ein Test des RailTime Sprachdienstes.', 1.0)['audio'];

            if (! str_starts_with($audio, 'RIFF') || substr($audio, 8, 4) !== 'WAVE') {
                $this->components->error('TTS-Rundtest lieferte keine gueltige WAV-Datei.');

                return false;
            }

            if (file_put_contents($path, $audio, LOCK_EX) === false) {
                throw new RuntimeException('WAV-Datei konnte fuer den Rundtest nicht bereitgestellt werden.');
            }

            $transcript = $speech->transcribe(
                new UploadedFile($path, 'railtime-speech-smoke.wav', 'audio/wav', UPLOAD_ERR_OK, true),
            )['text'];

            $this->components->info('TTS: gueltige WAV-Datei ('.strlen($audio).' Bytes).');
            $this->components->info('STT: Text erkannt ('.mb_strlen($transcript).' Zeichen).');

            return true;
        } catch (SpeechServiceException $exception) {
            $this->components->error('Rundtest fehlgeschlagen ('.$exception->reasonCode.').');

            return false;
        } catch (Throwable) {
            $this->components->error('Rundtest ist unerwartet fehlgeschlagen.');

            return false;
        } finally {
            @unlink($path);
        }
    }
}
