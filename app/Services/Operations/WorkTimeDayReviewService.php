<?php

namespace App\Services\Operations;

use App\Models\ShiftAssignment;
use App\Models\User;
use App\Models\WorkTimeEntry;
use App\Models\WorkTimeEvent;
use App\Support\Operations\OperationsAccess;
use App\Support\Operations\OperationsDateTime;
use App\Support\Operations\OperationsTransaction;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class WorkTimeDayReviewService
{
    public function confirmedAssignmentIds(int $userId, array $assignmentIds, ?int $exceptEntryId = null): array
    {
        if (! $assignmentIds) {
            return [];
        }
        $confirmed = [];
        foreach (array_chunk(array_unique($assignmentIds), 200) as $ids) {
            $reviews = WorkTimeEntry::where('user_id', $userId)
                ->when($exceptEntryId, fn ($query) => $query->where('id', '!=', $exceptEntryId))
                ->where(function ($query) use ($ids) {
                    foreach ($ids as $id) {
                        $query->orWhereJsonContains('plan_snapshot->day_review->assignment_ids', (int) $id);
                    }
                })->get(['plan_snapshot']);
            foreach ($reviews as $entry) {
                $confirmed += array_fill_keys($entry->plan_snapshot['day_review']['assignment_ids'], true);
            }
        }

        return array_values(array_intersect($assignmentIds, array_keys($confirmed)));
    }

    public function draft(WorkTimeEntry $entry): array
    {
        abort_unless(($entry->plan_snapshot['day_clock'] ?? false) && $entry->ends_at, 404);
        $starts = $entry->starts_at->timestamp;
        $ends = $entry->ends_at->timestamp;
        $pauses = $entry->activities()->where('kind', 'break')->orderBy('starts_at')->orderBy('id')->get();
        $cursor = $starts;
        $work = [];
        $breaks = [];
        $issue = null;
        foreach ($pauses as $pause) {
            $from = $pause->starts_at->timestamp;
            $to = $pause->ends_at?->timestamp;
            if ($to === null || $from < $cursor || $to < $from || $to > $ends || $pause->source === 'needs_review') {
                $issue = 'Bitte die Pausen im Arbeitsabschnitt prüfen und erneut öffnen.';
                break;
            }
            if ($cursor < $from) {
                $work[] = [$cursor, $from];
            }
            $breaks[] = [$from, $to];
            $cursor = $to;
        }
        if ($cursor < $ends) {
            $work[] = [$cursor, $ends];
        }
        if (array_sum(array_map(fn ($span) => $span[1] - $span[0], $work)) !== $entry->netSeconds()) {
            $issue = 'Für die Zuordnung werden Beginn und Ende jeder Pause benötigt. Bitte die Arbeitsabschnitte prüfen.';
        }

        $candidates = $this->duties($entry);
        $fields = [];
        $previous = collect($entry->plan_snapshot['day_review']['duties'] ?? [])->keyBy('assignment_id');
        foreach ($candidates as $id => $duty) {
            $fields[$id] = [
                'performed' => true,
                'starts_at' => $this->local($duty['from'], $entry->timezone),
                'ends_at' => $this->local($duty['to'], $entry->timezone),
            ];
            if ($saved = $previous->get($id)) {
                $fields[$id]['performed'] = $saved['performed'];
                if ($saved['performed']) {
                    $from = CarbonImmutable::parse($saved['starts_at'])->timestamp;
                    $to = CarbonImmutable::parse($saved['ends_at'])->timestamp;
                    if ($from >= $starts && $to <= $ends) {
                        $fields[$id]['starts_at'] = $this->local($from, $entry->timezone);
                        $fields[$id]['ends_at'] = $this->local($to, $entry->timezone);
                        $candidates[$id]['from'] = $from;
                        $candidates[$id]['to'] = $to;
                    }
                }
            }
        }

        return [
            'work' => $work, 'breaks' => $breaks, 'issue' => $issue, 'duties' => $candidates, 'fields' => $fields,
            'basis' => hash('sha256', json_encode([$entry->revision, $starts, $ends, $work, $candidates], JSON_THROW_ON_ERROR)),
        ];
    }

    private function duties(WorkTimeEntry $entry): array
    {
        $duties = [];
        $marked = $entry->events()->where('kind', 'duty_start')->orderBy('occurred_at')->orderBy('id')->get();
        $assignments = ShiftAssignment::where('user_id', $entry->user_id)->where('status', 'confirmed')
            ->whereDoesntHave('timeEntry', fn ($query) => $query->where('id', '!=', $entry->id))
            ->whereHas('shift', fn ($query) => $query->where('starts_at', '<', $entry->ends_at->utc())->where('ends_at', '>', $entry->starts_at->utc())
                ->where('published_revision', '>', 0)->whereColumn('published_revision', 'revision')
                ->whereColumn('shift_assignments.plan_revision', 'shifts.published_revision')->whereNotIn('status', ['draft', 'cancelled']))
            ->with('shift')->get();
        foreach ($assignments as $assignment) {
            $shift = $assignment->shift;
            $duties[$assignment->id] = [
                'title' => $shift->title, 'planned_from' => $shift->starts_at->timestamp, 'planned_to' => $shift->ends_at->timestamp,
                'from' => max($entry->starts_at->timestamp, $shift->starts_at->timestamp), 'to' => min($entry->ends_at->timestamp, $shift->ends_at->timestamp),
            ];
        }
        foreach ($marked as $index => $mark) {
            $data = $mark->data;
            $plannedFrom = CarbonImmutable::parse($data['planned_starts_at'])->timestamp;
            $plannedTo = CarbonImmutable::parse($data['planned_ends_at'])->timestamp;
            $duties[(int) $data['assignment_id']] = [
                'title' => $data['title'], 'planned_from' => $plannedFrom, 'planned_to' => $plannedTo,
                'from' => max($entry->starts_at->timestamp, $mark->occurred_at->timestamp),
                'to' => min($entry->ends_at->timestamp, $plannedTo, $marked->get($index + 1)?->occurred_at->timestamp ?? PHP_INT_MAX),
            ];
        }
        if (! $duties) {
            return [];
        }
        $ids = array_keys($duties);
        $handled = $this->confirmedAssignmentIds($entry->user_id, $ids, $entry->id);
        $otherMarks = WorkTimeEvent::where('kind', 'duty_start')
            ->whereIn('work_time_entry_id', WorkTimeEntry::select('id')->where('user_id', $entry->user_id)->where('id', '!=', $entry->id))
            ->whereIn('data->assignment_id', $ids)->get(['data'])->map(fn ($event) => $event->data['assignment_id']);
        $duties = array_diff_key($duties, array_flip(array_merge($handled, $otherMarks->all())));
        uasort($duties, fn ($a, $b) => $a['from'] <=> $b['from'] ?: $a['to'] <=> $b['to']);

        return $duties;
    }

    private function local(int $timestamp, string $timezone): string
    {
        return CarbonImmutable::createFromTimestampUTC($timestamp)->setTimezone($timezone)->format('Y-m-d\TH:i:s');
    }

    public function calculate(WorkTimeEntry $entry, array $draft, array $fields): array
    {
        if (array_diff_key($draft['duties'], $fields) || array_diff_key($fields, $draft['duties'])) {
            throw ValidationException::withMessages(['dayReview' => 'Die Dienste haben sich geändert. Bitte den Tagesbeleg erneut öffnen.']);
        }
        $taken = [];
        $rows = [];
        $seconds = 0;
        $conflict = $draft['issue'];
        foreach ($draft['duties'] as $id => $duty) {
            $input = Validator::make((array) $fields[$id], [
                'performed' => 'required|boolean', 'starts_at' => 'nullable|string|max:25', 'ends_at' => 'nullable|string|max:25',
            ])->validate();
            $performed = (bool) $input['performed'];
            $from = $to = null;
            $sum = 0;
            if ($performed) {
                // Unchanged suggestions keep their actual UTC instant, including repeated DST wall times.
                $from = ($input['starts_at'] ?? '') === $draft['fields'][$id]['starts_at'] ? $duty['from']
                    : OperationsDateTime::local($input['starts_at'] ?? '', $entry->timezone, 'dayReview')->timestamp;
                $to = ($input['ends_at'] ?? '') === $draft['fields'][$id]['ends_at'] ? $duty['to']
                    : OperationsDateTime::local($input['ends_at'] ?? '', $entry->timezone, 'dayReview')->timestamp;
                if ($from < $entry->starts_at->timestamp || $to > $entry->ends_at->timestamp || $to <= $from) {
                    $conflict = 'Dienstbeginn und Dienstende müssen in der erfassten Arbeitszeit liegen. Bitte die Zuordnung ändern.';
                }
                foreach ($draft['work'] as [$workFrom, $workTo]) {
                    $left = max($from, $workFrom);
                    $right = min($to, $workTo);
                    if ($left < $right) {
                        $taken[] = [$left, $right];
                        $sum += $right - $left;
                    }
                }
                if ($sum === 0) {
                    $conflict ??= 'Für den Dienst liegt keine Arbeitszeit vor. Bitte den Zeitraum ändern oder den Dienst als ausgefallen markieren.';
                }
            }
            $rows[] = [
                'assignment_id' => (int) $id, 'title' => $duty['title'], 'performed' => $performed,
                'starts_at' => $from !== null ? CarbonImmutable::createFromTimestampUTC($from)->toIso8601String() : null,
                'ends_at' => $to !== null ? CarbonImmutable::createFromTimestampUTC($to)->toIso8601String() : null,
                'seconds' => $sum,
            ];
            $seconds += $sum;
        }
        sort($taken);
        $end = null;
        foreach ($taken as [$from, $to]) {
            if ($end !== null && $from < $end) {
                $conflict = 'Die Dienstzeiten überschneiden sich. Bitte die tatsächlichen Zeiträume vor dem Bestätigen abgrenzen.';
                break;
            }
            $end = $to;
        }

        return [
            'net_seconds' => $entry->netSeconds(), 'standard_seconds' => $conflict ? null : $entry->netSeconds() - $seconds,
            'duties' => $rows, 'assignment_ids' => array_keys($draft['duties']), 'issue' => $conflict,
        ];
    }

    public function confirm(int $entryId, int $revision, string $basis, array $fields, string $eventKey, User $actor): WorkTimeEntry
    {
        OperationsAccess::own($actor, $actor->id);

        return OperationsTransaction::run(function () use ($entryId, $revision, $basis, $fields, $eventKey, $actor) {
            User::lockForUpdate()->findOrFail($actor->id);
            $entry = WorkTimeEntry::where('user_id', $actor->id)->lockForUpdate()->findOrFail($entryId);
            $request = hash('sha256', json_encode([$basis, $fields], JSON_THROW_ON_ERROR));
            $previous = $entry->plan_snapshot['day_review'] ?? [];
            if (in_array($entry->status, ['submitted', 'approved'], true)
                && ($previous['event_key'] ?? null) === $eventKey && ($previous['request_hash'] ?? null) === $request) {
                return $entry;
            }
            if ($entry->events()->where('event_key', $eventKey)->exists()) {
                throw ValidationException::withMessages(['dayReview' => 'Diese Bestätigung gehört zu einem früheren Stand. Bitte den Tagesbeleg erneut öffnen.']);
            }
            if (! in_array($entry->status, ['completed', 'returned'], true) || $entry->revision !== $revision) {
                throw ValidationException::withMessages(['dayReview' => 'Die Zeitmeldung wurde geändert. Bitte den Tagesbeleg erneut öffnen.']);
            }
            $draft = $this->draft($entry);
            if (! hash_equals($draft['basis'], $basis)) {
                throw ValidationException::withMessages(['dayReview' => 'Arbeitszeit oder Dienstplan wurden geändert. Bitte den Tagesbeleg erneut öffnen.']);
            }
            $summary = $this->calculate($entry, $draft, $fields);
            if ($summary['issue']) {
                throw ValidationException::withMessages(['dayReview' => $summary['issue']]);
            }
            unset($summary['issue']);
            $plan = $entry->plan_snapshot;
            $plan['day_review'] = $summary + ['entry_revision' => $revision, 'event_key' => $eventKey, 'request_hash' => $request];
            if ($entry->status === 'returned') {
                $entry->fill(['status' => 'completed', 'reviewed_by' => null, 'reviewed_at' => null, 'submitted_at' => null]);
            }
            $entry->fill(['plan_snapshot' => $plan])->save();

            return app(WorkTimeService::class)->clock($entry->id, $revision, 'submit', $eventKey, $actor);
        }, 3);
    }
}
