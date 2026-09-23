<?php

namespace App\Livewire\Admin;

use App\Enums\DropboxMode;
use App\Models\DropboxAppearance;
use App\Models\DropboxConflict;
use App\Models\DropboxConnection;
use App\Models\DropboxIdentity;
use App\Models\DropboxRecord;
use App\Models\DropboxSource;
use App\Models\DropboxWorkItem;
use App\Models\EmployeeCompetencyFact;
use App\Models\QualificationType;
use App\Models\User;
use App\Services\Dropbox\ClosingService;
use App\Services\Dropbox\ConnectionManager;
use App\Services\Dropbox\DomainAdapter;
use App\Services\Dropbox\DropboxClient;
use App\Services\Dropbox\QualificationProjection;
use App\Services\Dropbox\SyncHealth;
use App\Services\Dropbox\TemplateService;
use App\Services\Dropbox\WorkbookReader;
use App\Services\Dropbox\WorkLedger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

class DropboxSettings extends Component
{
    use WithFileUploads, WithPagination;

    #[Locked]
    public ?int $connectionId = null;

    #[Locked]
    public int $generation = 0;

    #[Locked]
    public ?int $conflictId = null;

    #[Locked]
    public string $conflictHash = '';

    #[Locked]
    public ?int $recordId = null;

    #[Locked]
    public string $recordHash = '';

    #[Locked]
    public array $reviewedDecision = [];

    public array $form = [];

    public string $folders = '';

    public string $panel = 'connection';

    public string $mode = 'off';

    public string $notice = '';

    public string $search = '';

    public array $identityForms = [];

    public array $choices = [];

    public array $custom = [];

    public array $recordForm = [];

    public array $newFact = ['identity_id' => '', 'kind' => 'local_knowledge', 'name' => '', 'scope' => '', 'value' => '', 'color' => ''];

    public array $newContact = ['name' => '', 'kind' => 'provider', 'first_name' => '', 'last_name' => '', 'contact_email' => '', 'phone' => ''];

    public $templateUpload;

    public bool $desktopUploadsFinished = false;

    public string $targetSource = '';

    public string $targetRecord = '';

    public string $mappingSearch = '';

    public string $qualificationType = '';

    public string $qualificationField = 'valid_until';

    public function mount(): void
    {
        $this->authorizeAdmin();
        if (! Schema::hasTable('dropbox_connections')) {
            return;
        }
        $connection = DropboxConnection::remote()->latest('id')->first() ?? DropboxConnection::create(['mode' => DropboxMode::Off, 'settings' => config('dropbox.defaults')]);
        $this->connectionId = $connection->id;
        $this->reloadForm();
    }

    private function authorizeAdmin(): void
    {
        app(ConnectionManager::class)->authorize(auth()->user());
    }

    private function connection(): DropboxConnection
    {
        $this->authorizeAdmin();

        return DropboxConnection::remote()->findOrFail($this->connectionId);
    }

    private function reloadForm(): void
    {
        $connection = $this->connection();
        $this->generation = $connection->generation;
        $this->form = array_merge(config('dropbox.defaults'), $connection->settings ?? []);
        unset($this->form['week_template'], $this->form['employee_template']);
        $this->form['app_key'] = $connection->app_key ?? '';
        $this->form['app_secret'] = '';
        $this->folders = implode("\n", $connection->option('folders'));
        $this->mode = $connection->mode->value;
    }

    public function save(): void
    {
        $this->authorizeAdmin();
        $this->form['folders'] = array_values(array_filter(array_map('trim', preg_split('/\R/u', $this->folders))));
        app(ConnectionManager::class)->save($this->connection(), $this->generation, $this->form, auth()->user());
        $this->reloadForm();
        $this->notice = 'Einstellungen gespeichert. Der Abgleich ist aus; Vorschau und Aktivierung sind getrennte Schritte.';
    }

    public function testConnection(): void
    {
        $this->authorizeAdmin();
        try {
            $account = app(DropboxClient::class)->rpc($this->connection(), 'users/get_current_account');
            if ($account['account_id'] !== $this->connection()->account_id) {
                throw new \RuntimeException;
            }
            app(SyncHealth::class)->probe();
            $this->notice = 'Dropbox antwortet. Hintergrundtest an alle drei Worker übergeben.';
        } catch (\Throwable) {
            $this->addError('connection', 'Verbindung oder Redis nicht erreichbar. Gespeicherte Freigabe und Serverkonfiguration prüfen.');
        }
    }

