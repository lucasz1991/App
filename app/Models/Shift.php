<?php

namespace App\Models;

use App\Enums\ShiftStatus;
use App\Models\Concerns\CapturesDropboxChanges;
use App\Models\Concerns\HasZonedSchedule;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Shift extends Model
{
    use CapturesDropboxChanges;
    use HasFactory;
    use \App\Models\Concerns\HasRoutingSite;
    use HasZonedSchedule;
    use SoftDeletes;

    protected $fillable = [
        'disposition_details',
        'public_id',
        'order_id',
        'title',
        'role_name',
        'starts_at',
        'ends_at',
        'timezone',
        'location_name',
        'customer_location_id',
        'location_snapshot',
        'required_staff',
        'status',
        'notes',
        'created_by',
        'updated_by',
        'planned_break_minutes',
    ];

    protected $casts = [
        'disposition_details' => 'array',
        'customer_location_id' => 'integer',
        'location_snapshot' => 'array',
        'required_staff' => 'integer',
        'status' => ShiftStatus::class,
        'revision' => 'integer',
        'published_revision' => 'integer',
        'published_at' => 'immutable_datetime',
        'published_snapshot' => 'array',
        'activity_requirements' => 'array',
        'planned_break_minutes' => 'integer',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $shift): void {
            $newRole = ! $shift->exists || $shift->isDirty('role_name');
            $pending = empty($shift->activity_requirements['confirmed']) && ! $shift->getOriginal('published_revision');
            if (($newRole || $pending) && \Illuminate\Support\Facades\Schema::hasColumn('shifts', 'activity_requirements')) {
                $shift->activity_requirements = $shift->currentActivityRequirements();
            }
        });

        static::creating(function (self $shift): void {
            if (blank($shift->public_id)) {
                $shift->public_id = (string) Str::uuid();
            }
        });
    }

    public function currentActivityRequirements(): array
    {
        return DutyActivity::fromCode($this->role_name)?->snapshot() ?? [
            'code' => $this->role_name, 'confirmed' => false, 'proof_type' => 'Einsatzbericht',
        ];
    }

    public function planningActivityRequirements(): ?array
    {
        if ($this->isDirty('role_name') && \Illuminate\Support\Facades\Schema::hasColumn('shifts', 'activity_requirements')) {
            return $this->currentActivityRequirements();
        }
        if (empty($this->activity_requirements['confirmed']) && ! $this->published_revision && \Illuminate\Support\Facades\Schema::hasColumn('shifts', 'activity_requirements')) {
            return $this->currentActivityRequirements();
        }

        return $this->activity_requirements;
    }

    public function sectionActivityRules(): \Illuminate\Support\Collection
    {
        if (! $this->exists || ! \Illuminate\Support\Facades\Schema::hasColumn('shift_sections', 'activity_requirements')) {
            return collect();
        }

        return ShiftSection::where('shift_id', $this->id)->whereNotNull('activity_requirements')->orderBy('starts_at')->get()
            ->mapWithKeys(fn ($section) => [$section->id => $section->activity_requirements + ['section_id' => $section->id,
                'section_label' => $section->label ?: $section->starts_at->timezone($section->timezone)->format('H:i')]]);
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(ShiftAssignment::class);
    }

    public function qualifications(): BelongsToMany
    {
        return $this->belongsToMany(QualificationType::class, 'shift_qualification_requirements');
    }

    public function assignees(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'shift_assignments')
            ->withPivot(['id', 'status', 'assigned_by', 'responded_at', 'note'])
            ->withTimestamps();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function scopeWithStatus(Builder $query, ShiftStatus|string $status): Builder
    {
        return $query->where('status', $status instanceof ShiftStatus ? $status->value : $status);
    }

    public function scopeUpcoming(Builder $query): Builder
    {
        return $query->where('ends_at', '>=', now()->utc());
    }

    public function scopeDuring(Builder $query, mixed $startsAt, mixed $endsAt): Builder
    {
        return $query
            ->where('starts_at', '<', CarbonImmutable::parse($endsAt)->utc())
            ->where('ends_at', '>', CarbonImmutable::parse($startsAt)->utc());
    }

    public function scopeNotCancelled(Builder $query): Builder
    {
        return $query->where('status', '!=', ShiftStatus::Cancelled->value);
    }
}
