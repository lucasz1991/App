<?php

namespace App\Services\DeviceManagement;

use App\Enums\DeviceComplianceStatus;
use App\Enums\DeviceLifecycleStatus;
use App\Enums\DeviceManagementStatus;
use App\Enums\DevicePlatform;
use App\Models\Device;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class DeviceInventoryImportService
{
    /** @var array<string, string> */
    private const HEADER_ALIASES = [
        'inventarnummer' => 'asset_tag', 'asset' => 'asset_tag', 'asset_tag' => 'asset_tag',
        'seriennummer' => 'serial_number', 'serial' => 'serial_number', 'serial_number' => 'serial_number', 'imei' => 'serial_number',
        'gerätename' => 'display_name', 'geraetename' => 'display_name', 'display_name' => 'display_name', 'name' => 'display_name',
        'hostname' => 'hostname',
        'plattform' => 'platform', 'platform' => 'platform',
        'gerätetyp' => 'form_factor', 'geraetetyp' => 'form_factor', 'form_factor' => 'form_factor',
        'eigentum' => 'ownership', 'ownership' => 'ownership',
        'hersteller' => 'manufacturer', 'manufacturer' => 'manufacturer',
        'modell' => 'model', 'model' => 'model',
        'betriebssystem' => 'os_version', 'os_version' => 'os_version',
        'standort' => 'declared_location', 'location' => 'declared_location', 'declared_location' => 'declared_location',
        'mitarbeiter' => 'employee_email', 'mitarbeiter_email' => 'employee_email', 'mitarbeiter_e-mail' => 'employee_email', 'employee_email' => 'employee_email',
    ];

    public function __construct(
        private readonly DeviceInventoryService $inventory,
        private readonly DeviceReadinessService $readiness,
    ) {}

    /** @return array{created:int,updated:int,assigned:int,rows:int} */
    public function import(UploadedFile $file, User $actor): array
    {
        Gate::forUser($actor)->authorize('devices.manage');

        if (! $file->isValid() || $file->getSize() > 2 * 1024 * 1024) {
            throw ValidationException::withMessages(['inventoryImport' => 'Die CSV-Datei ist ungültig oder größer als 2 MB.']);
        }

        $rows = $this->readRows($file);
        if ($rows === []) {
            throw ValidationException::withMessages(['inventoryImport' => 'Die CSV-Datei enthält keine Gerätedaten.']);
        }
        if (count($rows) > 500) {
            throw ValidationException::withMessages(['inventoryImport' => 'Pro Import sind höchstens 500 Geräte erlaubt.']);
        }

        foreach ($rows as $index => $row) {
            $validator = validator($row, [
                'asset_tag' => ['nullable', 'string', 'max:64'],
                'serial_number' => ['nullable', 'string', 'max:128'],
                'display_name' => ['required', 'string', 'max:191'],
                'hostname' => ['nullable', 'string', 'max:191'],
                'platform' => ['required', Rule::enum(DevicePlatform::class)],
                'form_factor' => ['required', Rule::in(['laptop', 'desktop', 'phone', 'tablet', 'other'])],
                'ownership' => ['required', Rule::in(['corporate', 'byod'])],
                'manufacturer' => ['nullable', 'string', 'max:100'],
                'model' => ['nullable', 'string', 'max:150'],
                'os_version' => ['nullable', 'string', 'max:100'],
                'declared_location' => ['nullable', 'string', 'max:191'],
                'employee_email' => ['nullable', 'email', 'max:191'],
            ]);
            if ($validator->fails() || (blank($row['asset_tag']) && blank($row['serial_number']))) {
                throw ValidationException::withMessages([
                    'inventoryImport' => 'CSV-Zeile '.($index + 2).' ist unvollständig oder ungültig: '
                        .($validator->errors()->first() ?: 'Inventar- oder Seriennummer fehlt.'),
                ]);
            }
        }

        if (collect($rows)->contains(fn (array $row): bool => filled($row['employee_email']))
            && Gate::forUser($actor)->denies('devices.assign')) {
            throw ValidationException::withMessages([
                'inventoryImport' => 'Die CSV enthält Mitarbeiterzuweisungen, dafür fehlt das Recht „Geräte zuweisen“.',
            ]);
        }

        foreach (['asset_tag' => 'Inventarnummer', 'serial_number' => 'Seriennummer'] as $field => $label) {
            $duplicates = collect($rows)
                ->pluck($field)
                ->filter(fn (mixed $value): bool => filled($value))
                ->map(fn (mixed $value): string => mb_strtolower(trim((string) $value)))
                ->duplicates();
            if ($duplicates->isNotEmpty()) {
                throw ValidationException::withMessages([
                    'inventoryImport' => "Die CSV enthält die {$label} mehrfach: ".$duplicates->first(),
                ]);
            }
        }

        $summary = DB::transaction(function () use ($rows, $actor): array {
            $summary = ['created' => 0, 'updated' => 0, 'assigned' => 0, 'rows' => count($rows)];

            foreach ($rows as $row) {
                $byAsset = filled($row['asset_tag']) ? Device::withTrashed()->where('asset_tag', $row['asset_tag'])->first() : null;
                $bySerial = filled($row['serial_number']) ? Device::withTrashed()->where('serial_number', $row['serial_number'])->first() : null;
                if ($byAsset && $bySerial && $byAsset->id !== $bySerial->id) {
                    throw ValidationException::withMessages([
                        'inventoryImport' => 'Inventar- und Seriennummer verweisen auf zwei unterschiedliche vorhandene Geräte.',
                    ]);
                }

                $device = $byAsset ?: $bySerial;
                $attributes = collect($row)
                    ->except('employee_email')
                    ->map(fn (mixed $value): mixed => is_string($value) && trim($value) === '' ? null : $value)
                    ->all();
                if ($device) {
                    if ($device->trashed()) {
                        throw ValidationException::withMessages([
                            'inventoryImport' => 'Ein Gerät der CSV ist ausgemustert/gelöscht und muss bewusst wiederhergestellt werden.',
                        ]);
                    }
                    $device->fill($attributes + ['updated_by' => $actor->id])->save();
                    $summary['updated']++;
                } else {
                    $device = Device::query()->create([
                        ...$attributes,
                        'lifecycle_status' => DeviceLifecycleStatus::Inventory,
                        'management_status' => DeviceManagementStatus::Unmanaged,
                        'compliance_status' => DeviceComplianceStatus::Unknown,
                        'created_by' => $actor->id,
                        'updated_by' => $actor->id,
                    ]);
                    $summary['created']++;
                }

                if (filled($row['employee_email']) && Gate::forUser($actor)->allows('devices.assign')) {
                    $employee = User::query()
                        ->whereRaw('LOWER(email) = ?', [mb_strtolower($row['employee_email'])])
                        ->where('status', true)
                        ->first();
                    if (! $employee) {
                        throw ValidationException::withMessages([
                            'inventoryImport' => 'Kein aktiver RailTime-Mitarbeiter für '.$row['employee_email'].' gefunden.',
                        ]);
                    }
                    $this->inventory->assign($device, $employee, $actor, 'Zuweisung aus Gerätebestandsimport');
                    $summary['assigned']++;
                } else {
                    $this->readiness->refresh($device);
                }
            }

            return $summary;
        });

        activity('device-management')
            ->causedBy($actor)
            ->withProperties($summary)
            ->log('device_inventory_csv_imported');

        return $summary;
    }

    /** @return array<int, array<string, string>> */
    private function readRows(UploadedFile $file): array
    {
        $handle = fopen($file->getRealPath(), 'rb');
        if (! is_resource($handle)) {
            return [];
        }

        try {
            $firstLine = fgets($handle);
            if (! is_string($firstLine)) {
                return [];
            }
            $delimiter = substr_count($firstLine, ';') >= substr_count($firstLine, ',') ? ';' : ',';
            rewind($handle);
            $rawHeaders = fgetcsv($handle, 0, $delimiter);
            if (! is_array($rawHeaders)) {
                return [];
            }

            $headers = array_map(function (string $header): ?string {
                $normalized = mb_strtolower(trim(str_replace("\xEF\xBB\xBF", '', $header)));

                return self::HEADER_ALIASES[$normalized] ?? null;
            }, $rawHeaders);
            if (in_array(null, $headers, true) || count(array_unique($headers)) !== count($headers)) {
                throw ValidationException::withMessages([
                    'inventoryImport' => 'Die CSV enthält unbekannte oder doppelte Spalten. Unterstützt: Inventarnummer, Seriennummer, Gerätename, Hostname, Plattform, Gerätetyp, Eigentum, Hersteller, Modell, Betriebssystem, Standort, Mitarbeiter_E-Mail.',
                ]);
            }

            $rows = [];
            while (($values = fgetcsv($handle, 0, $delimiter)) !== false) {
                if ($values === [null] || collect($values)->every(fn ($value): bool => trim((string) $value) === '')) {
                    continue;
                }
                if (count($values) !== count($headers)) {
                    throw ValidationException::withMessages(['inventoryImport' => 'Mindestens eine CSV-Zeile hat eine falsche Spaltenanzahl.']);
                }

                $row = [];
                foreach ($headers as $index => $header) {
                    $row[$header] = trim((string) ($values[$index] ?? ''));
                }
                $row += [
                    'asset_tag' => '', 'serial_number' => '', 'display_name' => '', 'hostname' => '',
                    'platform' => '', 'form_factor' => 'laptop', 'ownership' => 'corporate',
                    'manufacturer' => '', 'model' => '', 'os_version' => '',
                    'declared_location' => '', 'employee_email' => '',
                ];
                $rows[] = $row;
            }

            return $rows;
        } finally {
            fclose($handle);
        }
    }
}