    public function testWorkers(): void
    {
        $this->authorizeAdmin();
        try {
            app(SyncHealth::class)->probe();
            $this->notice = 'Worker-Test versendet. Status in wenigen Sekunden aktualisieren.';
        } catch (\Throwable) {
            $this->addError('runtime', 'Redis ist nicht erreichbar.');
        }
    }

    public function refreshStatus(): void
    {
        $this->authorizeAdmin();
    }

    public function addSource(): void
    {
        $this->authorizeAdmin();
        if (count($this->form['additional_sources'] ?? []) < 20) {
            $this->form['additional_sources'][] = ['path' => '', 'profile' => 'weekly'];
        }
    }

    public function removeSource(int $index): void
    {
        $this->authorizeAdmin();
        unset($this->form['additional_sources'][$index]);
        $this->form['additional_sources'] = array_values($this->form['additional_sources']);
    }

    public function closeConflict(): void
    {
        $this->authorizeAdmin();
        $this->conflictId = null;
    }

    public function closeRecord(): void
    {
        $this->authorizeAdmin();
        $this->recordId = null;
    }

    public function preview(): void
    {
        $connection = $this->connection();
        if (! $connection->refresh_token) {
            $this->addError('connection', 'Zuerst mit Dropbox verbinden.');

            return;
        }
        app(ConnectionManager::class)->reconcile($connection, true);
        app(WorkLedger::class)->dispatch($connection->id);
        $this->notice = 'Vorschau vorgemerkt. Fachdaten und Dropbox-Dateien werden dabei nicht verändert.';
        $this->panel = 'activity';
    }

    public function activate(): void
    {
        $this->authorizeAdmin();
        $mode = DropboxMode::tryFrom($this->mode);
        abort_unless($mode, 422);
        if ($mode === DropboxMode::AppOnly) {
            $this->startClosing();

            return;
        }
        app(ConnectionManager::class)->activate($this->connection(), $mode, auth()->user());
        $this->reloadForm();
        app(WorkLedger::class)->dispatch($this->connectionId);
        $this->notice = 'Betriebsart: '.$mode->label().'. Bereits laufende Uploads werden im Status ausgewiesen.';
    }

    public function stop(): void
    {
        app(ConnectionManager::class)->activate($this->connection(), DropboxMode::Off, auth()->user());
        $this->reloadForm();
        $this->notice = 'Abgleich ausgeschaltet. Bereits begonnene Uploads können noch fertiggestellt werden.';
    }

    public function disconnect(): void
    {
        app(ConnectionManager::class)->disconnect($this->connection(), auth()->user());
        $this->reloadForm();
        $this->notice = 'Dropbox getrennt. Alte Arbeitsaufträge sind deaktiviert.';
    }

    public function saveTemplate(string $kind): void
    {
        $connection = $this->connection();
        abort_unless(in_array($kind, ['week_template', 'employee_template'], true), 422);
        $this->validate(['templateUpload' => 'required|file|mimes:xlsx|max:12288']);
        $template = app(TemplateService::class)->install(file_get_contents($this->templateUpload->getRealPath()));
        DB::transaction(function () use ($connection, $template, $kind) {
            $fresh = DropboxConnection::lockForUpdate()->findOrFail($connection->id);
            abort_unless($fresh->generation === $this->generation, 409);
            $fresh->forceFill(['settings' => array_merge($fresh->settings, [$kind => $template]), 'mode' => DropboxMode::Off, 'generation' => $fresh->generation + 1, 'preview_at' => null, 'preview_generation' => null])->save();
        });
        $this->templateUpload = null;
        $this->reloadForm();
        $this->notice = 'Vorlage bereinigt, geprüft und gespeichert. Alte Daten und Kommentare wurden nicht in die Vorlage übernommen.';
    }

