@php
    $assistantComposer = ($composerMode ?? 'assistant') === 'assistant';
    $composerField = $assistantComposer ? 'prompt' : 'text';
    $composerAction = $assistantComposer ? 'submitPrompt' : 'submit';
    $composerId = 'intake-composer-'.$this->getId();
    $composerLimit = $maxCharacters ?? ($assistantComposer ? 2000 : 20000);
    $fileCount = count($uploads ?? []);
    $composerMaximum = max(1, min(3, (int) ($maxAttachmentCount ?? ($limits['max_attachment_count'] ?? 3))));
@endphp
<form class="rt-intake-composer grid min-w-0 gap-2"
    x-data="aiIntakeComposer($wire, @js(['mode' => $assistantComposer ? 'assistant' : 'capture', 'action' => $composerAction, 'componentId' => $this->getId(), 'maxFiles' => $composerMaximum]))"
    x-effect="syncContext()" x-on:submit.prevent.stop="submit()"
    x-on:ai-intake-composer-submitted.window="submitted($event.detail.componentId)"
    x-on:ai-intake-submitted.window="submitted(@js($this->getId()))"
    x-on:ai-intake-composer-audio-removed.window="if ($event.detail.componentId === @js($this->getId())) clearAudioPreview()">
    @if(!$assistantComposer)
        <label class="sr-only" for="{{ $composerId }}-contact">Antwortadresse</label>
        <x-ui.forms.input id="{{ $composerId }}-contact" wire:model="contactEmail" type="email" placeholder="Antwortadresse (optional)" />
        <x-ui.forms.input-error for="contactEmail" />
    @elseif($canReview ?? false)
        <div class="rt-intake-composer__mode flex flex-wrap items-center gap-2 text-xs">
            <label class="sr-only" for="{{ $composerId }}-mode">Frage oder Prüfhinweis</label>
            <x-ui.forms.select id="{{ $composerId }}-mode" wire:model="promptMode" class="rt-intake-composer__mode-select min-w-0">
                <option value="question">Frage stellen</option>
                @if($canReview ?? false)<option value="review">Mit Hinweis erneut prüfen</option>@endif
            </x-ui.forms.select>
        </div>
    @elseif($overview ?? false)
        <span class="rt-intake-composer__scope text-xs ops-muted">{{ $intakeId ? 'Ausgewählter Eingang' : ($customerId ? 'Eingänge dieses Kunden' : 'Alle Eingänge') }}</span>
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
    <div class="rt-intake-composer__progress flex items-center gap-2 text-xs" x-cloak x-show="uploadActive" role="progressbar" aria-label="Dateien werden hochgeladen" aria-valuemin="0" aria-valuemax="100" x-bind:aria-valuenow="Math.round(uploadProgress)">
        <progress max="100" x-bind:value="uploadProgress" class="min-w-0 flex-1"></progress><span x-text="`${Math.round(uploadProgress)} %`"></span><button type="button" x-on:click="cancelFileUpload()" aria-label="Dateiupload abbrechen"><i class="far fa-times" aria-hidden="true"></i></button>
    </div>
    <div x-cloak x-show="recording || requesting || uploading" class="flex flex-wrap items-center gap-2 text-xs" role="status" aria-live="polite">
        <span x-show="requesting">Mikrofonfreigabe ausstehend …</span><span x-show="recording">Aufnahme · <span x-text="seconds"></span> s</span><span x-show="uploading">Aufnahme wird hochgeladen …</span>
        <button type="button" x-show="recording" x-on:click="stop()" class="rounded-lg border border-rt-border px-2 py-1">Aufnahme übernehmen</button>
        <button type="button" x-on:click="cancelRecording()" class="rounded-lg border border-rt-border px-2 py-1">Abbrechen</button>
    </div>
    <div class="rt-intake-composer__shell flex min-w-0 items-end gap-1 rounded-xl border border-rt-border bg-rt-surface p-1.5">
        <input id="{{ $composerId }}-files" class="sr-only" type="file" multiple tabindex="-1" x-ref="files" wire:model="uploads"
            accept=".txt,.md,.csv,.json,.pdf,.jpg,.jpeg,.png,.webp,.docx,.xlsx,.pptx,.wav,.mp3,.ogg,.webm,.m4a,.mp4,.aac,.flac"
            x-on:change="filesSelected($event)" x-on:livewire-upload-start="beginUpload()" x-on:livewire-upload-progress="progressUpload($event.detail)"
            x-on:livewire-upload-finish="finishUpload()" x-on:livewire-upload-error="failUpload()" x-on:livewire-upload-cancel="cancelUpload()" />
        <button type="button" class="rt-intake-composer__tool shrink-0 rounded-lg p-2" x-on:click="chooseFiles()" x-bind:disabled="busy || uploadActive || recording || requesting || attachmentCount() >= {{ $composerMaximum }}" title="Dateien anhängen" aria-label="Dateien anhängen"><i class="far fa-paperclip" aria-hidden="true"></i></button>
        <button type="button" class="rt-intake-composer__tool shrink-0 rounded-lg p-2" x-on:click="toggleRecording()" x-bind:disabled="busy || uploadActive || uploading || requesting || !!$wire.audioUpload || attachmentCount() >= {{ $composerMaximum }}" x-bind:aria-pressed="recording.toString()" title="Sprache aufnehmen" aria-label="Sprache aufnehmen"><i class="far fa-microphone" aria-hidden="true"></i></button>
        <label class="sr-only" for="{{ $composerId }}-prompt">{{ $assistantComposer ? 'Frage oder Prüfhinweis' : 'Anfrage beschreiben' }}</label>
        <textarea id="{{ $composerId }}-prompt" class="rt-intake-composer__input min-h-10 min-w-0 flex-1 resize-none border-0 bg-transparent px-1 py-2 text-sm focus:ring-0"
            rows="1" maxlength="{{ $composerLimit }}" wire:model="{{ $composerField }}" x-ref="prompt" x-on:input="resize()" x-on:keydown.enter="enter($event)"
            x-bind:disabled="busy" placeholder="{{ $assistantComposer ? 'Frage stellen oder einen Prüfhinweis geben …' : 'Anfrage beschreiben oder Dateien anhängen …' }}"
            aria-describedby="intake-assistant-help-{{ $this->getId() }}"></textarea>
        <button type="submit" class="rt-intake-composer__send shrink-0 rounded-lg bg-rt-red p-2 text-white" x-bind:disabled="!canSubmit()" aria-label="{{ $assistantComposer ? 'Frage oder Prüfhinweis senden' : 'Als AI-Eingang erfassen' }}"><i class="far fa-paper-plane" aria-hidden="true"></i></button>
    </div>
    <p id="intake-assistant-help-{{ $this->getId() }}" class="text-xs ops-muted" title="{{ $assistantComposer ? 'Zusatzdateien und Sprache werden nur für diese Frage oder Prüfung verwendet, nicht als Originalanlagen gespeichert. Freigaben bleiben persönlich; Umschalt+Enter fügt einen Absatz hinzu.' : 'Der Eingang wird zur persönlichen Prüfung erfasst; Umschalt+Enter fügt einen Absatz hinzu.' }}">{{ $assistantComposer ? 'Dateien und Sprache: Zusatzhinweise nur für diese Prüfung.' : 'Unverbindlich zur Prüfung erfassen.' }}</p>
    @foreach([$composerField, 'promptMode', 'uploads', 'uploads.*', 'audioUpload'] as $errorField)
        @error($errorField)<p class="rt-ai-assist__error text-sm text-rt-red" role="alert">{{ $message }}</p>@enderror
    @endforeach
    <p x-cloak x-show="uploadError || error" x-text="uploadError || error" class="text-sm text-rt-red" role="alert"></p>
    <p x-cloak x-show="busy" class="text-xs ops-muted" role="status" aria-live="polite">{{ $assistantComposer ? 'Die AI prüft diesen Eingang …' : 'Eingang wird gespeichert …' }}</p>
</form>
