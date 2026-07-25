<?php

namespace App\Support\Push;

use App\Models\ChatMessage;
use App\Models\Message;
use App\Models\User;
use App\Notifications\RailtimeWebPushNotification;
use Illuminate\Support\Facades\Log;

class PushDelivery
{
    public function messageReceived(Message $message): void
    {
        $recipient = $message->recipient()->first();

        if (! $recipient) {
            return;
        }

        $this->notify(
            $recipient,
            new RailtimeWebPushNotification(
                notificationId: 'message:'.$message->getKey(),
                title: __('app.push_new_message_title'),
                body: __('app.push_new_message_body'),
                url: 'messages?open='.$message->getKey(),
                category: PushCategory::Messages,
            ),
        );
    }

    public function chatMessageReceived(ChatMessage $message): void
    {
        $message->chat
            ->participants()
            ->where('users.id', '!=', $message->user_id)
            ->where('users.status', true)
            ->eachById(function (User $recipient) use ($message): void {
                $this->notify(
                    $recipient,
                    new RailtimeWebPushNotification(
                        notificationId: 'chat-message:'.$message->getKey(),
                        title: __('app.push_new_chat_title'),
                        body: __('app.push_new_chat_body'),
                        url: 'chat?chat='.$message->chat_id,
                        category: PushCategory::Chat,
                    ),
                );
            });
    }

    protected function notify(User $recipient, RailtimeWebPushNotification $notification): void
    {
        try {
            $recipient->notify($notification);
        } catch (\Throwable $exception) {
            Log::notice('Web-Push konnte nicht eingeplant werden.', [
                'notification_id' => $notification->notificationId,
                'user_id' => $recipient->getKey(),
                'error_class' => $exception::class,
            ]);
        }
    }
}
