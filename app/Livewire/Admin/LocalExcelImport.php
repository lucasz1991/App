<?php

namespace App\Livewire\Admin;

use App\Models\DropboxAppearance;
use App\Models\DropboxIdentity;
use App\Models\LocalExcelImport as Import;
use App\Models\OperationsRuleProfile;
use App\Models\User;
use App\Services\Dropbox\WorkbookReader;
use App\Services\Excel\LocalExcelImporter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

class LocalExcelImport extends Component
{
    use WithFileUploads, WithPagination;

    public $upload;

    public string $profile = 'auto';

    #[Locked]
    public ?int $importId = null;

    public array $targets = [];

    public string $testPassword = '';

    public string $search = '';

    public string $notice = '';

    private function service(): LocalExcelImporter
    {
        $service = app(LocalExcelImporter::class);
        $service->authorize(auth()->user());

        return $service;
    }

    public function mount(): void
    {
        $this->service();
        if (Schema::hasTable('local_excel_imports')) {
            $this->importId = Import::latest('updated_at')->value('id');
        }
    }

    public function inspect(): void
    {
        $service = $this->service();
        $this->resetErrorBag();
        $this->validate(['upload' => 'required|file|mimes:xlsx|max:12288', 'profile' => 'required|in:auto,weekly,matrix']);
        $name = $this->upload->getClientOriginalName();
        $profile = $this->profile === 'auto' ? (str_contains(mb_strtolower($name), 'kompetenz') ? 'matrix' : 'weekly') : $this->profile;
        try {
            $import = $service->prepare(file_get_contents($this->upload->getRealPath()), $name, $profile, auth()->user());
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable) {
            $this->addError('upload', 'Die Excel-Datei konnte nicht gelesen werden. Eine gültige, ungeschützte XLSX-Datei mit passendem Dateityp auswählen.');

            return;
        }
        $this->importId = $import->id;
        $this->upload = null;
        $this->targets = [];
        $this->resetPage('localIdentities');
        $this->resetPage('localConflicts');
        $this->notice = 'Datei geprüft. Mitarbeiterzuordnungen kontrollieren und anschließend importieren.';
    }

    public function selectImport(int $id): void
    {
        $this->service();
        Import::findOrFail($id);
        $this->importId = $id;
        $this->targets = [];
        $this->search = '';
        $this->notice = '';
        $this->resetPage('localIdentities');
        $this->resetPage('localConflicts');
    }

    public function start(): void
    {
        $this->service()->start((int) $this->importId, auth()->user());
        $this->notice = 'Import gestartet. Diese Seite geöffnet lassen; große Dateien werden abschnittsweise übernommen.';
    }

    public function advance(): void
    {
        $this->service()->advance((int) $this->importId, auth()->user());
        if (Import::find($this->importId)?->status === 'done') {
            $this->notice = 'Importdurchlauf abgeschlossen. Ergebnis und offene Hinweise stehen unten.';
        }
    }

    public function pause(): void
    {
        $this->service()->pause((int) $this->importId, auth()->user());
        $this->notice = 'Import pausiert. Bereits übernommene Daten bleiben erhalten.';
    }

    public function assign(int $id): void
    {
        $service = $this->service();
        $import = Import::findOrFail($this->importId);
        abort_unless(in_array($id, $import->summary['identity_ids'], true), 404);
        $target = (string) ($this->targets[$id] ?? '');
        $user = $service->assign($id, $target, auth()->user(), $this->testPassword);
        $this->testPassword = '';
        $this->targets[$id] = $user ? (string) $user->id : 'provider';
        $this->notice = $target === 'new' ? 'Lokales Testkonto angelegt: '.$user->email.'. Anmeldung mit dem gerade eingegebenen Passwort.' : 'Zuordnung gespeichert. Bereits abgeschlossene Dateien bei Bedarf erneut importieren.';
    }

    public function updatedSearch(): void
    {
        $this->service();
        $this->resetPage('localIdentities');
    }

    public function render()
    {
        $service = $this->service();
        $ready = Schema::hasTable('local_excel_imports');
        $import = $ready && $this->importId ? Import::with('source')->find($this->importId) : null;
        $employees = $ready ? User::where('role', 'staff')->where('status', true)->with('profile')->orderBy('name')->get() : collect();
        $identities = $import ? DropboxIdentity::whereIn('id', $import->summary['identity_ids'])->when($this->search !== '', fn ($q) => $q->where('alias', 'like', '%'.$this->search.'%'))->orderByRaw("case when kind = 'unresolved' then 0 else 1 end")->orderBy('alias')->paginate(12, ['*'], 'localIdentities') : null;
        foreach ($identities ?? [] as $identity) {
            if (! isset($this->targets[$identity->id])) {
                $matches = $employees->filter(fn ($user) => in_array($identity->alias, [WorkbookReader::normalize($user->name), WorkbookReader::normalize(trim(($user->profile?->first_name ?? '').' '.($user->profile?->last_name ?? '')))], true));
                $this->targets[$identity->id] = $identity->kind === 'provider' ? 'provider' : ($identity->user_id ? (string) $identity->user_id : ($matches->count() === 1 ? (string) $matches->first()->id : ''));
            }
        }

        return view('livewire.admin.local-excel-import', [
            'ready' => $ready, 'import' => $import, 'employees' => $employees, 'identities' => $identities,
            'rulesReady' => Schema::hasTable('operations_rule_profiles') && OperationsRuleProfile::where('is_active', true)->exists(),
            'imports' => $ready ? Import::with('source')->latest('updated_at')->limit(20)->get() : collect(),
            'unresolved' => $import ? DropboxIdentity::whereIn('id', $import->summary['identity_ids'])->where('kind', 'unresolved')->count() : 0,
            'bound' => $import ? DropboxAppearance::where('source_id', $import->source_id)->distinct()->count('record_id') : 0,
            'conflicts' => $import ? $service->conflicts($import->source_id)->orderBy('id')->paginate(10, ['*'], 'localConflicts') : null,
            'canCreateTestUsers' => app()->environment(['local', 'testing']),
        ]);
    }
}
