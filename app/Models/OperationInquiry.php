<?php

namespace App\Models;

use App\Models\Concerns\HasZonedSchedule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class OperationInquiry extends Model
{
    protected $attributes = ['revision' => 1, 'status' => 'new'];

    use HasZonedSchedule;

    protected $guarded = ['id'];

    protected $casts = ['offer' => 'array', 'revision' => 'integer', 'verified_revision' => 'integer', 'accepted_revision' => 'integer', 'required_staff' => 'integer'];

    protected static function booted(): void
    {
        static::creating(function (self $inquiry): void {
            $inquiry->public_id ??= (string) Str::uuid();
        });
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function duplicateOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'duplicate_of_id');
    }

    public function getNumberAttribute(): string
    {
        return sprintf('A-%06d', $this->id);
    }
}