    public function saveIdentity(int $id): void
    {
        $connection = $this->connection();
        $identity = DropboxIdentity::where('connection_id', $connection->id)->findOrFail($id);
        $form = $this->identityForms[$id] ?? [];
        $data = validator($form, ['kind' => 'required|in:employee,provider,unresolved', 'user_id' => 'nullable|integer|exists:users,id'])->validate();
        if ($data['kind'] === 'employee') {
            $user = User::find($data['user_id'] ?? null);
            if (! $user || $user->role !== 'staff' || ! $user->status) {
                throw ValidationException::withMessages(['identityForms' => 'Ein aktives Mitarbeiterkonto auswählen.']);
            }
        }
        DB::transaction(function () use ($identity, $data, $connection) {
            $identity->forceFill(['kind' => $data['kind'], 'user_id' => $data['kind'] === 'employee' ? $data['user_id'] : null, 'revision' => $identity->revision + 1])->save();
            foreach (DropboxSource::where('connection_id', $connection->id)->get() as $source) {
                app(WorkLedger::class)->enqueue($connection, 'file', 'file:'.$source->id, ['source_id' => $source->id]);
            }
        });
        $this->notice = 'Zuordnung gespeichert; betroffene Quelldaten werden erneut geprüft.';
    }

    public function selectConflict(int $id): void
    {
        $conflict = DropboxConflict::where('connection_id', $this->connection()->id)->findOrFail($id);
        $this->panel = 'conflicts';
        $this->conflictId = $id;
        $this->conflictHash = WorkbookReader::fingerprint($conflict->snapshot);
        $this->choices = [];
        $this->custom = [];
        $this->reviewedDecision = [];
        $this->targetRecord = '';
        $this->mappingSearch = '';
        $this->targetSource = '';
        foreach ($conflict->snapshot['fields'] ?? [] as $field => $versions) {
            $this->choices[$field] = 'app';
            $this->custom[$field] = $conflict->snapshot['app'][$field] ?? '';
        }
    }

    private function selectedConflict(): DropboxConflict
    {
        $conflict = DropboxConflict::where('connection_id', $this->connection()->id)->findOrFail($this->conflictId);
        if (! hash_equals($this->conflictHash, WorkbookReader::fingerprint($conflict->snapshot)) || $conflict->state !== 'open') {
            throw ValidationException::withMessages(['conflict' => 'Konflikt wurde geändert. Neu öffnen.']);
        }
        if ($conflict->record_id && isset($conflict->snapshot['app'])) {
            $current = app(DomainAdapter::class)->current(DropboxRecord::findOrFail($conflict->record_id));
            if ($current !== $conflict->snapshot['app']) {
                throw ValidationException::withMessages(['conflict' => 'App-Daten wurden inzwischen geändert. Abgleich erneut starten.']);
            }
        }

        return $conflict;
    }

    public function reviewDecision(): void
    {
        $conflict = $this->selectedConflict();
        $decision = [];
        foreach ($conflict->snapshot['fields'] ?? [] as $field => $versions) {
            $choice = $this->choices[$field] ?? 'app';
            if (preg_match('/^excel\.(\d+)$/D', $choice, $match) && array_key_exists('excel', $versions[(int) $match[1]] ?? [])) {
                $value = $versions[(int) $match[1]]['excel'];
            } else {
                $value = match ($choice) {
                    'app' => $conflict->snapshot['app'][$field] ?? null, 'excel' => $versions[0]['excel'] ?? ($versions[0]['values'][0] ?? null), 'custom' => $this->custom[$field] ?? null, default => throw ValidationException::withMessages(['conflict' => 'Ungültige Entscheidung.'])
                };
            }
            if (is_bool($conflict->snapshot['app'][$field] ?? null)) {
                $value = filter_var($value, FILTER_VALIDATE_BOOL);
            }
            $decision[$field] = $value;
        }
        $this->reviewedDecision = $decision;
    }

    public function applyDecision(): void
    {
        $conflict = $this->selectedConflict();
        if (! $this->reviewedDecision) {
            throw ValidationException::withMessages(['conflict' => 'Entscheidung zuerst prüfen.']);
        }
        $conflict->forceFill(['decision' => $this->reviewedDecision, 'resolved_by' => auth()->id()])->save();
        app(WorkLedger::class)->enqueue($this->connection(), 'file', 'file:'.$conflict->source_id, ['source_id' => $conflict->source_id], 0);
        app(WorkLedger::class)->dispatch($this->connectionId);
        $this->notice = 'Entscheidung vorgemerkt. Dateiversion und Fachregeln werden vor Anwendung erneut geprüft.';
        $this->conflictId = null;
    }

