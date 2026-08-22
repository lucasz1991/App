<?php

namespace App\Notifications;

use App\Models\DeviceEnrollment;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Bewusst nicht gequeued: Der kurzlebige Klartext-Token darf weder serialisiert
 * noch in einer Queue-Payload oder Datenbankbenachrichtigung landen.
 */
class DeviceEnrollmentInvitation extends Notification
{
    public function __construct(
        private readonly DeviceEnrollment $enrollment,
        private readonly string $plainToken,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $device = $this->enrollment->device;
        $expiresAt = $this->enrollment->expires_at?->timezone(config('app.timezone'));
        $url = route('devices.enrollment', ['token' => $this->plainToken]);
        $deviceName = $device->display_name ?: $device->hostname ?: __('app.device_enrollment_default_device');

        if ($device->asset_tag) {
            $deviceName .= __('app.device_enrollment_asset_suffix', ['asset_tag' => $device->asset_tag]);
        }

        return (new MailMessage)
            ->subject(__('app.device_enrollment_mail_subject'))
            ->greeting(__('app.device_enrollment_mail_greeting', ['name' => trim((string) $notifiable->name)]))
            ->line(__('app.device_enrollment_mail_intro'))
            ->line(__('app.device_enrollment_mail_device', ['device' => $deviceName]))
            ->line(__('app.device_enrollment_mail_duration'))
            ->line(__('app.device_enrollment_mail_credentials'))
            ->action(__('app.device_enrollment_mail_action'), $url)
            ->line($expiresAt
                ? __('app.device_enrollment_mail_expires', [
                    'date' => $expiresAt->locale(app()->getLocale())->translatedFormat(__('app.device_enrollment_mail_date_format')),
                ])
                : __('app.device_enrollment_mail_expires_soon'))
            ->line(__('app.device_enrollment_mail_support'));
    }
}
