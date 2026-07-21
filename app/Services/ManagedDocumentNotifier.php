<?php

namespace App\Services;

use App\Models\ManagedDocument;
use App\Models\User;

class ManagedDocumentNotifier
{
    public function notify(ManagedDocument $document, string $event = 'updated'): int
    {
        if (! $document->notify_on_update || ! $document->is_active || ! $document->currentVersion) {
            return 0;
        }

        $document->loadMissing('currentVersion');
        $version = $document->currentVersion->version_number;
        $subject = __('app.managed_document_notification_subject', ['title' => $document->title]);
        $message = __('app.managed_document_notification_body', [
            'title' => $document->title,
            'version' => $version,
            'event' => __('app.managed_document_event_' . $event),
        ]);
        $actionUrl = route('managed-documents.download', $document, absolute: false);
        $senderId = auth()->id() ?: User::query()->where('role', 'admin')->value('id');
        $sent = 0;

        $document->recipientQuery()->select('users.*')->chunkById(100, function ($users) use (
            &$sent,
            $subject,
            $message,
            $senderId,
            $actionUrl
        ): void {
            foreach ($users as $user) {
                $user->receiveMessage(
                    $subject,
                    $message,
                    $senderId,
                    null,
                    $actionUrl,
                    __('app.open_current_file')
                );
                $sent++;
            }
        });

        return $sent;
    }
}
