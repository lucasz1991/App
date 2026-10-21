@php
    $composerMode = $composerMode ?? 'assistant';
    $assistantComposer = in_array($composerMode, ['assistant', 'operations-chat'], true);
    $operationsChatComposer = $composerMode === 'operations-chat';
    $composerField = $assistantComposer ? 'prompt' : 'text';
    $composerAction = $operationsChatComposer ? 'submitChat' : ($assistantComposer ? 'submitPrompt' : 'submit');
    $composerId = 'intake-composer-'.$this->getId();
    $composerLimit = $maxCharacters ?? ($operationsChatComposer ? 500 : ($assistantComposer ? 2000 : 20000));
    $fileCount = count($uploads ?? []);
    $composerMaximum = max(1, min(3, (int) ($maxAttachmentCount ?? ($limits['max_attachment_count'] ?? 3))));
@endphp
<form class="rt-intake-composer grid min-w-0 gap-2"
    x-data="aiIntakeComposer($wire, @js(['mode' => $composerMode, 'field' => $composerField, 'action' => $composerAction, 'componentId' => $this->getId(), 'maxFiles' => $composerMaximum]))"
    x-effect="syncContext()" x-on:submit.prevent.stop="submit()"
    x-on:ai-intake-composer-submitted.window="submitted($event.detail.componentId)"
    x-on:ai-intake-submitted.window="submitted(@js($this->getId()))"
    x-on:ai-intake-composer-audio-removed.window="if ($event.detail.componentId === @js($this->getId())) clearAudioPreview()">
    @if(!$assistantComposer)
        <label class="sr-only" for="{{ $composerId }}-contact">Antwortadresse</label>
        <x-ui.forms.input id="{{ $composerId }}-contact" wire:model="contactEmail" type="email" placeholder="Antwortadresse (optional)" />
        <x-ui.forms.input-error for="contactEmail" />
    @endif
    @if($fileCount)
        <div class="rt-intake-composer__files flex min-w-0 flex-wrap gap-1.5" aria-label="Ausgewählte Dateien">
            @foreach($uploads as $index => $upload)
                @if($upload instanceof \Livewire\Features\SupportFileUploads\TemporaryUploadedFile)
                    <span class="flex min-w-0 max-w-full items-center gap-1 rounded-lg border border-rt-border px-2 py-1 text-xs" wire:key="{{ $composerId }}-file-{{ $upload->getFilename() }}">
                        <i class="far fa-paperclip" aria-hidden="true"></i><span class="truncate">{{ $upload->getClientOriginalName() }}</span>
                        <button type="button" class="shrink-0 p-1" x-on:click="removeFile({{ $index }})" x-bind:disabled="busy || uploadActive" aria-label="Datei entfernen: {{ $upload->getClientOriginalName() }}"><i class="far fa-times" aria-hidden="true"></i></button>
                    </span>
                @endif
            @endforeach
        </div>
        <template x-for="(file, index) in filePreviews" :key="index"><div class="min-w-0"><img x-show="file.kind === 'image'" x-bind:src="file.kind === 'image' ? file.url : null" x-bind:alt="file.name" class="max-h-24 max-w-full rounded-lg object-contain" /><audio x-show="file.kind === 'audio'" x-bind:src="file.kind === 'audio' ? file.url : null" controls preload="metadata" class="h-9 w-full" x-bind:aria-label="'Audiodatei vorhören: ' + file.name"></audio></div></template>
    @endif
    @if($audioUpload)
        <div class="rt-intake-composer__audio grid min-w-0 gap-1">
            <div class="flex items-center gap-2 text-xs"><i class="far fa-microphone" aria-hidden="true"></i><span class="min-w-0 flex-1 truncate">{{ $audioUpload->getClientOriginalName() }}</span><button type="button" class="p-1" x-on:click="removeAudio()" x-bind:disabled="busy" aria-label="Sprachaufnahme entfernen"><i class="far fa-times" aria-hidden="true"></i></button></div>
            <audio x-show="audioPreview" x-bind:src="audioPreview || null" controls preload="metadata" class="h-9 w-full" aria-label="Sprachaufnahme vorhören"></audio>
        </div>
    @endif
    <div x-cloak x-show="recording || requesting" class="flex flex-wrap items-center gap-2 text-xs" role="status" aria-live="polite">
        <span x-show="requesting">Mikrofonfreigabe ausstehend …</span><span x-show="recording">Aufnahme · <span x-text="seconds"></span> s</span>
        <button type="button" x-show="recording" x-on:click="stop()" class="rounded-lg border border-rt-border px-2 py-1">Aufnahme übernehmen</button>
        <button type="button" x-show="recording || requesting" x-on:click="cancelRecording()" class="rounded-lg border border-rt-border px-2 py-1">Abbrechen</button>
    </div>
    <x-ui.floating.composer-row control-class="rt-intake-composer__shell border border-rt-border bg-rt-surface">
        <x-slot:leading>
        <input id="{{ $composerId }}-files" class="sr-only" type="file" multiple tabindex="-1" x-ref="files" wire:model="uploads"
            accept=".txt,.md,.csv,.json,.pdf,.jpg,.jpeg,.png,.webp,.docx,.xlsx,.pptx,.wav,.mp3,.ogg,.webm,.m4a,.mp4,.aac,.flac"
            x-on:change="filesSelected($event)" x-on:livewire-upload-start="beginUpload()" x-on:livewire-upload-progress="progressUpload($event.detail)"
            x-on:livewire-upload-finish="finishUpload()" x-on:livewire-upload-error="failUpload()" x-on:livewire-upload-cancel="cancelUpload()" />
        <button type="button" class="rt-floating-composer-attachment rt-intake-composer__tool shrink-0 rounded-full p-2" x-on:click="chooseFiles()" x-bind:disabled="busy || uploadActive || recording || requesting || attachmentCount() >= {{ $composerMaximum }}" title="Dateien anhängen" aria-label="Dateien anhängen"><i class="far fa-plus" aria-hidden="true"></i></button>
        </x-slot:leading>
        <label class="sr-only" for="{{ $composerId }}-prompt">{{ $operationsChatComposer ? 'Nachricht an AI-Assist' : ($assistantComposer ? 'Frage oder Prüfhinweis' : 'Anfrage beschreiben') }}</label>
        <textarea id="{{ $composerId }}-prompt" class="rt-intake-composer__input min-h-10 min-w-0 flex-1 resize-none border-0 bg-transparent px-1 py-2 text-sm focus:ring-0"
            rows="1" maxlength="{{ $composerLimit }}" wire:model="{{ $composerField }}" x-ref="prompt"
            x-on:input="updateInput(); @if($operationsChatComposer) $dispatch('ai-assist-composer-input', { value: $el.value }) @endif"
            x-on:keydown.enter="enter($event)"
            x-bind:disabled="busy" placeholder="{{ $operationsChatComposer ? 'Frage stellen oder / für Aktionen' : ($assistantComposer ? 'Frage oder Hinweis …' : 'Anfrage beschreiben …') }}"
            @if($operationsChatComposer) aria-label="Nachricht an AI-Assist" @endif
            @if(!$assistantComposer) aria-describedby="intake-assistant-help-{{ $this->getId() }}" @endif></textarea>
        <button type="button" class="rt-intake-composer__tool shrink-0 rounded-full p-2" x-on:click="toggleRecording()" x-bind:disabled="busy || uploadActive || uploading || requesting || !!$wire.audioUpload || attachmentCount() >= {{ $composerMaximum }}" x-bind:aria-pressed="recording.toString()" title="Sprache aufnehmen" aria-label="Sprache aufnehmen"><i class="far fa-microphone" aria-hidden="true"></i></button>
        <x-slot:actions>
        <button type="submit" x-cloak x-show="hasText || attachmentCount() > 0 || busy" class="rt-floating-composer-send rt-intake-composer__send shrink-0 rounded-full bg-rt-red p-2 text-white" x-bind:disabled="!canSubmit()" aria-label="{{ $operationsChatComposer ? 'Nachricht an AI-Assist senden' : ($assistantComposer ? 'Frage oder Prüfhinweis senden' : 'Als AI-Eingang erfassen') }}"><i class="far fa-paper-plane" aria-hidden="true"></i></button>
        </x-slot:actions>
    </x-ui.floating.composer-row>
    @if(!$assistantComposer)<p id="intake-assistant-help-{{ $this->getId() }}" class="text-xs ops-muted" title="Der Eingang wird zur persönlichen Prüfung erfasst; Umschalt+Enter fügt einen Absatz hinzu.">Unverbindlich zur Prüfung erfassen.</p>@endif
    @foreach([$composerField, 'uploads', 'uploads.*', 'audioUpload'] as $errorField)
        @error($errorField)<p class="rt-ai-assist__error text-sm text-rt-red" role="alert">{{ $message }}</p>@enderror
    @endforeach
    @if($operationsChatComposer)
        @error('assistant')<p class="rt-ai-assist__error text-sm text-rt-red" role="alert">{{ $message }}</p>@enderror
    @endif
    <p x-cloak x-show="uploadError || error" x-text="uploadError || error" class="text-sm text-rt-red" role="alert"></p>
    <p x-cloak x-show="busy" class="text-xs ops-muted" role="status" aria-live="polite">{{ $operationsChatComposer ? 'AI-Assist wird aktualisiert …' : ($assistantComposer ? 'Die AI prüft diesen Eingang …' : 'Eingang wird gespeichert …') }}</p>
</form>
