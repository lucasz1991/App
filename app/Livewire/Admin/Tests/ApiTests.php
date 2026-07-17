<?php

namespace App\Livewire\Admin\Tests;

use Livewire\Component;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use App\Services\ApiUvs\ApiUvsService;
use App\Models\Setting;

class ApiTests extends Component
{
    // Test-Parameter (Admin ändert das oben in der UI)
    public string $email = 'kevin@domain.de';
    public string $participantId = '0035645';      // UVS-Teilnehmer-ID (für Qualiprogram)
    public string $personId = '1-0035645';       // "{institut_id}-{person_nr}"
    public string $courseClassId = '25V20-1-VBMG51';             // Beispielklasse

    public string $sqlQuery = "SELECT person_id, nachname, vorname FROM person WHERE institut_id = 1 ORDER BY nachname";


    // Such-/Listen-Parameter
    public ?string $searchTerm = null;
    public ?int $limit = 10;
    public ?string $from = null;  // Y-m-d
    public ?string $to   = null;  // Y-m-d
    public ?string $sort = null;  // z.B. "bezeichnung"
    public ?string $order = 'asc';

    // Laufzeit
    public bool $useFake = false;     // Stub-Antworten statt echter API
    public bool $running = false;
    public array $results = [];       // key => Ergebnis

    protected $queryString = [
        'useFake' => ['except' => false],
    ];

    // ---- UI: Liste der Tests (Keys) ----
    public function testList(): array
    {
        return [
            ['key' => 'participant_by_mail',      'name' => 'Teilnehmer per E-Mail'],
            ['key' => 'participant_qualiprogram', 'name' => 'Teilnehmer + Qualiprogram (ID)'],
            ['key' => 'person_status',            'name' => 'Person-Status (person_id)'],
            ['key' => 'tutor_program_by_person',  'name' => 'Tutor-Programm (person_id)'],
            ['key' => 'course_classes',           'name' => 'Kurs/Klassen Liste (Filter)'],
            ['key' => 'course_class_participants','name' => 'Teilnehmer einer Klasse'],
            ['key' => 'course_by_klassen_id',      'name' => 'Kurs (by klassen_id)'],
        ];
    }

    public function mount(): void
    {
        $this->results = Cache::get($this->cacheKey(), []);
    }

    public function runAll(): void
    {
        $this->running = true;
        foreach ($this->testList() as $t) {
            $this->runOne($t['key']);
        }
        $this->running = false;
        $this->dispatch('notify', type: 'success', message: 'Alle Tests ausgeführt.');
        $this->render();
    }

    public function runOne(string $key): void
    {
        // Optional: Fake aktivieren
        if ($this->useFake) {
            $this->installFakes();
        }

        $svc = app(ApiUvsService::class);

        $start = microtime(true);
        try {
            $resp = match ($key) {
                'participant_by_mail'       => $svc->getParticipantbyMail($this->email),
                'participant_qualiprogram'  => $svc->getParticipantAndQualiprogrambyId($this->personId),
                'person_status'             => $svc->getPersonStatus($this->personId),
                'tutor_program_by_person'   => $svc->getTutorProgramDataByPersonId($this->personId),
                'course_classes'            => $svc->getCourseClasses(
                                                $this->nullIfEmpty($this->searchTerm),
                                                $this->limit ?: null,
                                                $this->nullIfEmpty($this->from),
                                                $this->nullIfEmpty($this->to),
                                                $this->nullIfEmpty($this->sort),
                                                $this->nullIfEmpty($this->order),
                                              ),
                'course_class_participants' => $svc->getCourseClassParticipants($this->courseClassId),
                'course_by_klassen_id'      => $svc->getCourseByKlassenId($this->courseClassId),
                default                     => ['ok' => false, 'status' => null, 'message' => "Unbekannter Test: {$key}"],
            };

            $ms = (int) round((microtime(true) - $start) * 1000);

            $ok      = (bool)($resp['ok'] ?? false);
            $status  = $resp['status'] ?? null;
            $message = $resp['message'] ?? ($ok ? 'OK' : 'Fehler');
            $data    = $resp['data'] ?? null;

            $this->results[$key] = [
                'ok'         => $ok,
                'status'     => $status,
                'duration'   => $ms,
                'message'    => $message,
                'preview'    => $this->preview($data),
                'timestamp'  => now()->format('Y-m-d H:i:s'),
            ];
        } catch (\Throwable $e) {
            $this->results[$key] = [
                'ok'         => false,
                'status'     => null,
                'duration'   => (int) round((microtime(true) - $start) * 1000),
                'message'    => $e->getMessage(),
                'preview'    => null,
                'timestamp'  => now()->format('Y-m-d H:i:s'),
            ];
        }

        Cache::put($this->cacheKey(), $this->results, now()->addMinutes(60));
        $this->dispatch('$refresh');
        $this->render();
    }

    public function clearResults(): void
    {
        $this->results = [];
        Cache::forget($this->cacheKey());
        $this->dispatch('notify', type: 'success', message: 'Ergebnisse geleert.');
    }

