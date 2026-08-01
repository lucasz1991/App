<?php

namespace App\Models;

use App\Enums\RoomRecordingStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class RoomRecording extends Model
{
    protected $fillable = [
        'uuid',
        'room_id',
        'storage_scope_uuid',
        'livekit_egress_id',
        'status',
        'storage_disk',
        'storage_key',
        'mime_type',
        'size_bytes',
        'duration_ms',
        'requested_at',
        'start_deadline_at',
        'started_at',
        'completed_at',
        'expires_at',
        'error_code',
        'error_message',
    ];

    protected $casts = [
        'status' => RoomRecordingStatus::class,
        'size_bytes' => 'integer',
        'duration_ms' => 'integer',
        'requested_at' => 'datetime',
        'start_deadline_at' => 'datetime',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (RoomRecording $recording): void {
            $recording->uuid ??= (string) Str::uuid();
            $recording->storage_scope_uuid ??= (string) Str::uuid();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function isPlayable(): bool
    {
        return $this->status === RoomRecordingStatus::Complete
            && $this->storage_key !== null
            && ($this->expires_at === null || $this->expires_at->isFuture());
    }
}
