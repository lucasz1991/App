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
        :count="2"
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
                signatureModalOpen: false,
                signatureFrameReady: false,
                signatureLoadFailed: false,
                signatureCopyHtml: '',
                signatureCopyStatus: @js(__('app.email_templates_flow.copy_status_idle')),
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
                    this.teardownSignatureFrame();
                },
                openModal(name, event) {
                    this.lastModalTrigger = event?.currentTarget ?? document.activeElement;
                    this.profileModalOpen = name === 'profile';
                    this.previewModalOpen = name === 'preview';
                    this.signatureModalOpen = name === 'signature';
                    if (name === 'signature') {
                        this.signatureFrameReady = false;
                        this.signatureLoadFailed = false;
                        this.signatureCopyStatus = this.signatureCopyHtml
                            ? @js(__('app.email_templates_flow.copy_status_idle'))
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
                        this.signatureCopyStatus = @js(__('app.email_templates_flow.copy_status_idle'));
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
            <section
                class="grid grid-cols-1 gap-2.5 sm:grid-cols-2 sm:gap-3"
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

            <section
                class="overflow-hidden rounded-[1.4rem] bg-rt-surface shadow-rt-sm ring-1 ring-rt-border/70 dark:bg-rt-dark-surface dark:ring-rt-dark-border/70"
                aria-labelledby="email-template-downloads-heading"
                data-email-template-primary-downloads
            >
                <div class="border-b border-rt-border/70 px-4 py-4 dark:border-rt-dark-border/70 sm:px-5 sm:py-5">
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                        <div class="min-w-0">
                            <p class="text-[10px] font-bold uppercase tracking-[0.16em] text-rt-red dark:text-rt-dark-accent">{{ __('app.email_templates_flow.eyebrow') }}</p>
                            <h2 id="email-template-downloads-heading" class="mt-1 text-lg font-semibold tracking-tight text-rt-text dark:text-rt-dark-text sm:text-xl">
                                {{ __('app.email_templates_flow.heading') }}
                            </h2>
                            <p class="mt-1 max-w-2xl text-sm leading-6 text-rt-muted dark:text-rt-dark-muted">
                                {{ __('app.email_templates_flow.description') }}
                            </p>
                        </div>
                        <span @class([
                            'inline-flex min-h-8 w-fit items-center gap-2 rounded-full px-3 text-xs font-semibold ring-1 ring-inset',
                            'bg-amber-50 text-amber-800 ring-amber-600/20 dark:bg-amber-500/10 dark:text-amber-300 dark:ring-amber-500/25' => $missingContactData,
                            'bg-emerald-50 text-emerald-800 ring-emerald-600/20 dark:bg-emerald-500/10 dark:text-emerald-300 dark:ring-emerald-500/25' => ! $missingContactData,
                        ])>
                            <i class="far {{ $missingContactData ? 'fa-info-circle' : 'fa-check-circle' }}" aria-hidden="true"></i>
                            {{ $missingContactData ? __('app.email_templates_flow.profile_needs_details') : __('app.email_templates_flow.personalized_ready') }}
                        </span>
                    </div>

                    <ol class="mt-4 grid gap-2 sm:grid-cols-3" aria-label="{{ __('app.email_templates_flow.steps_label') }}">
                        @foreach ([
                            [__('app.email_templates_flow.step_profile'), 'far fa-user-check'],
                            [__('app.email_templates_flow.step_signature'), 'far fa-signature'],
                            [__('app.email_templates_flow.step_template'), 'far fa-envelope-open-text'],
                        ] as $step => [$label, $icon])
                            <li class="flex min-h-11 items-center gap-2.5 rounded-xl bg-rt-surface-muted px-3 text-xs font-semibold text-rt-text ring-1 ring-inset ring-rt-border/60 dark:bg-rt-dark-surface-muted dark:text-rt-dark-text dark:ring-rt-dark-border/60">
                                <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-rt-surface text-[10px] font-bold text-rt-red ring-1 ring-rt-border/70 dark:bg-rt-dark-surface dark:text-rt-dark-accent dark:ring-rt-dark-border/70">{{ $step + 1 }}</span>
                                <i class="{{ $icon }} text-rt-red dark:text-rt-dark-accent" aria-hidden="true"></i>
                                <span>{{ $label }}</span>
                            </li>
                        @endforeach
                    </ol>
                </div>

                <div class="grid gap-px bg-rt-border/70 dark:bg-rt-dark-border/70 lg:grid-cols-2">
                    <article
                        class="flex min-w-0 flex-col bg-rt-surface p-4 dark:bg-rt-dark-surface sm:p-5"
                        data-email-template-primary-download="signature"
                    >
                        <div class="flex items-start gap-3.5">
                            <span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl bg-rt-accent-soft text-lg text-rt-red ring-1 ring-inset ring-rt-red/10 dark:bg-rt-dark-accent-soft dark:text-rt-dark-accent dark:ring-rt-dark-accent/15">
                                <i class="far fa-signature" aria-hidden="true"></i>
                            </span>
                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-center gap-2">
                                    <p class="text-[10px] font-bold uppercase tracking-[0.14em] text-rt-red dark:text-rt-dark-accent">{{ __('app.email_templates_flow.signature_label') }}</p>
                                    <span class="rounded-full bg-emerald-50 px-2 py-0.5 text-[10px] font-bold text-emerald-800 ring-1 ring-inset ring-emerald-600/20 dark:bg-emerald-500/10 dark:text-emerald-300 dark:ring-emerald-500/25">{{ __('app.email_templates_flow.recommended') }}</span>
                                </div>
                                <h3 class="mt-1.5 text-base font-semibold tracking-tight text-rt-text dark:text-rt-dark-text sm:text-lg">{{ __('app.email_templates_flow.signature_heading') }}</h3>
                                <p class="mt-1.5 text-xs leading-5 text-rt-muted dark:text-rt-dark-muted sm:text-sm sm:leading-6">{{ __('app.email_templates_flow.signature_description') }}</p>
                            </div>
                        </div>

                        <div class="my-4 space-y-2">
                            <button
                                type="button"
                                x-on:click="openModal('signature', $event)"
                                x-bind:aria-expanded="signatureModalOpen.toString()"
                                aria-haspopup="dialog"
                                aria-controls="email-template-signature-modal"
                                data-email-template-modal-trigger="signature"
                                data-email-template-signature-copy-action
                                data-email-template-primary-action
                                class="flex min-h-12 w-full items-center gap-3 rounded-xl bg-rt-red px-4 py-2.5 text-left text-white shadow-rt-xs transition hover:-translate-y-0.5 hover:bg-rt-red-dark focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-rt-red/20"
                            >
                                <i class="far fa-copy w-5 text-center" aria-hidden="true"></i>
                                <span class="min-w-0 flex-1">
                                    <span class="block text-sm font-semibold">{{ __('app.email_templates_flow.new_outlook') }}</span>
                                    <span class="mt-0.5 block text-[11px] text-white/80">{{ __('app.email_templates_flow.new_outlook_hint') }}</span>
                                </span>
                                <i class="far fa-arrow-right" aria-hidden="true"></i>
                            </button>

                            <a
                                href="{{ route('email-templates.download', ['template' => 'signatur-outlook-hell']) }}"
                                data-template-key="signatur-outlook-hell"
                                data-template-format="zip"
                                data-email-template-secondary-action
                                data-no-navigate
                                class="flex min-h-12 items-center gap-3 rounded-xl bg-rt-surface-muted px-4 py-2.5 text-left text-rt-text ring-1 ring-inset ring-rt-border/70 transition hover:ring-rt-red/30 focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-rt-red/15 dark:bg-rt-dark-surface-muted dark:text-rt-dark-text dark:ring-rt-dark-border/70"
                            >
                                <i class="fab fa-microsoft w-5 text-center text-rt-red dark:text-rt-dark-accent" aria-hidden="true"></i>
                                <span class="min-w-0 flex-1">
                                    <span class="block text-sm font-semibold">{{ __('app.email_templates_flow.classic_outlook') }}</span>
                                    <span class="mt-0.5 block text-[11px] text-rt-muted dark:text-rt-dark-muted">{{ __('app.email_templates_flow.classic_outlook_hint') }}</span>
                                </span>
                                <i class="far fa-download text-rt-red dark:text-rt-dark-accent" aria-hidden="true"></i>
                            </a>
                        </div>

                        <p class="mt-auto flex items-start gap-2 text-xs leading-5 text-rt-muted dark:text-rt-dark-muted">
                            <i class="far fa-shield-check mt-0.5 text-rt-red dark:text-rt-dark-accent" aria-hidden="true"></i>
                            {{ __('app.email_templates_flow.signature_safety') }}
                        </p>
                    </article>

                    <article
                        class="flex min-w-0 flex-col bg-rt-surface p-4 dark:bg-rt-dark-surface sm:p-5"
                        data-email-template-primary-download="template"
                    >
                        <div class="flex items-start gap-3.5">
                            <span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl bg-rt-accent-soft text-lg text-rt-red ring-1 ring-inset ring-rt-red/10 dark:bg-rt-dark-accent-soft dark:text-rt-dark-accent dark:ring-rt-dark-accent/15">
                                <i class="far fa-envelope-open-text" aria-hidden="true"></i>
                            </span>
                            <div class="min-w-0 flex-1">
                                <p class="text-[10px] font-bold uppercase tracking-[0.14em] text-rt-red dark:text-rt-dark-accent">{{ __('app.email_templates_flow.template_label') }}</p>
                                <h3 class="mt-1.5 text-base font-semibold tracking-tight text-rt-text dark:text-rt-dark-text sm:text-lg">{{ __('app.email_templates_flow.template_heading') }}</h3>
                                <p class="mt-1.5 text-xs leading-5 text-rt-muted dark:text-rt-dark-muted sm:text-sm sm:leading-6">{{ __('app.email_templates_flow.template_description') }}</p>
                            </div>
                        </div>

                        <div class="my-4 grid grid-cols-1 gap-2 sm:grid-cols-2" aria-label="{{ __('app.email_templates_flow.template_contents_label') }}">
                            <span class="flex min-h-11 items-center gap-2 rounded-xl bg-rt-surface-muted px-3 text-xs font-medium text-rt-text ring-1 ring-inset ring-rt-border/60 dark:bg-rt-dark-surface-muted dark:text-rt-dark-text dark:ring-rt-dark-border/60">
                                <i class="far fa-user-check text-rt-red dark:text-rt-dark-accent" aria-hidden="true"></i>
                                {{ __('app.email_templates_flow.profile_included') }}
                            </span>
                            <span class="flex min-h-11 items-center gap-2 rounded-xl bg-rt-surface-muted px-3 text-xs font-medium text-rt-text ring-1 ring-inset ring-rt-border/60 dark:bg-rt-dark-surface-muted dark:text-rt-dark-text dark:ring-rt-dark-border/60">
                                <i class="far fa-shield-check text-rt-red dark:text-rt-dark-accent" aria-hidden="true"></i>
                                {{ __('app.email_templates_flow.approved_design') }}
                            </span>
                        </div>

                        <div class="mt-auto grid gap-2 sm:grid-cols-2">
                            <a
                                href="{{ route('email-templates.download', ['template' => 'vorlage-html']) }}"
                                data-template-key="vorlage-html"
                                data-template-format="html"
                                data-email-template-primary-action
                                data-no-navigate
                                class="inline-flex min-h-11 items-center justify-center gap-2 rounded-xl bg-rt-red px-4 py-2 text-center text-sm font-semibold text-white shadow-rt-xs transition hover:-translate-y-0.5 hover:bg-rt-red-dark focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-rt-red/20"
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
                                class="inline-flex min-h-11 items-center justify-center gap-2 rounded-xl px-4 py-2 text-sm font-semibold text-rt-red ring-1 ring-inset ring-rt-red/20 transition hover:bg-rt-accent-soft focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-rt-red/15 dark:text-rt-dark-accent dark:ring-rt-dark-accent/25 dark:hover:bg-rt-dark-accent-soft"
                            >
                                <i class="far fa-eye" aria-hidden="true"></i>
                                {{ __('app.email_templates_flow.preview_first') }}
                            </button>
                        </div>
                    </article>
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
                </details>
            @endif

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
                    <ol class="grid gap-2 sm:grid-cols-3" aria-label="{{ __('app.email_templates_flow.copy_steps_label') }}">
                        @foreach ([
                            __('app.email_templates_flow.copy_step_one'),
                            __('app.email_templates_flow.copy_step_two'),
                            __('app.email_templates_flow.copy_step_three'),
                        ] as $step => $label)
                            <li class="flex min-h-12 items-start gap-2.5 rounded-xl bg-rt-surface-muted px-3 py-2.5 text-xs leading-5 text-rt-text ring-1 ring-inset ring-rt-border/60 dark:bg-rt-dark-surface-muted dark:text-rt-dark-text dark:ring-rt-dark-border/60">
                                <span class="mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-rt-surface text-[10px] font-bold text-rt-red ring-1 ring-rt-border/70 dark:bg-rt-dark-surface dark:text-rt-dark-accent dark:ring-rt-dark-border/70">{{ $step + 1 }}</span>
                                <span>{{ $label }}</span>
                            </li>
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
                        class="min-h-6 text-sm font-semibold text-rt-muted dark:text-rt-dark-muted"
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
                        @if ($user?->isAdmin())
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
                        @else
                            <p class="inline-flex min-h-11 items-center gap-2 rounded-xl bg-rt-surface-muted px-3 text-xs font-semibold text-rt-muted ring-1 ring-inset ring-rt-border/70 dark:bg-rt-dark-surface-muted dark:text-rt-dark-muted dark:ring-rt-dark-border/70">
                                <i class="far fa-sun text-rt-red dark:text-rt-dark-accent" aria-hidden="true"></i>
                                {{ __('app.email_templates_flow.employee_preview') }}
                            </p>
                        @endif

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
