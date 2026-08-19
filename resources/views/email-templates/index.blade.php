@extends('layouts.master')

@section('title', __('app.email_templates'))

@section('content')
    @php
        $user = auth()->user();
        $templateBuilder = new \App\Support\EmailTemplateBuilder($user);
        $templateValues = $templateBuilder->profileValues();
        $availableTemplates = collect(\App\Support\EmailTemplateBuilder::available());
        $mailTemplates = $availableTemplates->where('category', 'mail');
        $primaryDownloads = [
            'vorlage-html' => [
                'title' => 'Personalisierte Mailvorlage',
                'description' => 'Die fertige HTML-Vorlage mit Ihren Kontaktdaten für moderne Mailprogramme und den manuellen Import.',
                'eyebrow' => 'Mailvorlage',
                'format' => 'HTML',
                'icon' => 'far fa-envelope-open-text',
                'action' => 'Mailvorlage herunterladen',
            ],
            'signatur-outlook-hell' => [
                'title' => 'Outlook & Signatur einrichten',
                'description' => 'Ein Paket für beide Outlook-Generationen: Classic wird automatisch eingerichtet, das neue Outlook mit der enthaltenen HTML-Datei.',
                'eyebrow' => 'Einrichtungspaket',
                'format' => 'ZIP',
                'icon' => 'fab fa-microsoft',
                'action' => 'Outlook-Paket herunterladen',
            ],
        ];
        $previewTemplates = $mailTemplates->where('previewable', true);
        $previewUrls = $previewTemplates
            ->mapWithKeys(fn (array $template, string $key) => [
                $template['theme'] => route('email-templates.preview', ['template' => $key]),
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
        $adminMailDocuments = collect();
        if ($user?->isAdmin()) {
            try {
                if (\Illuminate\Support\Facades\Schema::hasTable('mail_documents')) {
                    $adminMailDocuments = \App\Models\MailDocument::query()
                        ->orderBy('id')
                        ->get()
                        ->keyBy(fn (\App\Models\MailDocument $document): string => $document->kind->value);
                }
            } catch (\Throwable) {
                $adminMailDocuments = collect();
            }
        }
    @endphp

    <x-ui.page
        :title="__('app.email_templates')"
        :description="__('app.email_templates_intro')"
        :eyebrow="__('app.personal_data')"
        :count="count($primaryDownloads)"
    >
        @if ($user?->isAdmin())
            <x-slot:actions>
                <a
                    href="{{ route('admin.mail-documents.editor', ['open' => 1]) }}"
                    wire:navigate
                    data-email-template-editor-link
                    class="inline-flex min-h-11 items-center justify-center gap-2 rounded-xl bg-rt-red px-4 text-sm font-semibold text-white shadow-sm transition hover:bg-rt-red-dark focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-rt-red/20"
                >
                    <i class="far fa-pen-ruler" aria-hidden="true"></i>
                    Vorlagen &amp; Signaturen bearbeiten
                </a>
            </x-slot:actions>
        @endif

        <div
            x-data="{
                profileModalOpen: false,
                previewModalOpen: false,
                mailTheme: 'light',
                previewPlaybackId: 0,
                previewAnimated: false,
                reducedMotion: false,
                motionMedia: null,
                motionListener: null,
                previewUrls: @js($previewUrls),
                previewLabels: @js($previewLabels),
                lastModalTrigger: null,
                init() {
                    this.motionMedia = window.matchMedia('(prefers-reduced-motion: reduce)');
                    this.motionListener = () => {
                        this.reducedMotion = this.motionMedia.matches;
                        if (this.reducedMotion) this.previewAnimated = false;
                    };
                    this.motionListener();
                    this.motionMedia.addEventListener?.('change', this.motionListener);
                },
                destroy() {
                    this.motionMedia?.removeEventListener?.('change', this.motionListener);
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
                    if (this.reducedMotion) return;
                    this.previewAnimated = true;
                    this.previewPlaybackId++;
                },
                previewFrameUrl() {
                    const url = this.previewUrls[this.mailTheme];
                    if (!url) return 'about:blank';
                    const preview = new URL(url, window.location.href);
                    if (this.previewAnimated) {
                        preview.searchParams.set('animate', '1');
                        preview.searchParams.set('play', String(this.previewPlaybackId));
                    } else {
                        preview.searchParams.set('static', '1');
                    }
                    return preview.href;
                },
                closeModal(name) {
                    this[name + 'ModalOpen'] = false;
                    if (name === 'preview') {
                        this.previewAnimated = false;
                    }
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

            @if ($user?->isAdmin() && $adminMailDocuments->isNotEmpty())
                <section aria-labelledby="mail-page-builder-heading" data-email-template-page-builder-previews>
                    <div class="mb-3 flex flex-wrap items-end justify-between gap-2">
                        <div>
                            <p class="text-[10px] font-bold uppercase tracking-[0.16em] text-rt-red dark:text-rt-dark-accent">LMZ Page Builder</p>
                            <h2 id="mail-page-builder-heading" class="mt-1 text-lg font-semibold text-rt-text dark:text-rt-dark-text">Aktuelle Arbeitsstände</h2>
                            <p class="mt-1 text-sm text-rt-muted dark:text-rt-dark-muted">Sichere Vorschauen öffnen den jeweiligen Editor erst nach dem Klick im Vollbild.</p>
                        </div>
                    </div>

                    <div class="grid gap-4 xl:grid-cols-2">
                        @foreach ([
                            \App\Enums\MailDocumentKind::Template->value => ['Nachrichtenschale', 'Vollständige E-Mail mit aktuellem Signatur-Arbeitsstand', 820],
                            \App\Enums\MailDocumentKind::Signature->value => ['Signatur', 'Signaturblock der Systemnachrichten mit lokalen RailTime-Markenelementen', 360],
                        ] as $documentKind => [$documentTitle, $documentDescription, $previewHeight])
                            @if ($document = $adminMailDocuments->get($documentKind))
                                @php
                                    $documentEditUrl = route('admin.mail-documents.editor', ['dokument' => $documentKind, 'open' => 1]);
                                    $documentPreviewSources = collect(['light' => 'Hell', 'dark' => 'Dunkel'])
                                        ->mapWithKeys(fn (string $label, string $theme): array => [$theme => [
                                            'label' => $label,
                                            'url' => route('admin.mail-documents.preview', [$document, 'theme' => $theme, 'animate' => 1]),
                                            'editUrl' => $documentEditUrl,
                                            'width' => 1920,
                                            'height' => $previewHeight,
                                        ]])
                                        ->all();
                                @endphp
                                <x-ui.page-builder.preview-card
                                    :title="$documentTitle"
                                    :description="$documentDescription"
                                    :status="$document->status->label()"
                                    :sources="$documentPreviewSources"
                                    default-source="light"
                                    :edit-url="$documentEditUrl"
                                    :replayable="true"
                                    :loading-overlay="false"
                                />
                            @endif
                        @endforeach
                    </div>
                </section>
            @endif

            <section
                class="overflow-hidden rounded-[1.4rem] bg-rt-surface shadow-rt-sm ring-1 ring-rt-border/70 dark:bg-rt-dark-surface dark:ring-rt-dark-border/70"
                aria-labelledby="email-template-downloads-heading"
                data-email-template-primary-downloads
            >
                <div class="border-b border-rt-border/70 px-4 py-4 dark:border-rt-dark-border/70 sm:px-5 sm:py-5">
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                        <div class="min-w-0">
                            <p class="text-[10px] font-bold uppercase tracking-[0.16em] text-rt-red dark:text-rt-dark-accent">Ihre Dateien</p>
                            <h2 id="email-template-downloads-heading" class="mt-1 text-lg font-semibold tracking-tight text-rt-text dark:text-rt-dark-text sm:text-xl">
                                Zwei Downloads, alles enthalten
                            </h2>
                            <p class="mt-1 max-w-2xl text-sm leading-6 text-rt-muted dark:text-rt-dark-muted">
                                Keine Format- oder Farbwahl mehr: Die empfohlenen Fassungen sind bereits personalisiert und einsatzbereit.
                            </p>
                        </div>
                        <span class="inline-flex min-h-8 w-fit items-center gap-2 rounded-full bg-emerald-50 px-3 text-xs font-semibold text-emerald-800 ring-1 ring-inset ring-emerald-600/20 dark:bg-emerald-500/10 dark:text-emerald-300 dark:ring-emerald-500/25">
                            <i class="far fa-check-circle" aria-hidden="true"></i>
                            2 von 2 bereit
                        </span>
                    </div>
                </div>

                <div class="grid gap-px bg-rt-border/70 dark:bg-rt-dark-border/70 lg:grid-cols-2">
                    @foreach ($primaryDownloads as $key => $download)
                        <article
                            class="flex min-w-0 flex-col bg-rt-surface p-4 dark:bg-rt-dark-surface sm:p-5"
                            data-email-template-primary-download="{{ $key }}"
                        >
                            <div class="flex items-start gap-3.5">
                                <span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl bg-rt-accent-soft text-lg text-rt-red ring-1 ring-inset ring-rt-red/10 dark:bg-rt-dark-accent-soft dark:text-rt-dark-accent dark:ring-rt-dark-accent/15">
                                    <i class="{{ $download['icon'] }}" aria-hidden="true"></i>
                                </span>
                                <div class="min-w-0 flex-1">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <p class="text-[10px] font-bold uppercase tracking-[0.14em] text-rt-red dark:text-rt-dark-accent">{{ $download['eyebrow'] }}</p>
                                        <span class="rounded-full bg-rt-surface-muted px-2 py-0.5 text-[10px] font-bold tracking-[0.08em] text-rt-muted ring-1 ring-inset ring-rt-border/70 dark:bg-rt-dark-surface-muted dark:text-rt-dark-muted dark:ring-rt-dark-border/70">{{ $download['format'] }}</span>
                                    </div>
                                    <h3 class="mt-1.5 text-base font-semibold tracking-tight text-rt-text dark:text-rt-dark-text sm:text-lg">{{ $download['title'] }}</h3>
                                    <p class="mt-1.5 text-xs leading-5 text-rt-muted dark:text-rt-dark-muted sm:text-sm sm:leading-6">{{ $download['description'] }}</p>
                                </div>
                            </div>

                            @if ($key === 'vorlage-html')
                                <div class="my-4 grid grid-cols-2 gap-2" aria-label="Inhalt der Mailvorlage">
                                    <span class="flex min-h-11 items-center gap-2 rounded-xl bg-rt-surface-muted px-3 text-xs font-medium text-rt-text ring-1 ring-inset ring-rt-border/60 dark:bg-rt-dark-surface-muted dark:text-rt-dark-text dark:ring-rt-dark-border/60">
                                        <i class="far fa-user-check text-rt-red dark:text-rt-dark-accent" aria-hidden="true"></i>
                                        Profildaten enthalten
                                    </span>
                                    <span class="flex min-h-11 items-center gap-2 rounded-xl bg-rt-surface-muted px-3 text-xs font-medium text-rt-text ring-1 ring-inset ring-rt-border/60 dark:bg-rt-dark-surface-muted dark:text-rt-dark-text dark:ring-rt-dark-border/60">
                                        <i class="far fa-shield-check text-rt-red dark:text-rt-dark-accent" aria-hidden="true"></i>
                                        Freigegebenes Design
                                    </span>
                                </div>
                            @else
                                <div class="my-4 grid gap-2 sm:grid-cols-2">
                                    <div class="rounded-xl bg-rt-surface-muted p-3 ring-1 ring-inset ring-rt-border/60 dark:bg-rt-dark-surface-muted dark:ring-rt-dark-border/60">
                                        <p class="flex items-center gap-2 text-xs font-semibold text-rt-text dark:text-rt-dark-text">
                                            <i class="far fa-bolt text-rt-red dark:text-rt-dark-accent" aria-hidden="true"></i>
                                            Classic Outlook
                                        </p>
                                        <p class="mt-1 text-[11px] leading-4 text-rt-muted dark:text-rt-dark-muted">CMD starten, Prüfung und Zuordnung laufen geführt automatisch.</p>
                                    </div>
                                    <div class="rounded-xl bg-rt-surface-muted p-3 ring-1 ring-inset ring-rt-border/60 dark:bg-rt-dark-surface-muted dark:ring-rt-dark-border/60">
                                        <p class="flex items-center gap-2 text-xs font-semibold text-rt-text dark:text-rt-dark-text">
                                            <i class="far fa-cloud text-rt-red dark:text-rt-dark-accent" aria-hidden="true"></i>
                                            Neues Outlook
                                        </p>
                                        <p class="mt-1 text-[11px] leading-4 text-rt-muted dark:text-rt-dark-muted">Enthaltene HTML-Datei öffnen, kopieren und in Konten → Signaturen einsetzen.</p>
                                    </div>
                                </div>
                            @endif

                            <div class="mt-auto grid gap-2 sm:grid-cols-2">
                                <a
                                    href="{{ route('email-templates.download', ['template' => $key]) }}"
                                    data-template-key="{{ $key }}"
                                    data-template-format="{{ strtolower($download['format']) }}"
                                    data-email-template-primary-action
                                    data-no-navigate
                                    class="inline-flex min-h-11 items-center justify-center gap-2 rounded-xl bg-rt-red px-4 py-2 text-center text-sm font-semibold text-white shadow-rt-xs transition hover:-translate-y-0.5 hover:bg-rt-red-dark focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-rt-red/20"
                                >
                                    <i class="far fa-download" aria-hidden="true"></i>
                                    {{ $download['action'] }}
                                </a>

                                @if ($key === 'vorlage-html')
                                    <button
                                        type="button"
                                        x-on:click="openPreview(mailTheme, $event)"
                                        class="inline-flex min-h-11 items-center justify-center gap-2 rounded-xl px-4 py-2 text-sm font-semibold text-rt-red ring-1 ring-inset ring-rt-red/20 transition hover:bg-rt-accent-soft focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-rt-red/15 dark:text-rt-dark-accent dark:ring-rt-dark-accent/25 dark:hover:bg-rt-dark-accent-soft"
                                    >
                                        <i class="far fa-eye" aria-hidden="true"></i>
                                        Vorschau öffnen
                                    </button>
                                @else
                                    <span class="flex min-h-11 items-center justify-center gap-2 rounded-xl bg-rt-surface-muted px-4 text-center text-xs font-semibold text-rt-muted ring-1 ring-inset ring-rt-border/60 dark:bg-rt-dark-surface-muted dark:text-rt-dark-muted dark:ring-rt-dark-border/60">
                                        <i class="far fa-file-archive" aria-hidden="true"></i>
                                        Anleitung im Paket
                                    </span>
                                @endif
                            </div>
                        </article>
                    @endforeach
                </div>
            </section>
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

                        <div class="grid grid-cols-2 gap-2">
                            <button
                                type="button"
                                x-on:click="replayPreview()"
                                x-bind:disabled="reducedMotion"
                                x-bind:title="reducedMotion ? @js(__('app.email_templates_preview_reduced_motion_hint')) : @js(__('app.email_templates_preview_replay'))"
                                class="col-span-2 inline-flex min-h-11 items-center justify-center gap-2 rounded-xl px-3 py-2 text-xs font-semibold text-rt-red ring-1 ring-inset ring-rt-red/20 transition hover:bg-rt-accent-soft focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-rt-red/20 dark:text-rt-dark-accent dark:ring-rt-dark-accent/25 dark:hover:bg-rt-dark-accent-soft sm:col-span-1 sm:px-4"
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
                        </div>
                    </div>

                    <p class="flex shrink-0 items-start gap-2 text-xs leading-5 text-rt-muted dark:text-rt-dark-muted">
                        <i class="far fa-sparkles mt-0.5 text-rt-red dark:text-rt-dark-accent" aria-hidden="true"></i>
                        <span>{{ __('app.email_templates_preview_lazy_hint') }}</span>
                        <span x-show="reducedMotion" x-cloak>{{ __('app.email_templates_preview_reduced_motion_hint') }}</span>
                        <span class="sr-only" x-text="previewLabels[mailTheme]"></span>
                    </p>

                    <template x-if="previewModalOpen">
                        <div class="relative min-h-0 flex-1 overflow-hidden rounded-2xl bg-white shadow-inner ring-1 ring-rt-border/70 dark:ring-rt-dark-border/70" data-email-template-preview>
                            <x-ui.preview.frame
                                x-bind:src="previewFrameUrl()"
                                :title="__('app.email_templates_preview_accordion')"
                                class="h-full min-h-[22rem] w-full"
                                data-email-template-preview-frame
                            />
                        </div>
                    </template>
                </div>
            </x-ui.state-modal>
        </div>
    </x-ui.page>
@endsection
