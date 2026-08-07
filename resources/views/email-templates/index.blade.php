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
                $template['theme'] => route('email-templates.preview', ['template' => $key]),
            ])
            ->all();
        $previewDownloadUrls = $previewTemplates
            ->mapWithKeys(fn (array $template, string $key) => [
                $template['theme'] => route('email-templates.download', ['template' => $key]),
            ])
            ->all();
        $previewLabels = $previewTemplates
            ->mapWithKeys(fn (array $template) => [
                $template['theme'] => __('app.email_templates_theme_'.$template['theme']).' · '.strtoupper($template['format']),
            ])
            ->all();
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
                profileModalOpen: false,
                previewModalOpen: false,
                mailTheme: 'light',
                signatureTheme: 'light',
                previewPlaybackId: 0,
                previewAnimated: false,
                reducedMotion: false,
                previewUrls: @js($previewUrls),
                previewDownloadUrls: @js($previewDownloadUrls),
                previewLabels: @js($previewLabels),
                lastModalTrigger: null,
                init() {
                    this.reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
                },
                toggleAccordionSection(section) {
                    this.openAccordionSection = this.openAccordionSection === section ? null : section;
                },
                openModal(name, event) {
                    this.lastModalTrigger = event?.currentTarget ?? document.activeElement;
                    this.profileModalOpen = name === 'profile';
                    this.previewModalOpen = name === 'preview';
                },
                openPreview(theme, event) {
                    this.mailTheme = theme;
                    this.previewAnimated = !this.reducedMotion;
                    if (this.previewAnimated) this.previewPlaybackId++;
                    this.openModal('preview', event);
                },
                selectPreviewTheme(theme) {
                    this.mailTheme = theme;
                    this.previewAnimated = !this.reducedMotion;
                    if (this.previewAnimated) this.previewPlaybackId++;
                },
                replayPreview() {
                    this.previewAnimated = true;
                    this.previewPlaybackId++;
                },
                previewFrameUrl() {
                    const url = this.previewUrls[this.mailTheme];
                    return this.previewAnimated
                        ? `${url}?animate=1&play=${this.previewPlaybackId}`
                        : url;
                },
                closeModal(name) {
                    this[name + 'ModalOpen'] = false;
                    if (name === 'preview') this.previewAnimated = false;
                    const trigger = this.lastModalTrigger;
                    this.lastModalTrigger = null;
                    this.$nextTick(() => trigger?.focus());
                },
            }"
            class="space-y-4 sm:space-y-5"
            data-email-templates-page
        >
            <section
                class="grid grid-cols-2 gap-2.5 sm:gap-3"
                aria-label="{{ __('app.personal_data') }}"
                data-email-template-quick-actions
            >
                <button
                    type="button"
                    x-on:click="openModal('profile', $event)"
                    x-bind:aria-expanded="profileModalOpen.toString()"
                    aria-haspopup="dialog"
                    aria-controls="email-template-profile-modal"
                    data-email-template-modal-trigger="profile"
                    class="group flex min-h-[4.75rem] min-w-0 items-center gap-2.5 rounded-2xl bg-rt-surface p-3 text-left shadow-rt-sm ring-1 ring-rt-border/70 transition duration-200 hover:-translate-y-0.5 hover:ring-rt-red/25 focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-rt-red/15 dark:bg-rt-dark-surface dark:ring-rt-dark-border/70 dark:hover:ring-rt-dark-accent/25 sm:min-h-[5.5rem] sm:gap-3 sm:p-4"
                >
                    <span @class([
                        'flex h-10 w-10 shrink-0 items-center justify-center rounded-xl ring-1 ring-inset sm:h-11 sm:w-11',
                        'bg-amber-50 text-amber-700 ring-amber-600/20 dark:bg-amber-500/10 dark:text-amber-300 dark:ring-amber-500/25' => $missingContactData,
                        'bg-emerald-50 text-emerald-700 ring-emerald-600/20 dark:bg-emerald-500/10 dark:text-emerald-300 dark:ring-emerald-500/25' => ! $missingContactData,
                    ])>
                        <i class="far {{ $missingContactData ? 'fa-info-circle' : 'fa-check-circle' }}" aria-hidden="true"></i>
                    </span>
                    <span class="min-w-0 flex-1">
                        <span class="block truncate text-sm font-semibold text-rt-text dark:text-rt-dark-text sm:text-base">{{ __('app.profile_status') }}</span>
                        <span class="mt-0.5 block truncate text-[11px] font-medium text-rt-muted dark:text-rt-dark-muted sm:text-xs">
                            {{ $missingContactData ? __('app.missing') : __('app.ready') }}
                        </span>
                    </span>
                    <i class="far fa-arrow-up-right hidden shrink-0 text-xs text-rt-soft transition group-hover:text-rt-red dark:text-rt-dark-soft dark:group-hover:text-rt-dark-accent sm:inline-block" aria-hidden="true"></i>
                </button>

                <button
                    type="button"
                    x-on:click="openPreview(mailTheme, $event)"
                    x-bind:aria-expanded="previewModalOpen.toString()"
                    aria-haspopup="dialog"
                    aria-controls="email-template-preview-modal"
                    data-email-template-modal-trigger="preview"
                    class="group flex min-h-[4.75rem] min-w-0 items-center gap-2.5 rounded-2xl bg-rt-surface p-3 text-left shadow-rt-sm ring-1 ring-rt-border/70 transition duration-200 hover:-translate-y-0.5 hover:ring-rt-red/25 focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-rt-red/15 dark:bg-rt-dark-surface dark:ring-rt-dark-border/70 dark:hover:ring-rt-dark-accent/25 sm:min-h-[5.5rem] sm:gap-3 sm:p-4"
                >
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-rt-accent-soft text-rt-red ring-1 ring-rt-red/10 dark:bg-rt-dark-accent-soft dark:text-rt-dark-accent sm:h-11 sm:w-11">
                        <i class="far fa-window-restore" aria-hidden="true"></i>
                    </span>
                    <span class="min-w-0 flex-1">
                        <span class="block truncate text-sm font-semibold text-rt-text dark:text-rt-dark-text sm:text-base">{{ __('app.email_templates_preview_accordion') }}</span>
                        <span class="mt-0.5 flex items-center gap-1.5 text-[11px] font-medium text-rt-muted dark:text-rt-dark-muted sm:text-xs">
                            <i x-bind:class="mailTheme === 'dark' ? 'far fa-moon-stars' : 'far fa-sun'" aria-hidden="true"></i>
                            <span x-text="mailTheme === 'dark' ? @js(__('app.email_templates_theme_dark')) : @js(__('app.email_templates_theme_light'))"></span>
                        </span>
                    </span>
                    <i class="far fa-arrow-up-right hidden shrink-0 text-xs text-rt-soft transition group-hover:text-rt-red dark:text-rt-dark-soft dark:group-hover:text-rt-dark-accent sm:inline-block" aria-hidden="true"></i>
                </button>
            </section>

            <div class="space-y-3" data-email-template-accordions>
                <x-ui.accordion.section
                    section="mail"
                    :label="__('app.email_templates_mail_section')"
                    :description="__('app.email_templates_mail_section_hint')"
                    icon="fad fa-envelope-open-text"
                    id-prefix="email-templates"
                    data-email-template-accordion="mail"
                >
                    <div class="space-y-3.5">
                        <div class="flex flex-col gap-3 rounded-2xl bg-rt-surface p-3 ring-1 ring-rt-border/60 dark:bg-rt-dark-surface dark:ring-rt-dark-border/60 sm:flex-row sm:items-center sm:justify-between sm:p-4">
                            <div class="min-w-0">
                                <p class="text-[10px] font-bold uppercase tracking-[0.16em] text-rt-red dark:text-rt-dark-accent">{{ __('app.toggle_theme') }}</p>
                                <p class="mt-1 text-xs leading-5 text-rt-muted dark:text-rt-dark-muted" x-text="mailTheme === 'dark' ? @js($themes['dark']['hint']) : @js($themes['light']['hint'])"></p>
                            </div>
                            <div
                                class="grid shrink-0 grid-cols-2 rounded-xl bg-rt-surface-muted p-1 ring-1 ring-rt-border/70 dark:bg-rt-dark-surface-muted dark:ring-rt-dark-border/70"
                                role="group"
                                aria-label="{{ __('app.toggle_theme') }}"
                                data-email-template-theme-toggle="mail"
                            >
                                @foreach ($themes as $themeKey => $theme)
                                    <button
                                        type="button"
                                        x-on:click="mailTheme = @js($themeKey)"
                                        x-bind:aria-pressed="(mailTheme === @js($themeKey)).toString()"
                                        data-email-template-theme-option="mail-{{ $themeKey }}"
                                        class="inline-flex min-h-11 items-center justify-center gap-2 rounded-lg px-3 py-2 text-xs font-semibold transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rt-red/35 sm:min-w-24"
                                        x-bind:class="mailTheme === @js($themeKey)
                                            ? 'bg-rt-surface text-rt-red shadow-rt-xs ring-1 ring-rt-border/70 dark:bg-rt-dark-surface dark:text-rt-dark-accent dark:ring-rt-dark-border/70'
                                            : 'text-rt-muted hover:text-rt-text dark:text-rt-dark-muted dark:hover:text-rt-dark-text'"
                                    >
                                        <i class="{{ $theme['icon'] }}" aria-hidden="true"></i>
                                        <span>{{ $theme['label'] }}</span>
                                    </button>
                                @endforeach
                            </div>
                        </div>

                        @foreach ($themes as $themeKey => $theme)
                            @php
                                $themeTemplates = $mailTemplates->where('theme', $themeKey);
                            @endphp
                            <article
                                x-show="mailTheme === @js($themeKey)"
                                x-cloak
                                @if ($themeKey === 'dark') style="display: none;" @endif
                                class="overflow-hidden rounded-2xl bg-rt-surface ring-1 ring-rt-border/70 dark:bg-rt-dark-surface dark:ring-rt-dark-border/70"
                                data-email-template-theme-panel="mail-{{ $themeKey }}"
                                data-template-category="mail"
                                data-template-theme="{{ $themeKey }}"
                            >
                                <div class="grid lg:grid-cols-[minmax(0,1.1fr)_minmax(19rem,0.9fr)]">
                                    <div @class([
                                        'flex items-center justify-center p-4 sm:p-6',
                                        'bg-[#f4f2ed]' => $themeKey === 'light',
                                        'bg-[#090d12]' => $themeKey === 'dark',
                                    ])>
                                        <div @class([
                                            'w-full max-w-md overflow-hidden rounded-xl shadow-lg ring-1',
                                            'bg-white ring-slate-900/10' => $themeKey === 'light',
                                            'bg-[#151d26] ring-white/10' => $themeKey === 'dark',
                                        ]) aria-hidden="true">
                                            <div class="h-1.5 bg-rt-red"></div>
                                            <div class="flex items-center justify-between gap-4 px-4 py-3">
                                                <div class="font-mono text-[9px] font-black italic tracking-tight">
                                                    <span class="text-rt-red">RAIL</span><span @class(['text-slate-800' => $themeKey === 'light', 'text-white' => $themeKey === 'dark'])>TIME</span>
                                                </div>
                                                <span @class([
                                                    'rounded-full px-2 py-1 text-[8px] font-bold uppercase tracking-[0.12em]',
                                                    'bg-slate-100 text-slate-500' => $themeKey === 'light',
                                                    'bg-white/5 text-white/55' => $themeKey === 'dark',
                                                ])>{{ $theme['label'] }}</span>
                                            </div>
                                            <div @class([
                                                'space-y-2.5 border-y px-4 py-4',
                                                'border-slate-100 bg-[#fbfaf7]' => $themeKey === 'light',
                                                'border-white/5 bg-[#111820]' => $themeKey === 'dark',
                                            ])>
                                                <span @class(['block h-2 w-28 rounded-full', 'bg-slate-800' => $themeKey === 'light', 'bg-white/85' => $themeKey === 'dark'])></span>
                                                <span @class(['block h-1.5 w-full rounded-full', 'bg-slate-200' => $themeKey === 'light', 'bg-white/10' => $themeKey === 'dark'])></span>
                                                <span @class(['block h-1.5 w-4/5 rounded-full', 'bg-slate-200' => $themeKey === 'light', 'bg-white/10' => $themeKey === 'dark'])></span>
                                                <div @class([
                                                    'mt-3 grid grid-cols-2 gap-px overflow-hidden rounded-md',
                                                    'bg-slate-200' => $themeKey === 'light',
                                                    'bg-white/10' => $themeKey === 'dark',
                                                ])>
                                                    <span @class(['h-9', 'bg-white' => $themeKey === 'light', 'bg-white/5' => $themeKey === 'dark'])></span>
                                                    <span @class(['h-9', 'bg-white' => $themeKey === 'light', 'bg-white/5' => $themeKey === 'dark'])></span>
                                                </div>
                                            </div>
                                            <div @class([
                                                'flex items-center justify-between gap-3 px-4 py-3',
                                                'bg-white text-slate-700' => $themeKey === 'light',
                                                'bg-[#080b10] text-white' => $themeKey === 'dark',
                                            ])>
                                                <span @class(['h-2 w-20 rounded-full', 'bg-slate-300' => $themeKey === 'light', 'bg-white/70' => $themeKey === 'dark'])></span>
                                                <span class="h-2 w-12 rounded-full bg-rt-red"></span>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="flex flex-col p-4 sm:p-5">
                                        <div class="flex items-start gap-3">
                                            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-rt-accent-soft text-rt-red dark:bg-rt-dark-accent-soft dark:text-rt-dark-accent">
                                                <i class="{{ $theme['icon'] }}" aria-hidden="true"></i>
                                            </span>
                                            <div class="min-w-0">
                                                <h3 class="font-semibold text-rt-text dark:text-rt-dark-text">{{ $theme['label'] }}</h3>
                                                <p class="mt-1 text-xs leading-5 text-rt-muted dark:text-rt-dark-muted">{{ $theme['hint'] }}</p>
                                            </div>
                                        </div>

                                        <div class="mt-4 grid gap-2 sm:grid-cols-2 lg:grid-cols-1">
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

                                        <button
                                            type="button"
                                            x-on:click="openPreview(@js($themeKey), $event)"
                                            class="mt-3 inline-flex min-h-11 w-full items-center justify-center gap-2 rounded-xl px-4 py-2 text-sm font-semibold text-rt-red ring-1 ring-inset ring-rt-red/20 transition hover:bg-rt-accent-soft focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-rt-red/15 dark:text-rt-dark-accent dark:ring-rt-dark-accent/25 dark:hover:bg-rt-dark-accent-soft"
                                        >
                                            <i class="far fa-eye" aria-hidden="true"></i>
                                            {{ __('app.email_templates_preview_accordion') }}
                                        </button>
                                    </div>
                                </div>
                            </article>
                        @endforeach
                    </div>
                </x-ui.accordion.section>

                <x-ui.accordion.section
                    section="signature"
                    :label="__('app.email_templates_signature_section')"
                    :description="__('app.email_templates_signature_section_hint')"
                    icon="fad fa-signature"
                    id-prefix="email-templates"
                    data-email-template-accordion="signature"
                >
                    <div class="space-y-3.5">
                        <div class="flex flex-col gap-3 rounded-2xl bg-rt-surface p-3 ring-1 ring-rt-border/60 dark:bg-rt-dark-surface dark:ring-rt-dark-border/60 sm:flex-row sm:items-center sm:justify-between sm:p-4">
                            <div class="min-w-0">
                                <p class="text-[10px] font-bold uppercase tracking-[0.16em] text-rt-red dark:text-rt-dark-accent">{{ __('app.toggle_theme') }}</p>
                                <p class="mt-1 text-xs leading-5 text-rt-muted dark:text-rt-dark-muted" x-text="signatureTheme === 'dark' ? @js($themes['dark']['hint']) : @js($themes['light']['hint'])"></p>
                            </div>
                            <div
                                class="grid shrink-0 grid-cols-2 rounded-xl bg-rt-surface-muted p-1 ring-1 ring-rt-border/70 dark:bg-rt-dark-surface-muted dark:ring-rt-dark-border/70"
                                role="group"
                                aria-label="{{ __('app.toggle_theme') }}"
                                data-email-template-theme-toggle="signature"
                            >
                                @foreach ($themes as $themeKey => $theme)
                                    <button
                                        type="button"
                                        x-on:click="signatureTheme = @js($themeKey)"
                                        x-bind:aria-pressed="(signatureTheme === @js($themeKey)).toString()"
                                        data-email-template-theme-option="signature-{{ $themeKey }}"
                                        class="inline-flex min-h-11 items-center justify-center gap-2 rounded-lg px-3 py-2 text-xs font-semibold transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rt-red/35 sm:min-w-24"
                                        x-bind:class="signatureTheme === @js($themeKey)
                                            ? 'bg-rt-surface text-rt-red shadow-rt-xs ring-1 ring-rt-border/70 dark:bg-rt-dark-surface dark:text-rt-dark-accent dark:ring-rt-dark-border/70'
                                            : 'text-rt-muted hover:text-rt-text dark:text-rt-dark-muted dark:hover:text-rt-dark-text'"
                                    >
                                        <i class="{{ $theme['icon'] }}" aria-hidden="true"></i>
                                        <span>{{ $theme['label'] }}</span>
                                    </button>
                                @endforeach
                            </div>
                        </div>

                        @foreach ($themes as $themeKey => $theme)
                            @php
                                $signatureKey = $themeKey === 'light' ? 'signatur-hell' : 'signatur-dunkel';
                                $signature = $signatureTemplates->get($signatureKey);
                            @endphp
                            <article
                                x-show="signatureTheme === @js($themeKey)"
                                x-cloak
                                @if ($themeKey === 'dark') style="display: none;" @endif
                                class="overflow-hidden rounded-2xl bg-rt-surface ring-1 ring-rt-border/70 dark:bg-rt-dark-surface dark:ring-rt-dark-border/70"
                                data-email-template-theme-panel="signature-{{ $themeKey }}"
                                data-template-category="signature"
                                data-template-theme="{{ $themeKey }}"
                            >
                                <div class="grid lg:grid-cols-[minmax(0,1.15fr)_minmax(18rem,0.85fr)]">
                                    <div @class([
                                        'flex items-center justify-center p-4 sm:p-6',
                                        'bg-[#f4f2ed]' => $themeKey === 'light',
                                        'bg-[#090d12]' => $themeKey === 'dark',
                                    ])>
                                        <div @class([
                                            'w-full max-w-lg overflow-hidden rounded-xl px-4 py-5 shadow-lg ring-1 sm:px-5',
                                            'bg-white ring-slate-900/10' => $themeKey === 'light',
                                            'bg-[#080b10] ring-white/10' => $themeKey === 'dark',
                                        ]) aria-hidden="true">
                                            <div class="grid grid-cols-[minmax(0,1fr)_4.75rem] items-start gap-4">
                                                <div class="min-w-0">
                                                    <p @class(['truncate text-sm font-bold', 'text-slate-900' => $themeKey === 'light', 'text-white' => $themeKey === 'dark'])>{{ $user->name }}</p>
                                                    <p class="mt-0.5 truncate text-[10px] font-semibold uppercase tracking-[0.12em] text-rt-red">{{ $templateValues['POSITION'] }}</p>
                                                    <div @class([
                                                        'mt-3 space-y-1.5 border-t pt-3 text-[9px]',
                                                        'border-slate-200 text-slate-600' => $themeKey === 'light',
                                                        'border-white/10 text-white/65' => $themeKey === 'dark',
                                                    ])>
                                                        @if ($templateValues['DURCHWAHL'] !== '')
                                                            <p class="flex min-w-0 items-center gap-2">
                                                                <i class="far fa-phone w-4 shrink-0 text-center text-rt-red"></i>
                                                                <span class="truncate">{{ $templateValues['DURCHWAHL'] }}</span>
                                                            </p>
                                                        @endif
                                                        @if ($templateValues['MOBIL'] !== '')
                                                            <p class="flex min-w-0 items-center gap-2">
                                                                <i class="far fa-mobile-alt w-4 shrink-0 text-center text-rt-red"></i>
                                                                <span class="truncate">{{ $templateValues['MOBIL'] }}</span>
                                                            </p>
                                                        @endif
                                                        <p class="flex min-w-0 items-center gap-2">
                                                            <i class="far fa-envelope w-4 shrink-0 text-center text-rt-red"></i>
                                                            <span class="truncate">{{ $templateValues['E_MAIL'] }}</span>
                                                        </p>
                                                        @if ($templateValues['FIRMEN_WEBSITE_LABEL'] !== '')
                                                            <p class="flex min-w-0 items-center gap-2">
                                                                <i class="far fa-globe w-4 shrink-0 text-center text-rt-red"></i>
                                                                <span class="truncate">{{ $templateValues['FIRMEN_WEBSITE_LABEL'] }}</span>
                                                            </p>
                                                        @endif
                                                    </div>
                                                </div>
                                                <div @class([
                                                    'border-l pl-3 text-right',
                                                    'border-slate-200' => $themeKey === 'light',
                                                    'border-white/10' => $themeKey === 'dark',
                                                ])>
                                                    <span class="block text-base font-black italic leading-none tracking-tighter text-rt-red">RAIL</span>
                                                    <span @class([
                                                        'block text-base font-black italic leading-none tracking-tighter',
                                                        'text-slate-700' => $themeKey === 'light',
                                                        'text-white' => $themeKey === 'dark',
                                                    ])>TIME</span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="flex flex-col p-4 sm:p-5">
                                        <div class="flex items-start gap-3">
                                            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-rt-accent-soft text-rt-red dark:bg-rt-dark-accent-soft dark:text-rt-dark-accent">
                                                <i class="{{ $theme['icon'] }}" aria-hidden="true"></i>
                                            </span>
                                            <div class="min-w-0">
                                                <h3 class="font-semibold text-rt-text dark:text-rt-dark-text">{{ __($signature['label']) }}</h3>
                                                <p class="mt-1 text-xs leading-5 text-rt-muted dark:text-rt-dark-muted">{{ __($signature['hint']) }}</p>
                                            </div>
                                        </div>
                                        <a
                                            href="{{ route('email-templates.download', ['template' => $signatureKey]) }}"
                                            data-template-key="{{ $signatureKey }}"
                                            data-template-format="html"
                                            data-no-navigate
                                            class="mt-4 inline-flex min-h-11 items-center justify-center gap-2 rounded-xl bg-rt-red px-4 py-2 text-sm font-semibold text-white shadow-rt-xs transition hover:-translate-y-0.5 hover:bg-rt-red-dark focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-rt-red/20 lg:mt-auto"
                                        >
                                            <i class="far fa-download" aria-hidden="true"></i>
                                            {{ __('app.download') }} · HTML
                                        </a>
                                    </div>
                                </div>
                            </article>
                        @endforeach

                        @php
                            $textSignature = $signatureTemplates->get('signatur-text');
                        @endphp
                        <article
                            class="flex flex-col gap-4 rounded-2xl bg-rt-surface p-4 ring-1 ring-rt-border/70 dark:bg-rt-dark-surface dark:ring-rt-dark-border/70 sm:flex-row sm:items-center sm:justify-between sm:p-5"
                            data-template-category="signature"
                            data-template-theme="neutral"
                        >
                            <div class="flex min-w-0 items-start gap-3">
                                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-rt-surface-muted text-rt-red ring-1 ring-rt-border/70 dark:bg-rt-dark-surface-muted dark:text-rt-dark-accent dark:ring-rt-dark-border/70">
                                    <i class="far fa-align-left" aria-hidden="true"></i>
                                </span>
                                <div class="min-w-0">
                                    <h3 class="font-semibold text-rt-text dark:text-rt-dark-text">{{ __($textSignature['label']) }}</h3>
                                    <p class="mt-1 text-xs leading-5 text-rt-muted dark:text-rt-dark-muted">{{ __($textSignature['hint']) }}</p>
                                </div>
                            </div>
                            <a
                                href="{{ route('email-templates.download', ['template' => 'signatur-text']) }}"
                                data-template-key="signatur-text"
                                data-template-format="txt"
                                data-no-navigate
                                class="inline-flex min-h-11 shrink-0 items-center justify-center gap-2 rounded-xl bg-rt-surface-muted px-4 py-2 text-sm font-semibold text-rt-text ring-1 ring-rt-border/70 transition hover:-translate-y-0.5 hover:text-rt-red focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-rt-red/15 dark:bg-rt-dark-surface-muted dark:text-rt-dark-text dark:ring-rt-dark-border/70 dark:hover:text-rt-dark-accent"
                            >
                                <i class="far fa-download" aria-hidden="true"></i>
                                {{ __('app.download') }} · TXT
                            </a>
                        </article>
                    </div>
                </x-ui.accordion.section>
            </div>

            <aside class="flex items-start gap-3 rounded-2xl bg-rt-surface-muted px-4 py-4 text-xs leading-5 text-rt-muted ring-1 ring-rt-border/70 dark:bg-rt-dark-surface-muted dark:text-rt-dark-muted dark:ring-rt-dark-border/70">
                <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-rt-surface text-rt-red ring-1 ring-rt-border/70 dark:bg-rt-dark-surface dark:text-rt-dark-accent dark:ring-rt-dark-border/70">
                    <i class="far fa-info-circle" aria-hidden="true"></i>
                </span>
                <div class="space-y-1">
                    <p>{{ __('app.email_templates_legal_hint') }}</p>
                    <p class="font-semibold text-rt-text dark:text-rt-dark-text">{{ __('app.email_templates_help_hint') }}</p>
                </div>
            </aside>

            <x-ui.state-modal
                id="email-template-profile-modal"
                state="profileModalOpen"
                :title="__('app.profile_status')"
                :description="__('app.email_templates_profile_accordion_hint')"
                icon="fad fa-address-card"
                max-width="2xl"
                close-action="closeModal('profile')"
                data-email-template-modal="profile"
            >
                <div class="space-y-4">
                    <div class="flex flex-col gap-4 rounded-2xl bg-rt-surface-muted p-4 ring-1 ring-rt-border/60 dark:bg-rt-dark-surface-muted dark:ring-rt-dark-border/60 sm:flex-row sm:items-center sm:justify-between">
                        <div class="flex min-w-0 items-center gap-3.5">
                            <img
                                src="{{ $user->profile_photo_url }}"
                                alt="{{ $user->name }}"
                                class="h-12 w-12 rounded-xl object-cover shadow-rt-xs ring-1 ring-rt-border/70 dark:ring-rt-dark-border/70"
                            >
                            <div class="min-w-0">
                                <p class="truncate text-base font-semibold tracking-tight text-rt-text dark:text-rt-dark-text">{{ $user->name }}</p>
                                <p class="mt-0.5 truncate text-xs text-rt-muted dark:text-rt-dark-muted">{{ $templateValues['POSITION'] }}</p>
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
                        </div>
                    @else
                        <div class="flex items-start gap-2.5 rounded-2xl bg-emerald-50 p-4 text-sm text-emerald-800 ring-1 ring-inset ring-emerald-600/20 dark:bg-emerald-500/10 dark:text-emerald-300 dark:ring-emerald-500/25">
                            <i class="far fa-check-circle mt-0.5" aria-hidden="true"></i>
                            <p class="leading-5">{{ __('app.email_templates_profile_ready_detail') }}</p>
                        </div>
                    @endif
                </div>

                <x-slot:footer>
                    @if ($missingPhone)
                        <a
                            href="{{ route('profile.show') }}"
                            wire:navigate
                            class="inline-flex min-h-11 flex-1 items-center justify-center gap-2 rounded-xl px-4 py-2 text-sm font-semibold text-rt-red ring-1 ring-inset ring-rt-red/20 transition hover:bg-rt-accent-soft dark:text-rt-dark-accent dark:ring-rt-dark-accent/25 dark:hover:bg-rt-dark-accent-soft sm:flex-none"
                        >
                            {{ __('app.edit_profile') }}
                            <i class="far fa-arrow-right" aria-hidden="true"></i>
                        </a>
                    @endif
                    <button
                        type="button"
                        x-on:click="closeModal('profile')"
                        class="inline-flex min-h-11 flex-1 items-center justify-center gap-2 rounded-xl bg-rt-red px-4 py-2 text-sm font-semibold text-white shadow-rt-xs transition hover:bg-rt-red-dark sm:flex-none"
                    >
                        {{ __('app.close') }}
                    </button>
                </x-slot:footer>
            </x-ui.state-modal>

            <x-ui.state-modal
                id="email-template-preview-modal"
                state="previewModalOpen"
                :title="__('app.email_templates_preview_accordion')"
                :description="__('app.email_templates_preview_accordion_hint')"
                icon="fad fa-window-restore"
                max-width="6xl"
                close-action="closeModal('preview')"
                class="h-[calc(100dvh-1rem)] sm:h-[calc(100dvh-3rem)]"
                data-email-template-modal="preview"
            >
                <div class="flex h-full min-h-[26rem] flex-col gap-3 sm:gap-4">
                    <div class="flex shrink-0 flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                        <div
                            class="grid grid-cols-2 rounded-xl bg-rt-surface-muted p-1 ring-1 ring-rt-border/70 dark:bg-rt-dark-surface-muted dark:ring-rt-dark-border/70"
                            role="group"
                            aria-label="{{ __('app.toggle_theme') }}"
                            data-email-template-preview-theme-toggle
                        >
                            @foreach ($themes as $themeKey => $theme)
                                <button
                                    type="button"
                                    x-on:click="selectPreviewTheme(@js($themeKey))"
                                    x-bind:aria-pressed="(mailTheme === @js($themeKey)).toString()"
                                    data-email-template-preview-theme-option="{{ $themeKey }}"
                                    class="inline-flex min-h-11 items-center justify-center gap-2 rounded-lg px-3 py-2 text-xs font-semibold transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rt-red/35 sm:min-w-24"
                                    x-bind:class="mailTheme === @js($themeKey)
                                        ? 'bg-rt-surface text-rt-red shadow-rt-xs ring-1 ring-rt-border/70 dark:bg-rt-dark-surface dark:text-rt-dark-accent dark:ring-rt-dark-border/70'
                                        : 'text-rt-muted hover:text-rt-text dark:text-rt-dark-muted dark:hover:text-rt-dark-text'"
                                >
                                    <i class="{{ $theme['icon'] }}" aria-hidden="true"></i>
                                    <span>{{ $theme['label'] }}</span>
                                </button>
                            @endforeach
                        </div>

                        <div class="grid grid-cols-3 gap-2">
                            <button
                                type="button"
                                x-on:click="replayPreview()"
                                class="inline-flex min-h-11 items-center justify-center gap-2 rounded-xl px-3 py-2 text-xs font-semibold text-rt-red ring-1 ring-inset ring-rt-red/20 transition hover:bg-rt-accent-soft focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-rt-red/20 dark:text-rt-dark-accent dark:ring-rt-dark-accent/25 dark:hover:bg-rt-dark-accent-soft sm:px-4"
                                data-email-template-preview-replay
                            >
                                <i class="far fa-rotate-right" aria-hidden="true"></i>
                                <span>{{ __('app.email_templates_preview_replay') }}</span>
                            </button>
                            <a
                                x-bind:href="previewFrameUrl()"
                                target="_blank"
                                rel="noopener"
                                class="inline-flex min-h-11 items-center justify-center gap-2 rounded-xl px-3 py-2 text-xs font-semibold text-rt-red ring-1 ring-inset ring-rt-red/20 transition hover:bg-rt-accent-soft dark:text-rt-dark-accent dark:ring-rt-dark-accent/25 dark:hover:bg-rt-dark-accent-soft sm:px-4"
                            >
                                <i class="far fa-external-link-alt" aria-hidden="true"></i>
                                <span>{{ __('app.open') }}</span>
                            </a>
                            <a
                                x-bind:href="previewDownloadUrls[mailTheme]"
                                data-no-navigate
                                class="inline-flex min-h-11 items-center justify-center gap-2 rounded-xl bg-rt-red px-3 py-2 text-xs font-semibold text-white shadow-rt-xs transition hover:bg-rt-red-dark sm:px-4"
                            >
                                <i class="far fa-download" aria-hidden="true"></i>
                                <span>{{ __('app.download') }}</span>
                            </a>
                        </div>
                    </div>

                    <p class="flex shrink-0 items-start gap-2 text-xs leading-5 text-rt-muted dark:text-rt-dark-muted">
                        <i class="far fa-sparkles mt-0.5 text-rt-red dark:text-rt-dark-accent" aria-hidden="true"></i>
                        <span>{{ __('app.email_templates_preview_lazy_hint') }}</span>
                        <span x-show="reducedMotion">{{ __('app.email_templates_preview_reduced_motion_hint') }}</span>
                        <span class="sr-only" x-text="previewLabels[mailTheme]"></span>
                    </p>

                    <template x-if="previewModalOpen">
                        <div class="min-h-0 flex-1 overflow-hidden rounded-2xl bg-white shadow-inner ring-1 ring-rt-border/70 dark:ring-rt-dark-border/70" data-email-template-preview>
                            <iframe
                                x-bind:src="previewFrameUrl()"
                                title="{{ __('app.email_templates_preview_accordion') }}"
                                sandbox=""
                                loading="lazy"
                                class="h-full min-h-[22rem] w-full border-0 bg-white"
                                data-email-template-preview-frame
                            ></iframe>
                        </div>
                    </template>
                </div>
            </x-ui.state-modal>
        </div>
    </x-ui.page>
@endsection
