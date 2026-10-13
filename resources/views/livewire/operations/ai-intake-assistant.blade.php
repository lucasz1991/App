<section class="rt-ai-assist__card mb-4" aria-label="AI-Eingang besprechen" x-data
    x-on:ai-intake-assistant-selected.window="if ($event.detail.componentId === @js($this->getId())) $nextTick(() => $refs.intakePrompt?.focus())">
    <div wire:loading.attr="inert" wire:target="ask,review,select,reloadContext,close">
    <fieldset class="min-w-0 border-0 p-0" wire:loading.attr="disabled" wire:target="ask,review,select,reloadContext,close">
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
                            <strong class="text-sm whitespace-pre-wrap break-words">{{ $run->configuration['prompt'] ?? '' }}</strong>
                            <p class="text-sm whitespace-pre-wrap break-words">{{ $run->result['answer'] ?? '' }}</p>
                        </li>
                    @endforeach
                </ol>
            @endif
            <form wire:submit="ask" class="grid gap-2 p-3">
                <label for="intake-assistant-prompt-{{ $this->getId() }}" class="text-sm font-semibold">Frage oder Prüfhinweis</label>
                <x-ui.forms.textarea id="intake-assistant-prompt-{{ $this->getId() }}" x-ref="intakePrompt" wire:model="prompt" :rows="3" maxlength="2000"
                    placeholder="Zum Beispiel: Warum fehlen die Dienste aus der zweiten Tabellenzeile?"
                    aria-describedby="intake-assistant-help-{{ $this->getId() }}" :aria-invalid="$errors->has('prompt') ? 'true' : null" />
                @error('prompt')<p class="rt-ai-assist__error" role="alert">{{ $message }}</p>@enderror
                <div class="flex flex-wrap gap-2">
                    <button type="submit" class="rt-ai-assist__btn" data-primary="true"><i class="far fa-comment-alt" aria-hidden="true"></i>Frage stellen</button>
                    @if($canReview)
                        <button type="button" class="rt-ai-assist__btn" wire:click="review"><i class="far fa-magic" aria-hidden="true"></i>Mit Hinweis erneut prüfen</button>
                    @endif
                </div>
                <p id="intake-assistant-help-{{ $this->getId() }}" class="text-xs">„Frage stellen“ erklärt den aktuellen Stand. „Mit Hinweis erneut prüfen“ aktualisiert die Mailerkennung. Kunden, Anfragen, Aufträge und Versand prüfst du anschließend persönlich.</p>
            </form>
            <div class="rt-ai-assist__card-buttons"><a class="rt-ai-assist__btn" href="{{ $intakeUrl }}">Ergebnis und Original im Eingang prüfen</a></div>
        @else
            <p class="rt-ai-assist__card-note">Bei einem Eingang „Mit AI besprechen“ wählen. Anschließend kannst du Fragen stellen und Hinweise zur Erkennung geben.</p>
        @endif
        @error('assistant')<p class="rt-ai-assist__error p-3" role="alert">{{ $message }}</p>@enderror
        @if($feedback !== '')<p class="rt-ai-assist__card-note" role="status">{{ $feedback }}</p>@endif
    </fieldset>
    </div>
    <p class="rt-ai-assist__card-note" wire:loading wire:target="ask,review" role="status" aria-live="polite">Die AI prüft diesen Eingang …</p>
</section>
