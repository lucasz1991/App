<?php

namespace App\Support\Push;

use App\Models\ChatMessage;
use App\Models\Message;
use App\Models\User;
use App\Notifications\RailtimeWebPushNotification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

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
                title: $message->sender?->name ?: __('app.push_new_message_title'),
                body: $this->messagePreview($message),
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
                        title: $message->sender?->name ?: __('app.push_new_chat_title'),
                        body: $this->chatPreview($message),
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

    protected function messagePreview(Message $message): string
    {
        $subject = $this->previewText($message->subject, 70);
        $body = $this->previewText($message->message);

        if ($subject !== '' && $body !== '') {
            return $subject.': '.$body;
        }

        return $subject !== '' ? $subject : ($body !== '' ? $body : __('app.push_new_message_body'));
    }

    protected function chatPreview(ChatMessage $message): string
    {
        $body = $this->previewText($message->body);

        if ($body !== '') {
            return $body;
        }

        if ($message->isVoice()) {
            return __('app.voice_message');
        }

        if ($message->files()->exists()) {
            return __('app.chat_attachment');
        }

        return __('app.push_new_chat_body');
    }

    protected function previewText(?string $value, int $limit = 160): string
    {
        return Str::of((string) $value)
            ->stripTags()
            ->squish()
            ->limit($limit)
            ->toString();
    }
}
