<?php

namespace App\Services\Operations;

use App\Models\OperationsMonthClosing;
use App\Models\User;
use App\Models\WorkTimeEntry;
use App\Support\Operations\OperationsEnhancementsSchema;
use App\Support\Operations\OperationsTransaction;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class PayrollClosingService
{
    public function ready(): bool
    {
        return Schema::hasColumns('operations_month_closings', ['id', 'user_id', 'month', 'timezone', 'status', 'revision', 'snapshot', 'prepared_by', 'closed_by', 'closed_at']);
    }

    /** All time writers serialize with a closing on the same user row. */
    public function assertMutable(User $subject, CarbonInterface $start, ?CarbonInterface $end = null): void
    {
        if (! Schema::hasTable('operations_month_closings')) {
            return;
        }
        abort_unless($this->ready(), 503, 'Monatssperren prüfen; Zeitänderung nicht verfügbar.');
        User::lockForUpdate()->findOrFail($subject->id);
        $end ??= $start->copy()->addSecond();
        foreach (OperationsMonthClosing::where('user_id', $subject->id)->where('status', 'closed')->get() as $closing) {
            [$a, $b] = $this->bounds($closing->month, $closing->timezone);
            if ($start->lt($b) && $end->gt($a)) {
                throw ValidationException::withMessages(['workflow' => 'Monat abgeschlossen. Vor Änderungen kontrolliert wieder öffnen.']);
            }
        }
    }

    private function bounds(string $month, ?string $zone = null): array
    {
        Validator::make(compact('month'), ['month' => 'required|date_format:Y-m'])->validate();
        $start = CarbonImmutable::parse($month.'-01 00:00:00', $zone ?? config('operations.display_timezone', 'Europe/Berlin'));

        return [$start->utc(), $start->addMonth()->utc()];
    }

    public function prepare(User $subject, string $month, User $actor): OperationsMonthClosing
    {
        app(PersonnelScopeService::class)->authorize($actor, $subject, 'operations.time.review');
        OperationsEnhancementsSchema::requireReady();
        abort_if($actor->id === $subject->id, 403);

        return OperationsTransaction::run(function () use ($subject, $month, $actor) {
            User::lockForUpdate()->findOrFail($subject->id);
            $existing = OperationsMonthClosing::where('user_id', $subject->id)->where('month', $month)->lockForUpdate()->first();
            abort_if($existing && $existing->status === 'closed', 409);
            $closing = $existing ?? new OperationsMonthClosing(['user_id' => $subject->id, 'month' => $month, 'timezone' => config('operations.display_timezone', 'Europe/Berlin'), 'revision' => 0]);
            $snapshot = $this->snapshot($subject, $month, $actor, $closing->timezone);
            $closing->forceFill(['status' => 'prepared', 'snapshot' => $snapshot, 'prepared_by' => $actor->id, 'revision' => $closing->revision + 1])->save();
            $this->revision($closing, 'prepared', $actor);

            return $closing;
        }, 3);
    }

    public function close(int $id, int $revision, User $actor): OperationsMonthClosing
    {
        $known = OperationsMonthClosing::findOrFail($id);
        app(PersonnelScopeService::class)->authorize($actor, (int) $known->user_id, 'operations.time.review');

        return OperationsTransaction::run(function () use ($known, $revision, $actor) {
            $subject = User::lockForUpdate()->findOrFail($known->user_id);
            $closing = OperationsMonthClosing::lockForUpdate()->findOrFail($known->id);
            abort_unless($closing->status === 'prepared' && $closing->revision === $revision && $closing->prepared_by !== $actor->id && $subject->id !== $actor->id, 409, 'Vier-Augen-Freigabe oder aktueller Stand fehlt.');
            $snapshot = $this->snapshot($subject, $closing->month, $actor, $closing->timezone);
            abort_unless(hash_equals(hash('sha256', json_encode($closing->snapshot)), hash('sha256', json_encode($snapshot))), 409, 'Monatsstand wurde geändert. Erneut vorbereiten.');
            abort_unless($snapshot['complete'], 422, 'Monat nicht vollständig geprüft.');
            $closing->forceFill(['status' => 'closed', 'closed_by' => $actor->id, 'closed_at' => now()->utc(), 'revision' => $revision + 1])->save();
            $this->revision($closing, 'closed', $actor);

            return $closing;
        }, 3);
    }

    public function reopen(int $id, int $revision, string $note, User $actor): OperationsMonthClosing
    {
        Validator::make(compact('note'), ['note' => 'required|string|min:10|max:2000'])->validate();
        $known = OperationsMonthClosing::findOrFail($id);
        app(PersonnelScopeService::class)->authorize($actor, (int) $known->user_id, 'operations.time.review');
        abort_if($known->user_id === $actor->id, 403);

        return OperationsTransaction::run(function () use ($known, $revision, $note, $actor) {
            User::lockForUpdate()->findOrFail($known->user_id);
            $closing = OperationsMonthClosing::lockForUpdate()->findOrFail($known->id);
            abort_unless($closing->revision === $revision && $closing->status === 'closed', 409);
            $closing->forceFill(['status' => 'reopened', 'revision' => $revision + 1, 'note' => $note])->save();
            $this->revision($closing, 'reopened', $actor);

            return $closing;
        }, 3);
    }

    private function snapshot(User $subject, string $month, User $actor, string $timezone): array
    {
        [$from, $until] = $this->bounds($month, $timezone);
        abort_unless($until->lte(now()->utc()), 422, 'Monat noch nicht beendet.');
        $entries = WorkTimeEntry::where('user_id', $subject->id)->where('starts_at', '<', $until)->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', $from))->orderBy('id')->lockForUpdate()->get();
        $completeness = app(WorkTimeExtensionService::class)->completeness($subject, $month.'-01', $until->subDay()->setTimezone($timezone)->toDateString(), $actor, $timezone);
        $rows = [];
        $issues = [];
        foreach ($entries as $entry) {
            $value = app(WorkTimeRemunerationService::class)->evaluate($entry, $from, $until);
            if ($entry->creditedSeconds() === null) {
                $value['complete'] = false;
                $value['issues'][] = 'Stundenkontobewertung fehlt.';
            }
            if (! $value['complete']) {
                $issues[] = ['entry_id' => $entry->id, 'issues' => $value['issues']];
            }
            $rows[] = ['id' => $entry->id, 'revision' => $entry->revision, 'status' => $entry->status, 'starts_at' => $entry->starts_at->toIso8601String(), 'ends_at' => $entry->ends_at?->toIso8601String(), 'raw_net_seconds' => $entry->netSeconds(), 'credited_seconds' => $entry->creditedSeconds(), 'quantities' => $value['quantities']];
        }

        return ['schema' => 'railtime-payroll-preparation-v1', 'external_delivery' => false, 'month' => $month, 'timezone' => $timezone, 'user_id' => $subject->id, 'payroll_reference' => app(PayrollReferenceService::class)->snapshot($subject->id), 'entries' => $rows, 'issues' => $issues, 'completeness' => $completeness, 'complete' => $issues === [] && $completeness['complete'] && $entries->every(fn ($e) => $e->status === 'approved')];
    }

    private function revision(OperationsMonthClosing $closing, string $action, User $actor): void
    {
        $closing->revisions()->create(['revision' => $closing->revision, 'action' => $action, 'snapshot' => $closing->snapshot, 'actor_id' => $actor->id, 'created_at' => now()->utc()]);
        app(OperationsAuditService::class)->record($closing, $actor, 'payroll.'.$action, ['revision' => $closing->revision]);
    }

    public function returnForCorrection(int $entryId, int $revision, string $note, User $actor): void
    {
        Validator::make(compact('note'), ['note' => 'required|string|min:10|max:2000'])->validate();
        $known = WorkTimeEntry::findOrFail($entryId);
        app(PersonnelScopeService::class)->authorize($actor, (int) $known->user_id, 'operations.time.review');
        abort_if($known->user_id === $actor->id, 403);
        OperationsTransaction::run(function () use ($known, $revision, $note, $actor) {
            $subject = User::lockForUpdate()->findOrFail($known->user_id);
            $entry = WorkTimeEntry::lockForUpdate()->findOrFail($known->id);
            $this->assertMutable($subject, $entry->starts_at, $entry->ends_at);
            abort_unless($entry->status === 'approved' && $entry->revision === $revision, 409);
            $entry->revisions()->create(['revision' => $entry->revision, 'action' => 'before_payroll_correction', 'actor_id' => $actor->id, 'snapshot' => $entry->toArray() + ['activities' => $entry->activities()->get()->toArray()], 'created_at' => now()->utc()]);
            $entry->forceFill(['status' => 'returned', 'revision' => $revision + 1, 'review_note' => $note])->save();
            app(OperationsAuditService::class)->record($entry, $actor, 'payroll.time_returned', ['revision' => $entry->revision, 'note' => $note]);
        }, 3);
    }

    public function csv(int $id, int $revision, User $actor, bool $delta = false): string
    {
        $closing = OperationsMonthClosing::findOrFail($id);
        app(PersonnelScopeService::class)->authorize($actor, (int) $closing->user_id, 'operations.time.export');
        $version = $closing->revisions()->where('revision', $revision)->where('action', 'closed')->firstOrFail();
        $previous = $delta ? $closing->revisions()->where('action', 'closed')->where('revision', '<', $revision)->latest('revision')->first() : null;
        $quantities = function ($snapshot) {
            $q = [];
            foreach ($snapshot['entries'] as $entry) {
                foreach ($entry['quantities'] as $row) {
                    $q[$row['wage_code']] = ($q[$row['wage_code']] ?? 0) + $row['quantity_seconds'];
                }
            }

            return $q;
        };
        $current = $quantities($version->snapshot);
        $old = $previous ? $quantities($previous->snapshot) : [];
        $rows = [];
        foreach (array_unique([...array_keys($current), ...array_keys($old)]) as $code) {
            $rows[] = [1, $closing->id, $revision, $closing->month, $closing->user_id, $version->snapshot['payroll_reference']['personnel_number'] ?? null, $code, ($current[$code] ?? 0) - ($old[$code] ?? 0), $delta ? 'delta' : 'full'];
        }

        return app(OperationsReportService::class)->csv(['Schema', 'Abschluss', 'Revision', 'Monat', 'Mitarbeiter-ID', 'Personalnummer', 'Lohnart', 'Vergütungsmenge (Sek.)', 'Profil'], $rows);
    }
}