    protected function preview($data): ?string
    {
        if (is_null($data)) return null;

        // Wenn der Service schon ein Array liefert → direkt pretty-printen.
        if (is_array($data) || is_object($data)) {
            return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        // Falls String: versuchen zu decodieren; wenn nicht möglich → roh anzeigen
        if (is_string($data)) {
            $decoded = json_decode($data, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }
            return $data;
        }

        // Fallback
        return (string) $data;
    }


    protected function cacheKey(): string
    {
        return 'admin.api_uvs_tests.v1';
    }

    protected function nullIfEmpty($v)
    {
        if (is_string($v) && trim($v) === '') return null;
        return $v;
    }

    /**
     * Einfache Fakes für schnelles Frontend-Testing ohne echte UVS-API.
     */
    protected function installFakes(): void
    {
        $base = rtrim((string) Setting::getValue('api', 'uvs_api_url'), '/');
        if (!$base) {
            // Fallback: match alles
            Http::fake(['*' => Http::response(['ok' => true, 'fake' => true], 200)]);
            return;
        }

        Http::fake([
            // GET /api/participants?mail=...
            "{$base}/api/participants*" => Http::response([
                'participant' => [
                    'id' => 12345,
                    'name' => 'Müstermann, Mäx',
                    'mail' => $this->email,
                ],
            ], 200),

            // GET /api/participants/{id}/qualiprogram
            "{$base}/api/participants/*/qualiprogram" => Http::response([
                'participant_id' => (int)$this->participantId,
                'qualiprogram' => ['modules' => 10, 'passed' => 7],
            ], 200),

            // GET /api/person/status?person_id=...
            "{$base}/api/person/status*" => Http::response([
                'person_id' => $this->personId,
                'status' => 'active',
                'last_sync' => now()->toIso8601String(),
            ], 200),

            // GET /api/tutorprogram/person?person_id=...
            "{$base}/api/tutorprogram/person*" => Http::response([
                'person_id' => $this->personId,
                'courses' => [['id' => 900, 'title' => 'Webdev'], ['id' => 901, 'title' => 'Linux']],
            ], 200),

            // GET /api/course-classes
            "{$base}/api/course-classes*" => Http::response([
                'items' => [
                    ['id' => 111, 'label' => 'INF23A', 'from' => '2025-10-01', 'to' => '2026-03-31'],
                    ['id' => 222, 'label' => 'INF23B', 'from' => '2025-10-01', 'to' => '2026-03-31'],
                ],
                'meta' => ['limit' => $this->limit, 'search' => $this->searchTerm],
            ], 200),

            // GET /api/course-classes/participants?course_class_id=...
            "{$base}/api/course-classes/participants*" => Http::response([
                'course_class_id' => $this->courseClassId,
                'participants' => [
                    ['id' => 1, 'name' => 'Alpha'],
                    ['id' => 2, 'name' => 'Beta'],
                ],
            ], 200),
        ]);
    }

        public function runSqlManual(): void
    {
        // Optional: Fake aktiv
        if ($this->useFake) {
            $this->installFakes();
        }

        $svc = app(ApiUvsService::class);

        $start = microtime(true);
        try {
            $resp = $svc->runSql($this->sqlQuery, true);

            $ms      = (int) round((microtime(true) - $start) * 1000);
            $ok      = (bool)($resp['ok'] ?? false);
            $status  = $resp['status'] ?? null;
            $message = $resp['message'] ?? ($ok ? 'OK' : 'Fehler');
            $data    = $resp['data'] ?? null;

            $this->results['sql_run'] = [
                'ok'         => $ok,
                'status'     => $status,
                'duration'   => $ms,
                'message'    => $message,
                'preview'    => $this->preview($data),
                'timestamp'  => now()->format('Y-m-d H:i:s'),
            ];
        } catch (\Throwable $e) {
            $this->results['sql_run'] = [
                'ok'         => false,
                'status'     => null,
                'duration'   => (int) round((microtime(true) - $start) * 1000),
                'message'    => $e->getMessage(),
                'preview'    => null,
                'timestamp'  => now()->format('Y-m-d H:i:s'),
            ];
        }

        Cache::put($this->cacheKey(), $this->results, now()->addMinutes(60));
        $this->dispatch('$refresh');
        $this->render();
    }

    public function clearSqlResult(): void
    {
        unset($this->results['sql_run']);
        Cache::put($this->cacheKey(), $this->results, now()->addMinutes(60));
        $this->dispatch('notify', type: 'success', message: 'SQL-Ausgabe geleert.');
    }

    public function render()
    {
        $baseUrl = (string) Setting::getValue('api', 'uvs_api_url');
        $apiKey  = (string) Setting::getValue('api', 'uvs_api_key');

        return view('livewire.admin.tests.api-tests', [
            'hasConfig' => ($baseUrl && $apiKey),
            'baseUrl'   => $baseUrl,
        ])->layout('layouts.master');
    }
}
