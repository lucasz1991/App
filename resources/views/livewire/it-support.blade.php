@section('title', __('app.it_support'))

@php
    $categoryMeta = [
        'question' => ['icon' => 'fa-comment-alt-question', 'description' => __('support.support_category_question_description')],
        'technical_issue' => ['icon' => 'fa-tools', 'description' => __('support.support_category_technical_issue_description')],
        'feedback' => ['icon' => 'fa-waveform-path', 'description' => __('support.support_category_feedback_description')],
        'feature_request' => ['icon' => 'fa-lightbulb-on', 'description' => __('support.support_category_feature_request_description')],
    ];
@endphp

<x-ui.page :title="__('app.it_support')">
    <div class="rt-support-app" data-it-support data-support-experience="support">
        <section class="rt-support-hero" aria-labelledby="support-hero-title" data-support-hero>
            <div class="rt-support-hero__route" aria-hidden="true">
                <svg viewBox="0 0 680 190" preserveAspectRatio="none">
                    <path data-support-route-path d="M-20 166C104 166 123 74 249 93C367 111 410 28 520 51C578 63 618 38 700 7" />
                    <circle data-support-route-node cx="249" cy="93" r="6" />
                    <circle data-support-route-node cx="520" cy="51" r="6" />
                </svg>
            </div>
            <div class="rt-support-hero__copy" data-support-hero-copy>
                <p class="rt-support-kicker rt-support-kicker--inverse">
                    <span class="rt-support-kicker__mark" aria-hidden="true"></span>
                    {{ __('support.support_hero_label') }}
                </p>
                <h2 id="support-hero-title">{{ __('support.support_hero_title') }}</h2>
                <p>{{ __('support.support_hero_description') }}</p>
            </div>
            <a href="{{ route('help') }}" wire:navigate class="rt-support-hero__help" data-support-hero-card>
                <span><i class="far fa-compass" aria-hidden="true"></i></span>
                <small>{{ __('app.help') }}</small>
                <strong>{{ __('support.support_help_action') }}</strong>
                <i class="far fa-arrow-right" aria-hidden="true"></i>
            </a>
        </section>

        <div class="rt-support-workspace">
            <form
                wire:submit="submit"
                class="rt-support-composer"
                data-support-form
                data-support-reveal
                x-data="{ characterCount: @js(mb_strlen($message)) }"
            >
                <header class="rt-support-composer__header">
                    <div>
                        <p class="rt-support-kicker">
                            <span class="rt-support-kicker__mark" aria-hidden="true"></span>
                            {{ __('support.support_form_label') }}
                        </p>
                        <h2>{{ __('support.support_form_title') }}</h2>
                        <p>{{ __('support.support_form_description') }}</p>
                    </div>
                    <span class="rt-support-composer__secure" aria-label="{{ __('support.support_privacy') }}">
                        <i class="far fa-shield-check" aria-hidden="true"></i>
                    </span>
                </header>

                <div class="rt-support-composer__alerts" aria-live="polite">
                    @if ($sent)
                        <x-ui.feedback.alert type="success" :title="__('app.it_support_sent_title')">
                            {{ __('app.it_support_sent') }}
                        </x-ui.feedback.alert>
                    @endif

                    @error('form')
                        <x-ui.feedback.alert type="danger">
                            {{ $message }}
                        </x-ui.feedback.alert>
                    @enderror
                </div>

                <fieldset class="rt-support-category">
                    <legend>{{ __('app.it_support_category') }}</legend>
                    <div class="rt-support-category__grid">
                        @foreach ($categories as $value => $label)
                            @php $meta = $categoryMeta[$value]; @endphp
                            <label>
                                <input type="radio" name="support_category" value="{{ $value }}" wire:model="category">
                                <span class="rt-support-category__icon"><i class="far {{ $meta['icon'] }}" aria-hidden="true"></i></span>
                                <span class="rt-support-category__copy">
                                    <strong>{{ $label }}</strong>
                                    <small>{{ $meta['description'] }}</small>
                                </span>
                                <span class="rt-support-category__check"><i class="far fa-check" aria-hidden="true"></i></span>
                            </label>
                        @endforeach
                    </div>
                    <x-ui.forms.input-error for="category" class="mt-2" />
                </fieldset>

                <div class="rt-support-field">
                    <div class="rt-support-field__label">
                        <x-ui.forms.label for="it_support_subject" :value="__('app.subject')" />
                        <span>5–160</span>
                    </div>
                    <x-ui.forms.input
                        id="it_support_subject"
                        type="text"
                        maxlength="160"
                        wire:model="subject"
                        :placeholder="__('app.it_support_subject_placeholder')"
                    />
                    <p class="rt-support-field__hint">{{ __('support.support_subject_hint') }}</p>
                    <x-ui.forms.input-error for="subject" class="mt-1.5" />
                </div>

                <div class="rt-support-field">
                    <div class="rt-support-field__label">
                        <x-ui.forms.label for="it_support_message" :value="__('app.it_support_message')" />
                        <span x-text="@js(__('support.support_characters', ['count' => '__COUNT__'])).replace('__COUNT__', characterCount)"></span>
                    </div>
                    <x-ui.forms.textarea
                        id="it_support_message"
                        rows="10"
                        maxlength="5000"
                        wire:model="message"
                        x-on:input="characterCount = $event.target.value.length"
                        placeholder="{{ __('app.it_support_message_placeholder') }}"
                        class="min-h-56 py-3"
                    />
                    <p class="rt-support-field__hint">{{ __('support.support_message_hint') }}</p>
                    <x-ui.forms.input-error for="message" class="mt-1.5" />
                </div>

                <footer class="rt-support-composer__footer">
                    <p><i class="far fa-lock" aria-hidden="true"></i>{{ __('support.support_privacy') }}</p>
                    <x-ui.buttons.button-basic
                        type="submit"
                        mode="primary"
                        class="w-full shrink-0 sm:w-auto"
                        wire:loading.attr="disabled"
                        wire:target="submit"
                    >
                        <i class="far fa-paper-plane" wire:loading.remove wire:target="submit" aria-hidden="true"></i>
                        <i class="fad fa-spinner-third fa-spin" wire:loading wire:target="submit" aria-hidden="true"></i>
                        <span wire:loading.remove wire:target="submit">{{ __('app.it_support_send') }}</span>
                        <span wire:loading wire:target="submit">{{ __('app.sending') }}</span>
                    </x-ui.buttons.button-basic>
                </footer>
            </form>

            <aside class="rt-support-sidebar" data-support-reveal>
                <section class="rt-support-process">
                    <header>
                        <p class="rt-support-kicker rt-support-kicker--inverse">
                            <span class="rt-support-kicker__mark" aria-hidden="true"></span>
                            {{ __('support.support_process_label') }}
                        </p>
                        <h2>{{ __('app.it_support_what_happens') }}</h2>
                    </header>
                    <ol>
                        @foreach ([
                            ['icon' => 'fa-paper-plane', 'text' => __('app.it_support_step_email')],
                            ['icon' => 'fa-fingerprint', 'text' => __('app.it_support_step_context')],
                            ['icon' => 'fa-reply', 'text' => __('app.it_support_step_reply')],
                        ] as $step)
                            <li>
                                <span>{{ str_pad((string) $loop->iteration, 2, '0', STR_PAD_LEFT) }}</span>
                                <i class="far {{ $step['icon'] }}" aria-hidden="true"></i>
                                <p>{{ $step['text'] }}</p>
                            </li>
                        @endforeach
                    </ol>
                </section>

                <section class="rt-support-context-card">
                    <span><i class="far fa-link" aria-hidden="true"></i></span>
                    <div>
                        <h3>{{ __('support.support_context_title') }}</h3>
                        <p>{{ __('support.support_context_description') }}</p>
                    </div>
                </section>

                <section class="rt-support-sender">
                    <div class="rt-support-sender__avatar" aria-hidden="true">{{ \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr($sender->name, 0, 1)) }}</div>
                    <div>
                        <p>{{ __('support.support_sender_label') }}</p>
                        <strong>{{ $sender->name }}</strong>
                        <a href="mailto:{{ $sender->email }}">{{ $sender->email }}</a>
                        <small>{{ __('support.support_sender_description') }}</small>
                    </div>
                </section>
            </aside>
        </div>
    </div>
</x-ui.page>
