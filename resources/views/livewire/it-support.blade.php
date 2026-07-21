@section('title', __('app.it_support'))

<x-ui.page
    :title="__('app.it_support')"
    :eyebrow="__('app.help_and_contact')"
    :description="__('app.it_support_description')"
>
    <div class="grid min-w-0 gap-4 lg:grid-cols-[minmax(0,1.45fr)_minmax(17rem,.55fr)]" data-anim="fade-up">
        <form
            wire:submit="submit"
            class="min-w-0 rounded-2xl bg-rt-surface p-4 shadow-rt-sm ring-1 ring-rt-border/60 dark:bg-rt-dark-surface dark:ring-rt-dark-border/60 sm:p-6"
        >
            @if ($sent)
                <x-ui.feedback.alert type="success" :title="__('app.it_support_sent_title')" class="mb-5">
                    {{ __('app.it_support_sent') }}
                </x-ui.feedback.alert>
            @endif

            @error('form')
                <x-ui.feedback.alert type="danger" class="mb-5">
                    {{ $message }}
                </x-ui.feedback.alert>
            @enderror

            <div class="grid min-w-0 gap-4 sm:grid-cols-2">
                <div class="min-w-0">
                    <x-ui.forms.label for="it_support_category" :value="__('app.it_support_category')" />
                    <x-ui.forms.select id="it_support_category" wire:model="category" class="mt-1.5">
                        @foreach ($categories as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </x-ui.forms.select>
                    <x-ui.forms.input-error for="category" class="mt-1.5" />
                </div>

                <div class="min-w-0">
                    <x-ui.forms.label for="it_support_subject" :value="__('app.subject')" />
                    <x-ui.forms.input
                        id="it_support_subject"
                        type="text"
                        maxlength="160"
                        wire:model="subject"
                        class="mt-1.5"
                        :placeholder="__('app.it_support_subject_placeholder')"
                    />
                    <x-ui.forms.input-error for="subject" class="mt-1.5" />
                </div>
            </div>

            <div class="mt-4 min-w-0">
                <x-ui.forms.label for="it_support_message" :value="__('app.it_support_message')" />
                <x-ui.forms.textarea
                    id="it_support_message"
                    rows="9"
                    maxlength="5000"
                    wire:model="message"
                    placeholder="{{ __('app.it_support_message_placeholder') }}"
                    class="mt-1.5 min-h-44 py-3"
                />
                <div class="mt-1.5 flex flex-wrap items-start justify-between gap-2">
                    <x-ui.forms.input-error for="message" />
                    <span class="ml-auto text-[11px] text-rt-soft dark:text-rt-dark-soft">{{ __('app.it_support_message_limit') }}</span>
                </div>
            </div>

            <div class="mt-6 flex flex-col gap-3 border-t border-rt-border/70 pt-5 dark:border-rt-dark-border/70 sm:flex-row sm:items-center sm:justify-between">
                <p class="text-xs leading-5 text-rt-muted dark:text-rt-dark-muted">
                    {{ __('app.it_support_reply_hint', ['email' => $sender->email]) }}
                </p>
                <x-ui.buttons.button-basic
                    type="submit"
                    mode="primary"
                    class="w-full shrink-0 sm:w-auto"
                    wire:loading.attr="disabled"
                    wire:target="submit"
                >
                    <i data-feather="send" class="h-4 w-4" wire:loading.remove wire:target="submit"></i>
                    <i class="fad fa-spinner-third fa-spin" wire:loading wire:target="submit" aria-hidden="true"></i>
                    <span wire:loading.remove wire:target="submit">{{ __('app.it_support_send') }}</span>
                    <span wire:loading wire:target="submit">{{ __('app.sending') }}</span>
                </x-ui.buttons.button-basic>
            </div>
        </form>

        <aside class="min-w-0 space-y-4">
            <section class="rounded-2xl bg-rt-surface p-4 shadow-rt-sm ring-1 ring-rt-border/60 dark:bg-rt-dark-surface dark:ring-rt-dark-border/60 sm:p-5">
                <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-rt-accent-soft text-rt-accent dark:bg-rt-dark-accent-soft dark:text-rt-dark-accent">
                    <i data-feather="life-buoy" class="h-5 w-5"></i>
                </span>
                <h2 class="mt-4 text-base font-semibold text-rt-text dark:text-white">{{ __('app.it_support_what_happens') }}</h2>
                <ul class="mt-3 space-y-3 text-sm leading-6 text-rt-muted dark:text-rt-dark-muted">
                    <li class="flex gap-2.5"><i data-feather="check" class="mt-1 h-4 w-4 shrink-0 text-rt-accent"></i><span>{{ __('app.it_support_step_email') }}</span></li>
                    <li class="flex gap-2.5"><i data-feather="check" class="mt-1 h-4 w-4 shrink-0 text-rt-accent"></i><span>{{ __('app.it_support_step_context') }}</span></li>
                    <li class="flex gap-2.5"><i data-feather="check" class="mt-1 h-4 w-4 shrink-0 text-rt-accent"></i><span>{{ __('app.it_support_step_reply') }}</span></li>
                </ul>
            </section>

            <section class="rounded-2xl bg-rt-surface-muted p-4 ring-1 ring-rt-border/60 dark:bg-rt-dark-surface-muted dark:ring-rt-dark-border/60 sm:p-5">
                <p class="text-[10px] font-semibold uppercase tracking-[0.16em] text-rt-soft dark:text-rt-dark-soft">{{ __('app.sender') }}</p>
                <p class="mt-2 truncate text-sm font-semibold text-rt-text dark:text-white">{{ $sender->name }}</p>
                <p class="mt-0.5 break-all text-xs text-rt-muted dark:text-rt-dark-muted">{{ $sender->email }}</p>
            </section>
        </aside>
    </div>
</x-ui.page>
