<?php

namespace App\Services\Operations;

use App\Models\AbsenceRequest;
use App\Models\User;
use App\Models\WorkTimeEntry;
use App\Support\Operations\OperationsAccess;
use App\Support\Operations\ReportingPeriod;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class OperationsReportService
{
    public function ownTimes(User $actor, string $from, string $until): string
    {
        OperationsAccess::own($actor, $actor->id);
        OperationsAccess::requireReady();
        $entries = ReportingPeriod::apply(WorkTimeEntry::where('user_id', $actor->id), $from, $until)->orderBy('starts_at')->limit(5001)->get();
        $this->limit($entries->count());
        app(OperationsAuditService::class)->record($actor, $actor, 'time.personal_export', compact('from', 'until') + ['count' => $entries->count()]);

        return $this->csv(['Zeit-ID', 'Revision', 'Dienst', 'Auftrag', 'Beginn', 'Ende', 'Zeitzone', 'Pause (Sek.)', 'Netto (Sek.)', 'Status'],
            $entries->map(fn ($e) => [$e->id, $e->revision, $e->plan_snapshot['title'] ?? '', $e->plan_snapshot['order_number'] ?? '', $e->starts_at->toIso8601String(), $e->ends_at?->toIso8601String(), $e->timezone, $e->pause_seconds, $e->netSeconds(), $e->status])->all());
    }

    public function absences(User $actor, string $from, string $until, string $status = 'all', string $kind = 'all', string $search = ''): string
    {
        OperationsAccess::authorize($actor, 'operations.absences.review');
        OperationsAccess::requireReady();
        Validator::make(compact('status', 'kind'), ['status' => 'required|in:all,pending,approved,rejected,withdrawn,cancelled', 'kind' => 'required|in:all,vacation,unavailable,other'])->validate();
        $records = ReportingPeriod::apply(AbsenceRequest::with('user:id,name'), $from, $until, true)
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))->when($kind !== 'all', fn ($q) => $q->where('kind', $kind))
            ->when(filled($search), fn ($q) => $q->whereHas('user', fn ($q) => $q->where('name', 'like', '%'.mb_substr($search, 0, 100).'%')))->orderBy('starts_at')->limit(5001)->get();
        $this->limit($records->count());
        app(OperationsAuditService::class)->record($actor, $actor, 'absence.exported', compact('from', 'until', 'status', 'kind') + ['count' => $records->count()]);

        // Notes and reasons are intentionally excluded from personnel exports.
        return $this->csv(['Antrag-ID', 'Revision', 'Mitarbeiter-ID', 'Mitarbeiter', 'Art', 'Beginn', 'Ende', 'Zeitzone', 'Status'],
            $records->map(fn ($a) => [$a->id, $a->revision, $a->user_id, $a->user?->name, $a->kind, $a->starts_at->toIso8601String(), $a->ends_at->toIso8601String(), $a->timezone, $a->status])->all());
    }

    private function limit(int $count): void
    {
        if ($count > 5000) {
            throw ValidationException::withMessages(['workflow' => 'Mehr als 5.000 Einträge. Bitte Zeitraum verkürzen.']);
        }
    }

    private function csv(array $headers, array $rows): string
    {
        $stream = fopen('php://temp', 'w+');
        fwrite($stream, "\xEF\xBB\xBF");
        fputcsv($stream, $headers, ';', '"', '');
        foreach ($rows as $row) {
            fputcsv($stream, array_map(fn ($v) => preg_match('/^[\s\x00-\x1f]*[=+@-]/u', (string) $v) ? "'".$v : $v, $row), ';', '"', '');
        }
        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);

        return $csv;
    }
}
