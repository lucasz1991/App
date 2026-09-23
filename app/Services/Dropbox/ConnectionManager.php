<?php

namespace App\Services\Dropbox;

use App\Enums\DropboxMode;
use App\Models\DropboxConnection;
use App\Models\DropboxRecord;
use App\Models\DropboxSource;
use App\Models\EmployeeQualification;
use App\Models\Order;
use App\Models\Shift;
use App\Models\User;
use App\Models\UserProfile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ConnectionManager
{
    public function authorize(?User $user): void
    {
        abort_unless($user?->isSuperAdmin() && $user->status, 403);
        Gate::forUser($user)->authorize('settings.manage');
    }

    public function save(DropboxConnection $connection, int $generation, array $form, User $actor): DropboxConnection
    {
        $this->authorize($actor);
        $data = Validator::make($form, [
            'app_key' => 'nullable|string|max:191', 'app_secret' => 'nullable|string|max:500',
            'folders' => 'required|array|min:1|max:10', 'folders.*' => ['required', 'string', 'max:400', 'regex:#^/(?!.*(?:\.\.|[\r\n]))[^\r\n]*$#D'],
            'matrix_path' => ['nullable', 'string', 'max:500', 'regex:#^/(?!.*\.\.).+\.xlsx$#iD'],
            'additional_sources' => 'sometimes|array|max:20',
            'additional_sources.*.path' => ['required', 'string', 'max:500', 'regex:#^/(?!.*\.\.).+\.xlsx$#iD'],
            'additional_sources.*.profile' => 'required|in:weekly,matrix',
            'filename_rule' => 'required|string|max:180', 'domains' => 'required|array|min:1',
            'domains.*' => [Rule::in(['planning', 'assignments', 'contacts', 'competencies'])],
            'auto_discover' => 'required|boolean', 'watch_history' => 'required|boolean', 'create_workbooks' => 'required|boolean', 'create_sheets' => 'required|boolean', 'append_rows' => 'required|boolean',
            'debounce_seconds' => ['required', Rule::in([2, 5, 10])], 'check_minutes' => ['required', Rule::in([5, 15, 60])], 'timezone' => ['required', Rule::in(['Europe/Berlin'])],
        ])->validate();
        if (! str_contains($data['filename_rule'], '{KW}')) {
            throw ValidationException::withMessages(['filename_rule' => 'Die Dateinamensregel benötigt {KW}.']);
        }
        if (preg_match('#[/\\\\]#', $data['filename_rule'])) {
            throw ValidationException::withMessages(['filename_rule' => 'Die Namensregel darf keinen Ordnerpfad enthalten.']);
        }
        if ($data['create_workbooks']) {
            try {
                app(WeekFileMatcher::class)->filename('2026-W40', $data['filename_rule']);
            } catch (\RuntimeException) {
                throw ValidationException::withMessages(['filename_rule' => 'Eine eigene Regel benötigt für neue Wochenmappen {KW}, {YYYY} und die Endung .xlsx.']);
            }
        }
        if (in_array('assignments', $data['domains'], true) && ! in_array('planning', $data['domains'], true)) {
            throw ValidationException::withMessages(['domains' => 'Mitarbeiterzuordnungen benötigen den Datenbereich Aufträge und Schichten.']);
        }

        return DB::transaction(function () use ($connection, $generation, $data) {
            $current = DropboxConnection::lockForUpdate()->findOrFail($connection->id);
            if ($current->generation !== $generation) {
                throw ValidationException::withMessages(['settings' => 'Einstellungen wurden inzwischen geändert. Neu laden.']);
            }
            $credentialsChanged = ($current->app_key ?? '') !== ($data['app_key'] ?? '') || (! empty($data['app_secret']) && $data['app_secret'] !== $current->app_secret);
            $settings = array_merge(config('dropbox.defaults'), $current->settings ?? [], array_diff_key($data, ['app_key' => true, 'app_secret' => true]));
            $settings['matrix_path'] ??= '';
            $settings['folders'] = array_values(array_unique(array_map(fn ($p) => rtrim($p, '/') ?: '/', $settings['folders'])));
            $current->forceFill(['app_key' => $data['app_key'], 'settings' => $settings, 'mode' => DropboxMode::Off, 'closing' => false, 'generation' => $current->generation + 1, 'preview_at' => null, 'preview_generation' => null]);
            if (! empty($data['app_secret'])) {
                $current->app_secret = $data['app_secret'];
            }
            if ($credentialsChanged) {
                $current->access_token = null;
                $current->refresh_token = null;
                $current->scopes = [];
            }
            $current->save();

            return $current;
        });
    }

    public function activate(DropboxConnection $connection, DropboxMode $mode, User $actor): void
    {
        $this->authorize($actor);
        if ($mode === DropboxMode::AppOnly) {
            throw ValidationException::withMessages(['mode' => 'Zuerst Abschlussabgleich und Archivierung durchführen.']);
        }
        if ($mode !== DropboxMode::Off) {
            if (! $connection->refresh_token || ! $connection->account_id) {
                throw ValidationException::withMessages(['connection' => 'Zuerst mit Dropbox verbinden.']);
            }
            $required = $mode === DropboxMode::Bidirectional ? config('dropbox.scopes') : array_diff(config('dropbox.scopes'), ['files.content.write']);
            if (array_diff($required, $connection->scopes ?? [])) {
                throw ValidationException::withMessages(['connection' => 'Erforderliche Dropbox-Rechte fehlen. Erneut verbinden.']);
            }
            if (! app(SyncHealth::class)->read()['ready']) {
                throw ValidationException::withMessages(['runtime' => 'Redis und alle drei Worker müssen den Hintergrundtest bestanden haben.']);
            }
            if ($mode !== DropboxMode::Preview && ((int) $connection->preview_generation !== $connection->generation || ! $connection->preview_at)) {
                throw ValidationException::withMessages(['preview' => 'Zuerst eine vollständige Vorschau mit diesen Einstellungen durchführen.']);
            }
            if ($mode === DropboxMode::Bidirectional) {
                if ($connection->option('create_workbooks')) {
                    app(TemplateService::class)->load($connection->option('week_template'));
                }
                if ($connection->option('create_sheets')) {
                    app(TemplateService::class)->load($connection->option('employee_template'));
                }
            }
        }
        DB::transaction(function () use ($connection, $mode) {
            $fresh = DropboxConnection::lockForUpdate()->findOrFail($connection->id);
            if ($fresh->generation !== $connection->generation) {
                throw ValidationException::withMessages(['settings' => 'Einstellungen wurden geändert.']);
            }
            $fresh->forceFill(['mode' => $mode, 'generation' => $fresh->generation + 1, 'closing' => false])->save();
            if ($mode->active()) {
                $this->reconcile($fresh, $mode === DropboxMode::Preview);
            }
        });
    }

    public function reconcile(DropboxConnection $connection, bool $preview = false): void
    {
        if ($preview) {
            DropboxConnection::whereKey($connection->id)->where('generation', $connection->generation)->update(['preview_at' => null, 'preview_generation' => null]);
        }
        $ledger = app(WorkLedger::class);
        $prefix = $preview ? 'preview:' : '';
        $ledger->enqueue($connection, 'scan', $prefix.'scan', ['preview' => $preview], 0);
        foreach (DropboxSource::where('connection_id', $connection->id)->whereNotIn('state', ['missing', 'excluded'])->get() as $source) {
            $ledger->enqueue($connection, 'file', $prefix.'file:'.$source->id, ['source_id' => $source->id, 'preview' => $preview], 0);
        }
        foreach (Shift::select('id')->cursor() as $shift) {
            $ledger->enqueue($connection, 'export', $prefix.'Shift:'.$shift->id, ['type' => 'Shift', 'id' => $shift->id, 'preview' => $preview], 0);
        }
        foreach ([UserProfile::class, EmployeeQualification::class] as $class) {
            foreach ($class::select('id')->cursor() as $model) {
                $type = class_basename($model);
                $ledger->enqueue($connection, 'export', $prefix.$type.':'.$model->id, ['type' => $type, 'id' => $model->id, 'preview' => $preview], 0);
            }
        }
        foreach (Order::whereDoesntHave('shifts')->select('id')->cursor() as $order) {
            $ledger->enqueue($connection, 'export', $prefix.'Order:'.$order->id, ['type' => 'Order', 'id' => $order->id, 'preview' => $preview], 0);
        }
        foreach (DropboxRecord::where('connection_id', $connection->id)->where('domain', '!=', 'planning')->cursor() as $record) {
            $ledger->enqueue($connection, 'export', $prefix.$record->model_type.':'.$record->model_id, ['type' => $record->model_type, 'id' => $record->model_id, 'preview' => $preview], 0);
        }
    }

    public function disconnect(DropboxConnection $connection, User $actor): void
    {
        $this->authorize($actor);
        // Invalidate durable work before any network request. Local disconnect
        // remains effective even if Dropbox token revocation cannot be reached.
        $copy = clone $connection;
        DB::transaction(function () use ($connection) {
            $fresh = DropboxConnection::lockForUpdate()->findOrFail($connection->id);
            $fresh->forceFill(['mode' => DropboxMode::Off, 'generation' => $fresh->generation + 1, 'closing' => false, 'access_token' => null, 'refresh_token' => null, 'expires_at' => null, 'preview_at' => null, 'preview_generation' => null])->save();
        });
        if ($copy->access_token && $copy->expires_at?->isFuture()) {
            try {
                app(DropboxClient::class)->rpc($copy, 'auth/token/revoke');
            } catch (\Throwable) {
            }
        }
    }
}
