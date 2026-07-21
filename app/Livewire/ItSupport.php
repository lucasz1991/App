<?php

namespace App\Livewire;

use App\Models\Mail as MailModel;
use App\Support\SupportRecipient;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Component;

class ItSupport extends Component
{
    public string $category = 'question';

    public string $subject = '';

    public string $message = '';

    public bool $sent = false;

    #[Locked]
    public ?string $originPath = null;

    public function mount(): void
    {
        $previousUrl = url()->previous();
        $appHost = parse_url((string) config('app.url'), PHP_URL_HOST);
        $previousHost = parse_url($previousUrl, PHP_URL_HOST);

        if ($appHost && $previousHost === $appHost) {
            $this->originPath = parse_url($previousUrl, PHP_URL_PATH) ?: null;
        }
    }

    public function submit(): void
    {
        $validated = $this->validate([
            'category' => ['required', Rule::in(array_keys($this->categories()))],
            'subject' => ['required', 'string', 'min:5', 'max:160'],
            'message' => ['required', 'string', 'min:20', 'max:5000'],
        ]);

        $rateLimitKey = 'it-support:' . auth()->id();

        if (RateLimiter::tooManyAttempts($rateLimitKey, 3)) {
            $seconds = RateLimiter::availableIn($rateLimitKey);
            $message = __('app.it_support_rate_limit', [
                'minutes' => max(1, (int) ceil($seconds / 60)),
            ]);
            $this->addError('form', $message);
            // 'form' ist keine Komponenten-Property und ueberlebt Livewires
            // Snapshot-Filter nicht — der Fehlerton kommt daher ueber den Toast.
            $this->dispatch('swal:toast', type: 'error', text: $message);

            return;
        }

        $recipient = SupportRecipient::resolve();

        if (! $recipient) {
            $message = __('app.it_support_recipient_missing');
            $this->addError('form', $message);
            $this->dispatch('swal:toast', type: 'error', text: $message);

            return;
        }

        $user = auth()->user();
        $categoryLabel = $this->categories()[$validated['category']];
        $messageLines = preg_split('/\R/u', trim($validated['message'])) ?: [];
        $teamName = $user->dashboardTeam()?->name ?: __('app.not_set');

        MailModel::query()->create([
            'type' => 'mail',
            'status' => false,
            'content' => [
                'subject' => '[IT-Support] ' . trim($validated['subject']),
                'header' => __('app.it_support_mail_heading'),
                'body' => trim($validated['message']),
                'lines' => array_merge([
                    __('app.it_support_mail_category', ['category' => $categoryLabel]),
                    __('app.it_support_mail_sender', [
                        'name' => $user->name,
                        'email' => $user->email,
                    ]),
                    __('app.it_support_mail_account', [
                        'id' => $user->id,
                        'role' => $user->role,
                        'team' => $teamName,
                    ]),
                    $this->originPath
                        ? __('app.it_support_mail_origin', ['path' => $this->originPath])
                        : null,
                    __('app.it_support_mail_message'),
                ], array_values(array_filter($messageLines, static fn ($line): bool => trim((string) $line) !== ''))),
                'reply_to' => $user->email,
                'reply_to_name' => $user->name,
                'support_request' => true,
            ],
            'recipients' => [[
                'email' => $recipient,
                'status' => false,
            ]],
        ]);

        RateLimiter::hit($rateLimitKey, 600);

        $this->reset('subject', 'message');
        $this->category = 'question';
        $this->sent = true;
        $this->resetValidation();
        $this->dispatch('swal:toast', type: 'success', text: __('app.it_support_sent'));
    }

    /**
     * @return array<string, string>
     */
    public function categories(): array
    {
        return [
            'question' => __('app.it_support_category_question'),
            'technical_issue' => __('app.it_support_category_technical_issue'),
            'feedback' => __('app.it_support_category_feedback'),
            'feature_request' => __('app.it_support_category_feature_request'),
        ];
    }

    public function render()
    {
        return view('livewire.it-support', [
            'categories' => $this->categories(),
            'sender' => auth()->user(),
        ])->layout('layouts.master', [
            'area' => auth()->user()->usesAdminLayout() ? 'admin' : 'user',
        ]);
    }
}