    public function retryConflict(int $id): void
    {
        $connection = $this->connection();
        $conflict = DropboxConflict::where('connection_id', $connection->id)->findOrFail($id);
        if ($conflict->source_id) {
            app(WorkLedger::class)->enqueue($connection, 'file', 'file:'.$conflict->source_id, ['source_id' => $conflict->source_id], 0);
        } else {
            app(ConnectionManager::class)->reconcile($connection);
        }
        $conflict->update(['state' => 'rechecking']);
        app(WorkLedger::class)->dispatch($connection->id);
    }

    public function chooseDestination(): void
    {
        $conflict = $this->selectedConflict();
        abort_unless($conflict->record_id, 422);
        $source = DropboxSource::where('connection_id', $this->connectionId)->findOrFail((int) $this->targetSource);
        $record = DropboxRecord::findOrFail($conflict->record_id);
        $record->metadata = array_merge($record->metadata ?? [], ['target_source_id' => $source->id]);
        $record->save();
        app(WorkLedger::class)->enqueue($this->connection(), 'export', $record->model_type.':'.$record->model_id, ['type' => $record->model_type, 'id' => $record->model_id], 0);
        $conflict->update(['state' => 'resolved', 'resolved_by' => auth()->id()]);
        $this->conflictId = null;
    }

    public function linkRow(): void
    {
        $conflict = $this->selectedConflict();
        abort_unless(in_array($conflict->reason, ['mapping_required', 'ambiguous_row'], true), 422);
        $record = DropboxRecord::where('connection_id', $this->connectionId)->where('domain', $conflict->snapshot['domain'] ?? 'planning')->find((int) $this->targetRecord);
        if (! $record) {
            throw ValidationException::withMessages(['conflict' => 'Einen vorhandenen Datensatz auswählen.']);
        }
        $conflict->update(['decision' => ['record_id' => $record->id], 'resolved_by' => auth()->id()]);
        app(WorkLedger::class)->enqueue($this->connection(), 'file', 'file:'.$conflict->source_id, ['source_id' => $conflict->source_id], 0);
        app(WorkLedger::class)->dispatch($this->connectionId);
        $this->conflictId = null;
        $this->notice = 'Zuordnung vorgemerkt. Geänderte Inhalte und abweichende Feldwerte werden erneut geprüft.';
    }

    public function acknowledgeUnmapped(): void
    {
        $conflict = $this->selectedConflict();
        abort_unless($conflict->reason === 'not_representable', 422);
        $conflict->update(['state' => 'acknowledged', 'resolved_by' => auth()->id()]);
        $this->conflictId = null;
        $this->notice = 'Nicht abbildbare App-Daten vermerkt. Sobald eine Zuordnung möglich ist, wird der Datensatz regulär abgeglichen.';
    }

    public function restoreRow(): void
    {
        $conflict = $this->selectedConflict();
        abort_unless($conflict->reason === 'row_missing' && $conflict->record_id, 422);
        DB::transaction(function () use ($conflict) {
            $record = DropboxRecord::where('connection_id', $this->connectionId)->findOrFail($conflict->record_id);
            DropboxAppearance::where('source_id', $conflict->source_id)->where('record_id', $record->id)->where('sheet', $conflict->snapshot['sheet'])->where('slot', $conflict->snapshot['previous_slot'])->delete();
            $record->metadata = array_merge($record->metadata ?? [], ['target_source_id' => $conflict->source_id]);
            $record->save();
            app(WorkLedger::class)->enqueue($this->connection(), 'export', $record->model_type.':'.$record->model_id, ['type' => $record->model_type, 'id' => $record->model_id], 0);
            $conflict->update(['state' => 'resolved', 'resolved_by' => auth()->id()]);
        });
        app(WorkLedger::class)->dispatch($this->connectionId);
        $this->conflictId = null;
        $this->notice = 'Wiederanlage aus dem erhaltenen App-Datensatz vorgemerkt.';
    }

