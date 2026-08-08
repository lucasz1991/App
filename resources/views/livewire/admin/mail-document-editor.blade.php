@section('title', 'E-Mail-Vorlagen')

@php
    $kinds = [
        \App\Enums\MailDocumentKind::Template->value => ['Nachrichtenschale', 'Rahmen jeder Systemmail'],
        \App\Enums\MailDocumentKind::Signature->value => ['Signaturblock', 'Signatur und Pflichtangaben'],
    ];
@endphp

<x-ui.page
    title="E-Mail-Vorlagen"
    eyebrow="Systemmails"
    description="Nachrichtenschale und Signaturblock bearbeiten. Veröffentlicht wird, was Downloads und Systemmails anschließend verwenden."
    :auto-intro="false"
    content-class="space-y-4"
    data-mail-document-studio
>
    @if ($currentDocument === null)
        <div class="flex items-start gap-3 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:border-amber-900 dark:bg-amber-500/10 dark:text-amber-200" role="status">
            <i data-feather="alert-triangle" class="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true"></i>
            <div>
                <p><strong>Die Maildokumente sind noch nicht eingerichtet.</strong></p>
                <p class="mt-1 leading-6">
                    Solange nichts veröffentlicht ist, arbeiten Downloads und Systemmails unverändert mit den heutigen
                    Blade-Quellen weiter — es fehlt also nichts, es lässt sich nur nichts bearbeiten.
                    Zum Einrichten <code class="rounded bg-black/5 px-1 py-0.5 dark:bg-white/10">php artisan migrate</code>
                    und <code class="rounded bg-black/5 px-1 py-0.5 dark:bg-white/10">php artisan db:seed --class=MailDocumentSeeder</code> ausführen.
                </p>
            </div>
        </div>
    @else
        <x-slot:actions>
            <span
                data-mail-document-status
                data-status="{{ $currentDocument->status->value }}"
                @class([
                    'inline-flex min-h-9 items-center rounded-full px-3 text-xs font-bold uppercase tracking-[0.08em]',
                    'bg-amber-50 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300' => ! $currentDocument->isPublished(),
                    'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300' => $currentDocument->isPublished(),
                ])
            >{{ $currentDocument->status->label() }}</span>
        </x-slot:actions>

        <script type="application/json" data-mail-document-config>{!! json_encode($editorConfig, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}</script>

        <div class="flex flex-col gap-3 rounded-xl border border-rt-border bg-rt-surface px-4 py-3 text-sm dark:border-rt-dark-border dark:bg-rt-dark-surface lg:flex-row lg:items-center lg:justify-between">
            <div class="flex flex-wrap gap-2" role="group" aria-label="Dokument auswählen">
                @foreach ($kinds as $kindValue => [$kindLabel, $kindHint])
                    <a
                        href="{{ route('admin.mail-documents.editor', ['dokument' => $kindValue]) }}"
                        wire:navigate
                        data-mail-document-switch="{{ $kindValue }}"
                        aria-current="{{ $currentKind === $kindValue ? 'page' : 'false' }}"
                        @class([
                            'inline-flex min-h-11 flex-col justify-center rounded-xl border px-3.5 py-1 text-left transition',
                            'border-rt-red bg-rt-red/5 text-rt-red' => $currentKind === $kindValue,
                            'border-rt-border text-rt-muted hover:border-rt-red/40 hover:text-rt-red dark:border-rt-dark-border dark:text-rt-dark-muted' => $currentKind !== $kindValue,
                        ])
                    >
                        <span class="text-sm font-semibold">{{ $kindLabel }}</span>
                        <small class="text-xs">{{ $kindHint }}</small>
                    </a>
                @endforeach
            </div>

            <div class="flex flex-wrap items-center gap-3">
                <p class="min-h-5 text-xs text-rt-muted dark:text-rt-dark-muted" data-mail-document-message aria-live="polite">
                    @if ($currentDocument->isPublished())
                        Veröffentlicht am {{ $currentDocument->published_at?->translatedFormat('d.m.Y H:i') }} Uhr.
                    @else
                        Noch nichts veröffentlicht — es gilt die heutige Blade-Quelle.
                    @endif
                </p>
                <button
                    type="button"
                    class="inline-flex min-h-11 items-center justify-center gap-2 rounded-xl bg-rt-red px-4 text-sm font-semibold text-white transition hover:bg-rt-red-dark disabled:opacity-50"
                    data-mail-document-publish
                >
                    <i data-feather="upload-cloud" class="h-4 w-4" aria-hidden="true"></i>
                    Veröffentlichen
                </button>
            </div>
        </div>

        {{-- Beanstandungen der Haertung. Sie werden nie stillschweigend
             geschluckt: was der Sanitizer entfernt hat, steht hier. --}}
        <div class="hidden rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:border-amber-900 dark:bg-amber-500/10 dark:text-amber-200" data-mail-document-findings role="alert" hidden>
            <p class="font-semibold" data-mail-document-findings-title>Hinweise der Prüfung</p>
            <ul class="mt-1 list-disc space-y-1 pl-5 leading-6" data-mail-document-findings-list></ul>
        </div>

        <div class="rt-marketing-editor-frame">
            <div
                id="mail-document-editor-{{ $currentDocument->public_id }}"
                class="rt-marketing-builder-root"
                data-mail-document-root
                wire:ignore
            >
                <div class="rt-marketing-editor-loading" role="status">
                    <span class="rt-marketing-editor-loading__mark">RT</span>
                    <span>LMZ Page Builder wird geladen …</span>
                </div>
            </div>
        </div>

        @script
            <script>
                (function () {
                    // Die Verdrahtung steht bewusst auf der Seite: das Modul
                    // resources/js/mail-builder.js bringt keinen eigenen
                    // Startvorgang mit, app.js reicht es nur als
                    // window.RailTimeMailBuilder durch.
                    const workspace = document.querySelector('[data-mail-document-studio]');
                    const root = workspace?.querySelector('[data-mail-document-root]');
                    const runtimeBridge = window.RailTimeMailBuilder;

                    if (!workspace || !root || !runtimeBridge) {
                        return;
                    }

                    // Ein zweiter Durchlauf (Livewire-Navigation, erneutes
                    // Rendern) darf keine zweite Instanz auf denselben Knoten
                    // setzen.
                    window.RailTimeMailDocumentEditor?.destroy?.();

                    const config = JSON.parse(workspace.querySelector('[data-mail-document-config]')?.textContent || '{}');
                    const document_ = config.documents?.[config.currentDocument];
                    const publishButton = workspace.querySelector('[data-mail-document-publish]');
                    const messageNode = workspace.querySelector('[data-mail-document-message]');
                    const findingsBox = workspace.querySelector('[data-mail-document-findings]');
                    const findingsList = workspace.querySelector('[data-mail-document-findings-list]');
                    const findingsTitle = workspace.querySelector('[data-mail-document-findings-title]');
                    const statusBadge = workspace.querySelector('[data-mail-document-status]');

                    if (!document_) {
                        return;
                    }

                    let instance = null;
                    let destroyed = false;

                    const toast = (type, text, title) => window.dispatchEvent(new CustomEvent('swal:toast', {
                        detail: { type, text, title: title || undefined },
                    }));

                    const setMessage = (text) => {
                        if (messageNode) messageNode.textContent = text;
                    };

                    const showFindings = (report) => {
                        if (!findingsBox || !findingsList) return;

                        const messages = Array.isArray(report?.messages) ? report.messages : [];
                        findingsList.replaceChildren();

                        if (messages.length === 0) {
                            findingsBox.hidden = true;
                            findingsBox.classList.add('hidden');
                            return;
                        }

                        const removed = (report.findings || []).some((finding) => finding.severity === 'violation');
                        if (findingsTitle) {
                            findingsTitle.textContent = removed
                                ? 'Die Prüfung hat Inhalte entfernt'
                                : 'Hinweise der Prüfung';
                        }

                        messages.forEach((message) => {
                            const item = window.document.createElement('li');
                            item.textContent = message;
                            findingsList.appendChild(item);
                        });

                        findingsBox.hidden = false;
                        findingsBox.classList.remove('hidden');
                    };

                    const applyDocumentState = (payload) => {
                        if (!payload) return;

                        // Pflicht: ohne den frischen Hash laeuft der naechste
                        // Autosave in die Konfliktmeldung.
                        document_.contentHash = payload.content_hash || document_.contentHash;
                        document_.version = payload.version ?? document_.version;
                        document_.status = payload.status || document_.status;
                        document_.hasUnpublishedChanges = Boolean(payload.has_unpublished_changes);

                        if (statusBadge) {
                            statusBadge.dataset.status = document_.status;
                            statusBadge.textContent = payload.status_label || statusBadge.textContent;
                        }
                    };

                    const loadOnce = (tag, attributes) => new Promise((resolve, reject) => {
                        const selector = tag === 'link'
                            ? `link[href="${attributes.href}"]`
                            : `script[src="${attributes.src}"]`;

                        if (window.document.querySelector(selector)) {
                            resolve();
                            return;
                        }

                        const node = window.document.createElement(tag);
                        Object.assign(node, attributes);
                        node.addEventListener('load', resolve, { once: true });
                        node.addEventListener('error', () => reject(new Error('Der LMZ Page Builder konnte nicht geladen werden.')), { once: true });
                        window.document.head.appendChild(node);
                    });

                    const ensureRuntime = async () => {
                        if (window.LMZBuilder?.create) {
                            return window.LMZBuilder;
                        }

                        await Promise.all([
                            loadOnce('link', { rel: 'stylesheet', href: config.vendor.grapesCss }),
                            loadOnce('link', { rel: 'stylesheet', href: config.vendor.builderCss }),
                        ]);
                        await loadOnce('script', { src: config.vendor.builderJs, defer: true });

                        if (!window.LMZBuilder?.create) {
                            throw new Error('LMZ Page Builder 2.4.5 wurde nicht initialisiert.');
                        }

                        return window.LMZBuilder;
                    };

                    const request = async (url, method, body = null) => {
                        const response = await fetch(url, {
                            method,
                            credentials: 'same-origin',
                            headers: {
                                Accept: 'application/json',
                                'X-Requested-With': 'XMLHttpRequest',
                                'X-CSRF-TOKEN': window.document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
                                ...(body ? { 'Content-Type': 'application/json' } : {}),
                            },
                            body: body ? JSON.stringify(body) : null,
                        });

                        let payload = {};
                        try {
                            payload = await response.json();
                        } catch (_) {
                            payload = {};
                        }

                        if (!response.ok) {
                            const validation = payload.errors
                                ? Object.values(payload.errors).flat().filter(Boolean)
                                : [];
                            const error = new Error(validation[0] || payload.message || `Anfrage fehlgeschlagen (${response.status}).`);
                            error.messages = validation;
                            throw error;
                        }

                        return payload;
                    };

                    const boot = async () => {
                        const runtime = await ensureRuntime();

                        if (destroyed) return;

                        instance = await runtimeBridge.create({
                            runtime,
                            root,
                            projectId: `mail:${document_.id}`,
                            vendor: config.vendor,
                            storage: {
                                onLoad: ({ editor }) => runtimeBridge.projectFor(
                                    document_,
                                    (css) => editor.Parser?.parseCss?.(css) || [],
                                ),
                                onSave: async ({ project, html, css }) => {
                                    const payload = await request(document_.endpoints.update, 'PUT', {
                                        builder_data: project,
                                        html,
                                        css,
                                        expected_hash: document_.contentHash || '',
                                    });

                                    document_.builderData = project;
                                    document_.css = css;
                                    applyDocumentState(payload.document);
                                    showFindings(payload.report);
                                    setMessage(document_.hasUnpublishedChanges
                                        ? 'Gespeichert — noch nicht veröffentlicht.'
                                        : 'Gespeichert.');
                                },
                            },
                        });

                        if (destroyed) {
                            instance.destroy();
                            instance = null;
                        }
                    };

                    publishButton?.addEventListener('click', async () => {
                        publishButton.disabled = true;

                        try {
                            // Erst der Arbeitsstand, dann die Freigabe:
                            // veroeffentlicht wird der gespeicherte Entwurf.
                            if (instance && instance.hasUnsavedChanges() && !(await instance.save('manual'))) {
                                throw new Error('Der Entwurf konnte nicht gespeichert werden.');
                            }

                            const payload = await request(document_.endpoints.publish, 'POST');
                            applyDocumentState(payload.document);
                            showFindings(payload.report);
                            setMessage(`Veröffentlicht am ${payload.document?.published_label ?? ''} Uhr.`);
                            toast('success', 'Systemmails und Downloads verwenden ab sofort diese Fassung.', 'Veröffentlicht');
                        } catch (error) {
                            showFindings({ messages: error.messages || [error.message], findings: [{ severity: 'violation' }] });
                            toast('error', error.message, 'Nicht veröffentlicht');
                        } finally {
                            publishButton.disabled = false;
                        }
                    });

                    boot().catch((error) => {
                        if (destroyed) return;

                        root.innerHTML = '';
                        const notice = window.document.createElement('div');
                        notice.className = 'rt-marketing-editor-error';
                        notice.setAttribute('role', 'alert');
                        notice.textContent = `Editor konnte nicht geladen werden: ${error.message}`;
                        root.appendChild(notice);
                        toast('error', error.message, 'E-Mail-Editor nicht verfügbar');
                    });

                    const teardown = () => {
                        destroyed = true;
                        instance?.destroy?.();
                        instance = null;
                        window.RailTimeMailDocumentEditor = null;
                        window.document.removeEventListener('livewire:navigating', teardown);
                    };

                    window.document.addEventListener('livewire:navigating', teardown);
                    window.RailTimeMailDocumentEditor = {
                        destroy: teardown,
                        hasUnsavedChanges: () => Boolean(instance?.hasUnsavedChanges?.()),
                    };
                })();
            </script>
        @endscript
    @endif
</x-ui.page>
