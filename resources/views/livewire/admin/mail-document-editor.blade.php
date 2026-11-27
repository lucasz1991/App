@section('title', 'Mail- & Signatur-Editor')

@php
    $kinds = [
        \App\Enums\MailDocumentKind::Template->value => ['Nachrichtenvorlage', 'Mail-Notifications und Systemmails'],
        \App\Enums\MailDocumentKind::Signature->value => ['Signaturblock', 'Outlook-Paket und Systemmails'],
    ];
@endphp

<x-ui.page-builder.editor-shell
    title="Mail- & Signatur-Editor"
    eyebrow="E-Mail-Vorlagen"
    description="Nachrichtenschale und Signaturblock zentral bearbeiten. Die veröffentlichten Fassungen gelten für Downloads und Systemmails."
    :back-url="route('email-templates.index')"
    back-label="Zur Vorlagen-Seite"
    :preview-sources="$editorPreviewSources"
    preview-default="light"
    preview-replayable
    :preview-loading-overlay="false"
    :auto-open="$editorRequested"
    :open-url="$currentDocument !== null && ! $editorRequested ? $editorOpenUrl : null"
    :render-workspace="$currentDocument === null || $editorRequested"
    :single-toolbar="$currentDocument !== null && $editorRequested"
    editor-mode="mail"
    workspace-class="min-h-0 flex-1 overflow-hidden p-0"
    data-mail-document-studio
    data-mail-document-back
>
    @unless ($editorRequested)
        <x-slot:overviewActions>
            <a href="{{ route('admin.mail-documents.u2e-draft', ['status' => 1]) }}" class="inline-flex min-h-11 items-center justify-center rounded-xl border border-rt-border px-3.5 text-sm font-semibold text-rt-text dark:border-rt-dark-border dark:text-rt-dark-text focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rt-red" data-u2e-status-link>U2e-Status</a>
            <span
                x-data="{ canCreate: @js($currentKind === \App\Enums\MailDocumentKind::Template->value && app(\App\Support\OutlookAddin\OutlookTemplateLibrary::class)->available()) }"
                x-on:mail-library-toolbar-state.window="canCreate = Boolean($event.detail.canCreate)"
                x-show="canCreate"
                x-cloak
            >
                <x-ui.buttons.button-basic
                    type="button"
                    mode="primary"
                    size="sm"
                    class="min-h-11 rounded-xl px-3.5"
                    x-on:click="$dispatch('mail-library-create-requested')"
                    aria-label="Neue Vorlage"
                    title="Neue Vorlage"
                    data-mail-library-create-trigger
                >
                    <i class="far fa-plus h-4 w-4 text-sm leading-4 text-center" aria-hidden="true"></i>
                    <span class="hidden sm:inline">Neue Vorlage</span>
                </x-ui.buttons.button-basic>
            </span>
        </x-slot:overviewActions>
        <x-slot:overview>
            <livewire:admin.mail-document-library :initial-kind="$currentKind" />
        </x-slot:overview>
    @endunless
    @if ($currentDocument !== null && $editorRequested)
        <x-slot:toolbar>
            <div class="rt-mail-studio-toolbar" role="toolbar" aria-label="Mail- und Signatur-Editor" data-mail-studio-toolbar data-mail-toolbar-layout="responsive" data-mail-toolbar-single>
                <div class="rt-mail-studio-toolbar__scroll" role="group" aria-label="Editor-Menüs; auf kleinen Bildschirmen horizontal verschiebbar">
                @include('livewire.admin.mail-document-editor.toolbar.documents')

                @include('livewire.admin.mail-document-editor.toolbar.preview')

                @include('livewire.admin.mail-document-editor.toolbar.actions')
                </div>

                @include('livewire.admin.mail-document-editor.toolbar.primary-actions')
            </div>
        </x-slot:toolbar>
    @endif

    @if ($currentDocument === null)
        @include('livewire.admin.mail-document-editor.missing-document')

        @script
            @include('livewire.admin.mail-document-editor.scripts.bootstrap-import')
        @endscript
    @elseif ($editorRequested)
        <script type="application/json" data-mail-document-config>{!! json_encode($editorConfig, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}</script>

        <div class="rt-mail-studio" data-mail-studio>
            {{-- Beanstandungen der Haertung. Sie werden nie stillschweigend
                 geschluckt: was der Sanitizer entfernt hat, steht hier. --}}
            @include('livewire.admin.mail-document-editor.findings')

            @include('livewire.admin.mail-document-editor.editor-frame')
        </div>

        @include('livewire.admin.mail-document-editor.code-dialog')

        @include('livewire.admin.mail-document-editor.design-manager')

        @script
            <script>
                (async function () {
                    @include('livewire.admin.mail-document-editor.scripts.editor.start')
                    @include('livewire.admin.mail-document-editor.scripts.editor.state')
                    @include('livewire.admin.mail-document-editor.scripts.editor.preview')
                    @include('livewire.admin.mail-document-editor.scripts.editor.findings')
                    @include('livewire.admin.mail-document-editor.scripts.editor.runtime')
                    @include('livewire.admin.mail-document-editor.scripts.editor.source')
                    @include('livewire.admin.mail-document-editor.scripts.editor.portable-media')
                    @include('livewire.admin.mail-document-editor.scripts.editor.boot')
                    @include('livewire.admin.mail-document-editor.scripts.editor.controls')
                    @include('livewire.admin.mail-document-editor.scripts.editor.design-manager')
                    @include('livewire.admin.mail-document-editor.scripts.editor.actions')
                    @include('livewire.admin.mail-document-editor.scripts.editor.lifecycle')
                })();
            </script>
        @endscript
    @endif
</x-ui.page-builder.editor-shell>
