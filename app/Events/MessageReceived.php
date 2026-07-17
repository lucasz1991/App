<?php

namespace App\Events;

use App\Models\Message;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Wird gefeuert, wenn ein Benutzer eine interne Nachricht erhaelt.
 * ShouldBroadcastNow: sendet direkt beim Request (kein Queue-Worker
 * noetig fuer die Echtzeit-Zustellung ueber Reverb).
 */
class MessageReceived implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public Message $message,
    ) {
    }

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('App.Models.User.' . $this->message->to_user);
    }

    public function broadcastAs(): string
    {
        return 'message.received';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->message->id,
            'subject' => $this->message->subject,
            'from' => $this->message->sender?->name,
        ];
    }
}
