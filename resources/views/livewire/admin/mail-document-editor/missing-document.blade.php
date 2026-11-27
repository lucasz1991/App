        <div class="h-full overflow-y-auto p-3 sm:p-5" data-mail-document-bootstrap data-kind="{{ $currentKind }}" data-endpoint="{{ route('admin.mail-documents.import') }}">
            <div class="mx-auto max-w-3xl space-y-4">
                <nav class="grid gap-2 sm:grid-cols-2" aria-label="Maildokument auswählen">
                    @foreach ($kinds as $kindValue => [$kindLabel, $kindHint])
                        <a
                            href="{{ route('admin.mail-documents.editor', ['dokument' => $kindValue, 'open' => 1]) }}"
                            data-mail-document-hard-switch
                            aria-current="{{ $currentKind === $kindValue ? 'page' : 'false' }}"
                            class="rounded-xl border px-4 py-3 text-sm transition {{ $currentKind === $kindValue ? 'border-rt-accent bg-rt-accent-soft text-rt-accent' : 'border-slate-200 bg-white text-slate-700 hover:border-rt-accent/40 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200' }}"
                        >
                            <strong class="block">{{ $kindLabel }}</strong>
                            <span class="mt-1 block text-xs opacity-75">{{ $kindHint }}</span>
                        </a>
                    @endforeach
                </nav>

                <div class="rounded-2xl border border-amber-200 bg-amber-50 p-5 text-sm text-amber-950 dark:border-amber-900 dark:bg-amber-500/10 dark:text-amber-100">
                    <div class="flex items-start gap-3">
                        <i class="far fa-upload mt-0.5 h-5 w-5 shrink-0 text-lg leading-5 text-center" aria-hidden="true"></i>
                        <div class="min-w-0 flex-1">
                            <h2 class="font-semibold">{{ $kinds[$currentKind][0] }} per Exportdatei einrichten</h2>
                            <p class="mt-1 leading-6">
                                Dieses Dokument fehlt noch. Wähle ein vollständiges RailTime-JSON-Bundle aus; es wird geprüft und ausschließlich als Entwurf angelegt. Vorhandene Dokumente werden nie überschrieben. Systemmails bleiben bis zur ausdrücklichen Veröffentlichung gesperrt.
                            </p>
                            <div class="mt-4 flex flex-wrap items-center gap-3">
                                <button type="button" class="inline-flex min-h-11 items-center gap-2 rounded-lg bg-rt-accent px-4 py-2 font-semibold text-white transition hover:bg-rt-accent/90 disabled:cursor-wait disabled:opacity-60" data-mail-document-bootstrap-button>
                                    <i class="far fa-file-plus h-4 w-4 text-sm leading-4 text-center" aria-hidden="true"></i>
                                    JSON-Bundle importieren
                                </button>
                                <span class="text-xs" data-mail-document-bootstrap-status aria-live="polite">Format v2 · maximal 16 MiB</span>
                            </div>
                            <p class="mt-3 hidden rounded-lg border border-red-300 bg-red-50 px-3 py-2 text-red-800 dark:border-red-900 dark:bg-red-950/40 dark:text-red-200" data-mail-document-bootstrap-error role="alert" hidden></p>
                            <input type="file" class="hidden" accept="application/json,.json" data-mail-document-bootstrap-file tabindex="-1" aria-hidden="true">
                        </div>
                    </div>
                </div>
            </div>
        </div>
