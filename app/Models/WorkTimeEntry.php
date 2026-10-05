<?php

namespace App\Models;

use App\Models\Concerns\CapturesDropboxChanges;
use App\Models\Concerns\HasZonedSchedule;
use App\Support\Operations\WorkTimeSchema;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkTimeEntry extends Model
{
    use CapturesDropboxChanges;

    protected $attributes = ['revision' => 1, 'status' => 'running', 'pause_seconds' => 0];

    use HasZonedSchedule;

    protected $guarded = ['id'];

    protected $casts = ['plan_snapshot' => 'array', 'revision' => 'integer', 'pause_seconds' => 'integer', 'submitted_at' => 'immutable_datetime', 'reviewed_at' => 'immutable_datetime'];

    protected function pausedAt(): Attribute
    {
        return $this->zonedDateTimeAttribute();
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(ShiftAssignment::class, 'shift_assignment_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function events(): HasMany
    {
        return $this->hasMany(WorkTimeEvent::class);
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(WorkTimeRevision::class);
    }

    public function activities(): HasMany
    {
        return $this->hasMany(WorkTimeActivity::class);
    }

    public function contextLabel(): string
    {
        return ['shift' => 'Dienst', 'internal' => 'Interne Arbeit', 'training' => 'Schulung', 'unplanned' => 'Ungeplante Arbeit'][$this->work_context ?? 'shift'] ?? 'Arbeit';
    }

    public function creditedSeconds(): ?int
    {
        if (! WorkTimeSchema::ready()) {
            return $this->netSeconds();
        }
        $rates = $this->plan_snapshot['valuation'] ?? [];
        if (! array_key_exists('valuation', $this->plan_snapshot ?? []) && ($this->work_context ?? 'shift') === 'shift') {
            return $this->netSeconds();
        }
        if (! $this->ends_at || ! $rates) {
            return null;
        }
        $activities = $this->activities()->orderBy('starts_at')->get();
        if ($activities->isEmpty()) {
            $kind = in_array($this->work_context, ['internal', 'training'], true) ? $this->work_context : 'work';
            if (! array_key_exists($kind, $rates) || ($this->pause_seconds > 0 && ! array_key_exists('break', $rates))) {
                return null;
            }

            return (int) floor($this->netSeconds() * $rates[$kind] / 10000 + $this->pause_seconds * ($rates['break'] ?? 0) / 10000);
        }
        $cursor = $this->starts_at->utc();
        $credited = 0;
        foreach ($activities as $activity) {
            if (! $activity->ends_at || $activity->source === 'needs_review' || ! $activity->starts_at->equalTo($cursor) || ! array_key_exists($activity->kind, $rates)) {
                return null;
            }
            $credited += max(0, (int) $activity->starts_at->diffInSeconds($activity->ends_at)) * $rates[$activity->kind] / 10000;
            $cursor = $activity->ends_at->utc();
        }

        return $cursor->equalTo($this->ends_at) ? (int) floor($credited) : null;
    }

    public function netSeconds(): int
    {
        $end = $this->ends_at ?? now()->utc();
        $pause = $this->pause_seconds + ($this->paused_at ? max(0, (int) $this->paused_at->diffInSeconds($end)) : 0);

        return max(0, (int) $this->starts_at->diffInSeconds($end) - $pause);
    }
}
