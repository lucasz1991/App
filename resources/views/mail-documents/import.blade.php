@extends('layouts.master')

@section('title', 'Mailentwürfe importieren')

@section('content')
    @php
        $documentsByKind = $documents->groupBy(fn (\App\Models\MailDocument $document): string => $document->kind->value);
        $missingKinds = collect(\App\Enums\MailDocumentKind::cases())
            ->reject(fn (\App\Enums\MailDocumentKind $kind): bool => $documentsByKind->has($kind->value));
        $clientConfig = array_merge($importConfig, [
            'createEndpoint' => route('admin.mail-documents.import'),
            'missingKinds' => $missingKinds->map(fn (\App\Enums\MailDocumentKind $kind): array => [
                'kind' => $kind->value,
                'kindLabel' => $kind->label(),
            ])->values()->all(),
        ]);
    @endphp

    <x-ui.page
        title="Mailentwürfe importieren"
        eyebrow="E-Mail-Vorlagen"
        description="Vorlagen und Signaturen aus einem vollständigen RailTime-JSON-Bundle aktualisieren – ohne den Page Builder zu starten."
        :back-url="route('email-templates.index')"
        back-label="Zur Vorlagenübersicht"
        :auto-intro="false"
        content-class="space-y-5"
    >
        <x-slot:actions>
            <a
                href="{{ route('admin.mail-documents.editor') }}"
                class="inline-flex min-h-11 items-center justify-center gap-2 rounded-xl border border-rt-border bg-rt-surface px-4 text-sm font-semibold text-rt-text shadow-sm transition hover:border-rt-red/40 hover:text-rt-red focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-rt-red/15 dark:border-rt-dark-border dark:bg-rt-dark-surface dark:text-rt-dark-text dark:hover:border-rt-dark-accent/50 dark:hover:text-rt-dark-accent"
            >
                <i class="far fa-pen-ruler" aria-hidden="true"></i>
                Editor optional öffnen
            </a>
        </x-slot:actions>

        <script type="application/json" data-mail-draft-import-config>{!! json_encode($clientConfig, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}</script>

        <section
            class="overflow-hidden rounded-2xl border border-rt-border/80 bg-rt-surface shadow-rt-sm dark:border-rt-dark-border dark:bg-rt-dark-surface"
            aria-labelledby="mail-draft-import-heading"
            data-mail-draft-import
        >
            <div class="grid gap-px bg-rt-border/70 dark:bg-rt-dark-border lg:grid-cols-[minmax(0,1.05fr)_minmax(20rem,0.95fr)]">
                <div class="bg-rt-surface p-5 dark:bg-rt-dark-surface sm:p-7">
                    <div class="flex items-start gap-3">
                        <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-rt-accent-soft text-rt-red dark:bg-rt-dark-accent-soft dark:text-rt-dark-accent">
                            <i class="far fa-file-import text-lg" aria-hidden="true"></i>
                        </span>
                        <div class="min-w-0">
                            <p class="text-[10px] font-bold uppercase tracking-[0.16em] text-rt-red dark:text-rt-dark-accent">Builder-freier Rettungsimport</p>
                            <h2 id="mail-draft-import-heading" class="mt-1 text-lg font-semibold text-rt-text dark:text-rt-dark-text">JSON-Bundle direkt in einen Entwurf laden</h2>
                            <p class="mt-2 max-w-2xl text-sm leading-6 text-rt-muted dark:text-rt-dark-muted">
                                Die Datei wird zunächst lokal geprüft und anschließend über dieselbe serverseitige Medien-, HTML-, CSS- und E-Mail-Kompatibilitätsprüfung wie im Editor verarbeitet. GrapesJS und der umfangreiche Medienkatalog werden auf dieser Seite nicht geladen.
                            </p>
                        </div>
                    </div>

                    <div class="mt-6 grid gap-5">
                        <label class="grid gap-2 text-sm font-semibold text-rt-text dark:text-rt-dark-text">
                            Zielentwurf
                            <select
                                class="min-h-12 w-full rounded-xl border border-rt-border bg-white px-3 text-sm font-medium text-rt-text shadow-sm outline-none transition focus:border-rt-red focus:ring-4 focus:ring-rt-red/10 dark:border-rt-dark-border dark:bg-rt-dark-surface-muted dark:text-rt-dark-text"
                                data-mail-draft-import-target
                            >
                                @foreach (\App\Enums\MailDocumentKind::cases() as $kind)
                                    @if (($kindDocuments = $documentsByKind->get($kind->value, collect()))->isNotEmpty())
                                        <optgroup label="{{ $kind->label() }}">
                                            @foreach ($kindDocuments as $document)
                                                <option value="{{ $document->public_id }}">
                                                    {{ $document->name ?: $kind->label() }} · v{{ $document->version }}{{ $document->isActive() ? ' · aktiv' : '' }}
                                                </option>
                                            @endforeach
                                        </optgroup>
                                    @else
                                        <optgroup label="{{ $kind->label() }}">
                                            <option value="new:{{ $kind->value }}">{{ $kind->label() }} erstmalig einrichten</option>
                                        </optgroup>
                                    @endif
                                @endforeach
                            </select>
                        </label>

                        <div class="rounded-xl border border-dashed border-rt-border bg-rt-surface-muted/70 p-4 dark:border-rt-dark-border dark:bg-rt-dark-surface-muted/60">
                            <label class="flex min-h-28 cursor-pointer flex-col items-center justify-center gap-2 rounded-lg px-4 py-5 text-center outline-none transition hover:bg-white/70 focus-within:ring-4 focus-within:ring-rt-red/10 dark:hover:bg-white/5">
                                <span class="flex h-10 w-10 items-center justify-center rounded-full bg-white text-rt-red shadow-sm dark:bg-rt-dark-surface dark:text-rt-dark-accent">
                                    <i class="far fa-upload" aria-hidden="true"></i>
                                </span>
                                <span class="text-sm font-semibold text-rt-text dark:text-rt-dark-text">RailTime-Exportdatei auswählen</span>
                                <span class="text-xs text-rt-muted dark:text-rt-dark-muted" data-mail-draft-import-file-label>JSON v2 · maximal 16 MiB</span>
                                <input type="file" class="sr-only" accept="application/json,.json" data-mail-draft-import-file>
                            </label>
                        </div>

                        <div class="flex flex-col gap-3 sm:flex-row sm:items-center">
                            <button
                                type="button"
                                class="inline-flex min-h-12 items-center justify-center gap-2 rounded-xl bg-rt-red px-5 text-sm font-semibold text-white shadow-sm transition hover:bg-rt-red-dark focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-rt-red/20 disabled:cursor-not-allowed disabled:opacity-50"
                                data-mail-draft-import-submit
                                disabled
                            >
                                <i class="far fa-cloud-upload" aria-hidden="true"></i>
                                Entwurf aktualisieren
                            </button>
                            <p class="text-xs leading-5 text-rt-muted dark:text-rt-dark-muted">
                                Der bisherige Stand bleibt in der Versionshistorie erhalten.
                            </p>
                        </div>

                        <div class="hidden rounded-xl border px-4 py-3 text-sm leading-6" data-mail-draft-import-message role="status" aria-live="polite" hidden></div>
                    </div>
                </div>

                <aside class="bg-rt-surface-muted/80 p-5 dark:bg-rt-dark-surface-muted/70 sm:p-7" aria-label="Importauswirkung">
                    <p class="text-[10px] font-bold uppercase tracking-[0.16em] text-rt-muted dark:text-rt-dark-muted">Gewähltes Ziel</p>
                    <dl class="mt-4 grid gap-3 text-sm">
                        <div class="flex items-center justify-between gap-4 border-b border-rt-border/70 pb-3 dark:border-rt-dark-border/70">
                            <dt class="text-rt-muted dark:text-rt-dark-muted">Dokumentart</dt>
                            <dd class="text-right font-semibold text-rt-text dark:text-rt-dark-text" data-mail-draft-import-kind>–</dd>
                        </div>
                        <div class="flex items-center justify-between gap-4 border-b border-rt-border/70 pb-3 dark:border-rt-dark-border/70">
                            <dt class="text-rt-muted dark:text-rt-dark-muted">Design-Slot</dt>
                            <dd class="text-right font-semibold text-rt-text dark:text-rt-dark-text" data-mail-draft-import-name>–</dd>
                        </div>
                        <div class="flex items-center justify-between gap-4 border-b border-rt-border/70 pb-3 dark:border-rt-dark-border/70">
                            <dt class="text-rt-muted dark:text-rt-dark-muted">Arbeitsstand</dt>
                            <dd class="text-right font-semibold text-rt-text dark:text-rt-dark-text" data-mail-draft-import-version>–</dd>
                        </div>
                        <div class="flex items-center justify-between gap-4">
                            <dt class="text-rt-muted dark:text-rt-dark-muted">Bundle erkannt</dt>
                            <dd class="text-right font-semibold text-rt-text dark:text-rt-dark-text" data-mail-draft-import-bundle>Noch keine Datei</dd>
                        </div>
                    </dl>

                    <div class="mt-6 rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm leading-6 text-emerald-950 dark:border-emerald-900 dark:bg-emerald-500/10 dark:text-emerald-100">
                        <div class="flex items-start gap-3">
                            <i class="far fa-shield-check mt-1 shrink-0" aria-hidden="true"></i>
                            <div>
                                <p class="font-semibold">Veröffentlichung bleibt geschützt</p>
                                <p class="mt-1 text-xs leading-5 opacity-85">
                                    Ein Import ersetzt nur HTML, CSS, Medienbezüge und Builder-Daten des gewählten Entwurfs. Die aktuell aktive Systemmail wird erst über „Speichern &amp; veröffentlichen“ im Editor geändert.
                                </p>
                            </div>
                        </div>
                    </div>

                    <a
                        href="#"
                        class="mt-4 hidden min-h-11 items-center justify-center gap-2 rounded-xl border border-rt-border bg-rt-surface px-4 text-sm font-semibold text-rt-text transition hover:border-rt-red/40 hover:text-rt-red focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-rt-red/15 dark:border-rt-dark-border dark:bg-rt-dark-surface dark:text-rt-dark-text"
                        data-mail-draft-import-editor-link
                        hidden
                    >
                        <i class="far fa-arrow-up-right-from-square" aria-hidden="true"></i>
                        Importierten Entwurf im Editor öffnen
                    </a>
                </aside>
            </div>
        </section>

        <section class="grid gap-4 md:grid-cols-3" aria-label="Importablauf">
            @foreach ([
                ['1', 'Ziel wählen', 'Den vorhandenen Vorlagen- oder Signatur-Slot auswählen.'],
                ['2', 'Bundle prüfen', 'Art, Format, Größe und vollständiger Medienbestand werden geprüft.'],
                ['3', 'Entwurf ersetzen', 'Eine neue Dokumentversion entsteht; die Freigabe bleibt unangetastet.'],
            ] as [$step, $title, $copy])
                <article class="rounded-2xl border border-rt-border/70 bg-rt-surface p-4 dark:border-rt-dark-border dark:bg-rt-dark-surface">
                    <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-rt-accent-soft text-xs font-bold text-rt-red dark:bg-rt-dark-accent-soft dark:text-rt-dark-accent">{{ $step }}</span>
                    <h3 class="mt-3 text-sm font-semibold text-rt-text dark:text-rt-dark-text">{{ $title }}</h3>
                    <p class="mt-1 text-xs leading-5 text-rt-muted dark:text-rt-dark-muted">{{ $copy }}</p>
                </article>
            @endforeach
        </section>
    </x-ui.page>
@endsection

@section('js')
    <script>
        (() => {
            const root = document.querySelector('[data-mail-draft-import]');
            const configNode = document.querySelector('[data-mail-draft-import-config]');
            if (!root || !configNode || root.dataset.initialized === 'true') return;

            root.dataset.initialized = 'true';
            const listeners = new AbortController();
            let config = null;
            try {
                config = JSON.parse(configNode.textContent || '{}');
            } catch (_) {
                return;
            }

            const documents = new Map((config.documents || []).map((document) => [document.id, document]));
            const target = root.querySelector('[data-mail-draft-import-target]');
            const fileInput = root.querySelector('[data-mail-draft-import-file]');
            const fileLabel = root.querySelector('[data-mail-draft-import-file-label]');
            const submit = root.querySelector('[data-mail-draft-import-submit]');
            const message = root.querySelector('[data-mail-draft-import-message]');
            const kindValue = root.querySelector('[data-mail-draft-import-kind]');
            const nameValue = root.querySelector('[data-mail-draft-import-name]');
            const versionValue = root.querySelector('[data-mail-draft-import-version]');
            const bundleValue = root.querySelector('[data-mail-draft-import-bundle]');
            const editorLink = root.querySelector('[data-mail-draft-import-editor-link]');
            let bundle = null;

            if (!target || !fileInput || !submit || !message) return;

            const selectedTarget = () => {
                if (target.value.startsWith('new:')) {
                    const kind = target.value.slice(4);
                    const missing = (config.missingKinds || []).find((entry) => entry.kind === kind);
                    return {
                        id: target.value,
                        kind,
                        kindLabel: missing?.kindLabel || kind,
                        name: 'Erstimport',
                        status: 'Noch nicht eingerichtet',
                        version: 0,
                        contentHash: null,
                        endpoint: config.createEndpoint,
                        editorUrl: null,
                        creating: true,
                    };
                }

                return documents.get(target.value) || null;
            };

            const setMessage = (text = '', tone = 'info') => {
                message.textContent = text;
                message.hidden = text === '';
                message.classList.toggle('hidden', text === '');
                message.className = `rounded-xl border px-4 py-3 text-sm leading-6 ${text === '' ? 'hidden' : ''}`;
                const tones = {
                    success: 'border-emerald-200 bg-emerald-50 text-emerald-950 dark:border-emerald-900 dark:bg-emerald-500/10 dark:text-emerald-100',
                    error: 'border-red-200 bg-red-50 text-red-900 dark:border-red-900 dark:bg-red-500/10 dark:text-red-100',
                    info: 'border-sky-200 bg-sky-50 text-sky-950 dark:border-sky-900 dark:bg-sky-500/10 dark:text-sky-100',
                };
                message.classList.add(...tones[tone].split(' '));
            };

            const updateSummary = () => {
                const selected = selectedTarget();
                kindValue.textContent = selected?.kindLabel || '–';
                nameValue.textContent = selected?.name || '–';
                versionValue.textContent = selected
                    ? (selected.creating ? selected.status : `${selected.status} · Version ${selected.version}`)
                    : '–';
                submit.disabled = !selected || !bundle || bundle.kind !== selected.kind;
                editorLink.hidden = !selected?.editorUrl;
                editorLink.classList.toggle('hidden', !selected?.editorUrl);
                editorLink.classList.toggle('inline-flex', Boolean(selected?.editorUrl));
                if (selected?.editorUrl) editorLink.href = selected.editorUrl;

                if (bundle && selected && bundle.kind !== selected.kind) {
                    setMessage(`Das gewählte Bundle gehört zu „${bundle.kind}“, das Ziel aber zu „${selected.kind}“.`, 'error');
                } else if (bundle) {
                    setMessage('Bundle und Ziel passen zusammen. Der Entwurf kann jetzt aktualisiert werden.', 'info');
                }
            };

            const responseError = async (response) => {
                let payload = null;
                try { payload = await response.json(); } catch (_) {}
                const errors = Object.values(payload?.errors || {}).flat().filter(Boolean);
                return new Error(errors[0] || payload?.message || `Import fehlgeschlagen (HTTP ${response.status}).`);
            };

            target.addEventListener('change', updateSummary, { signal: listeners.signal });
            fileInput.addEventListener('change', async () => {
                const file = fileInput.files?.[0] || null;
                bundle = null;
                submit.disabled = true;
                bundleValue.textContent = 'Noch keine gültige Datei';
                setMessage();
                if (!file) {
                    fileLabel.textContent = 'JSON v2 · maximal 16 MiB';
                    updateSummary();
                    return;
                }

                fileLabel.textContent = `${file.name} · ${(file.size / 1024).toFixed(1)} KiB`;
                try {
                    if (file.size < 1 || file.size > Number(config.maxBytes || 0)) {
                        throw new Error('Das JSON-Bundle muss zwischen 1 Byte und 16 MiB groß sein.');
                    }

                    const parsed = JSON.parse((await file.text()).replace(/^\uFEFF/, ''));
                    if (!parsed || Array.isArray(parsed)
                        || parsed.format !== 'railtime-mail-document'
                        || parsed.version !== 2
                        || !['template', 'signature'].includes(parsed.kind)
                        || typeof parsed.html !== 'string'
                        || typeof parsed.css !== 'string'
                        || !Array.isArray(parsed.media)) {
                        throw new Error('Die Datei ist kein vollständiges RailTime-Mail-Bundle in Version 2.');
                    }

                    bundle = parsed;
                    bundleValue.textContent = `${parsed.kind} · ${parsed.media.length} Medien`;
                    updateSummary();
                } catch (error) {
                    bundleValue.textContent = 'Datei abgelehnt';
                    setMessage(error instanceof Error ? error.message : 'Die Datei konnte nicht gelesen werden.', 'error');
                }
            }, { signal: listeners.signal });

            submit.addEventListener('click', async () => {
                const selected = selectedTarget();
                if (!selected || !bundle || bundle.kind !== selected.kind) return;

                submit.disabled = true;
                submit.setAttribute('aria-busy', 'true');
                target.disabled = true;
                fileInput.disabled = true;
                setMessage('Bundle wird serverseitig geprüft und als Entwurf gespeichert …', 'info');

                try {
                    const payload = selected.creating
                        ? bundle
                        : { ...bundle, expected_hash: selected.contentHash };
                    const response = await fetch(selected.endpoint, {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: {
                            'Accept': 'application/json',
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                        body: JSON.stringify(payload),
                    });
                    if (!response.ok) throw await responseError(response);

                    const result = await response.json();
                    if (selected.creating) {
                        setMessage('Dokument wurde als Entwurf eingerichtet. Die Importseite wird aktualisiert …', 'success');
                        window.setTimeout(() => window.location.reload(), 450);
                        return;
                    }

                    selected.contentHash = result.document.content_hash;
                    selected.version = result.document.version;
                    selected.status = result.document.status_label;
                    selected.active = result.document.is_active;
                    versionValue.textContent = `${selected.status} · Version ${selected.version}`;
                    setMessage(result.message || 'Der Entwurf wurde importiert.', 'success');
                } catch (error) {
                    setMessage(error instanceof Error ? error.message : 'Das Bundle konnte nicht importiert werden.', 'error');
                } finally {
                    submit.removeAttribute('aria-busy');
                    target.disabled = false;
                    fileInput.disabled = false;
                    submit.disabled = !bundle || bundle.kind !== selected.kind;
                }
            }, { signal: listeners.signal });

            document.addEventListener('livewire:navigating', () => listeners.abort(), {
                once: true,
                signal: listeners.signal,
            });
            updateSummary();
        })();
    </script>
@endsection
