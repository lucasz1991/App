@extends('layouts.master')

@section('title', __('app.email_templates'))

@section('content')
    @php
        $user = auth()->user();
        $templateBuilder = new \App\Support\EmailTemplateBuilder($user);
        $templateValues = $templateBuilder->profileValues();
        $availableTemplates = collect(\App\Support\EmailTemplateBuilder::available());
        $mailTemplates = $availableTemplates->where('category', 'mail');
        $previewTemplates = $mailTemplates->where('previewable', true);
        $previewUrls = $previewTemplates
            ->mapWithKeys(fn (array $template, string $key) => [
                $template['theme'] => route('email-templates.preview', ['template' => $key]),
            ])
            ->all();
        $missingPhone = $templateValues['DURCHWAHL'] === '' && $templateValues['MOBIL'] === '';
        $missingPosition = blank($user->profile?->position);
        $missingContactData = $missingPhone || $missingPosition;
        $outlookAddinConfiguration = app(\App\Support\OutlookAddin\OutlookAddinConfiguration::class);
        $outlookAddinDeployed = $outlookAddinConfiguration->deployed();
        $outlookAddinConnected = $outlookAddinConfiguration->availableTo($user);
        $outlookAddinCurrent = $outlookAddinConnected
            && app(\App\Support\OutlookAddin\OutlookAddinUserSnapshotStore::class)->isCurrentForUser($user);
        $outlookAddinManaged = $outlookAddinConnected && $outlookAddinCurrent;
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
                        ->groupBy(fn (\App\Models\MailDocument $document): string => $document->kind->value)
                        ->map(fn ($slots) => $slots->first(
                            fn (\App\Models\MailDocument $document): bool => $document->isActive(),
                        ) ?? $slots->first());
                }
            } catch (\Throwable) {
                $adminMailDocuments = collect();
            }
        }
    @endphp

    <x-ui.page
        :title="__('app.email_templates')"
        :description="__('app.email_templates_short_hint')"
        :auto-intro="false"
    >
            <x-slot:actions>
                <x-email-templates.outlook-access :connected="$outlookAddinConnected" :current="$outlookAddinCurrent" :deployed="$outlookAddinDeployed" />
                @if ($user?->isAdmin())
                <a
                    href="{{ route('admin.mail-documents.import-page') }}"
                    data-email-template-import-link
                    class="inline-flex min-h-11 items-center justify-center gap-2 rounded-xl border border-rt-border bg-rt-surface px-4 text-sm font-semibold text-rt-text shadow-sm transition hover:border-rt-red/40 hover:text-rt-red focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-rt-red/15 dark:border-rt-dark-border dark:bg-rt-dark-surface dark:text-rt-dark-text dark:hover:border-rt-dark-accent/50 dark:hover:text-rt-dark-accent"
                >
                    <i class="far fa-file-import" aria-hidden="true"></i>
                    Entwürfe importieren
                </a>
                <a
                    href="{{ route('admin.mail-documents.editor') }}"
                    data-email-template-editor-link
                    class="inline-flex min-h-11 items-center justify-center gap-2 rounded-xl bg-rt-red px-4 text-sm font-semibold text-white shadow-sm transition hover:bg-rt-red-dark focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-rt-red/20"
                >
                    <i class="far fa-pen-ruler" aria-hidden="true"></i>
                    Vorlagen verwalten
                </a>
                @endif
            </x-slot:actions>

        <div
            x-data="{
                previewModalOpen: false,
                signatureModalOpen: false,
                signatureFrameReady: false,
                signatureLoadFailed: false,
                signatureCopyHtml: '',
                signatureCopyStatus: '',
                signatureCopyUrl: @js(route('email-templates.signature-copy')),
                signatureResizeObserver: null,
                signatureResizeListener: null,
                mailTheme: 'light',
                previewPlaybackId: 0,
                previewAnimated: false,
                reducedMotion: false,
                motionMedia: null,
                motionListener: null,
                previewUrls: @js($previewUrls),
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
                    this.teardownSignatureFrame();
                },
                openModal(name, event) {
                    this.lastModalTrigger = event?.currentTarget ?? document.activeElement;
                    this.previewModalOpen = name === 'preview';
                    this.signatureModalOpen = name === 'signature';
                    if (name === 'signature') {
                        this.signatureFrameReady = false;
                        this.signatureLoadFailed = false;
                        this.signatureCopyStatus = this.signatureCopyHtml
                            ? ''
                            : @js(__('app.email_templates_flow.copy_status_preparing'));
                        this.$nextTick(() => this.loadSignatureCopy());
                    }
                },
                async loadSignatureCopy() {
                    if (this.signatureCopyHtml) return;

                    this.signatureFrameReady = false;
                    this.signatureLoadFailed = false;
                    this.signatureCopyStatus = @js(__('app.email_templates_flow.copy_status_preparing'));

                    try {
                        const response = await fetch(this.signatureCopyUrl, {
                            headers: { Accept: 'application/json' },
                            credentials: 'same-origin',
                            cache: 'no-store',
                        });
                        if (!response.ok) throw new Error(@js(__('app.email_templates_flow.copy_error_load')));

                        const payload = await response.json();
                        if (typeof payload?.html !== 'string' || payload.html.trim() === '') {
                            throw new Error(@js(__('app.email_templates_flow.copy_error_incomplete')));
                        }

                        this.signatureCopyHtml = payload.html;
                        this.signatureLoadFailed = false;
                        this.signatureCopyStatus = '';
                    } catch (error) {
                        this.signatureCopyHtml = '';
                        this.signatureLoadFailed = true;
                        this.signatureCopyStatus = @js(__('app.email_templates_flow.copy_error_retry'));
                    }
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
                fitSignatureFrame() {
                    const frame = this.$refs.signatureCopyFrame;
                    const doc = frame?.contentDocument;
                    if (!frame || !doc?.body || !doc.documentElement) return;

                    frame.style.height = '1px';
                    const height = Math.max(doc.body.scrollHeight, doc.documentElement.scrollHeight, 1);
                    frame.style.height = Math.ceil(height) + 'px';
                },
                watchSignatureFrame() {
                    this.teardownSignatureFrame();

                    const frame = this.$refs.signatureCopyFrame;
                    const root = frame?.contentDocument?.documentElement;
                    if (!frame || !root) return;

                    if (window.ResizeObserver) {
                        this.signatureResizeObserver = new window.ResizeObserver(() => this.fitSignatureFrame());
                        this.signatureResizeObserver.observe(root);
                    }

                    this.signatureResizeListener = () => this.fitSignatureFrame();
                    window.addEventListener('resize', this.signatureResizeListener, { passive: true });
                },
                teardownSignatureFrame() {
                    this.signatureResizeObserver?.disconnect();
                    this.signatureResizeObserver = null;
                    if (this.signatureResizeListener) {
                        window.removeEventListener('resize', this.signatureResizeListener);
                        this.signatureResizeListener = null;
                    }
                },
                selectSignature() {
                    const frame = this.$refs.signatureCopyFrame;
                    const doc = frame?.contentDocument;
                    const selection = frame?.contentWindow?.getSelection?.();
                    const signature = doc?.querySelector('body > table[role=presentation]');
                    if (!doc || !selection || !signature) return false;

                    const range = doc.createRange();
                    range.selectNode(signature);
                    selection.removeAllRanges();
                    selection.addRange(range);
                    frame.contentWindow.focus();

                    return true;
                },
                copySignature() {
                    if (!this.selectSignature()) {
                        this.signatureCopyStatus = @js(__('app.email_templates_flow.copy_error_mark'));
                        return;
                    }

                    const frame = this.$refs.signatureCopyFrame;
                    const doc = frame?.contentDocument;
                    let copied = false;
                    try {
                        copied = doc.execCommand('copy');
                    } catch (error) {
                        copied = false;
                    }

                    this.signatureCopyStatus = copied
                        ? @js(__('app.email_templates_flow.copy_success'))
                        : @js(__('app.email_templates_flow.copy_fallback'));

                    if (copied) {
                        frame.contentWindow?.getSelection?.()?.removeAllRanges();
                        this.$nextTick(() => this.$refs.signatureCopyButton?.focus());
                    }
                },
                closeModal(name) {
                    this[name + 'ModalOpen'] = false;
                    if (name === 'preview') {
                        this.previewAnimated = false;
                    }
                    if (name === 'signature') {
                        this.signatureFrameReady = false;
                        this.teardownSignatureFrame();
                    }
                    const trigger = this.lastModalTrigger;
                    this.lastModalTrigger = null;
                    this.$nextTick(() => trigger?.focus());
                },
            }"
            class="space-y-4 sm:space-y-5"
            data-email-templates-page
        >
            @if ($missingContactData)
                <aside class="flex flex-col gap-3 rounded-2xl bg-amber-50 px-4 py-3 text-sm text-amber-950 ring-1 ring-inset ring-amber-600/20 dark:bg-amber-500/10 dark:text-amber-200 dark:ring-amber-500/25 sm:flex-row sm:items-center">
                    <span class="flex min-w-0 flex-1 items-start gap-2.5">
                        <i class="far fa-info-circle mt-0.5 shrink-0" aria-hidden="true"></i>
                        <span>
                            <strong class="font-semibold">{{ __('app.email_templates_flow.profile_needs_details') }}</strong>
                            <span class="mt-0.5 block text-xs leading-5 opacity-80">{{ __('app.email_templates_missing_data') }}</span>
                        </span>
                    </span>
                    @if ($missingPhone)
                        <a
                            href="{{ route('profile.show') }}"
                            wire:navigate
                            class="inline-flex min-h-11 shrink-0 items-center justify-center gap-2 rounded-xl px-3 py-2 text-sm font-semibold ring-1 ring-inset ring-amber-700/25 transition hover:bg-amber-100 focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-amber-500/20 dark:ring-amber-300/25 dark:hover:bg-amber-500/10"
                        >
                            {{ __('app.edit_profile') }}
                            <i class="far fa-arrow-right" aria-hidden="true"></i>
                        </a>
                    @endif
                </aside>
            @endif

            @unless ($outlookAddinManaged)
                <details
                    open
                    class="group overflow-hidden rounded-2xl bg-rt-surface shadow-rt-sm ring-1 ring-rt-border/70 dark:bg-rt-dark-surface dark:ring-rt-dark-border/70"
                    aria-labelledby="email-template-downloads-heading"
                    data-email-template-primary-downloads
                >
                <summary class="flex min-h-12 cursor-pointer list-none items-center gap-3 px-4 py-3 text-sm font-semibold text-rt-text transition hover:bg-rt-surface-muted focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-inset focus-visible:ring-rt-red/15 dark:text-rt-dark-text dark:hover:bg-rt-dark-surface-muted sm:px-5 [&::-webkit-details-marker]:hidden">
                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-rt-accent-soft text-rt-red dark:bg-rt-dark-accent-soft dark:text-rt-dark-accent">
                        <i class="far fa-screwdriver-wrench" aria-hidden="true"></i>
                    </span>
                    <span class="min-w-0 flex-1">
                        <span id="email-template-downloads-heading" class="block">
                            {{ $outlookAddinConnected ? 'Manuelle Einrichtung' : __('app.email_templates_short_hint') }}
                        </span>
                        <span class="mt-0.5 block text-xs font-normal text-rt-muted dark:text-rt-dark-muted">
                            {{ $outlookAddinConnected ? 'Der aktuelle Outlook-Stand wird beim nächsten erfolgreichen Abruf bestätigt.' : 'Signatur und Vorlage für das verwendete Mailprogramm bereitstellen.' }}
                        </span>
                    </span>
                    <i class="far fa-chevron-down text-xs text-rt-soft transition group-open:rotate-180 dark:text-rt-dark-soft" aria-hidden="true"></i>
                </summary>

                <div class="divide-y divide-rt-border/70 border-t border-rt-border/70 dark:divide-rt-dark-border/70 dark:border-rt-dark-border/70">
                    <article
                        class="grid min-w-0 gap-4 bg-rt-surface p-4 dark:bg-rt-dark-surface sm:p-5 lg:grid-cols-[minmax(0,1fr)_minmax(25rem,31rem)] lg:items-center"
                        data-email-template-primary-download="signature"
                    >
                        <div class="flex min-w-0 items-start gap-3">
                            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-rt-accent-soft text-rt-red dark:bg-rt-dark-accent-soft dark:text-rt-dark-accent">
                                <i class="far fa-signature" aria-hidden="true"></i>
                            </span>
                            <div class="min-w-0 flex-1">
                                <h3 class="text-base font-semibold tracking-tight text-rt-text dark:text-rt-dark-text">{{ __('app.email_templates_flow.signature_heading') }}</h3>
                                <p class="mt-1 text-xs leading-5 text-rt-muted dark:text-rt-dark-muted">{{ __('app.email_templates_flow.new_outlook_hint') }}</p>
                            </div>
                        </div>

                        <div class="grid gap-2 sm:grid-cols-2">
                            <button
                                type="button"
                                x-on:click="openModal('signature', $event)"
                                x-bind:aria-expanded="signatureModalOpen.toString()"
                                aria-haspopup="dialog"
                                aria-controls="email-template-signature-modal"
                                data-email-template-modal-trigger="signature"
                                data-email-template-signature-copy-action
                                data-email-template-primary-action
                                data-email-template-employee-action="signature-copy"
                                class="inline-flex min-h-11 w-full items-center justify-center gap-2 rounded-xl bg-rt-red px-4 py-2 text-center text-sm font-semibold text-white shadow-rt-xs transition hover:bg-rt-red-dark focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-rt-red/20"
                            >
                                <i class="far fa-copy" aria-hidden="true"></i>
                                <span>{{ __('app.email_templates_flow.new_outlook') }}</span>
                            </button>

                            <a
                                href="{{ route('email-templates.download', ['template' => 'signatur-outlook-hell']) }}"
                                data-template-key="signatur-outlook-hell"
                                data-template-format="zip"
                                data-email-template-secondary-action
                                data-email-template-employee-action="signature-classic"
                                data-no-navigate
                                class="inline-flex min-h-11 items-center justify-center gap-2 rounded-xl px-4 py-2 text-center text-sm font-semibold text-rt-muted transition hover:bg-rt-surface-muted hover:text-rt-red focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-rt-red/15 dark:text-rt-dark-muted dark:hover:bg-rt-dark-surface-muted dark:hover:text-rt-dark-accent"
                            >
                                <i class="fab fa-microsoft text-rt-red dark:text-rt-dark-accent" aria-hidden="true"></i>
                                <span>{{ __('app.email_templates_flow.classic_outlook') }}</span>
                            </a>
                        </div>
                    </article>

                    <article
                        class="grid min-w-0 gap-4 bg-rt-surface p-4 dark:bg-rt-dark-surface sm:p-5 lg:grid-cols-[minmax(0,1fr)_minmax(25rem,31rem)] lg:items-center"
                        data-email-template-primary-download="template"
                    >
                        <div class="flex min-w-0 items-start gap-3">
                            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-rt-accent-soft text-rt-red dark:bg-rt-dark-accent-soft dark:text-rt-dark-accent">
                                <i class="far fa-envelope-open-text" aria-hidden="true"></i>
                            </span>
                            <div class="min-w-0 flex-1">
                                <h3 class="text-base font-semibold tracking-tight text-rt-text dark:text-rt-dark-text">{{ __('app.email_templates_flow.template_heading') }}</h3>
                            </div>
                        </div>

                        <div class="grid gap-2 sm:grid-cols-2">
                            <a
                                href="{{ route('email-templates.download', ['template' => 'vorlage-html']) }}"
                                data-template-key="vorlage-html"
                                data-template-format="html"
                                data-email-template-primary-action
                                data-email-template-employee-action="template-download"
                                data-no-navigate
                                class="inline-flex min-h-11 items-center justify-center gap-2 rounded-xl bg-rt-red px-4 py-2 text-center text-sm font-semibold text-white shadow-rt-xs transition hover:bg-rt-red-dark focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-rt-red/20"
                            >
                                <i class="far fa-download" aria-hidden="true"></i>
                                {{ __('app.email_templates_flow.download_template') }}
                            </a>
                            <button
                                type="button"
                                x-on:click="openPreview(mailTheme, $event)"
                                x-bind:aria-expanded="previewModalOpen.toString()"
                                aria-haspopup="dialog"
                                aria-controls="email-template-preview-modal"
                                data-email-template-modal-trigger="preview"
                                data-email-template-employee-action="template-preview"
                                class="inline-flex min-h-11 items-center justify-center gap-2 rounded-xl px-4 py-2 text-sm font-semibold text-rt-muted transition hover:bg-rt-surface-muted hover:text-rt-red focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-rt-red/15 dark:text-rt-dark-muted dark:hover:bg-rt-dark-surface-muted dark:hover:text-rt-dark-accent"
                            >
                                <i class="far fa-eye" aria-hidden="true"></i>
                                {{ __('app.email_templates_flow.preview_first') }}
                            </button>
                        </div>
                    </article>
                </div>
                </details>
            @endunless
            @if ($user?->isAdmin() && $adminMailDocuments->isNotEmpty())
                <details
                    class="group overflow-hidden rounded-2xl bg-rt-surface shadow-rt-sm ring-1 ring-rt-border/70 dark:bg-rt-dark-surface dark:ring-rt-dark-border/70"
                    data-email-template-admin-workspaces
                >
                    <summary class="flex min-h-12 cursor-pointer list-none items-center gap-3 px-4 py-3 text-sm font-semibold text-rt-text transition hover:bg-rt-surface-muted focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-inset focus-visible:ring-rt-red/15 dark:text-rt-dark-text dark:hover:bg-rt-dark-surface-muted sm:px-5 [&::-webkit-details-marker]:hidden">
                        <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-rt-accent-soft text-rt-red dark:bg-rt-dark-accent-soft dark:text-rt-dark-accent">
                            <i class="far fa-pen-ruler" aria-hidden="true"></i>
                        </span>
                        <span class="min-w-0 flex-1">
                            <span class="block">Administration: aktuelle Arbeitsstände</span>
                            <span class="mt-0.5 block text-xs font-normal text-rt-muted dark:text-rt-dark-muted">Vorlagen und Signaturen im Page Builder prüfen</span>
                        </span>
                        <i class="far fa-chevron-down text-xs text-rt-soft transition group-open:rotate-180 dark:text-rt-dark-soft" aria-hidden="true"></i>
                    </summary>

                    <section class="border-t border-rt-border/70 p-4 dark:border-rt-dark-border/70 sm:p-5" aria-labelledby="mail-page-builder-heading" data-email-template-page-builder-previews>
                        <div class="mb-3">
                            <p class="text-[10px] font-bold uppercase tracking-[0.16em] text-rt-red dark:text-rt-dark-accent">LMZ Page Builder</p>
                            <h2 id="mail-page-builder-heading" class="mt-1 text-lg font-semibold text-rt-text dark:text-rt-dark-text">Aktuelle Arbeitsstände</h2>
                            <p class="mt-1 text-sm text-rt-muted dark:text-rt-dark-muted">Sichere Vorschauen öffnen den jeweiligen Editor erst nach dem Klick im Vollbild.</p>
                        </div>

                        <div class="grid gap-4 xl:grid-cols-2">
                            @foreach ([
                                \App\Enums\MailDocumentKind::Template->value => ['Nachrichtenschale', 'Vollständige E-Mail mit aktuellem Signatur-Arbeitsstand', 820],
                                \App\Enums\MailDocumentKind::Signature->value => ['Signatur', 'Signaturblock der Systemnachrichten mit lokalen RailTime-Markenelementen', 360],
                            ] as $documentKind => [$documentTitle, $documentDescription, $previewHeight])
                                @if ($document = $adminMailDocuments->get($documentKind))
                                    @php
                                        $documentEditUrl = route('admin.mail-documents.editor', [
                                            'dokument' => $documentKind,
                                            'slot' => $document->public_id,
                                            'open' => 1,
                                        ]);
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
                                        :navigate-edit="false"
                                    />
                                @endif
                            @endforeach
                        </div>
                    </section>
                </details>
            @endif

            <x-email-templates.outlook-app-links />

            <x-ui.state-modal
                id="email-template-signature-modal"
                state="signatureModalOpen"
                :title="__('app.email_templates_flow.copy_modal_title')"
                :description="__('app.email_templates_flow.copy_modal_description')"
                icon="fad fa-signature"
                max-width="6xl"
                close-action="closeModal('signature')"
                data-email-template-modal="signature"
            >
                <div class="space-y-4">
                    <ol class="list-decimal space-y-1 pl-5 text-xs leading-5 text-rt-muted marker:font-semibold marker:text-rt-red dark:text-rt-dark-muted dark:marker:text-rt-dark-accent" aria-label="{{ __('app.email_templates_flow.copy_steps_label') }}">
                        @foreach ([
                            __('app.email_templates_flow.copy_step_one'),
                            __('app.email_templates_flow.copy_step_two'),
                            __('app.email_templates_flow.copy_step_three'),
                        ] as $label)
                            <li>{{ $label }}</li>
                        @endforeach
                    </ol>

                    <div class="max-h-[55dvh] overflow-auto rounded-2xl bg-[#e9edf1] p-2 ring-1 ring-inset ring-rt-border/70 dark:bg-rt-dark-surface-muted dark:ring-rt-dark-border/70 sm:p-4">
                        <template x-if="signatureModalOpen && signatureCopyHtml">
                            <iframe
                                x-ref="signatureCopyFrame"
                                x-bind:srcdoc="signatureCopyHtml"
                                x-on:load="signatureFrameReady = true; fitSignatureFrame(); watchSignatureFrame(); window.setTimeout(() => fitSignatureFrame(), 120)"
                                title="{{ __('app.email_templates_flow.copy_frame_title') }}"
                                sandbox="allow-same-origin"
                                scrolling="no"
                                class="mx-auto block min-h-64 w-full max-w-[720px] border-0 bg-transparent"
                                data-email-template-signature-copy-frame
                            ></iframe>
                        </template>
                    </div>

                    <p
                        x-show="signatureCopyStatus"
                        x-cloak
                        class="text-sm font-semibold text-rt-muted dark:text-rt-dark-muted"
                        role="status"
                        aria-live="polite"
                        x-text="signatureCopyStatus"
                        data-email-template-signature-copy-status
                    ></p>
                </div>

                <x-slot:footer>
                    <button
                        type="button"
                        x-on:click="closeModal('signature')"
                        class="inline-flex min-h-11 flex-1 items-center justify-center rounded-xl px-4 py-2 text-sm font-semibold text-rt-text ring-1 ring-inset ring-rt-border/70 transition hover:bg-rt-surface-muted dark:text-rt-dark-text dark:ring-rt-dark-border/70 dark:hover:bg-rt-dark-surface-muted sm:flex-none"
                    >
                        {{ __('app.close') }}
                    </button>
                    <button
                        type="button"
                        x-on:click="signatureLoadFailed ? loadSignatureCopy() : copySignature()"
                        x-bind:disabled="!signatureFrameReady && !signatureLoadFailed"
                        x-ref="signatureCopyButton"
                        class="inline-flex min-h-11 flex-1 items-center justify-center gap-2 rounded-xl bg-rt-red px-4 py-2 text-sm font-semibold text-white shadow-rt-xs transition hover:bg-rt-red-dark disabled:cursor-not-allowed disabled:opacity-50 sm:flex-none"
                        data-email-template-signature-copy-confirm
                    >
                        <i class="far" x-bind:class="signatureLoadFailed ? 'fa-rotate-right' : 'fa-copy'" aria-hidden="true"></i>
                        <span x-text="signatureLoadFailed ? @js(__('app.email_templates_flow.retry_button')) : @js(__('app.email_templates_flow.copy_button'))"></span>
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
                    @if ($user?->isAdmin())
                        <div class="flex shrink-0 flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                            <div class="space-y-1.5">
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
                                <p class="text-[11px] font-medium text-rt-muted dark:text-rt-dark-muted">{{ __('app.email_templates_flow.admin_preview_note') }}</p>
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
                                    class="col-span-2 inline-flex min-h-11 items-center justify-center gap-2 rounded-xl px-3 py-2 text-xs font-semibold text-rt-red ring-1 ring-inset ring-rt-red/20 transition hover:bg-rt-accent-soft dark:text-rt-dark-accent dark:ring-rt-dark-accent/25 dark:hover:bg-rt-dark-accent-soft sm:col-span-1 sm:px-4"
                                >
                                    <i class="far fa-external-link-alt" aria-hidden="true"></i>
                                    <span>{{ __('app.open') }}</span>
                                </a>
                            </div>
                        </div>
                    @endif

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
