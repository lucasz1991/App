@php
    $templateBuilder = new \App\Support\EmailTemplateBuilder(auth()->user());
    $templateValues = $templateBuilder->profileValues();
    $missingContactData = $templateValues['DURCHWAHL'] === '' || auth()->user()->profile?->position === null;
@endphp

<div
    class="rounded-2xl bg-rt-surface-muted p-1.5 shadow-rt-sm ring-1 ring-rt-border/60 dark:bg-rt-dark-surface-muted dark:ring-rt-dark-border/60"
    data-anim="fade-up"
>
    <div class="rounded-[calc(1rem-2px)] bg-rt-surface p-5 dark:bg-rt-dark-surface sm:p-6">
        <h3 class="text-base font-semibold tracking-tight text-rt-text dark:text-rt-dark-text">
            {{ __('app.email_templates') }}
        </h3>
        <p class="mt-1 text-sm text-rt-muted dark:text-rt-dark-muted">
            {{ __('app.email_templates_intro') }}
        </p>

        {{-- Vorschau der eingesetzten Daten --}}
        <dl class="mt-5 grid grid-cols-1 gap-x-6 gap-y-3 rounded-xl bg-rt-surface-muted p-4 text-sm ring-1 ring-rt-border/60 dark:bg-rt-dark-surface-muted dark:ring-rt-dark-border/60 sm:grid-cols-2">
            <div>
                <dt class="text-xs font-semibold uppercase tracking-wide text-rt-muted dark:text-rt-dark-muted">{{ __('app.name') }}</dt>
                <dd class="mt-0.5 font-medium text-rt-text dark:text-rt-dark-text">{{ $templateValues['VORNAME_NACHNAME'] }}</dd>
            </div>
            <div>
                <dt class="text-xs font-semibold uppercase tracking-wide text-rt-muted dark:text-rt-dark-muted">{{ __('app.position') }}</dt>
                <dd class="mt-0.5 font-medium text-rt-text dark:text-rt-dark-text">{{ $templateValues['POSITION'] }}</dd>
            </div>
            <div>
                <dt class="text-xs font-semibold uppercase tracking-wide text-rt-muted dark:text-rt-dark-muted">{{ __('app.email') }}</dt>
                <dd class="mt-0.5 font-medium text-rt-text dark:text-rt-dark-text">{{ $templateValues['E_MAIL'] }}</dd>
            </div>
            <div>
                <dt class="text-xs font-semibold uppercase tracking-wide text-rt-muted dark:text-rt-dark-muted">{{ __('app.phone') }} / {{ __('app.mobile') }}</dt>
                <dd class="mt-0.5 font-medium text-rt-text dark:text-rt-dark-text">
                    {{ $templateValues['DURCHWAHL'] !== '' ? $templateValues['DURCHWAHL'] : '—' }}
                    &middot;
                    {{ $templateValues['MOBIL'] !== '' ? $templateValues['MOBIL'] : '—' }}
                </dd>
            </div>
        </dl>

        @if ($missingContactData)
            <div class="mt-4 flex items-start gap-3 rounded-xl bg-amber-50 p-4 text-sm text-amber-800 ring-1 ring-inset ring-amber-600/20 dark:bg-amber-500/10 dark:text-amber-300 dark:ring-amber-500/30">
                <i class="fad fa-info-circle mt-0.5" aria-hidden="true"></i>
                <span>{{ __('app.email_templates_missing_data') }}</span>
            </div>
        @endif

        {{-- Downloads --}}
        <ul class="mt-6 space-y-3">
            @foreach (\App\Support\EmailTemplateBuilder::available() as $key => $template)
                <li class="flex flex-col gap-3 rounded-xl p-4 ring-1 ring-rt-border/60 dark:ring-rt-dark-border/60 sm:flex-row sm:items-center sm:justify-between">
                    <div class="flex min-w-0 items-start gap-3">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-rt-accent-soft text-rt-accent dark:bg-rt-dark-accent-soft dark:text-rt-dark-accent">
                            <i class="fad {{ str_starts_with($key, 'vorlage') ? 'fa-envelope-open-text' : 'fa-signature' }}" aria-hidden="true"></i>
                        </span>
                        <div class="min-w-0">
                            <p class="font-semibold text-rt-text dark:text-rt-dark-text">{{ __($template['label']) }}</p>
                            <p class="mt-0.5 text-sm text-rt-muted dark:text-rt-dark-muted">{{ __($template['hint']) }}</p>
                        </div>
                    </div>
                    <a
                        href="{{ route('profile.email-templates', ['template' => $key]) }}"
                        class="inline-flex w-fit shrink-0 items-center gap-2 rounded-lg bg-rt-red px-4 py-2 text-sm font-semibold text-white shadow-rt-xs transition-all duration-300 ease-rt-spring hover:bg-rt-red-dark"
                    >
                        <i class="fad fa-download" aria-hidden="true"></i>
                        {{ __('app.download') }}
                    </a>
                </li>
            @endforeach
        </ul>

        <p class="mt-5 text-xs text-rt-muted dark:text-rt-dark-muted">
            {{ __('app.email_templates_legal_hint') }}
        </p>
    </div>
</div>