    public function editRecord(int $id): void
    {
        $record = DropboxRecord::where('connection_id', $this->connection()->id)->findOrFail($id);
        $this->recordId = $id;
        $this->recordForm = app(DomainAdapter::class)->current($record);
        $this->recordHash = WorkbookReader::fingerprint($this->recordForm);
        $this->panel = 'data';
        $fact = $record->domain === 'competencies' ? EmployeeCompetencyFact::find($record->model_id) : null;
        $this->qualificationType = (string) ($fact?->qualification_type_id ?? '');
        $this->qualificationField = $fact?->qualification_field ?? 'valid_until';
    }

    public function bindQualification(): void
    {
        $connection = $this->connection();
        $record = DropboxRecord::where('connection_id', $connection->id)->where('domain', 'competencies')->findOrFail($this->recordId);
        $this->validate(['qualificationType' => 'nullable|integer|exists:qualification_types,id', 'qualificationField' => 'required|in:status,valid_from,valid_until']);
        DB::transaction(function () use ($record) {
            $fact = EmployeeCompetencyFact::findOrFail($record->model_id);
            $identity = DropboxIdentity::findOrFail($fact->identity_id);
            if (! $identity->user_id) {
                throw ValidationException::withMessages(['record' => 'Zuerst das Mitarbeiterkonto zuordnen.']);
            }
            $fact->update(['qualification_type_id' => $this->qualificationType ?: null, 'qualification_field' => $this->qualificationType ? $this->qualificationField : null]);
            if ($this->qualificationType) {
                app(QualificationProjection::class)->project($fact);
            }
        });
        $this->editRecord($record->id);
        $this->notice = 'Nachweiszuordnung gespeichert. Excel-Angaben erteilen weiterhin keine Nachweisfreigabe.';
    }

    public function saveRecord(): void
    {
        $connection = $this->connection();
        DB::transaction(function () use ($connection) {
            $record = DropboxRecord::where('connection_id', $connection->id)->lockForUpdate()->findOrFail($this->recordId);
            $current = app(DomainAdapter::class)->current($record);
            if (! hash_equals($this->recordHash, WorkbookReader::fingerprint($current))) {
                throw ValidationException::withMessages(['record' => 'Datensatz wurde geändert. Neu öffnen.']);
            }
            $values = array_intersect_key($this->recordForm, $current);
            app(DomainAdapter::class)->apply($connection, $record, $values);
            app(WorkLedger::class)->enqueue($connection, 'export', $record->model_type.':'.$record->model_id, ['type' => $record->model_type, 'id' => $record->model_id]);
        });
        $this->recordId = null;
        $this->notice = 'Fachdaten gespeichert; Excel-Übertragung vorgemerkt.';
    }

    public function createFact(): void
    {
        $connection = $this->connection();
        $data = $this->validate(['newFact.identity_id' => 'required|integer', 'newFact.kind' => 'required|in:local_knowledge,introduction,document,customer_authorization', 'newFact.name' => 'required|string|max:191', 'newFact.scope' => 'nullable|string|max:191', 'newFact.value' => 'nullable|string|max:4000', 'newFact.color' => ['nullable', 'regex:/^[0-9A-F]{8}$/D']])['newFact'];
        DropboxIdentity::where('connection_id', $connection->id)->findOrFail($data['identity_id']);
        EmployeeCompetencyFact::create(['identity_id' => $data['identity_id'], 'kind' => $data['kind'], 'name' => $data['name'], 'scope' => $data['scope'] ?: $data['name'], 'value' => ['value' => $data['value'] ?? '', 'color' => $data['color'] ?: null]]);
        $this->notice = 'Kompetenzangabe gespeichert. Sie ersetzt keinen geprüften Nachweis.';
    }

    public function createContact(): void
    {
        $connection = $this->connection();
        $data = $this->validate(['newContact.name' => 'required|string|max:191', 'newContact.kind' => 'required|in:provider,unresolved', 'newContact.first_name' => 'nullable|string|max:100', 'newContact.last_name' => 'nullable|string|max:100', 'newContact.contact_email' => 'nullable|email|max:254', 'newContact.phone' => 'nullable|string|max:100'])['newContact'];
        DropboxIdentity::create(['connection_id' => $connection->id, 'alias' => WorkbookReader::normalize($data['name']), 'kind' => $data['kind'], 'details' => ['display_name' => $data['name'], ...array_diff_key($data, ['kind' => true])]]);
        $this->notice = 'Kontakt angelegt. Mitarbeiterkonto gegebenenfalls unter Zuordnungen verknüpfen.';
    }

