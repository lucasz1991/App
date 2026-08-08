@section('title', 'Marketing · '.$creativeRecord->title)

@php
    $shared = $creativeRecord->shared_content ?: [];
    $list = static function (array $content, string $key, array $fallback = []): array {
        $value = $content[$key] ?? $fallback;

        return is_array($value) ? array_values($value) : $fallback;
    };
    $statusValue = $creativeRecord->status instanceof \BackedEnum ? $creativeRecord->status->value : (string) $creativeRecord->status;
    $statusLabel = ['draft' => 'Entwurf', 'approved' => 'Freigegeben', 'archived' => 'Archiviert'][$statusValue] ?? $statusValue;
@endphp

<x-ui.page
    :title="$creativeRecord->title"
    eyebrow="Marketing-Studio"
    description="Gemeinsame Inhalte pflegen und Story, Post sowie Webmotiv unabhängig gestalten."
    :back-url="route('admin.marketing.creatives.index')"
    :auto-intro="false"
    content-class="space-y-4"
    class="rt-marketing-editor-page"
    data-marketing-studio
>
    <x-slot:actions>
        <span
            data-marketing-creative-status
            data-status="{{ $statusValue }}"
            @class([
                'inline-flex min-h-9 items-center rounded-full px-3 text-xs font-bold uppercase tracking-[0.08em]',
                'bg-amber-50 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300' => $statusValue === 'draft',
                'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300' => $statusValue === 'approved',
                'bg-slate-100 text-slate-600 dark:bg-slate-500/10 dark:text-slate-300' => $statusValue === 'archived',
            ])
        >{{ $statusLabel }}</span>
    </x-slot:actions>

    <script type="application/json" data-marketing-editor-config>{!! json_encode($editorConfig, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}</script>

    @if ($statusValue === 'archived')
        <div class="flex items-start gap-3 rounded-xl border border-slate-300 bg-slate-50 px-4 py-3 text-sm text-slate-700 dark:border-slate-700 dark:bg-slate-500/10 dark:text-slate-300" role="status" data-marketing-archived-notice>
            <i data-feather="archive" class="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true"></i>
            <p><strong>Archivierte Ansicht.</strong> Inhalte, Layout und PNG-Export sind gesperrt. Die drei Formate können weiterhin angesehen werden.</p>
        </div>
    @endif

    <div
        @class([
            'flex flex-col gap-3 rounded-xl border px-4 py-3 text-sm sm:flex-row sm:items-center sm:justify-between',
            'border-rt-border bg-rt-surface text-rt-muted dark:border-rt-dark-border dark:bg-rt-dark-surface dark:text-rt-dark-muted' => ! $mediaSourceInvalid,
            'border-rose-200 bg-rose-50 text-rose-800 dark:border-rose-900 dark:bg-rose-500/10 dark:text-rose-200' => $mediaSourceInvalid,
        ])
        @if ($mediaSourceInvalid) role="alert" @else role="status" @endif
        data-marketing-media-source
    >
        <div class="flex min-w-0 items-start gap-3">
            <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-rt-surface-muted text-rt-red dark:bg-rt-dark-surface-muted dark:text-rose-300">
                <i data-feather="{{ $mediaSourceInvalid ? 'alert-triangle' : 'folder' }}" class="h-4 w-4" aria-hidden="true"></i>
            </span>
            <div class="min-w-0">
                <p class="break-words font-semibold text-rt-text dark:text-rt-dark-text">Bildquelle: <span>{{ $mediaSourcePath }}</span></p>
                <p class="mt-0.5 text-xs leading-5">
                    @if ($mediaSourceInvalid)
                        Keine Bilder verfügbar. Lege auf der Motive-Seite das Grundverzeichnis oder einen vorhandenen Ordner fest.
                    @else
                        {{ $mediaAssetCount }} {{ $mediaAssetCount === 1 ? 'Bild' : 'Bilder' }} aus diesem Ordnerbaum · Uploads und Änderungen erfolgen zentral unter Dateien.
                    @endif
                </p>
            </div>
        </div>
        <a href="{{ $mediaFilesUrl }}" wire:navigate class="inline-flex min-h-11 shrink-0 items-center justify-center gap-2 rounded-xl border border-current/20 px-3.5 text-sm font-semibold transition hover:border-rt-red/40 hover:text-rt-red">
            <i data-feather="external-link" class="h-4 w-4" aria-hidden="true"></i>
            Dateien öffnen
        </a>
    </div>

    <div
        class="rt-marketing-workbench"
        data-marketing-editor-workbench
        data-mobile-pane="layout"
        x-data="{ mobilePane: 'layout' }"
        x-bind:data-mobile-pane="mobilePane"
    >
        <nav class="rt-marketing-mobile-panes" aria-label="Arbeitsbereich auswählen">
            <button
                type="button"
                x-on:click="mobilePane = 'layout'; $nextTick(() => window.dispatchEvent(new CustomEvent('marketing-editor:viewport-change')))"
                x-bind:aria-pressed="mobilePane === 'layout' ? 'true' : 'false'"
                aria-controls="rt-marketing-layout-pane"
            >
                <i data-feather="layout" aria-hidden="true"></i>
                <span>
                    <strong>Layout</strong>
                    <small>Motiv gestalten</small>
                </span>
            </button>
            <button
                type="button"
                x-on:click="mobilePane = 'content'"
                x-bind:aria-pressed="mobilePane === 'content' ? 'true' : 'false'"
                aria-controls="rt-marketing-content-pane"
            >
                <i data-feather="edit-3" aria-hidden="true"></i>
                <span>
                    <strong>Inhalte</strong>
                    <small>Texte für alle Formate</small>
                </span>
            </button>
        </nav>

        <aside id="rt-marketing-content-pane" class="rt-marketing-content-panel" aria-label="Gemeinsame Motivinhalte">
            <div class="border-b border-rt-border px-4 py-4 dark:border-rt-dark-border">
                <p class="text-xs font-bold uppercase tracking-[0.12em] text-rt-red">Gemeinsame Inhalte</p>
                <p class="mt-1 text-xs leading-5 text-rt-muted dark:text-rt-dark-muted">Änderungen gelten für alle drei Formate. Die Positionen im Layout bleiben unabhängig.</p>
            </div>

            <form action="{{ route('admin.marketing.creatives.update', $creativeRecord) }}" method="post" class="rt-marketing-content-form" data-marketing-shared-form>
                @csrf
                @method('PATCH')

                <fieldset class="space-y-3">
                    <legend class="rt-marketing-fieldset-title">Grundtext</legend>
                    <label class="rt-marketing-field">
                        <span>Interner Titel</span>
                        <input type="text" name="title" value="{{ $creativeRecord->title }}" maxlength="160" required>
                    </label>
                    <label class="rt-marketing-field">
                        <span>Kicker</span>
                        <input type="text" name="shared_content[kicker]" value="{{ $shared['kicker'] ?? '' }}" maxlength="80" data-marketing-bind="kicker">
                    </label>
                    <label class="rt-marketing-field">
                        <span>Überschrift</span>
                        <textarea name="shared_content[title]" rows="3" maxlength="180" required data-marketing-bind="title">{{ $shared['title'] ?? $creativeRecord->title }}</textarea>
                    </label>
                    <label class="rt-marketing-field">
                        <span>Unterzeile</span>
                        <textarea name="shared_content[subtitle]" rows="2" maxlength="220" data-marketing-bind="subtitle">{{ $shared['subtitle'] ?? '' }}</textarea>
                    </label>
                    <label class="rt-marketing-field">
                        <span>Kurztext</span>
                        <textarea name="shared_content[intro]" rows="4" maxlength="500" data-marketing-bind="intro">{{ $shared['intro'] ?? '' }}</textarea>
                    </label>
                </fieldset>

                <fieldset class="space-y-3">
                    <legend class="rt-marketing-fieldset-title">Faktenleiste</legend>
                    @foreach (array_pad($list($shared, 'facts'), 3, ['value' => '', 'label' => '']) as $index => $fact)
                        @if ($index < 3)
                            <div class="grid grid-cols-[6rem_minmax(0,1fr)] gap-2">
                                <label class="rt-marketing-field">
                                    <span>Wert {{ $index + 1 }}</span>
                                    <input type="text" name="shared_content[facts][{{ $index }}][value]" value="{{ is_array($fact) ? ($fact['value'] ?? '') : $fact }}" maxlength="24">
                                </label>
                                <label class="rt-marketing-field">
                                    <span>Bezeichnung</span>
                                    <input type="text" name="shared_content[facts][{{ $index }}][label]" value="{{ is_array($fact) ? ($fact['label'] ?? '') : '' }}" maxlength="80">
                                </label>
                            </div>
                        @endif
                    @endforeach
                </fieldset>

                <fieldset class="space-y-3">
                    <legend class="rt-marketing-fieldset-title">Aufgaben</legend>
                    @foreach (array_pad($list($shared, 'tasks'), 4, '') as $index => $task)
                        @if ($index < 4)
                            <label class="rt-marketing-field">
                                <span>Aufgabe {{ $index + 1 }}</span>
                                <textarea name="shared_content[tasks][]" rows="2" maxlength="220" data-marketing-list-bind="tasks">{{ $task }}</textarea>
                            </label>
                        @endif
                    @endforeach
                </fieldset>

                <fieldset class="space-y-3">
                    <legend class="rt-marketing-fieldset-title">Profil</legend>
                    @foreach (array_pad($list($shared, 'profile'), 4, '') as $index => $profileItem)
                        @if ($index < 4)
                            <label class="rt-marketing-field">
                                <span>Anforderung {{ $index + 1 }}</span>
                                <textarea name="shared_content[profile][]" rows="2" maxlength="220" data-marketing-list-bind="profile">{{ $profileItem }}</textarea>
                            </label>
                        @endif
                    @endforeach
                </fieldset>

                <fieldset class="space-y-3">
                    <legend class="rt-marketing-fieldset-title">Vorteile</legend>
                    @foreach (array_pad($list($shared, 'benefits'), 4, '') as $index => $benefit)
                        @if ($index < 4)
                            <label class="rt-marketing-field">
                                <span>Vorteil {{ $index + 1 }}</span>
                                <textarea name="shared_content[benefits][]" rows="2" maxlength="220" data-marketing-list-bind="benefits">{{ $benefit }}</textarea>
                            </label>
                        @endif
                    @endforeach
                </fieldset>

                <fieldset class="space-y-3">
                    <legend class="rt-marketing-fieldset-title">Bewerbung und Kontakt</legend>
                    <label class="rt-marketing-field">
                        <span>CTA</span>
                        <input type="text" name="shared_content[cta_label]" value="{{ $shared['cta_label'] ?? 'Jetzt bewerben' }}" maxlength="80" data-marketing-bind="cta_label">
                    </label>
                    <label class="rt-marketing-field">
                        <span>Zieladresse</span>
                        <input type="url" name="shared_content[cta_url]" value="{{ $shared['cta_url'] ?? 'https://www.rail-time.de/de/karriere' }}" maxlength="500" data-marketing-bind="cta_url">
                    </label>
                    <label class="rt-marketing-field">
                        <span>Telefon</span>
                        <input type="tel" name="shared_content[contact_phone]" value="{{ $shared['contact_phone'] ?? '04171 546803' }}" maxlength="80" data-marketing-bind="contact_phone">
                    </label>
                    <label class="rt-marketing-field">
                        <span>E-Mail</span>
                        <input type="email" name="shared_content[contact_email]" value="{{ $shared['contact_email'] ?? 'info@rail-time.de' }}" maxlength="190" data-marketing-bind="contact_email">
                    </label>
                    <label class="rt-marketing-field">
                        <span>Website</span>
                        <input type="text" name="shared_content[website]" value="{{ $shared['website'] ?? 'www.rail-time.de' }}" maxlength="190" data-marketing-bind="website">
                    </label>
                </fieldset>

                <div class="sticky bottom-0 -mx-4 border-t border-rt-border bg-rt-surface/95 px-4 py-4 backdrop-blur dark:border-rt-dark-border dark:bg-rt-dark-surface/95">
                    <button type="submit" class="inline-flex min-h-11 w-full items-center justify-center gap-2 rounded-xl bg-rt-red px-4 text-sm font-semibold text-white transition hover:bg-rt-red-dark disabled:opacity-50" data-marketing-content-save>
                        <i data-feather="save" class="h-4 w-4" aria-hidden="true"></i>
                        Inhalte speichern
                    </button>
                    <p class="mt-2 min-h-5 text-center text-xs text-rt-muted dark:text-rt-dark-muted" data-marketing-content-status aria-live="polite"></p>
                </div>
            </form>
        </aside>

        <section id="rt-marketing-layout-pane" class="rt-marketing-editor-panel" aria-label="Layout-Editor">
            <div class="rt-marketing-editor-toolbar">
                <div class="rt-marketing-format-switch" role="group" aria-label="Ausgabeformat">
                    @foreach ([
                        'story' => ['Story', '1080 × 1920'],
                        'post' => ['Post', '1080 × 1080'],
                        'web' => ['Web', '1200 × 630'],
                    ] as $formatValue => [$formatLabel, $dimensions])
                        <button
                            type="button"
                            data-marketing-format="{{ $formatValue }}"
                            aria-pressed="{{ $format === $formatValue ? 'true' : 'false' }}"
                            @class(['is-active' => $format === $formatValue])
                        >
                            <span>{{ $formatLabel }}</span>
                            <small>{{ $dimensions }}</small>
                        </button>
                    @endforeach
                </div>

                <div class="rt-marketing-view-controls">
                    <label class="rt-marketing-safe-toggle">
                        <input type="checkbox" data-marketing-safe-zone checked>
                        <span>Safe Zone</span>
                    </label>
                    <label class="rt-marketing-zoom-control">
                        <span class="sr-only">Zoom</span>
                        <select data-marketing-zoom>
                            <option value="fit">Einpassen</option>
                            <option value="50">50 %</option>
                            <option value="75">75 %</option>
                            <option value="100">100 %</option>
                        </select>
                    </label>
                    <button type="button" class="rt-marketing-export-button" data-marketing-export>
                        <i data-feather="download" class="h-4 w-4" aria-hidden="true"></i>
                        PNG erstellen
                    </button>
                </div>
            </div>

            <div class="rt-marketing-artboard-context">
                <span class="rt-marketing-artboard-context__icon" aria-hidden="true">
                    <i data-feather="maximize-2"></i>
                </span>
                <span class="rt-marketing-artboard-context__copy">
                    <strong data-marketing-artboard-label>
                        {{ ['story' => 'Story · 1080 × 1920 px', 'post' => 'Post · 1080 × 1080 px', 'web' => 'Web · 1200 × 630 px'][$format] ?? 'Story · 1080 × 1920 px' }}
                    </strong>
                    <small>
                        Feste Exportfläche · <span data-marketing-scale-label>Ansicht wird eingepasst</span>
                        <span data-marketing-pan-hint hidden> · Wischen zum Verschieben</span>
                    </small>
                </span>
                <span class="rt-marketing-artboard-context__badge">Pixelgenau</span>
            </div>

            <div class="rt-marketing-render-status" data-marketing-render-status data-state="idle" aria-live="polite">
                <span class="rt-marketing-render-status__dot" aria-hidden="true"></span>
                <span data-marketing-render-message>Noch kein Export für dieses Format erstellt.</span>
                <a href="#" class="hidden" data-marketing-render-download>PNG herunterladen</a>
            </div>

            <div class="rt-marketing-editor-frame" data-marketing-editor-frame data-format="{{ $format }}" data-safe-zone="true">
                <div
                    id="marketing-studio-editor-{{ $creativeRecord->public_id }}"
                    class="rt-marketing-builder-root"
                    data-marketing-editor-root
                    wire:ignore
                >
                    <div class="rt-marketing-editor-loading" role="status">
                        <span class="rt-marketing-editor-loading__mark">RT</span>
                        <span>LMZ Page Builder wird geladen …</span>
                    </div>
                </div>
            </div>
        </section>
    </div>
</x-ui.page>
