<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommunicationMeetingParticipant extends Model
{
    protected $fillable = ['communication_meeting_id', 'user_id', 'response', 'responded_at'];

    protected $casts = ['responded_at' => 'datetime'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function meeting(): BelongsTo
    {
        return $this->belongsTo(CommunicationMeeting::class, 'communication_meeting_id');
    }
}
