@props([
    'file',                 // Pflicht: dein File-/Attachment-Objekt
    'previewUrl'  => null,  // optional, falls du explizit was setzen willst
    'downloadUrl' => null,  // optional, falls du explizit was setzen willst
])

@php
    // Titel & Meta
    $name      = $file->name ?? $file->original_name ?? 'Datei';
    $size      = $file->size_formatted ?? null;
    $mime      = $file->mime_type ?? $file->mimetype ?? null;

    // Icon / Thumbnail
    $icon      = $file->icon_or_thumbnail
        ?? ($file->icon ?? null)
        ?? asset('images/fileicons/file-generic.svg');

    // Fallback-URLs: wenn das Model eine URL-Methode hat
    $previewUrl  = $previewUrl  ?? (method_exists($file, 'getEphemeralPublicUrl') ? $file->getEphemeralPublicUrl() : null);
@endphp

<div class="rt-ui-surface flex items-center justify-between gap-3 rounded-lg bg-rt-surface px-3 py-2 text-sm shadow-rt-xs ring-1 ring-rt-border/60 dark:bg-rt-dark-surface dark:ring-rt-dark-border/60">

    {{-- Linke Seite: Icon + Infos --}}
    <div class="flex items-center gap-3 min-w-0">
        <div class="flex-shrink-0 w-9 h-9 flex items-center justify-center">
            @if($icon)
                <img
                    class="w-9 h-9 object-contain"
                    src="{{ $icon }}"
                    alt="Datei-Icon"
                >
            @else
                <div class="rt-ui-surface-muted flex h-8 w-8 items-center justify-center rounded bg-rt-surface-muted text-rt-soft dark:bg-rt-dark-surface-muted dark:text-rt-dark-soft">
                    <i class="fal fa-file"></i>
                </div>
            @endif
        </div>

        <div class="flex-1 min-w-0">
            <div class="truncate font-medium text-rt-text dark:text-rt-dark-text">
                {{ $name }}
            </div>

            <div class="flex items-center gap-2 text-[11px] text-rt-muted dark:text-rt-dark-muted">
                @if($size)
                    <span>{{ $size }}</span>
                @endif
                @if($mime)
                    <span class="inline-block h-1 w-1 rounded-full bg-rt-soft dark:bg-rt-dark-soft"></span>
                    <span>{{ strtoupper($mime) }}</span>
                @endif
            </div>
        </div>
    </div>

    {{-- Rechte Seite: Aktionen (immer gleich) --}}
    <div class="flex items-center gap-1 flex-shrink-0">

        {{-- Vorschau --}}
        @if($previewUrl)
            <a
                href="{{ $previewUrl }}"
                target="_blank"
                class="rt-ui-button rt-ui-button-secondary inline-flex items-center gap-1 rounded-lg border border-rt-border bg-rt-surface px-2 py-1 text-xs font-medium text-rt-text shadow-rt-xs transition-all duration-300 ease-rt-spring hover:bg-rt-surface-muted active:scale-[0.98] focus:outline-none focus:ring-2 focus:ring-rt-red/40 dark:border-rt-dark-border dark:bg-rt-dark-surface dark:text-rt-dark-text dark:hover:bg-rt-dark-surface-muted"
                title="Datei in neuem Tab öffnen"
            >
                <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M15 3h6v6"/>
                    <path d="M10 14 21 3"/>
                    <path d="M5 5v14h14"/>
                </svg>
                <span>Download</span>
            </a>
        @endif
    </div>
</div>
