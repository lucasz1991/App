@extends('layouts.master')

@section('title', __('app.email_templates'))

@section('content')
    @php
        $user = auth()->user();
        $templateBuilder = new \App\Support\EmailTemplateBuilder($user);
        $templateValues = $templateBuilder->profileValues();
        $availableTemplates = collect(\App\Support\EmailTemplateBuilder::available());
        $mailTemplates = $availableTemplates->where('category', 'mail');
        $signatureTemplates = $availableTemplates->where('category', 'signature');
        $previewTemplates = $mailTemplates->where('previewable', true);
        $previewUrls = $previewTemplates
            ->mapWithKeys(fn (array $template, string $key) => [
                $key => route('email-templates.preview', ['template' => $key]),
            ])
            ->all();
        $previewDownloadUrls = $previewTemplates
            ->mapWithKeys(fn (array $template, string $key) => [
                $key => route('email-templates.download', ['template' => $key]),
            ])
            ->all();
        $previewLabels = $previewTemplates
            ->mapWithKeys(fn (array $template, string $key) => [
                $key => __('app.email_templates_theme_'.$template['theme']).' · '.strtoupper($template['format']),
            ])
            ->all();
        $defaultPreview = (string) $previewTemplates->keys()->first();
        $missingPhone = $templateValues['DURCHWAHL'] === '' && $templateValues['MOBIL'] === '';
        $missingPosition = blank($user->profile?->position);
        $missingContactData = $missingPhone || $missingPosition;
        $themes = [
            'light' => [
                'label' => __('app.email_templates_theme_light'),
                'hint' => __('app.email_templates_theme_light_hint'),
                'icon' => 'far fa-sun',
            ],
            'dark' => [
                'label' => __('app.email_templates_theme_dark'),
                'hint' => __('app.email_templates_theme_dark_hint'),
                'icon' => 'far fa-moon-stars',
            ],
        ];
    @endphp

    <x-ui.page
        :title="__('app.email_templates')"
        :description="__('app.email_templates_intro')"
        :eyebrow="__('app.personal_data')"
        :count="$availableTemplates->count()"
    >
        <div
            x-data="{
                openAccordionSection: null,
                previewTemplate: @js($defaultPreview),
                previewUrls: @js($previewUrls),
                previewDownloadUrls: @js($previewDownloadUrls),
                previewLabels: @js($previewLabels),
                toggleAccordionSection(section) {
                    this.openAccordionSection = this.openAccordionSection === section ? null : section;
                },
            }"
            class="space-y-6"
            data-email-templates-page
        >
            <div class="grid items-start gap-3 xl:grid-cols-2" data-email-template-accordions>
                <x-ui.accordion.section
                    section="profile"
                    :label="__('app.profile_status')"
                    :description="__('app.email_templates_profile_accordion_hint')"
                    icon="fad fa-address-card"
                    id-prefix="email-templates"
                    data-email-template-accordion="profile"
                >
                    <div class="space-y-4">
                        <div class="flex flex-col gap-4 rounded-2xl bg-rt-surface p-4 ring-1 ring-rt-border/60 dark:bg-rt-dark-surface dark:ring-rt-dark-border/60 sm:flex-row sm:items-center sm:justify-between">
                            <div class="flex min-w-0 items-center gap-3.5">
                                <img
                                    src="{{ $user->profile_photo_url }}"
                                    alt="{{ $user->name }}"
                                    class="h-12 w-12 rounded-xl object-cover shadow-rt-xs ring-1 ring-rt-border/70 dark:ring-rt-dark-border/70"
                                >
                                <div class="min-w-0">
                                    <p class="truncate text-base font-semibold tracking-tight text-rt-text dark:text-rt-dark-text">
                                        {{ $user->name }}
                                    </p>
                                    <p class="mt-0.5 truncate text-xs text-rt-muted dark:text-rt-dark-muted">
                                        {{ $templateValues['POSITION'] }}
                                    </p>
                                </div>
                            </div>

                            <span @class([
                                'inline-flex w-fit items-center gap-2 rounded-full px-3 py-1.5 text-xs font-semibold ring-1 ring-inset',
                                'bg-amber-50 text-amber-800 ring-amber-600/20 dark:bg-amber-500/10 dark:text-amber-300 dark:ring-amber-500/30' => $missingContactData,
                                'bg-emerald-50 text-emerald-800 ring-emerald-600/20 dark:bg-emerald-500/10 dark:text-emerald-300 dark:ring-emerald-500/30' => ! $missingContactData,
                            ])>
                                <i class="far {{ $missingContactData ? 'fa-info-circle' : 'fa-check-circle' }}" aria-hidden="true"></i>
                                {{ $missingContactData ? __('app.missing') : __('app.ready') }}
                            </span>
                        </div>

                        <dl class="grid gap-px overflow-hidden rounded-2xl bg-rt-border/70 ring-1 ring-rt-border/70 dark:bg-rt-dark-border/70 dark:ring-rt-dark-border/70 sm:grid-cols-2">
                            <div class="bg-rt-surface px-4 py-3.5 dark:bg-rt-dark-surface">
                                <dt class="text-[10px] font-bold uppercase tracking-[0.14em] text-rt-soft dark:text-rt-dark-soft">{{ __('app.position') }}</dt>
                                <dd class="mt-1.5 text-sm font-medium text-rt-text dark:text-rt-dark-text">{{ $templateValues['POSITION'] }}</dd>
                            </div>
                            <div class="bg-rt-surface px-4 py-3.5 dark:bg-rt-dark-surface">
                                <dt class="text-[10px] font-bold uppercase tracking-[0.14em] text-rt-soft dark:text-rt-dark-soft">{{ __('app.email') }}</dt>
                                <dd class="mt-1.5 break-all text-sm font-medium text-rt-text dark:text-rt-dark-text">{{ $templateValues['E_MAIL'] }}</dd>
                            </div>
                            <div class="bg-rt-surface px-4 py-3.5 dark:bg-rt-dark-surface">
                                <dt class="text-[10px] font-bold uppercase tracking-[0.14em] text-rt-soft dark:text-rt-dark-soft">{{ __('app.phone') }}</dt>
                                <dd class="mt-1.5 text-sm font-medium text-rt-text dark:text-rt-dark-text">{{ $templateValues['DURCHWAHL'] !== '' ? $templateValues['DURCHWAHL'] : '—' }}</dd>
                            </div>
                            <div class="bg-rt-surface px-4 py-3.5 dark:bg-rt-dark-surface">
                                <dt class="text-[10px] font-bold uppercase tracking-[0.14em] text-rt-soft dark:text-rt-dark-soft">{{ __('app.mobile') }}</dt>
                                <dd class="mt-1.5 text-sm font-medium text-rt-text dark:text-rt-dark-text">{{ $templateValues['MOBIL'] !== '' ? $templateValues['MOBIL'] : '—' }}</dd>
                            </div>
                        </dl>

                        @if ($missingContactData)
                            <div class="rounded-2xl bg-amber-50 p-4 text-sm text-amber-900 ring-1 ring-inset ring-amber-600/20 dark:bg-amber-500/10 dark:text-amber-200 dark:ring-amber-500/25">
                                <p class="font-semibold">{{ __('app.email_templates_missing_data') }}</p>
                                <div class="mt-2 space-y-1 text-xs leading-5 opacity-90">
                                    @if ($missingPhone)
                                        <p>{{ __('app.email_templates_phone_missing') }}</p>
                                    @endif
                                    @if ($missingPosition)
                                        <p>{{ __('app.email_templates_position_managed') }}</p>
                                    @endif
                                </div>
                                @if ($missingPhone)
                                    <a
                                        href="{{ route('profile.show') }}"
                                        wire:navigate
                                        class="mt-3 inline-flex items-center gap-2 text-xs font-semibold underline decoration-amber-500/50 underline-offset-4 transition hover:decoration-current"
                                    >
                                        {{ __('app.complete_profile') }}
                                        <i class="far fa-arrow-right" aria-hidden="true"></i>
                                    </a>
                                @endif
                            </div>
                        @else
                            <div class="flex items-start gap-2.5 rounded-2xl bg-emerald-50 p-4 text-sm text-emerald-800 ring-1 ring-inset ring-emerald-600/20 dark:bg-emerald-500/10 dark:text-emerald-300 dark:ring-emerald-500/25">
                                <i class="far fa-check-circle mt-0.5" aria-hidden="true"></i>
                                <p class="leading-5">{{ __('app.email_templates_profile_ready_detail') }}</p>
                            </div>
                        @endif
                    </div>
                </x-ui.accordion.section>

                <x-ui.accordion.section
                    section="preview"
                    :label="__('app.email_templates_preview_accordion')"
                    :description="__('app.email_templates_preview_accordion_hint')"
                    icon="fad fa-window-restore"
                    id-prefix="email-templates"
                    data-email-template-accordion="preview"
                >
                    <div class="space-y-4">
                        <div class="flex flex-wrap items-center gap-2" role="group" aria-label="{{ __('app.email_templates_preview_accordion') }}">
                            @foreach ($previewTemplates as $key => $template)
                                <button
                                    type="button"
                                    x-on:click="previewTemplate = @js($key)"
                                    x-bind:aria-pressed="(previewTemplate === @js($key)).toString()"
                                    class="inline-flex min-h-10 items-center gap-2 rounded-xl px-3.5 py-2 text-sm font-semibold ring-1 ring-inset transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rt-red/40"
                                    x-bind:class="previewTemplate === @js($key)
                                        ? 'bg-rt-red text-white ring-rt-red shadow-rt-xs'
                                        : 'bg-rt-surface text-rt-muted ring-rt-border hover:text-rt-red dark:bg-rt-dark-surface dark:text-rt-dark-muted dark:ring-rt-dark-border dark:hover:text-rt-dark-accent'"
                                >
                                    <i class="{{ $template['theme'] === 'dark' ? 'far fa-moon-stars' : 'far fa-sun' }}" aria-hidden="true"></i>
                                    {{ __('app.email_templates_theme_'.$template['theme']) }}
                                </button>
                            @endforeach
                        </div>

                        <p class="flex items-start gap-2 text-xs leading-5 text-rt-muted dark:text-rt-dark-muted">
                            <i class="far fa-sparkles mt-0.5 text-rt-red dark:text-rt-dark-accent" aria-hidden="true"></i>
                            {{ __('app.email_templates_preview_lazy_hint') }}
                        </p>

                        {{-- x-if ist hier bewusst: Ein verborgenes iframe würde
                             trotzdem laden. Erst das offene Accordion erzeugt
                             die personalisierte Vorschau im DOM. --}}
                        <template x-if="openAccordionSection === 'preview'">
                            <div class="overflow-hidden rounded-2xl bg-rt-surface shadow-rt-sm ring-1 ring-rt-border/70 dark:bg-rt-dark-surface dark:ring-rt-dark-border/70" data-email-template-preview>
                                <header class="flex flex-col gap-3 border-b border-rt-border/70 px-4 py-3 dark:border-rt-dark-border/70 sm:flex-row sm:items-center sm:justify-between">
                                    <div class="min-w-0">
                                        <p class="text-[10px] font-bold uppercase tracking-[0.16em] text-rt-red dark:text-rt-dark-accent">{{ __('app.preview') }}</p>
                                        <p class="mt-0.5 truncate text-sm font-semibold text-rt-text dark:text-rt-dark-text" x-text="previewLabels[previewTemplate]"></p>
                                    </div>
                                    <div class="flex flex-wrap items-center gap-2">
                                        <a
                                            x-bind:href="previewUrls[previewTemplate]"
                                            target="_blank"
                                            rel="noopener"
                                            class="inline-flex min-h-9 items-center gap-2 rounded-lg px-3 py-1.5 text-xs font-semibold text-rt-red ring-1 ring-inset ring-rt-red/20 transition hover:bg-rt-accent-soft dark:text-rt-dark-accent dark:ring-rt-dark-accent/25 dark:hover:bg-rt-dark-accent-soft"
                                        >
                                            <i class="far fa-external-link-alt" aria-hidden="true"></i>
                                            {{ __('app.email_templates_open_preview') }}
                                        </a>
                                        <a
                                            x-bind:href="previewDownloadUrls[previewTemplate]"
                                            data-no-navigate
                                            class="inline-flex min-h-9 items-center gap-2 rounded-lg bg-rt-red px-3 py-1.5 text-xs font-semibold text-white shadow-rt-xs transition hover:bg-rt-red-dark"
                                        >
                                            <i class="far fa-download" aria-hidden="true"></i>
                                            {{ __('app.download') }}
                                        </a>
                                    </div>
                                </header>
                                <iframe
                                    x-bind:src="previewUrls[previewTemplate]"
                                    title="{{ __('app.email_templates_preview_accordion') }}"
                                    sandbox=""
                                    loading="lazy"
                                    class="h-[34rem] w-full border-0 bg-white sm:h-[42rem]"
                                ></iframe>
                            </div>
                        </template>
                    </div>
                </x-ui.accordion.section>
            </div>

            <section
                class="overflow-hidden rounded-2xl bg-rt-surface shadow-rt-sm ring-1 ring-rt-border/70 dark:bg-rt-dark-surface dark:ring-rt-dark-border/70"
                data-anim="fade-up"
                data-email-template-downloads
            >
                <header class="flex flex-col gap-4 border-b border-rt-border/70 bg-rt-surface-muted px-5 py-5 dark:border-rt-dark-border/70 dark:bg-rt-dark-surface-muted sm:flex-row sm:items-center sm:justify-between sm:px-6">
                    <div class="min-w-0">
                        <p class="text-[10px] font-bold uppercase tracking-[0.18em] text-rt-red dark:text-rt-dark-accent">{{ __('app.personal_data') }}</p>
                        <h2 class="mt-1 text-lg font-semibold tracking-tight text-rt-text dark:text-rt-dark-text">{{ __('app.downloads') }}</h2>
                        <p class="mt-1 max-w-3xl text-sm leading-6 text-rt-muted dark:text-rt-dark-muted">{{ __('app.email_templates_intro') }}</p>
                    </div>
                    <span class="inline-flex w-fit items-center gap-2 rounded-full bg-rt-surface px-3 py-1.5 text-xs font-semibold tabular-nums text-rt-muted ring-1 ring-rt-border/70 dark:bg-rt-dark-surface dark:text-rt-dark-muted dark:ring-rt-dark-border/70">
                        <i class="far fa-layer-group text-rt-red dark:text-rt-dark-accent" aria-hidden="true"></i>
                        {{ $availableTemplates->count() }}
                    </span>
                </header>

                <div class="space-y-8 p-5 sm:p-6">
                    <section aria-labelledby="email-template-mail-heading">
                        <div class="mb-4 flex items-start gap-3">
                            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-rt-accent-soft text-rt-red dark:bg-rt-dark-accent-soft dark:text-rt-dark-accent">
                                <i class="far fa-envelope-open-text" aria-hidden="true"></i>
                            </span>
                            <div>
                                <h2 id="email-template-mail-heading" class="text-base font-semibold text-rt-text dark:text-rt-dark-text">{{ __('app.email_templates_mail_section') }}</h2>
                                <p class="mt-0.5 text-sm leading-5 text-rt-muted dark:text-rt-dark-muted">{{ __('app.email_templates_mail_section_hint') }}</p>
                            </div>
                        </div>

                        <div class="grid gap-4 xl:grid-cols-2">
                            @foreach ($themes as $themeKey => $theme)
                                @php
                                    $themeTemplates = $mailTemplates->where('theme', $themeKey);
                                @endphp
                                <article
                                    class="overflow-hidden rounded-2xl ring-1 ring-rt-border/70 dark:ring-rt-dark-border/70"
                                    data-template-category="mail"
                                    data-template-theme="{{ $themeKey }}"
                                >
                                    <div @class([
                                        'relative overflow-hidden px-5 py-5',
                                        'bg-[#f4f2ed]' => $themeKey === 'light',
                                        'bg-[#090d12]' => $themeKey === 'dark',
                                    ])>
                                        <div @class([
                                            'mx-auto max-w-sm overflow-hidden rounded-xl shadow-lg ring-1',
                                            'bg-white ring-slate-900/10' => $themeKey === 'light',
                                            'bg-[#151d26] ring-white/10' => $themeKey === 'dark',
                                        ]) aria-hidden="true">
                                            <div class="h-1.5 bg-rt-red"></div>
                                            <div class="space-y-2.5 px-4 py-4">
                                                <div class="flex items-center justify-between gap-4">
                                                    <span class="font-mono text-[8px] font-bold uppercase tracking-[0.16em] text-rt-red">RT / Mail</span>
                                                    <span @class([
                                                        'h-2 w-14 rounded-full',
                                                        'bg-slate-200' => $themeKey === 'light',
                                                        'bg-white/10' => $themeKey === 'dark',
                                                    ])></span>
                                                </div>
                                                <span @class([
                                                    'block h-3 w-3/4 rounded-full',
                                                    'bg-slate-900' => $themeKey === 'light',
                                                    'bg-white' => $themeKey === 'dark',
                                                ])></span>
                                                <span @class([
                                                    'block h-2 w-full rounded-full',
                                                    'bg-slate-200' => $themeKey === 'light',
                                                    'bg-white/10' => $themeKey === 'dark',
                                                ])></span>
                                                <span @class([
                                                    'block h-2 w-5/6 rounded-full',
                                                    'bg-slate-200' => $themeKey === 'light',
                                                    'bg-white/10' => $themeKey === 'dark',
                                                ])></span>
                                                <div @class([
                                                    'mt-3 grid grid-cols-2 gap-px overflow-hidden rounded-md',
                                                    'bg-slate-200' => $themeKey === 'light',
                                                    'bg-white/10' => $themeKey === 'dark',
                                                ])>
                                                    <span @class(['h-8', 'bg-slate-50' => $themeKey === 'light', 'bg-white/5' => $themeKey === 'dark'])></span>
                                                    <span @class(['h-8', 'bg-slate-50' => $themeKey === 'light', 'bg-white/5' => $themeKey === 'dark'])></span>
                                                </div>
                                            </div>
                                            <div class="flex items-center justify-between gap-3 bg-[#080b10] px-4 py-3">
                                                <span class="h-2 w-20 rounded-full bg-white/80"></span>
                                                <span class="h-2 w-12 rounded-full bg-rt-red"></span>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="bg-rt-surface p-5 dark:bg-rt-dark-surface">
                                        <div class="flex items-start justify-between gap-4">
                                            <div>
                                                <div class="flex items-center gap-2">
                                                    <i class="{{ $theme['icon'] }} text-rt-red dark:text-rt-dark-accent" aria-hidden="true"></i>
                                                    <h3 class="font-semibold text-rt-text dark:text-rt-dark-text">{{ $theme['label'] }}</h3>
                                                </div>
                                                <p class="mt-1 text-xs leading-5 text-rt-muted dark:text-rt-dark-muted">{{ $theme['hint'] }}</p>
                                            </div>
                                            <span class="rounded-full bg-rt-surface-muted px-2 py-1 font-mono text-[9px] font-bold uppercase tracking-[0.12em] text-rt-muted ring-1 ring-rt-border/70 dark:bg-rt-dark-surface-muted dark:text-rt-dark-muted dark:ring-rt-dark-border/70">
                                                {{ $theme['label'] }}
                                            </span>
                                        </div>

                                        <div class="mt-4 grid gap-2">
                                            @foreach ($themeTemplates as $key => $template)
                                                <a
                                                    href="{{ route('email-templates.download', ['template' => $key]) }}"
                                                    data-template-key="{{ $key }}"
                                                    data-template-format="{{ $template['format'] }}"
                                                    data-no-navigate
                                                    class="group flex min-h-14 items-center gap-3 rounded-xl bg-rt-surface-muted/70 px-3.5 py-3 ring-1 ring-rt-border/60 transition hover:-translate-y-0.5 hover:bg-rt-accent-soft hover:ring-rt-red/20 dark:bg-rt-dark-surface-muted/60 dark:ring-rt-dark-border/60 dark:hover:bg-rt-dark-accent-soft dark:hover:ring-rt-dark-accent/20"
                                                >
                                                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-rt-surface text-rt-red shadow-rt-xs ring-1 ring-rt-border/70 dark:bg-rt-dark-surface dark:text-rt-dark-accent dark:ring-rt-dark-border/70">
                                                        <i class="far {{ $template['format'] === 'eml' ? 'fa-envelope' : 'fa-code' }}" aria-hidden="true"></i>
                                                    </span>
                                                    <span class="min-w-0 flex-1">
                                                        <span class="block text-sm font-semibold text-rt-text dark:text-rt-dark-text">{{ __($template['label']) }}</span>
                                                        <span class="mt-0.5 block text-[11px] text-rt-muted dark:text-rt-dark-muted">{{ strtoupper($template['extension']) }}</span>
                                                    </span>
                                                    <i class="far fa-download shrink-0 text-xs text-rt-muted transition group-hover:text-rt-red dark:text-rt-dark-muted dark:group-hover:text-rt-dark-accent" aria-hidden="true"></i>
                                                </a>
                                            @endforeach
                                        </div>
                                    </div>
                                </article>
                            @endforeach
                        </div>
                    </section>

                    <section class="border-t border-rt-border/70 pt-7 dark:border-rt-dark-border/70" aria-labelledby="email-template-signature-heading">
                        <div class="mb-4 flex items-start gap-3">
                            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-rt-accent-soft text-rt-red dark:bg-rt-dark-accent-soft dark:text-rt-dark-accent">
                                <i class="far fa-signature" aria-hidden="true"></i>
                            </span>
                            <div>
                                <h2 id="email-template-signature-heading" class="text-base font-semibold text-rt-text dark:text-rt-dark-text">{{ __('app.email_templates_signature_section') }}</h2>
                                <p class="mt-0.5 text-sm leading-5 text-rt-muted dark:text-rt-dark-muted">{{ __('app.email_templates_signature_section_hint') }}</p>
                            </div>
                        </div>

                        <div class="grid gap-4 lg:grid-cols-3">
                            @foreach ($themes as $themeKey => $theme)
                                @php
                                    $signatureKey = $themeKey === 'light' ? 'signatur-hell' : 'signatur-dunkel';
                                    $signature = $signatureTemplates->get($signatureKey);
                                @endphp
                                <article
                                    class="flex min-h-full flex-col overflow-hidden rounded-2xl ring-1 ring-rt-border/70 dark:ring-rt-dark-border/70"
                                    data-template-category="signature"
                                    data-template-theme="{{ $themeKey }}"
                                >
                                    <div @class([
                                        'border-b px-4 py-5',
                                        'border-slate-200 bg-white' => $themeKey === 'light',
                                        'border-white/10 bg-[#080b10]' => $themeKey === 'dark',
                                    ])>
                                        <div class="grid grid-cols-[minmax(0,1fr)_5rem] items-start gap-3" aria-hidden="true">
                                            <div>
                                                <span @class([
                                                    'block h-2.5 w-24 rounded-full',
                                                    'bg-slate-900' => $themeKey === 'light',
                                                    'bg-white' => $themeKey === 'dark',
                                                ])></span>
                                                <span class="mt-1.5 block h-1.5 w-16 rounded-full bg-rt-red"></span>
                                                <div class="mt-3 space-y-1.5">
                                                    @foreach (range(1, 3) as $line)
                                                        <div class="flex items-center gap-1.5">
                                                            <span class="h-3 w-3 rounded bg-rt-red"></span>
                                                            <span @class([
                                                                'h-1.5 rounded-full',
                                                                'w-20' => $line === 1,
                                                                'w-24' => $line === 2,
                                                                'w-16' => $line === 3,
                                                                'bg-slate-300' => $themeKey === 'light',
                                                                'bg-white/20' => $themeKey === 'dark',
                                                            ])></span>
                                                        </div>
                                                    @endforeach
                                                </div>
                                            </div>
                                            <div @class([
                                                'border-l pl-3',
                                                'border-slate-200' => $themeKey === 'light',
                                                'border-white/10' => $themeKey === 'dark',
                                            ])>
                                                <span class="block text-right text-sm font-black italic tracking-tighter text-rt-red">RAIL</span>
                                                <span @class([
                                                    'block text-right text-sm font-black italic tracking-tighter',
                                                    'text-slate-700' => $themeKey === 'light',
                                                    'text-white' => $themeKey === 'dark',
                                                ])>TIME</span>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="flex flex-1 flex-col bg-rt-surface p-5 dark:bg-rt-dark-surface">
                                        <div class="flex items-center gap-2">
                                            <i class="{{ $theme['icon'] }} text-rt-red dark:text-rt-dark-accent" aria-hidden="true"></i>
                                            <h3 class="font-semibold text-rt-text dark:text-rt-dark-text">{{ __($signature['label']) }}</h3>
                                        </div>
                                        <p class="mt-2 flex-1 text-xs leading-5 text-rt-muted dark:text-rt-dark-muted">{{ __($signature['hint']) }}</p>
                                        <a
                                            href="{{ route('email-templates.download', ['template' => $signatureKey]) }}"
                                            data-template-key="{{ $signatureKey }}"
                                            data-template-format="html"
                                            data-no-navigate
                                            class="mt-4 inline-flex min-h-10 items-center justify-center gap-2 rounded-xl bg-rt-red px-4 py-2 text-sm font-semibold text-white shadow-rt-xs transition hover:-translate-y-0.5 hover:bg-rt-red-dark"
                                        >
                                            <i class="far fa-download" aria-hidden="true"></i>
                                            {{ __('app.download') }} · HTML
                                        </a>
                                    </div>
                                </article>
                            @endforeach

                            @php
                                $textSignature = $signatureTemplates->get('signatur-text');
                            @endphp
                            <article
                                class="flex min-h-full flex-col overflow-hidden rounded-2xl bg-rt-surface ring-1 ring-rt-border/70 dark:bg-rt-dark-surface dark:ring-rt-dark-border/70"
                                data-template-category="signature"
                                data-template-theme="neutral"
                            >
                                <div class="border-b border-rt-border/70 bg-rt-surface-muted px-5 py-5 dark:border-rt-dark-border/70 dark:bg-rt-dark-surface-muted">
                                    <div class="rounded-xl bg-rt-surface p-4 font-mono text-[9px] leading-4 text-rt-muted shadow-inner ring-1 ring-rt-border/60 dark:bg-rt-dark-surface dark:text-rt-dark-muted dark:ring-rt-dark-border/60" aria-hidden="true">
                                        <span class="block font-bold text-rt-text dark:text-rt-dark-text">{{ $user->name }}</span>
                                        <span class="block text-rt-red dark:text-rt-dark-accent">{{ $templateValues['POSITION'] }}</span>
                                        <span class="mt-2 block">E {{ $templateValues['E_MAIL'] }}</span>
                                        <span class="block">{{ $templateValues['FIRMENNAME'] }}</span>
                                    </div>
                                </div>
                                <div class="flex flex-1 flex-col p-5">
                                    <div class="flex items-center gap-2">
                                        <i class="far fa-align-left text-rt-red dark:text-rt-dark-accent" aria-hidden="true"></i>
                                        <h3 class="font-semibold text-rt-text dark:text-rt-dark-text">{{ __($textSignature['label']) }}</h3>
                                    </div>
                                    <p class="mt-2 flex-1 text-xs leading-5 text-rt-muted dark:text-rt-dark-muted">{{ __($textSignature['hint']) }}</p>
                                    <a
                                        href="{{ route('email-templates.download', ['template' => 'signatur-text']) }}"
                                        data-template-key="signatur-text"
                                        data-template-format="txt"
                                        data-no-navigate
                                        class="mt-4 inline-flex min-h-10 items-center justify-center gap-2 rounded-xl bg-rt-surface-muted px-4 py-2 text-sm font-semibold text-rt-text ring-1 ring-rt-border/70 transition hover:-translate-y-0.5 hover:text-rt-red dark:bg-rt-dark-surface-muted dark:text-rt-dark-text dark:ring-rt-dark-border/70 dark:hover:text-rt-dark-accent"
                                    >
                                        <i class="far fa-download" aria-hidden="true"></i>
                                        {{ __('app.download') }} · TXT
                                    </a>
                                </div>
                            </article>
                        </div>
                    </section>

                    <div class="flex flex-col gap-3 rounded-2xl bg-rt-surface-muted px-4 py-4 text-xs leading-5 text-rt-muted ring-1 ring-rt-border/70 dark:bg-rt-dark-surface-muted dark:text-rt-dark-muted dark:ring-rt-dark-border/70 sm:flex-row sm:items-start">
                        <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-rt-surface text-rt-red ring-1 ring-rt-border/70 dark:bg-rt-dark-surface dark:text-rt-dark-accent dark:ring-rt-dark-border/70">
                            <i class="far fa-info-circle" aria-hidden="true"></i>
                        </span>
                        <div class="space-y-1">
                            <p>{{ __('app.email_templates_legal_hint') }}</p>
                            <p class="font-semibold text-rt-text dark:text-rt-dark-text">{{ __('app.email_templates_help_hint') }}</p>
                        </div>
                    </div>
                </div>
            </section>
        </div>
    </x-ui.page>
@endsection
