@props([
    'model',
    'mode' => 'multi',
    'label' => null,
    'acceptedFiles' => null,
    'maxFiles' => null,
    'maxFilesize' => null,
])

@php
    $label = $label ?? __('app.drop_files_here');
    $isSingle = in_array($mode, ['single', true, 1, '1'], true);
    $dropzoneMaxFiles = $isSingle ? 1 : ($maxFiles ?? 20);
    $dropzoneMaxFilesize = $maxFilesize ?? 50;
    $dropzoneAcceptedFiles = $acceptedFiles;
    $isGerman = app()->getLocale() === 'de';
    $labels = [
        'removeFile' => $isGerman ? 'Datei entfernen' : 'Remove file',
        'tooMany' => $isGerman
            ? "Maximal {$dropzoneMaxFiles} Dateien sind möglich."
            : "A maximum of {$dropzoneMaxFiles} files is allowed.",
        'tooLarge' => $isGerman
            ? "Die Datei ist größer als {$dropzoneMaxFilesize} MB."
            : "The file is larger than {$dropzoneMaxFilesize} MB.",
        'invalidType' => $isGerman ? 'Dieser Dateityp ist nicht erlaubt.' : 'This file type is not allowed.',
        'uploadFailed' => $isGerman ? 'Upload fehlgeschlagen.' : 'Upload failed.',
        'cancelUpload' => $isGerman ? 'Upload abbrechen' : 'Cancel upload',
        'uploadCanceled' => $isGerman ? 'Upload abgebrochen.' : 'Upload canceled.',
        'preparing' => $isGerman ? 'Wird vorbereitet' : 'Preparing',
        'uploading' => $isGerman ? 'Wird übertragen' : 'Uploading',
        'ready' => $isGerman ? 'Bereit zum Speichern' : 'Ready to save',
    ];
@endphp

<div
    x-data="filePoolDropzone({
        model: @js($model),
        single: @js($isSingle),
        maxFiles: @js($dropzoneMaxFiles),
        maxFilesize: @js($dropzoneMaxFilesize),
        acceptedFiles: @js($dropzoneAcceptedFiles),
        labels: @js($labels),
    })"
    class="rt-filepool-dropzone-shell"
    data-filepool-dropzone
    data-filepool-model="{{ $model }}"
    data-filepool-mode="{{ $isSingle ? 'single' : 'multi' }}"
>
    <div
        x-ref="dzForm"
        class="rt-filepool-dropzone rt-ui-surface-muted dropzone pointer-events-auto rounded-[1.35rem] border-2 border-dashed border-rt-border bg-rt-surface-muted transition-[border-color,background-color,box-shadow,transform] duration-300 ease-rt-spring hover:border-rt-red/60 hover:bg-rt-red/[0.03] focus-within:border-rt-red/70 focus-within:ring-4 focus-within:ring-rt-red/10 dark:border-rt-dark-border dark:bg-rt-dark-surface-muted dark:hover:border-rt-red/60 dark:hover:bg-rt-red/[0.06]"
        wire:ignore
    >
        <button type="button" class="dz-message needsclick" aria-label="{{ $label }}">
            <span class="rt-filepool-dropzone__icon" aria-hidden="true">
                <i class="fad fa-cloud-upload-alt"></i>
            </span>
            <span class="rt-filepool-dropzone__title">{{ $label }}</span>
            <span class="rt-filepool-dropzone__hint">
                {{ $isGerman ? 'Aus Explorer oder Finder hineinziehen oder zum Auswählen klicken' : 'Drag files here from Explorer or Finder, or click to browse' }}
            </span>
            <span class="rt-filepool-dropzone__limits" aria-label="{{ $dropzoneMaxFiles }} {{ __('app.files') }}, {{ $dropzoneMaxFilesize }} MB {{ __('app.file') }}">
                <span><i class="far fa-layer-group" aria-hidden="true"></i> {{ $dropzoneMaxFiles }} {{ __('app.files') }}</span>
                <span><i class="far fa-weight-hanging" aria-hidden="true"></i> {{ $dropzoneMaxFilesize }} MB / {{ __('app.file') }}</span>
            </span>
            @if($isSingle)
                <span class="rt-filepool-dropzone__single">{{ __('app.max_one_file') }}</span>
            @endif
        </button>

        <div class="dz-previews" aria-live="polite" aria-relevant="additions removals"></div>
    </div>

    <input
        x-ref="fileInput"
        type="file"
        @unless($isSingle) multiple @endunless
        class="sr-only"
        @if($acceptedFiles) accept="{{ $acceptedFiles }}" @endif
        wire:model="{{ $model }}"
        tabindex="-1"
        aria-hidden="true"
    >

    @error($model)
        <p class="mt-2 flex items-start gap-2 text-sm font-medium text-red-600 dark:text-red-400" role="alert">
            <i class="far fa-exclamation-circle mt-1 shrink-0" aria-hidden="true"></i>
            <span>{{ $message }}</span>
        </p>
    @enderror
</div>
