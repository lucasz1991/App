<?php

namespace App\Livewire\Admin\Users\Messages;

use App\Models\Mail;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithFileUploads;

class MessageForm extends Component
{
    use WithFileUploads;

    public array $selectedUsers = [];
    public array $directRecipients = [];

    public bool $showMailModal = false;
    public ?int $mailUserId = null;
    public bool $mailWithMail = false;
    public bool $forceMailOnly = false;

    public string $mailSubject = '';
    public string $mailHeader = '';
    public string $mailBody = '';
    public string $mailLink = '';

    public array $fileUploads = [];

    #[On('openMailModal')]
    public function handleOpenMailModal($payload = null): void
    {
        $this->resetRecipientSelection();

        if (is_numeric($payload)) {
            $this->mailUserId = (int) $payload;
            $this->showMailModal = true;
            return;
        }

        if (is_array($payload) && $this->openDirectRecipientsModal($payload)) {
            return;
        }

        if (is_array($payload) && ! empty($payload)) {
            $ids = array_values(array_unique(array_map('intval', $payload)));
            $ids = array_values(array_filter($ids, fn (int $id) => $id > 0));

            if (! empty($ids)) {
                $this->selectedUsers = $ids;
                $this->showMailModal = true;
                return;
            }
        }

        $this->dispatch('swal:toast', type: 'info', text: __('app.select_recipient_hint'));
    }

    public function handleModalClosed(): void
    {
        if ($this->showMailModal) {
            return;
        }

        $this->resetRecipientSelection();
        $this->mailWithMail = false;
        $this->mailSubject = '';
        $this->mailHeader = '';
        $this->mailBody = '';
        $this->mailLink = '';
        $this->fileUploads = [];
        $this->resetValidation();
    }

    public function resetMailModal(): void
    {
        $this->showMailModal = false;
        $this->resetRecipientSelection();
        $this->mailWithMail = false;
        $this->mailSubject = '';
        $this->mailHeader = '';
        $this->mailBody = '';
        $this->mailLink = '';
        $this->fileUploads = [];
        $this->resetValidation();
    }

    public function sendMail(): void
    {
        $this->validate([
            'mailSubject' => 'required|string|max:255',
            'mailHeader' => 'required|string|max:255',
            'mailBody' => 'required|string',
            'mailLink' => [
                'nullable',
                'max:2048',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if ($value === null || $value === '') {
                        return;
                    }

                    if (! filter_var($value, FILTER_VALIDATE_URL)) {
                        $fail(__('app.enter_valid_link'));
                    }
                },
            ],
            'fileUploads' => ['array'],
            'fileUploads.*' => ['file', 'max:2048'],
        ], [
            'mailSubject.required' => __('app.enter_subject'),
            'mailSubject.max' => __('app.subject_max_length'),
            'mailHeader.required' => __('app.enter_header'),
            'mailHeader.max' => __('app.header_max_length'),
            'mailBody.required' => __('app.enter_message'),
            'fileUploads.*.max' => __('app.file_too_large'),
        ]);

        $content = [
            'subject' => $this->mailSubject,
            'header' => $this->mailHeader,
            'body' => $this->mailBody,
            'link' => $this->mailLink,
        ];

        $type = $this->resolveMailType();
        $mail = null;

        if ($this->mailUserId) {
            $user = User::find($this->mailUserId);

            if (! $user) {
                $this->dispatch('swal:toast', type: 'error', text: __('app.user_not_found'));
                return;
            }

            $mail = Mail::create([
                'type' => $type,
                'status' => false,
                'content' => $content,
                'recipients' => [[
                    'user_id' => $user->id,
                    'email' => $user->email,
                    'status' => false,
                ]],
            ]);

            $this->dispatch('swal:toast', type: 'success', text: __('app.mail_prepared_for_email', ['email' => $user->email]));
        } elseif (! empty($this->directRecipients)) {
            $mail = Mail::create([
                'type' => 'mail',
                'status' => false,
                'content' => $content,
                'recipients' => collect($this->directRecipients)
                    ->map(fn (string $email) => [
                        'email' => $email,
                        'status' => false,
                    ])
                    ->values()
                    ->all(),
            ]);

            $recipientLabel = count($this->directRecipients) === 1
                ? $this->directRecipients[0]
                : __('app.x_mail_addresses', ['count' => count($this->directRecipients)]);

            $this->dispatch('swal:toast', type: 'success', text: __('app.mail_prepared_for_email', ['email' => $recipientLabel]));
        } else {
            $recipients = [];

            foreach ($this->selectedUsers as $userId) {
                $user = User::find($userId);

                if (! $user) {
                    continue;
                }

                $recipients[] = [
                    'user_id' => $user->id,
                    'email' => $user->email,
                    'status' => false,
                ];
            }

            if (empty($recipients)) {
                $this->dispatch('swal:toast', type: 'error', text: __('app.no_valid_recipients'));
                return;
            }

            $mail = Mail::create([
                'type' => $type,
                'status' => false,
                'content' => $content,
                'recipients' => $recipients,
            ]);

            $this->dispatch('swal:toast', type: 'success', text: __('app.mail_prepared_for_count', ['count' => count($recipients)]));
        }

        foreach ($this->fileUploads as $uploadedFile) {
            $filename = $uploadedFile->getClientOriginalName();
            $path = $uploadedFile->store('uploads/files', 'private');
            $mime = Storage::disk('private')->mimeType($path) ?: $uploadedFile->getClientMimeType();

            $mail->files()->create([
                'name' => $filename,
                'path' => $path,
                'disk' => 'private',
                'mime_type' => $mime,
                'size' => $uploadedFile->getSize(),
                'expires_at' => null,
            ]);
        }

        $this->resetMailModal();
    }

    protected function openDirectRecipientsModal(array $payload): bool
    {
        $emails = $payload['emails'] ?? $payload['email'] ?? null;

        if ($emails === null) {
            return false;
        }

        $normalized = $this->normalizeEmails($emails);

        if (empty($normalized)) {
            $this->dispatch('swal:toast', type: 'error', text: __('app.invalid_email_hint'));
            return true;
        }

        $this->directRecipients = $normalized;
        $this->forceMailOnly = (bool) ($payload['forceMailOnly'] ?? $payload['force_mail_only'] ?? true);
        $this->mailWithMail = true;
        $this->showMailModal = true;

        return true;
    }

    protected function normalizeEmails(array|string $emails): array
    {
        if (is_string($emails)) {
            $emails = preg_split('/[\s,;]+/', $emails, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        }

        return collect($emails)
            ->map(fn ($email) => strtolower(trim((string) $email)))
            ->filter(fn (string $email) => filter_var($email, FILTER_VALIDATE_EMAIL))
            ->unique()
            ->values()
            ->all();
    }

    protected function resetRecipientSelection(): void
    {
        $this->selectedUsers = [];
        $this->directRecipients = [];
        $this->mailUserId = null;
        $this->forceMailOnly = false;
    }

    protected function resolveMailType(): string
    {
        if ($this->forceMailOnly || ! empty($this->directRecipients)) {
            return 'mail';
        }

        return $this->mailWithMail ? 'both' : 'message';
    }

    public function render()
    {
        return view('livewire.admin.users.messages.message-form');
    }
}
