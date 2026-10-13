<section class="rt-ai-assist__card mb-4" aria-label="AI-Eingang besprechen" x-data
    x-on:ai-intake-assistant-selected.window="if ($event.detail.componentId === @js($this->getId())) $nextTick(() => $el.querySelector('.rt-intake-composer__input')?.focus())">
    <div wire:loading.attr="inert" wire:target="ask,review,submitPrompt,select,reloadContext,close">
    <fieldset class="min-w-0 border-0 p-0" wire:loading.attr="disabled" wire:target="ask,review,submitPrompt,select,reloadContext,close">
        <div class="rt-ai-assist__card-head">
            <i class="far fa-comment-alt" aria-hidden="true"></i>
            <strong>{{ $intake ? ($intake->title ?: 'Eingang #'.$intake->id) : 'Eingang mit AI besprechen' }}</strong>
            @if($intakeId)
                <button type="button" class="rt-ai-assist__icon-btn" wire:click="close" aria-label="Eingangsprüfung schließen"><i class="far fa-times" aria-hidden="true"></i></button>
            @endif
        </div>
        @if($contextChanged)
            <p class="rt-ai-assist__card-note" role="status">Der Eingang oder die Einstellungen wurden geändert. Den aktuellen Stand laden und die Frage neu formulieren.</p>
            <div class="rt-ai-assist__card-buttons"><button type="button" class="rt-ai-assist__btn" wire:click="reloadContext">Aktuellen Stand laden</button></div>
        @elseif($intake)
            <p class="rt-ai-assist__card-note">Fragen zum Original stellen oder die Erkennung mit einem konkreten Hinweis korrigieren. Antworten beziehen sich nur auf diesen Eingang.</p>
            @if($history->isNotEmpty())
                <ol class="grid gap-3 p-3" aria-label="Fragen zu diesem Eingang">
                    @foreach($history as $run)
                        <li class="grid min-w-0 gap-1" wire:key="intake-question-{{ $run->id }}">
                            <strong class="text-sm whitespace-pre-wrap break-words">{{ ($run->configuration['prompt'] ?? '') ?: 'Datei- oder Sprachhinweis' }}</strong>
                            @foreach($run->configuration['supplemental_context']['files'] ?? [] as $file)<span class="text-xs ops-muted"><i class="far fa-paperclip" aria-hidden="true"></i> {{ $file['name'] }}</span>@endforeach
                            <p class="text-sm whitespace-pre-wrap break-words">{{ $run->result['answer'] ?? '' }}</p>
                        </li>
                    @endforeach
                </ol>
            @endif
            <div class="p-3">@include('livewire.operations.partials.ai-intake-composer', ['composerMode' => 'assistant'])</div>
            <div class="rt-ai-assist__card-buttons"><a class="rt-ai-assist__btn" href="{{ $intakeUrl }}">Ergebnis und Original im Eingang prüfen</a></div>
        @else
            <p class="rt-ai-assist__card-note">Bei einem Eingang „Mit AI besprechen“ wählen. Anschließend kannst du Fragen stellen und Hinweise zur Erkennung geben.</p>
        @endif
        @error('assistant')<p class="rt-ai-assist__error p-3" role="alert">{{ $message }}</p>@enderror
        @if($feedback !== '')<p class="rt-ai-assist__card-note" role="status">{{ $feedback }}</p>@endif
    </fieldset>
    </div>
    <p class="rt-ai-assist__card-note" wire:loading wire:target="ask,review,submitPrompt" role="status" aria-live="polite">Die AI prüft diesen Eingang …</p>
</section>