    public function startClosing(): void
    {
        $connection = $this->connection();
        if (! $this->desktopUploadsFinished) {
            throw ValidationException::withMessages(['closing' => 'Zuerst bestätigen, dass alle Excel-Bearbeitungen gespeichert und hochgeladen sind.']);
        }
        app(ClosingService::class)->start($connection, auth()->user());
        $this->notice = 'Abschlussabgleich gestartet. Erst nach konfliktfreiem Abgleich und Archivierung wird die Kopplung beendet.';
    }

    public function render()
    {
        $this->authorizeAdmin();
        $ready = Schema::hasTable('dropbox_connections');
        $connection = $ready && $this->connectionId ? $this->connection() : null;
        $identityQuery = DropboxIdentity::where('connection_id', $this->connectionId);
        if ($this->search !== '') {
            $identityQuery->where('alias', 'like', '%'.$this->search.'%');
        }
        $identities = $ready ? $identityQuery->orderByRaw("case when kind = 'unresolved' then 0 else 1 end")->orderBy('alias')->paginate(12, ['*'], 'identitiesPage') : null;
        foreach ($identities ?? [] as $identity) {
            $this->identityForms[$identity->id] ??= ['kind' => $identity->kind, 'user_id' => $identity->user_id ?? ''];
        }
        $selectedConflict = $ready && $this->conflictId ? DropboxConflict::where('connection_id', $this->connectionId)->find($this->conflictId) : null;

        return view('livewire.admin.dropbox-settings', [
            'ready' => $ready, 'connection' => $connection, 'health' => $connection ? app(SyncHealth::class)->read() : null,
            'sources' => $ready ? DropboxSource::where('connection_id', $this->connectionId)->orderByDesc('first_seen_at')->get() : collect(),
            'conflicts' => $ready ? DropboxConflict::where('connection_id', $this->connectionId)->whereIn('state', ['open', 'rechecking'])->latest()->paginate(10, ['*'], 'conflictsPage') : null,
            'selectedConflict' => $selectedConflict,
            'identities' => $identities, 'employees' => $ready ? User::where('role', 'staff')->where('status', true)->orderBy('name')->get(['id', 'name']) : collect(),
            'allIdentities' => $ready ? DropboxIdentity::where('connection_id', $this->connectionId)->orderBy('alias')->get() : collect(),
            'mappingRecords' => $ready && $this->conflictId ? DropboxRecord::where('connection_id', $this->connectionId)
                ->where('domain', $selectedConflict->snapshot['domain'] ?? 'planning')
                ->when($this->mappingSearch !== '', function ($query) {
                    $query->where(function ($search) {
                        $term = mb_substr(trim($this->mappingSearch), 0, 150);
                        $search->where('metadata', 'like', '%'.$term.'%');
                        if (ctype_digit($term)) {
                            $search->orWhere('id', (int) $term)->orWhere('model_id', (int) $term);
                        }
                    });
                })->latest()->limit(100)->get() : collect(),
            'qualificationTypes' => $ready ? QualificationType::orderBy('name')->get() : collect(),
            'records' => $ready ? DropboxRecord::where('connection_id', $this->connectionId)->latest()->paginate(15, ['*'], 'recordsPage') : null,
            'pending' => $connection ? DropboxWorkItem::where('connection_id', $connection->id)->where('generation', $connection->generation)->whereColumn('requested', '>', 'completed')->count() : 0,
            'running' => $connection ? DropboxWorkItem::where('connection_id', $connection->id)->where('running_until', '>', now())->count() : 0,
            'jobs' => $connection ? DropboxWorkItem::where('connection_id', $connection->id)->where('generation', $connection->generation)->whereColumn('requested', '>', 'completed')->latest()->limit(20)->get() : collect(),
            'runs' => $ready ? DB::table('dropbox_runs')->where('connection_id', $this->connectionId)->latest('id')->limit(20)->get() : collect(),
        ]);
    }
}
