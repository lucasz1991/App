<?php

namespace App\Models;

use App\Models\Concerns\CapturesDropboxChanges;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeQualification extends Model
{
    use CapturesDropboxChanges;

    protected $attributes = ['revision' => 1, 'status' => 'pending'];

    protected $guarded = ['id'];

    protected $casts = ['valid_from' => 'immutable_date', 'valid_until' => 'immutable_date', 'reviewed_at' => 'immutable_datetime', 'revision' => 'integer'];

    protected $hidden = ['evidence_path'];

    public function validityLabel(): string
    {
        if ($this->status !== 'approved') {
            return '—';
        }
        $today = now(config('operations.display_timezone', 'Europe/Berlin'))->toDateString();
        if ($this->valid_until->toDateString() < $today) {
            return 'Abgelaufen';
        }
        if ($this->valid_from->toDateString() > $today) {
            return 'Künftig gültig';
        }
        if ($this->valid_until->toDateString() <= now(config('operations.display_timezone', 'Europe/Berlin'))->addDays(30)->toDateString()) {
            return 'Läuft in 30 Tagen ab';
        }

        return 'Gültig';
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(QualificationType::class, 'qualification_type_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
