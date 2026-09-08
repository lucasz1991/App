<?php

namespace App\Livewire;

use App\Services\Support\SupportCaseService;
use Illuminate\Support\Str;
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

    #[Locked]
    public string $requestId = '';

    public function mount(): void
    {
        $this->requestId = (string) Str::uuid();
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

        app(SupportCaseService::class)->create(auth()->user(), ['request_id' => $this->requestId] + $validated);
        $this->requestId = (string) Str::uuid();

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

    /**
     * @return array<string, string>
     */
    protected function messages(): array
    {
        return [
            'category.required' => __('support.validation_category_required'),
            'category.in' => __('support.validation_category_invalid'),
            'subject.required' => __('support.validation_subject_required'),
            'subject.min' => __('support.validation_subject_min'),
            'subject.max' => __('support.validation_subject_max'),
            'message.required' => __('support.validation_message_required'),
            'message.min' => __('support.validation_message_min'),
            'message.max' => __('support.validation_message_max'),
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
